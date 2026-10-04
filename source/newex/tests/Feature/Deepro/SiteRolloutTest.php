<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Models\{User\User,Wallet\Wallet,Wallet\WalletAddress,Currency\Currency,Network\Network};
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use Illuminate\Support\Facades\{DB,Http,Mail,Event,Queue,Auth,Cache};

final class SiteRolloutTest extends TestCase {
 protected function setUp():void {
  parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
  config(['app.readonly'=>false,'app.fireblocks_enabled'=>false,'cache.default'=>'array','broadcasting.default'=>'log','mail.default'=>'array']);
  DB::beginTransaction();Mail::fake();Event::fake();Queue::fake();Http::fake(['*'=>Http::response([],503)]);
 }
 protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
 private function user():User { $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'site-'.uniqid().'@example.com','email_verified_at'=>now(),'is_xn'=>false]));$u->assignRole('user');return $u; }
 private function login(User $u):void {Auth::forgetGuards();$u->withAccessToken(new \Laravel\Sanctum\TransientToken());$this->actingAs($u,'web');}
 private function wallet(User $u):Wallet {return Wallet::firstOrCreate(['user_id'=>$u->id,'currency_id'=>2]);}
 public function test_evm_key_derivation_matches_independent_curve_and_signature_verification():void {
  $ec=new \Elliptic\EC('secp256k1');$addresses=[];
  for($i=0;$i<4;$i++) {
   $pair=(new EthereumGateway)->createEthAddress();$key=$ec->keyFromPrivate(substr($pair['private_key'],2),'hex');
   $pub=$key->getPublic(false,'hex');$derived='0x'.substr(\kornrunner\Keccak::hash(hex2bin(substr($pub,2)),256),-40);
   $this->assertTrue(hash_equals($derived,$pair['address']),'Public address matches private key');
   $msg=hash('sha256','Local non-transaction signing test');$sig=$key->sign($msg);
   $this->assertTrue($ec->keyFromPublic($pub,'hex')->verify($msg,$sig));$addresses[]=$pair['address'];
  }
  $this->assertCount(4,array_unique($addresses));
 }
 public function test_address_refresh_shares_evm_identity_but_never_rotates_existing_network():void {
  $u=$this->user();$wallet=$this->wallet($u);$c=Currency::findOrFail(2);$r=app(\App\Repositories\Wallet\WalletRepository::class);
  $bsc=Network::findOrFail(6);$eth=Network::findOrFail(3);
  $first=$r->getWalletAddress($wallet,$c,$bsc);$again=$r->getWalletAddress($wallet,$c,$bsc);$shared=$r->getWalletAddress($wallet,$c,$eth);
  $this->assertNotFalse($first);$this->assertSame($first->id,$again->id);$this->assertSame($first->address,$shared->address);
  $this->assertNotSame($first->private_key,DB::table('wallet_addresses')->where('id',$first->id)->value('private_key'));
  $different=(new EthereumGateway)->createEthAddress();$shared->address=$different['address'];$shared->private_key=$different['private_key'];$shared->save();
  $this->assertSame($different['address'],$r->getWalletAddress($wallet,$c,$eth)->address);
  $other=$this->user();$this->assertNotSame($first->address,$r->getWalletAddress($this->wallet($other),$c,$bsc)->address);
  $this->assertSame(2,WalletAddress::where('user_id',$u->id)->count());
 }
 public function test_address_book_enforces_owner_network_memo_and_keeps_funds_untouched():void {
  DB::table('currencies')->where('id',2)->update(['status'=>true,'withdraw_status'=>true,'disabled_withdrawal_networks'=>'']);DB::table('networks')->where('id',6)->update(['status'=>true,'withdraw_status'=>true]);
  $a=$this->user();$b=$this->user();$this->login($a);$wallet=$this->wallet($a);$before=$wallet->refresh()->getAttributes();
  $payload=['symbol'=>'USDT','network'=>6,'label'=>'My external wallet','address'=>'0x'.str_repeat('1',40)];
  $id=$this->postJson('/api/v1/wallets/address-book',$payload)->assertCreated()->json('id');
  $this->postJson('/api/v1/wallets/address-book',$payload)->assertCreated()->assertJsonPath('id',$id);
  $this->getJson('/api/v1/wallets/address-book?symbol=USDT&network=6')->assertOk()->assertJsonCount(1);
  $this->getJson('/api/v1/wallets/address-book?symbol=USDT&network=3')->assertOk()->assertExactJson([]);
  $this->postJson('/api/v1/wallets/address-book',array_replace($payload,['network'=>5]))->assertStatus(422);
  $this->postJson('/api/v1/wallets/address-book',array_replace($payload,['address'=>'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb']))->assertStatus(422);
  DB::table('currencies')->where('id',2)->update(['has_payment_id'=>true]);
  $this->postJson('/api/v1/wallets/address-book',$payload)->assertStatus(422)->assertJsonValidationErrors('payment_id');
  $this->login($b);$this->getJson('/api/v1/wallets/address-book?symbol=USDT&network=6')->assertOk()->assertExactJson([]);$this->deleteJson('/api/v1/wallets/address-book/'.$id)->assertNotFound();
  $this->login($a);$this->deleteJson('/api/v1/wallets/address-book/'.$id)->assertNoContent();
  $this->assertSame($before,$wallet->refresh()->getAttributes());
 }
 public function test_curated_icon_requires_exact_identity_and_has_existing_local_file():void {
  foreach(config('currency-icons') as $id=>$entry) {$c=Currency::findOrFail($id);$this->assertSame($entry['path'],$c->logo_path);$this->assertFileExists(public_path($c->logo_path));}
  $c=Currency::findOrFail(14);$c->bep_contract='0x'.str_repeat('f',40);$this->assertNotSame(config('currency-icons.14.path'),$c->logo_path);
 }
 public function test_public_trades_fail_gracefully_and_do_not_send_exchange_credentials():void {
  $m=\App\Models\Market\Market::where('name','BTC-USDT')->firstOrFail();$m->chart_symbol='BTCUSDT';$m->save();
  Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['*'=>Http::response(['code'=>-1,'msg'=>'unavailable'],451)]);
  $this->getJson('/api/v1/markets/historical/trades?market=BTC-USDT')->assertStatus(503)->assertJsonPath('trades',[]);
  Http::assertSent(fn($r)=>str_starts_with($r->url(),'https://data-api.binance.vision/')&&!$r->hasHeader('X-MBX-APIKEY'));
  Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['*'=>Http::response([['price'=>'70000','qty'=>'0.01','time'=>1700000000000,'isBuyerMaker'=>true]],200)]);
  $this->getJson('/api/v1/markets/historical/trades?market=BTC-USDT')->assertOk()->assertJsonPath('success',true)->assertJsonCount(1,'trades');
 }
 public function test_locale_catalogue_has_long_keys_and_persists_explicit_user_choice_after_logout():void {
  $this->artisan('deepro:sync-simplified-chinese')->assertExitCode(0);
  $u=$this->user();$cookie=app(\App\Services\Language\LanguageService::class)->setLanguage('zh-cn',$u);
  $this->assertSame('zh-cn',$cookie->getValue());$this->assertSame('zh-cn',$u->fresh()->language->slug);
  app()->setLocale('zh-cn');$this->assertSame('地址簿',__('Address book'));$this->assertSame('Deepro · UMI 账户绑定验证码',__('Deepro · UMI account binding code'));
  $cat=json_decode(file_get_contents(resource_path('lang/zh-cn.json')),true);foreach($cat as $k=>$v){preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/',$k,$a);preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/',$v,$b);sort($a[0]);sort($b[0]);$this->assertSame($a[0],$b[0],$k);}
 }
}
