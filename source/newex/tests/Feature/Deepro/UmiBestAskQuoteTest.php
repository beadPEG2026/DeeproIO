<?php
namespace Tests\Feature\Deepro;

use App\Models\Market\Market;
use App\Services\Umi\V2\{BestAskBook,BestAskQuote,FundedTime};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

final class UmiBestAskQuoteTest extends TestCase
{
    private BestAskBook $book;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.ask_test'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''],
            'database.default'=>'ask_test','umi.asset.contract'=>'0x1111111111111111111111111111111111111111']);
        DB::purge('ask_test');
        Schema::create('currencies',function(Blueprint $t){$t->id();$t->string('symbol');$t->string('bep_contract')->default('');$t->boolean('status')->default(true);$t->softDeletes();});
        Schema::create('markets',function(Blueprint $t){$t->id();$t->string('name');$t->integer('base_currency_id');$t->integer('quote_currency_id');$t->boolean('status')->default(true);$t->boolean('trade_status')->default(true);$t->softDeletes();});
        Schema::create('umi_v2_live_quotes',function(Blueprint $t){$t->id();$t->string('asset');$t->string('price');$t->string('source');$t->string('source_ref');$t->string('observed_at');$t->integer('approved_by')->nullable();$t->string('created_at');$t->unique(['asset','source','source_ref']);});
        DB::table('currencies')->insert([['id'=>1,'symbol'=>'UMI','bep_contract'=>config('umi.asset.contract')],['id'=>2,'symbol'=>'USDT','bep_contract'=>'']]);
        DB::table('markets')->insert(['id'=>1,'name'=>'UMI-USDT','base_currency_id'=>1,'quote_currency_id'=>2]);
        $this->book=new class extends BestAskBook {public array $data=['asks'=>[['price'=>'1.2576','quantity'=>'10']],'bids'=>[['price'=>'1.2575','quantity'=>'10']]]; public function read(Market $market):array{return $this->data;}};
        app()->instance(BestAskBook::class,$this->book);
        $this->travelTo(now()->startOfSecond());
    }
    protected function tearDown():void {$this->travelBack();parent::tearDown();}
    public function test_uses_lowest_executable_ask_without_requiring_any_trade():void
    {
        $this->book->data['asks'][]=['price'=>'1.3','quantity'=>'100'];
        $q=app(BestAskQuote::class)->read();
        self::assertSame('1.2576',$q->price);self::assertSame(BestAskQuote::SOURCE,$q->source);
        self::assertSame(0,DB::table('umi_v2_live_quotes')->count(),'Public reads do not write snapshots');
        $saved=app(BestAskQuote::class)->read(true);self::assertSame($saved->id,app(BestAskQuote::class)->read(true)->id);
        self::assertSame(1,DB::table('umi_v2_live_quotes')->count());
    }
    public function test_empty_crossed_zero_and_malformed_books_are_unavailable():void
    {
        foreach ([['asks'=>[],'bids'=>[]],['asks'=>[['price'=>'1','quantity'=>'0']]],['asks'=>[['price'=>'0','quantity'=>'10']]],['asks'=>[['price'=>'NaN','quantity'=>'10']]],['asks'=>[['price'=>'1','quantity'=>'10']],'bids'=>[['price'=>'1','quantity'=>'1']]]] as $book) {
            $this->book->data=$book;self::assertFalse(app(BestAskQuote::class)->status()['available']);
        }
        self::assertSame(0,DB::table('umi_v2_live_quotes')->count());
    }
    public function test_historical_points_use_saved_ask_not_future_price():void
    {
        $old=app(BestAskQuote::class)->read(true);$at=now()->addSeconds(6)->toIso8601String();
        $this->travel(10)->seconds();$this->book->data=['asks'=>[['price'=>'2','quantity'=>'10']]];app(BestAskQuote::class)->read(true);
        self::assertSame($old->id,app(BestAskQuote::class)->at($at)->id);
        self::assertSame('1.2576',app(BestAskQuote::class)->at($at)->price);
        $this->expectException(\DomainException::class);app(BestAskQuote::class)->at(now()->addSeconds(21)->toIso8601String());
    }
    public function test_market_disabled_or_duplicate_cannot_produce_business_quote():void
    {
        DB::table('markets')->where('id',1)->update(['trade_status'=>false]);self::assertFalse(app(BestAskQuote::class)->status()['available']);
        DB::table('markets')->where('id',1)->update(['trade_status'=>true]);DB::table('markets')->insert(['name'=>'UMI-USDT-duplicate','base_currency_id'=>1,'quote_currency_id'=>2]);
        self::assertFalse(app(BestAskQuote::class)->status()['available']);
    }
    public function test_ask_price_keeps_decimal_precision():void
    {
        $this->book->data=['asks'=>[['price'=>'1.123456789012345678','quantity'=>'1']]];
        self::assertSame('1.123456789012345678',app(BestAskQuote::class)->read(true)->price);
    }
}
