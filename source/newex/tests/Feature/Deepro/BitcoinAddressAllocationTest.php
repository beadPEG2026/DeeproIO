<?php
namespace Tests\Feature\Deepro;

use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Models\Wallet\{Wallet,WalletAddress};
use App\Repositories\Wallet\WalletRepository;
use App\Services\PaymentGateways\Coin\Bitcoin\Api\BitcoinGateway;
use Denpa\Bitcoin\ClientFactory;
use Denpa\Bitcoin\Responses\BitcoindResponse;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\{DB,Event,Http,Mail,Queue};
use Mockery;
use Tests\TestCase;

final class BitcoinAddressAllocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();
        config(['app.fireblocks_enabled'=>false]);
        Event::fake(); Mail::fake(); Queue::fake(); Http::preventStrayRequests();
    }
    protected function tearDown(): void
    {
        while(DB::transactionLevel()>0) DB::rollBack();
        parent::tearDown();
    }
    private function response($result): BitcoindResponse
    {
        return new BitcoindResponse(new Response(200,[],json_encode(['result'=>$result,'error'=>null,'id'=>1])));
    }
    private function rpc(): ClientFactory
    {
        $rpc=Mockery::mock(ClientFactory::class);
        $rpc->shouldNotReceive('dumpprivkey');
        $rpc->shouldNotReceive('sendToAddress');
        $this->app->instance('bitcoind',$rpc);
        return $rpc;
    }
    public function test_descriptor_address_is_saved_and_reused_without_exporting_wallet_keys(): void
    {
        $c=Currency::where('symbol','BTC')->firstOrFail();$n=Network::where('slug','btc')->firstOrFail();
        $w=Wallet::where('currency_id',$c->id)->firstOrFail();
        WalletAddress::where('user_id',$w->user_id)->where('network_id',$n->id)->delete();
        $rpc=$this->rpc();$address='bc1qfixtureonlynotarealaddress';
        $rpc->shouldReceive('getwalletinfo')->once()->andReturn($this->response(['descriptors'=>true,'private_keys_enabled'=>true]));
        $rpc->shouldReceive('getnewaddress')->once()->andReturn($this->response($address));
        $rpc->shouldReceive('getaddressinfo')->with($address)->once()->andReturn($this->response(['address'=>$address,'ismine'=>true,'iswatchonly'=>false]));
        $repo=new WalletRepository();$a=$repo->getWalletAddress($w,$c,$n);
        $this->assertInstanceOf(WalletAddress::class,$a);
        $this->assertSame($address,$a->address);$this->assertSame('', $a->private_key);
        $this->assertSame((int)$w->user_id,(int)$a->user_id);
        $this->assertSame($a->id,$repo->getWalletAddress($w,$c,$n)->id);
        $this->assertSame(1,WalletAddress::where('user_id',$w->user_id)->where('network_id',$n->id)->count());
        Http::assertNothingSent();
    }
    public function test_watch_only_wallet_cannot_allocate_a_deposit_address(): void
    {
        $rpc=$this->rpc();$rpc->shouldReceive('getwalletinfo')->once()->andReturn($this->response(['private_keys_enabled'=>false]));
        $rpc->shouldNotReceive('getnewaddress');
        $this->expectExceptionMessage('BTC_WALLET_CANNOT_SIGN');
        (new BitcoinGateway())->createOwnedBitcoinAddress();
    }
    public function test_an_unowned_or_mismatched_address_is_never_saved(): void
    {
        $c=Currency::where('symbol','BTC')->firstOrFail();$n=Network::where('slug','btc')->firstOrFail();
        $w=Wallet::where('currency_id',$c->id)->firstOrFail();
        WalletAddress::where('user_id',$w->user_id)->where('network_id',$n->id)->delete();
        foreach ([['ismine'=>false],['ismine'=>true,'iswatchonly'=>true],['ismine'=>true,'address'=>'wrong']] as $overrides) {
            $rpc=$this->rpc();$address='bc1qfixtureonlynotarealaddress';
            $rpc->shouldReceive('getwalletinfo')->once()->andReturn($this->response(['private_keys_enabled'=>true]));
            $rpc->shouldReceive('getnewaddress')->once()->andReturn($this->response($address));
            $rpc->shouldReceive('getaddressinfo')->with($address)->once()->andReturn($this->response(array_merge(['address'=>$address,'ismine'=>true],$overrides)));
            $this->assertFalse((new WalletRepository())->getWalletAddress($w,$c,$n));
            $this->assertSame(0,WalletAddress::where('user_id',$w->user_id)->where('network_id',$n->id)->count());
        }
    }
    public function test_empty_rpc_result_does_not_create_a_database_address(): void
    {
        $rpc=$this->rpc();$rpc->shouldReceive('getwalletinfo')->once()->andReturn($this->response(['private_keys_enabled'=>true]));
        $rpc->shouldReceive('getnewaddress')->once()->andReturn($this->response(null));
        $rpc->shouldNotReceive('getaddressinfo');
        $this->expectExceptionMessage('BTC_ADDRESS_NOT_GENERATED');
        (new BitcoinGateway())->createOwnedBitcoinAddress();
    }
}
