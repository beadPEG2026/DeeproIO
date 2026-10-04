<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Services\Deposit\{TronRpcBudget,TronGridClient,ChainAmount};
use Illuminate\Support\Facades\{DB,Schema,Http,Cache};

final class TronRpcGovernanceTest extends TestCase
{
    private string $key;
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        if (!Schema::hasTable('rpc_provider_budgets')) (require database_path('migrations/2026_09_24_150000_tron_rpc_governance.php'))->up();
        if(!DB::selectOne("SELECT to_regprocedure('deepro_rpc_reserve(text,integer,text)') AS f")->f) (require database_path('migrations/2026_09_24_151000_tron_rpc_fair_queue.php'))->up();
        $this->key='isolated-'.bin2hex(random_bytes(16));
        config(['services.trongrid.key'=>$this->key,'deposits.tron.request_interval'=>0.1,'cache.default'=>'array']);
        Cache::flush();Http::preventStrayRequests();
    }
    protected function tearDown(): void
    {
        while(DB::transactionLevel()) DB::rollBack();
        DB::table('rpc_provider_budgets')->where('key_hash',hash('sha256',$this->key))->delete();
        parent::tearDown();
    }
    public function test_php_admission_commits_outside_ledger_transaction_and_node_sees_it(): void
    {
        config(['deposits.tron.request_interval'=>2]);
        DB::beginTransaction();
        $budget=new TronRpcBudget();$budget->acquire($this->key);
        $this->assertSame(1,DB::transactionLevel());
        $this->assertSame(0,$budget->connection()->transactionLevel());
        // Node reserves the next future slot instead of racing PHP for it.
        $r=DB::selectOne('SELECT * FROM deepro_rpc_reserve(?, ?, ?)',[hash('sha256',$this->key),100,'node']);
        $this->assertTrue($r->admitted);$this->assertGreaterThan(0,$r->wait_ms);
        DB::rollBack();
        $this->assertSame(1,(int)$budget->connection()->table('rpc_provider_budgets')->where('key_hash',hash('sha256',$this->key))->value('php_requests'));
    }
    public function test_provider_retry_after_is_shared_and_never_shortened_by_a_success(): void
    {
        $budget=new TronRpcBudget();$budget->acquire($this->key);
        $budget->outcome($this->key,429,'120');$budget->outcome($this->key,200);
        $r=DB::selectOne('SELECT * FROM deepro_rpc_reserve(?, ?, ?)',[hash('sha256',$this->key),100,'node']);
        $this->assertFalse($r->admitted);$this->assertGreaterThan(118000,$r->wait_ms);
        $this->expectExceptionMessage('TRONGRID_SHARED_COOLDOWN');$budget->acquire($this->key);
    }
    public function test_rate_limit_body_stops_next_php_call_without_anonymous_retry(): void
    {
        Http::fake(fn()=>Http::response(['success'=>false,'statusCode'=>429,'error'=>'frequency limit'],200));
        try {app(TronGridClient::class)->request('v1/accounts/fixture/transactions',[]);$this->fail();}catch(\RuntimeException $e){$this->assertSame('TRONGRID_HTTP_429',$e->getMessage());}
        try {app(TronGridClient::class)->request('wallet/getnowblock',[]);$this->fail();}catch(\RuntimeException $e){$this->assertSame('TRONGRID_SHARED_COOLDOWN',$e->getMessage());}
        Http::assertSentCount(1);Http::assertSent(fn($r)=>$r->hasHeader('TRON-PRO-API-KEY',$this->key));
    }
    public function test_finalized_evidence_is_cached_but_all_recipient_logs_are_kept(): void
    {
        $hash=str_repeat('a',64);$one=TronGridClient::base58Address('41'.str_repeat('1',40));$two=TronGridClient::base58Address('41'.str_repeat('2',40));
        $logs=[];foreach(['1','2'] as $to)$logs[]=['address'=>str_repeat('b',40),'topics'=>[ChainAmount::TRANSFER,str_repeat('0',24).str_repeat('3',40),str_repeat('0',24).str_repeat($to,40)],'data'=>str_pad('f4240',64,'0',STR_PAD_LEFT)];
        Http::fake(function($r)use($hash,$logs){
            if(str_contains($r->url(),'getnowblock'))return Http::response(['block_header'=>['raw_data'=>['number'=>125]]]);
            if(str_contains($r->url(),'gettransactioninfobyid'))return Http::response(['id'=>$hash,'blockNumber'=>100,'blockTimeStamp'=>1000000,'receipt'=>['result'=>'SUCCESS'],'log'=>$logs]);
            return Http::response(['txID'=>$hash,'ret'=>[['contractRet'=>'SUCCESS']]]);
        });
        $client=app(TronGridClient::class);$a=$client->tokenTransfers($hash,$one);$b=$client->tokenTransfers($hash,$two);
        $this->assertCount(1,$a);$this->assertCount(1,$b);$this->assertSame('0',$a[0]['event_index']);$this->assertSame('1',$b[0]['event_index']);
        $this->assertSame($a[0]['event_index'],$client->tokenTransfers($hash,$one)[0]['event_index']);Http::assertSentCount(3);
    }
    public function test_empty_or_failed_receipt_is_not_cached(): void
    {
        $hash=str_repeat('c',64);Http::fakeSequence()->push([])->push(['id'=>$hash,'blockNumber'=>123,'blockTimeStamp'=>123456,'receipt'=>['result'=>'REVERT']])->push(['id'=>$hash,'blockNumber'=>123,'blockTimeStamp'=>123456,'receipt'=>['result'=>'SUCCESS']]);
        $client=app(TronGridClient::class);
        $this->assertSame([],$client->request('walletsolidity/gettransactioninfobyid',['value'=>$hash]));
        $this->assertSame('REVERT',$client->request('walletsolidity/gettransactioninfobyid',['value'=>$hash])['receipt']['result']);
        $this->assertSame('SUCCESS',$client->request('walletsolidity/gettransactioninfobyid',['value'=>$hash])['receipt']['result']);
        $client->request('walletsolidity/gettransactioninfobyid',['value'=>$hash]);Http::assertSentCount(3);
    }
}
