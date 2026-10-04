<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use Illuminate\Support\Facades\{DB,Cache,Event,Http,Mail,Queue};
use Illuminate\Http\Request;
use App\Models\User\User;
use App\Models\Launchpad\Launchpad;
use App\Models\CopyTrading\CopyTradingTrader;
use App\Services\Content\{ProductPublication,LocalizedPage};
use App\Services\Deposit\{DepositChannelPolicy,BitcoinWalletScanner};

final class UserExperienceRemediationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();
        config(['app.readonly'=>false,'cache.default'=>'array','session.driver'=>'array']);
        Event::fake(); Mail::fake(); Queue::fake(); Http::preventStrayRequests();
    }
    protected function tearDown():void { while(DB::transactionLevel()>0) DB::rollBack(); parent::tearDown(); }

    public function test_kline_poll_keeps_rolling_statistics_separate_from_runtime_last():void
    {
        $market=\App\Models\Market\Market::where('name','BTC-USDT')->firstOrFail();
        Cache::put('market_kline_runtime_config_'.$market->id,['last'=>83832.95]);
        foreach (['last'=>83820,'high'=>85255,'low'=>83183,'volume'=>17873] as $key=>$value) market_set_stats($market->id,$key,$value);
        $url='/markets/'.$market->id.'/kline-delete-time';
        $response=$this->getJson($url)->assertOk();
        $this->assertSame(83832.95,(float)$response->json('market.last'));
        $this->assertSame(85255.0,(float)$response->json('market.high'));
        $this->assertSame(83183.0,(float)$response->json('market.low'));
        market_set_stats_force($market->id,'high',0);market_set_stats_force($market->id,'low',0);
        $this->getJson($url)->assertOk()->assertJsonPath('market.high',null)->assertJsonPath('market.low',null);
    }

    public function test_btc_uses_private_wallet_scanner_health_and_respects_all_disable_switches():void
    {
        $c=DB::table('currencies')->where('symbol','BTC')->first();
        $n=DB::table('networks')->where('slug','btc')->first();
        DB::table('currencies')->where('id',$c->id)->update(['status'=>true,'deposit_status'=>true,'disabled_deposit_networks'=>'']);
        DB::table('networks')->where('id',$n->id)->update(['status'=>true,'deposit_status'=>true]);
        DB::table('currency_networks')->updateOrInsert(['currency_id'=>$c->id,'network_id'=>$n->id],[]);
        config(['bitcoind.default.host'=>'local.test','bitcoind.default.user'=>'test','bitcoind.default.password'=>'test']);
        $p=app(DepositChannelPolicy::class);
        Cache::forget(BitcoinWalletScanner::STATE);
        $this->assertSame('Deposit scanner needs attention',$p->error($c->id,$n->id));
        foreach ([now()->subHour(),now()->addHour()] as $date) {
            Cache::put(BitcoinWalletScanner::STATE,['checked_at'=>$date->toIso8601String(),'height'=>123]);
            $this->assertNotNull($p->error($c->id,$n->id));
        }
        Cache::put(BitcoinWalletScanner::STATE,['checked_at'=>now()->toIso8601String(),'height'=>123]);
        $this->assertNull($p->error($c->id,$n->id));
        $networks=app(\App\Http\Controllers\Api\v1\WalletController::class)->loadNetworks(Request::create('/','GET',['symbol'=>'BTC','purpose'=>'deposit']))->getData(true);
        $this->assertArrayHasKey($n->id,$networks);
        DB::table('networks')->where('id',$n->id)->update(['deposit_status'=>false]);
        $this->assertSame('Deposits are unavailable',$p->error($c->id,$n->id));
        DB::table('networks')->where('id',$n->id)->update(['deposit_status'=>true]);
        DB::table('currencies')->where('id',$c->id)->update(['disabled_deposit_networks'=>(string)$n->id]);
        $this->assertSame('Deposits are unavailable',$p->error($c->id,$n->id));
        DB::table('currencies')->where('id',$c->id)->update(['disabled_deposit_networks'=>'']);
        config(['bitcoind.default.password'=>'']);$this->assertSame('Scanner credential is missing',$p->error($c->id,$n->id));
        config(['bitcoind.default.password'=>'test']);DB::table('currency_networks')->where('currency_id',$c->id)->where('network_id',$n->id)->delete();
        $this->assertSame('Asset is not linked to this network',$p->error($c->id,$n->id));
        Http::assertNothingSent();
    }
    public function test_unreviewed_launchpads_are_hidden_in_public_api_and_cannot_be_purchased():void
    {
        $p=Launchpad::firstOrFail();$p->forceFill(['status'=>true,'purchasable'=>true,'publication_status'=>'draft','publication_reviewed_by'=>null,'publication_reviewed_at'=>null])->save();
        $this->getJson('/api/v1/launchpad?id='.$p->id)->assertNotFound();
        $this->assertFalse((new \App\Repositories\Launchpad\LaunchpadRepository)->get(false,'all',true)->contains('id',$p->id));
        $rule=new \App\Http\Requests\Web\Launchpad\Rules\LaunchpadPurchasableRule;
        $this->assertFalse($rule->passes('id',$p->id));
        $p->publication_status='published';$p->save();
        $this->assertFalse($p->isPublished());$this->assertFalse($rule->passes('id',$p->id));
        $p->forceFill(['publication_reviewed_by'=>User::firstOrFail()->id,'publication_reviewed_at'=>now()])->save();
        $this->assertTrue($rule->passes('id',$p->id));
    }
    public function test_publishing_records_actor_and_basis_and_draft_edit_hides_product():void
    {
        $p=Launchpad::firstOrFail();$u=User::firstOrFail();
        $r=Request::create('/','POST',['name'=>'Reviewed offering','publication_status'=>'published','publication_reference'=>'Local QA review: name, units and terms checked against the issuer document on 2026-09-26.']);$r->setUserResolver(fn()=>$u);
        $saved=ProductPublication::save($r,function($fields)use($p){$p->update($fields);return $p->refresh();});
        $this->assertTrue($saved->isPublished());
        $this->assertDatabaseHas('product_publication_events',['product_type'=>'launchpads','product_id'=>$p->id,'actor_id'=>$u->id,'status'=>'published']);
        $r->merge(['publication_status'=>'draft']);ProductPublication::save($r,function($fields)use($p){$p->update($fields);return $p->refresh();});
        $this->assertFalse($p->isPublished());$this->assertSame(2,DB::table('product_publication_events')->where('product_type','launchpads')->where('product_id',$p->id)->count());
    }
    public function test_test_name_and_unsubstantiated_publication_are_rejected():void
    {
        foreach ([['test','Long enough review text but still a test name'],['Formal name','short']] as [$name,$ref]) {
            $r=Request::create('/','POST',['name'=>$name,'publication_status'=>'published','publication_reference'=>$ref]);$r->setUserResolver(fn()=>User::firstOrFail());
            try {ProductPublication::fields($r);$this->fail('Unreviewed content published');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('publication_reference',$e->errors());}
        }
    }
    public function test_unpublished_copy_trader_cannot_accept_new_followers_but_existing_can_unfollow():void
    {
        $t=CopyTradingTrader::firstOrFail();$t->forceFill(['is_enabled'=>true,'publication_status'=>'draft'])->save();
        $u=User::where('id','!=',$t->user_id)->firstOrFail();
        DB::table('copy_trading_follows')->where('copy_trading_trader_id',$t->id)->where('follower_user_id',$u->id)->delete();
        $this->actingAs($u);
        $this->get('/copy-trading/'.$t->id)->assertNotFound();
        $this->post('/copy-trading/'.$t->id.'/follow')->assertSessionHas('error');
        $this->assertDatabaseMissing('copy_trading_follows',['copy_trading_trader_id'=>$t->id,'follower_user_id'=>$u->id,'is_enabled'=>true]);
        \App\Models\CopyTrading\CopyTradingFollow::create(['copy_trading_trader_id'=>$t->id,'follower_user_id'=>$u->id,'trader_user_id'=>$t->user_id,'is_enabled'=>true]);
        $this->delete('/copy-trading/'.$t->id.'/follow')->assertSessionHas('success');
        $this->assertDatabaseHas('copy_trading_follows',['copy_trading_trader_id'=>$t->id,'follower_user_id'=>$u->id,'is_enabled'=>false]);
    }
    public function test_umi_availability_uses_currency_switches_and_unknown_assets_stay_unavailable():void
    {
        DB::table('currencies')->where('symbol','UMI')->update(['deposit_status'=>false,'withdraw_status'=>false]);
        $service=app(\App\Services\Wallet\AssetAvailability::class);
        $this->assertSame(['deposit'=>false,'withdraw'=>false],$service->forSymbol('UMI',null));
        $this->assertSame(['deposit'=>false,'withdraw'=>false],$service->forSymbol('DOESNOTEXIST',null));
    }
    public function test_deposit_resource_exposes_iso_time_and_safe_review_explanation():void
    {
        $d=\App\Models\Deposit\Deposit::firstOrFail();
        $d->status=DEPOSIT_IGNORED;
        $d->initial_raw=json_encode(['pilot_reason'=>'DEPOSIT_PILOT_LIMIT_EXCEEDED','private_key'=>'fixture-must-not-leak']);
        $data=(new \App\Http\Resources\Wallet\Deposit\Deposit($d))->toArray(Request::create('/'));
        $this->assertMatchesRegularExpression('/T.*[+-][0-9]{2}:[0-9]{2}$/',$data['created_at_iso']);
        $this->assertNotEmpty($data['status_reason']);
        $this->assertTrue($data['review_required']);
        $this->assertStringNotContainsString('fixture-must-not-leak',json_encode($data));
    }
    public function test_chinese_cms_fallback_and_explicit_editorial_override():void
    {
        $page=(object)['slug'=>'terms','title'=>'Terms','content'=>'English','html_content'=>'<p>English</p>','title_zh-tw'=>'服務條款','content_zh-tw'=>'資產管理','html_content_zh-tw'=>'<p>風險</p>'];
        $this->assertSame('资产管理',LocalizedPage::fields($page,'zh-cn')['content']);
        $this->assertSame('<p>风险</p>',LocalizedPage::fields($page,'zh-cn')['html_content']);
        $page->{'content_zh-cn'}='编辑发布的简体说明';
        $this->assertSame('编辑发布的简体说明',LocalizedPage::fields($page,'zh-cn')['content']);
        $this->assertSame('English',LocalizedPage::fields($page,'en')['content']);
    }
    public function test_missing_option_limits_reject_backend_requests_but_explicit_zero_is_unlimited():void
    {
        $m=\App\Models\Market\Market::where('name','BTC-USDT')->firstOrFail();$m->forceFill(['status'=>true,'options_min_amount'=>null,'options_max_amount'=>null])->save();
        app('request')->merge(['market'=>$m->name]);$rule=new \App\Http\Requests\Api\Order\Rules\OptionsQuantityRule;
        $this->assertFalse($rule->passes('quantity','10'));
        $m->forceFill(['options_min_amount'=>'0','options_max_amount'=>'0'])->save();$this->assertTrue($rule->passes('quantity','10'));
        $m->forceFill(['options_min_amount'=>'5','options_max_amount'=>'20'])->save();
        $this->assertFalse($rule->passes('quantity','1'));$this->assertFalse($rule->passes('quantity','30'));$this->assertTrue($rule->passes('quantity','10'));
    }
}
