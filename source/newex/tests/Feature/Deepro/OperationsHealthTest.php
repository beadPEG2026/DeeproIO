<?php
namespace Tests\Feature\Deepro;

use App\Services\SystemMonitor\{OperationsHealth, QueueHealth};
use Illuminate\Support\Facades\{DB, Redis};
use Tests\TestCase;
use Mockery;

final class OperationsHealthTest extends TestCase
{
    private array $files=[];
    protected function setUp(): void { parent::setUp(); $this->assertSame('deepro_test',DB::connection()->getDatabaseName()); }
    protected function tearDown(): void {foreach($this->files as $file)unlink($file);parent::tearDown();}
    private function sample(array $report): string {
        $file=tempnam(sys_get_temp_dir(),'health-test-');$this->files[]=$file;
        file_put_contents($file,json_encode($report+['schema_version'=>2,'mode'=>'read_only']));return $file;
    }
    public function test_missing_stale_future_and_corrupt_reports_are_unknown(): void
    {
        $service=new OperationsHealth();
        $this->assertFalse($service->summary('/nonexistent-health-sample')['fresh']);
        foreach ([now()->subMinutes(4)->toISOString(),now()->addMinutes(4)->toISOString(),'invalid'] as $time) {
            $r=$service->summary($this->sample(['generated_at'=>$time,'status'=>'ok','checks'=>[]]));
            $this->assertFalse($r['fresh']);$this->assertSame('unknown',$r['status']);
        }
    }
    public function test_only_known_statuses_reach_the_admin_view(): void
    {
        $file=$this->sample(['generated_at'=>now()->toISOString(),'status'=>'ok','checks'=>[
            ['name'=>'postgres','status'=>'ok','details'=>['private'=>'do-not-serialize']],
            ['name'=>'unlisted-secret-check','status'=>'ok'],
            ['name'=>'pitr_evidence','status'=>'<script>'],
        ]]);
        $r=(new OperationsHealth())->summary($file);
        $this->assertTrue($r['fresh']);$this->assertSame('unknown',$r['status']);
        $this->assertSame(11,count($r['checks']));
        $checks=array_column($r['checks'],null,'name');
        $this->assertSame('ok',$checks['postgres']['status']);
        $this->assertSame('unknown',$checks['pitr_evidence']['status']);
        $this->assertStringNotContainsString('private',json_encode($r));
    }
    public function test_backfill_counts_are_bounded_and_critical_status_cannot_be_hidden(): void
    {
        $file=$this->sample(['generated_at'=>now()->toISOString(),'status'=>'ok','checks'=>[
            ['name'=>'containers','status'=>'critical'],
            ['name'=>'deposit_backfill','status'=>'warning','details'=>[
                'bsc'=>['scopes'=>17,'pending'=>17,'rpc_key'=>'never-expose'],
                'ethereum'=>['scopes'=>3,'pending'=>4],
                'polygon'=>['scopes'=>2,'pending'=>'<script>'],
                'unlisted'=>['scopes'=>1,'pending'=>1]]],
            ['name'=>'postgres','status'=>'ok'],['name'=>'postgres','status'=>'ok']]]);
        $r=(new OperationsHealth())->summary($file);
        $checks=array_column($r['checks'],null,'name');
        $this->assertSame('critical',$r['status']);
        $this->assertSame('unknown',$checks['postgres']['status']);
        $this->assertSame([['name'=>'bsc','scopes'=>17,'attention'=>17]],$checks['deposit_backfill']['chains']);
        $this->assertStringNotContainsString('never-expose',json_encode($r));
    }
    public function test_missing_schema_and_duplicate_checks_cannot_report_all_healthy(): void
    {
        $file=$this->sample(['schema_version'=>1,'generated_at'=>now()->toISOString(),'status'=>'ok','checks'=>[]]);
        $this->assertFalse((new OperationsHealth())->summary($file)['fresh']);
        $file=$this->sample(['generated_at'=>now()->toISOString(),'status'=>'ok','checks'=>array_fill(0,11,['name'=>'postgres','status'=>'ok'])]);
        $this->assertSame('unknown',(new OperationsHealth())->summary($file)['status']);
    }
    public function test_queue_inspection_is_read_only_and_never_returns_payload(): void
    {
        $redis=Mockery::mock();Redis::shouldReceive('connection')->once()->andReturn($redis);
        foreach (['default','market','orders','low'] as $name) {
            $key='queues:'.$name;
            $redis->shouldReceive('llen')->with($key)->once()->andReturn(1);
            $redis->shouldReceive('lindex')->with($key,0)->once()->andReturn(json_encode(['pushedAt'=>microtime(true)-360,'data'=>['token'=>'do-not-serialize']]));
            $redis->shouldReceive('zcard')->with($key.':delayed')->once()->andReturn(2);
            $redis->shouldReceive('zcard')->with($key.':reserved')->once()->andReturn(3);
            $redis->shouldReceive('zcount')->with($key.':reserved','-inf',Mockery::type('int'))->once()->andReturn(1);
        }
        $r=(new QueueHealth())->snapshot();
        $this->assertSame(4,count($r));$this->assertGreaterThanOrEqual(360,$r['orders']['oldest_wait_seconds']);
        $this->assertSame(1,$r['orders']['expired_reserved']);
        $this->assertStringNotContainsString('do-not-serialize',json_encode($r));
    }
}
