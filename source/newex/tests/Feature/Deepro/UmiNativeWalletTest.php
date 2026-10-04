<?php
namespace Tests\Feature\Deepro;
use App\Models\User\User;
use App\Services\Umi\Business\{Engine,CustodyTransfers,Amount};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class UmiNativeWalletTest extends TestCase {
    use DatabaseTransactions, \Tests\Support\UmiSponsorFixture;
    private User $user;
    private object $account;
    private int $wallet;
    protected function setUp():void {
        parent::setUp();$this->assertSame('umi_regression',DB::connection()->getDatabaseName());
        config(['umi-business.enabled'=>true,'umi-business.funded_live'=>true]);
        // The isolated regression clone can predate today; no settlement is needed for these wallet tests.
        DB::table('umi_business_state')->where('id',1)->update(['business_date'=>now(config('umi-business.defaults.timezone'))->subDay()->toDateString(),'paused'=>false]);
        DB::table('umi_business_balances')->where('bucket','system:inventory')->where('asset','USDT')->update(['amount'=>'0']);
        $this->user=User::factory()->create();$this->user->assignRole('user');
        app(Engine::class)->enroll($this->user->id,$this->umiSponsorCode(),(string)Str::uuid());
        $this->account=app(Engine::class)->owned($this->user->id);
        $this->wallet=DB::table('wallets')->where('user_id',$this->user->id)->where('currency_id',DB::table('currencies')->where('symbol','USDT')->value('id'))->value('id');
        DB::table('wallets')->where('id',$this->wallet)->update(['balance_in_wallet'=>'100.000000000000000001']);
    }
    private function transfer(string $direction,string $amount,?string $key=null):array {return app(CustodyTransfers::class)->transfer($this->user->id,'USDT',$direction,$amount,$key??(string)Str::uuid());}
    private function balance():string{return DB::table('wallets')->where('id',$this->wallet)->value('balance_in_wallet');}
    public function test_native_wallet_round_trip_is_exact_and_replay_never_debits_twice():void {
        $key=(string)Str::uuid();$one=$this->transfer('in','10.000000000000000001',$key);$again=$this->transfer('in','10.000000000000000001',$key);
        $this->assertSame($one['id'],$again['id']);$this->assertTrue($again['replayed']);$this->assertSame(0,bccomp('90',$this->balance(),18));
        $this->transfer('out','10.000000000000000001');$this->assertSame(0,bccomp('100.000000000000000001',$this->balance(),18));
        $this->assertSame(2,DB::table('umi_custody_transfers')->where('wallet_id',$this->wallet)->count());$this->assertTrue(app(Engine::class)->ledger->audit()['ok']);
    }
    public function test_insufficient_native_balance_leaves_both_ledgers_unchanged():void {
        $before=[DB::table('umi_business_operations')->count(),DB::table('umi_business_entries')->count(),$this->balance()];
        try{$this->transfer('in','101');$this->fail('Overdraft allowed');}catch(ValidationException $e){}
        $this->assertSame($before,[DB::table('umi_business_operations')->count(),DB::table('umi_business_entries')->count(),$this->balance()]);
    }
    public function test_insufficient_umi_balance_cannot_mint_native_wallet_funds():void {
        $before=$this->balance();try{$this->transfer('out','1');$this->fail('Overdraft allowed');}catch(ValidationException $e){}
        $this->assertSame($before,$this->balance());$this->assertSame(0,DB::table('umi_custody_transfers')->where('wallet_id',$this->wallet)->count());
    }
    public function test_sub_atomic_amounts_are_rejected_without_rounding():void {
        $before=$this->balance();try{$this->transfer('in','0.0000000000000000001');$this->fail('Precision lost');}catch(ValidationException $e){}
        $this->assertSame($before,$this->balance());
    }
    public function test_receipt_failure_rolls_back_both_wallet_and_umi_entries():void {
        DB::statement("CREATE FUNCTION pg_temp.reject_umi_receipt() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''receipt_test_failure''; END'");
        DB::statement('CREATE TRIGGER test_receipt_failure BEFORE INSERT ON umi_custody_transfers FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_umi_receipt()');
        $before=[$this->balance(),DB::table('umi_business_entries')->count()];
        try{$this->transfer('in','1');$this->fail('Receipt failure ignored');}catch(\Illuminate\Database\QueryException $e){$this->assertStringContainsString('receipt_test_failure',$e->getMessage());}
        $this->assertSame($before,[$this->balance(),DB::table('umi_business_entries')->count()]);
    }
    public function test_user_cannot_fund_admin_pool_and_admin_funding_is_debited():void {
        try{app(CustodyTransfers::class)->transfer($this->user->id,'USDT','in','1',(string)Str::uuid(),'inventory');$this->fail();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->user->assignRole('superadmin');app(CustodyTransfers::class)->transfer($this->user->id,'USDT','in','1',(string)Str::uuid(),'inventory');
        $this->assertSame(0,bccomp('99.000000000000000001',$this->balance(),18));
        $this->assertSame(0,bccomp('1',DB::table('umi_business_balances')->where('bucket','system:inventory')->where('asset','USDT')->value('amount'),18));
    }
}
