<?php
namespace Tests\Feature\Deepro;

use App\Http\Controllers\Web\Admin\LiquidityController;
use App\Services\Supervisor\Supervisor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Cache};
use Illuminate\Validation\ValidationException;
use JalalLinuX\Pm2\Structure\Process;
use Mockery;
use Tests\TestCase;

final class ServiceControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        $this->assertSame('deepro_test', DB::selectOne('SELECT current_database() AS name')->name);
        config(['cache.default' => 'array', 'app.readonly' => false, 'app.plan' => 5]);
    }

    private function controller(Supervisor $pm): LiquidityController
    {
        return new class($pm) extends LiquidityController {
            public function __construct(private Supervisor $pm) { parent::__construct(); }
            protected function marketSupervisor(): Supervisor { return $this->pm; }
        };
    }

    private function request(array $data): Request
    {
        return Request::create('/liquidity', 'POST', $data);
    }

    public function test_process_summary_does_not_serialize_environment_or_arguments(): void
    {
        $controller = new class extends LiquidityController {
            public function summarize($process): array { return $this->processSummary($process); }
        };
        $process = (object) ['name' => 'horizon', 'pid' => 123,
            'pm2Env' => (object) ['status' => 'online', 'pm2Home' => '/process-home',
                'env' => ['PRIVATE_VALUE' => 'must-not-reach-browser'], 'args' => 'private-arguments']];
        $summary = $controller->summarize($process);
        $this->assertSame(['name' => 'horizon', 'pid' => 123,
            'pm2Env' => ['status' => 'online', 'pm2Home' => '/process-home']], $summary);
        $this->assertStringNotContainsString('must-not-reach-browser', json_encode($summary));
    }

    public function test_unlisted_names_and_mismatched_commands_are_rejected_before_pm2(): void
    {
        $pm = Mockery::mock(Supervisor::class);
        $pm->shouldNotReceive('start'); $pm->shouldNotReceive('delete'); $pm->shouldNotReceive('findBy');
        $cases = [
            ['service' => 'horizon', 'command' => 'migrate:fresh'],
            ['service' => 'other-worker'], ['service' => 'all'],
            ['service' => 'horizon; echo unsafe'], ['service' => ['horizon']],
            ['service' => 'horizon', 'market' => 'UMI-USDT'],
            ['service' => 'horizon', 'command' => ['horizon']],
            ['service' => 'horizon', 'command' => 'horizon --force'],
        ];
        foreach (['run', 'stop'] as $method) foreach ($cases as $input) {
            try {
                $this->controller($pm)->$method($this->request($input));
                $this->fail('Unlisted operation accepted');
            } catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
    }

    public function test_plan_restricted_services_cannot_be_called_directly(): void
    {
        config(['app.plan' => 1]);
        $pm = Mockery::mock(Supervisor::class);
        $pm->shouldNotReceive('findBy');
        $this->expectException(ValidationException::class);
        $this->controller($pm)->run($this->request(['service' => 'futures-processor']));
    }

    public function test_allowed_start_uses_server_command_and_requires_online_confirmation(): void
    {
        $pm = Mockery::mock(Supervisor::class);
        $online = Process::fromJson(['name' => 'horizon', 'pm2_env' => ['status' => 'online']]);
        $pm->shouldReceive('findBy')->with('name', 'horizon')->twice()->andReturn(null, $online);
        $pm->shouldReceive('start')->once()->withArgs(function ($command, $options) {
            $this->assertStringEndsWith(' horizon', $command);
            $this->assertSame(['name' => 'horizon', 'interpreter' => 'none'], $options);
            return true;
        })->andReturn(true);
        $result = $this->controller($pm)->run($this->request(['service' => 'horizon']));
        $this->assertSame(200, $result->getStatusCode());
        $this->assertTrue($result->getData(true)['running']);
    }

    public function test_failed_start_is_not_success_and_does_not_expose_process_exception(): void
    {
        foreach (['false', 'exception', 'offline'] as $failure) {
            $pm = Mockery::mock(Supervisor::class);
            $find = $pm->shouldReceive('findBy')->with('name', 'horizon');
            if ($failure === 'offline') $find->twice()->andReturn(null, Process::fromJson(['name' => 'horizon', 'pm2_env' => ['status' => 'errored']]));
            else $find->once()->andReturn(null);
            $start = $pm->shouldReceive('start')->once();
            if ($failure === 'exception') $start->andThrow(new \RuntimeException('sensitive-process-output'));
            else $start->andReturn($failure !== 'false');
            $response = $this->controller($pm)->run($this->request(['service' => 'horizon']));
            $this->assertSame(503, $response->getStatusCode());
            $this->assertStringNotContainsString('sensitive-process-output', $response->getContent());
        }
    }

    public function test_failure_to_stop_does_not_launch_duplicate_process(): void
    {
        $pm = Mockery::mock(Supervisor::class);
        $online = Process::fromJson(['name' => 'horizon', 'pm2_env' => ['status' => 'online']]);
        $pm->shouldReceive('findBy')->with('name', 'horizon')->twice()->andReturn($online);
        $pm->shouldReceive('delete')->with('horizon')->once()->andReturn(true);
        $pm->shouldNotReceive('start');
        $response = $this->controller($pm)->run($this->request(['service' => 'horizon', 'command' => 'horizon']));
        $this->assertSame(503, $response->getStatusCode());
    }

    public function test_stopping_missing_service_is_idempotent(): void
    {
        $pm = Mockery::mock(Supervisor::class);
        $pm->shouldReceive('findBy')->with('name', 'horizon')->once()->andReturn(null);
        $pm->shouldNotReceive('delete'); $pm->shouldNotReceive('start');
        $response = $this->controller($pm)->stop($this->request(['service' => 'horizon']));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['running']);
    }
}
