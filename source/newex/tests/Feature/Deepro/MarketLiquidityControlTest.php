<?php

namespace Tests\Feature\Deepro;

use App\Http\Controllers\Web\Admin\LiquidityController;
use App\Models\Market\Market;
use App\Services\Supervisor\Supervisor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Event, Http, Mail, Queue};
use JalalLinuX\Pm2\Structure\Process;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MarketLiquidityControlTest extends TestCase
{
    private bool $transactionStarted = false;
    private const BOOK_KEYS = ['bids', 'asks', 'bids_total', 'asks_total', 'executable', 'received_at'];

    protected function setUp(): void
    {
        parent::setUp();
        // Check both configuration and the connected server before any fixture writes.
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        $this->assertSame('deepro_test', DB::selectOne('SELECT current_database() AS name')->name);
        config(['cache.default' => 'array', 'performance.cache_store' => 'array',
            'session.driver' => 'array', 'broadcasting.default' => 'log', 'app.readonly' => false]);
        Event::fake();
        Mail::fake();
        Queue::fake();
        Http::fake(['*' => Http::response([], 503)]);
        Http::preventStrayRequests();
        DB::beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->transactionStarted) {
                while (DB::transactionLevel() > 0) DB::rollBack();
                Http::assertNothingSent();
                Mail::assertNothingSent();
                Queue::assertNothingPushed();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function market(string $name = 'UMI-USDT', bool $custom = false): Market
    {
        $market = Market::whereName($name)->firstOrFail();
        $market->update(['liq' => false, 'status' => true, 'trade_status' => true,
            'chart_symbol' => $name === 'UMI-USDT' ? 'CAKEUSDT' : 'BTCUSDT',
            'bs' => $name === 'UMI-USDT' ? '0.5' : '1',
            'last' => '1.23456789', 'custom_liquidity' => $custom]);
        return $market->fresh();
    }

    private function process(Market $market, string $status = 'online'): Process
    {
        return Process::fromJson(['name' => 'LIQ-'.market_sanitize($market->name),
            'pm2_env' => ['status' => $status]]);
    }

    private function controller(Supervisor $supervisor): LiquidityController
    {
        return new class($supervisor) extends LiquidityController {
            public function __construct(private Supervisor $testSupervisor) { parent::__construct(); }
            protected function marketSupervisor(): Supervisor { return $this->testSupervisor; }
        };
    }

    private function request(Market $market): Request
    {
        return Request::create('/exchange-control-panel/liquidity', 'POST', ['market' => $market->name]);
    }

    private function seedOldSnapshot(Market $market): void
    {
        foreach (self::BOOK_KEYS as $key) Cache::put("markets_liquidity.{$market->name}.$key", ['old']);
    }

    private function assertSnapshotCleared(Market $market): void
    {
        foreach (self::BOOK_KEYS as $key) $this->assertNull(Cache::get("markets_liquidity.{$market->name}.$key"), $key);
    }

    private function successfulSupervisor(Market $market, bool $existing = false): Supervisor
    {
        $supervisor = Mockery::mock(Supervisor::class);
        $name = 'LIQ-'.market_sanitize($market->name);
        $find = $supervisor->shouldReceive('findBy')->with('name', $name);
        if ($existing) {
            $find->times(3)->andReturn($this->process($market), null, $this->process($market));
            $supervisor->shouldReceive('delete')->with($name)->once()->andReturn(true);
        } else {
            $find->twice()->andReturn(null, $this->process($market));
        }
        $supervisor->shouldReceive('start')->once()->withArgs(function (string $command, array $options) use ($market, $name) {
            $this->assertFalse((bool) $market->fresh()->liq);
            $this->assertSnapshotCleared($market);
            $this->assertStringContainsString('market:run-liquidity '.escapeshellarg($market->name), $command);
            $this->assertSame($name, $options['name']);
            return true;
        })->andReturn(true);
        return $supervisor;
    }

    public static function enabledMarkets(): array
    {
        return ['restart UMI mapped to CAKE at BS 0.5' => ['UMI-USDT', false, true],
            'BTC' => ['BTC-USDT', false, false], 'custom liquidity' => ['UMI-USDT', true, false]];
    }

    #[DataProvider('enabledMarkets')]
    public function test_start_enables_confirmed_process_without_repricing_or_http(string $name, bool $custom, bool $existing): void
    {
        $market = $this->market($name, $custom);
        $before = $market->only(['last', 'bs', 'chart_symbol', 'custom_liquidity']);
        $this->seedOldSnapshot($market);
        $response = $this->controller($this->successfulSupervisor($market, $existing))->run($this->request($market));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['liq']);
        $this->assertTrue((bool) $market->fresh()->liq);
        $this->assertSame($before, $market->fresh()->only(array_keys($before)));
        $this->assertSnapshotCleared($market);
    }

    public static function failedStarts(): array
    {
        return ['PM2 returns false' => ['false'], 'PM2 reports offline' => ['offline'],
            'PM2 throws' => ['throw'], 'PM2 process missing' => ['missing']];
    }

    #[DataProvider('failedStarts')]
    public function test_start_failure_disables_old_flag_and_snapshot(string $failure): void
    {
        $market = $this->market();
        $market->update(['liq' => true]);
        $before = $market->fresh()->only(['last', 'bs', 'chart_symbol']);
        $this->seedOldSnapshot($market);
        $name = 'LIQ-'.market_sanitize($market->name);
        $supervisor = Mockery::mock(Supervisor::class);
        $find = $supervisor->shouldReceive('findBy')->with('name', $name);
        if (in_array($failure, ['offline', 'missing'], true)) {
            $find->times(3)->andReturn($this->process($market), null, $failure === 'offline' ? $this->process($market, 'stopped') : null);
        } else {
            $find->twice()->andReturn($this->process($market), null);
        }
        $supervisor->shouldReceive('delete')->with($name)->twice()->andReturn(true);
        $start = $supervisor->shouldReceive('start')->once();
        if ($failure === 'throw') $start->andThrow(new \RuntimeException('Synthetic PM2 start failure'));
        else $start->andReturn($failure !== 'false');

        $response = $this->controller($supervisor)->run($this->request($market));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['liq']);
        $this->assertNotEmpty($response->getData(true)['message']);
        $this->assertFalse((bool) $market->fresh()->liq);
        $this->assertSame($before, $market->fresh()->only(array_keys($before)));
        $this->assertSnapshotCleared($market);
    }

    public function test_failed_old_process_deletion_does_not_launch_another_worker(): void
    {
        $market = $this->market();
        $market->update(['liq' => true]);
        $this->seedOldSnapshot($market);
        $name = 'LIQ-'.market_sanitize($market->name);
        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->shouldReceive('findBy')->with('name', $name)->once()->andReturn($this->process($market));
        $supervisor->shouldReceive('delete')->with($name)->twice()->andReturn(false);
        $supervisor->shouldNotReceive('start');
        $response = $this->controller($supervisor)->run($this->request($market));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse((bool) $market->fresh()->liq);
        $this->assertSnapshotCleared($market);
    }

    public function test_stop_persists_disabled_state_and_clears_every_snapshot_key(): void
    {
        $market = $this->market();
        $market->update(['liq' => true]);
        $this->seedOldSnapshot($market);
        $name = 'LIQ-'.market_sanitize($market->name);
        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->shouldReceive('findBy')->with('name', $name)->twice()->andReturn($this->process($market), null);
        $supervisor->shouldReceive('delete')->with($name)->once()->andReturn(true);
        $supervisor->shouldNotReceive('start');

        $response = $this->controller($supervisor)->stop($this->request($market));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['liq']);
        $this->assertFalse((bool) $market->fresh()->liq);
        $this->assertSnapshotCleared($market);
    }

    public function test_delete_success_with_remaining_process_is_not_treated_as_stopped(): void
    {
        $market = $this->market();
        $market->update(['liq' => true]);
        $this->seedOldSnapshot($market);
        $name = 'LIQ-'.market_sanitize($market->name);
        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->shouldReceive('findBy')->with('name', $name)->twice()->andReturn($this->process($market));
        $supervisor->shouldReceive('delete')->with($name)->twice()->andReturn(true);
        $supervisor->shouldNotReceive('start');
        $response = $this->controller($supervisor)->stop($this->request($market));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['liq']);
        $this->assertFalse((bool) $market->fresh()->liq);
        $this->assertSnapshotCleared($market);
    }

    private function prepareReferenceBook(Market $market, int $receivedAt): void
    {
        DB::table('orders')->where('market_id', $market->id)->delete();
        DB::table('platform_credit_pools')->where('id', 1)->update(['enabled' => true,
            'default_for_usdt' => false, 'credit_limit' => '5000000', 'quote_position' => '0', 'realized_pnl' => '0']);
        DB::table('platform_credit_positions')->delete();
        DB::table('platform_reference_consumption')->where('market_id', $market->id)->delete();
        DB::table('market_execution_policies')->updateOrInsert(['market_id' => $market->id],
            ['mode' => 'platform_credit', 'maker_user_id' => null, 'max_quote_per_fill' => '1000',
                'created_at' => now(), 'updated_at' => now()]);
        $book = ['snapshot' => 'liquidity-control-test', 'received_at' => $receivedAt, 'bids' => [], 'asks' => []];
        for ($i = 0; $i < 20; $i++) {
            // Worker output is already BS-adjusted: a CAKE ask of 4 is an UMI ask of 2.
            $offset = bcdiv((string) $i, '100', 8);
            $book['asks'][] = ['price' => bcadd('2', $offset, 8), 'quantity' => '10'];
            $book['bids'][] = ['price' => bcsub('1.99', $offset, 8), 'quantity' => '10'];
        }
        Cache::put("markets_liquidity.{$market->name}.executable", $book);
        foreach (['received_at', 'bids', 'asks'] as $key) Cache::put("markets_liquidity.{$market->name}.$key", $book[$key]);
    }

    public function test_successful_start_exposes_fresh_reference_book_without_applying_bs_twice(): void
    {
        $market = $this->market();
        $this->prepareReferenceBook($market, time());
        $url = '/api/v1/markets/orderbook?market='.$market->name;
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'asks')->assertJsonCount(0, 'bids');

        $response = $this->controller($this->successfulSupervisor($market))->run($this->request($market));
        $this->assertSame(200, $response->getStatusCode());
        // A started worker has not yet supplied a fresh subscription snapshot.
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'asks')->assertJsonCount(0, 'bids');
        $this->prepareReferenceBook($market, time());
        $book = $this->getJson($url)->assertOk()->assertJsonPath('execution_mode', 'platform_credit')
            ->assertJsonCount(20, 'asks')->assertJsonCount(20, 'bids')->json();
        $this->assertEquals(2, $book['asks'][0]['price']);
        $this->assertEquals(1.99, $book['bids'][0]['price']);
        $this->assertEquals('1.23456789', $market->fresh()->last);
        $this->assertSame('0.5', $market->fresh()->bs);
    }

    public function test_enabled_market_does_not_expose_expired_reference_book(): void
    {
        $market = $this->market();
        $response = $this->controller($this->successfulSupervisor($market))->run($this->request($market));
        $this->assertSame(200, $response->getStatusCode());
        $this->prepareReferenceBook($market, time() - 60);
        $this->getJson('/api/v1/markets/orderbook?market='.$market->name)->assertOk()
            ->assertJsonCount(0, 'asks')->assertJsonCount(0, 'bids');
        $this->assertTrue((bool) $market->fresh()->liq);
    }

    public function test_admin_status_requires_both_online_process_and_database_enablement(): void
    {
        $market = $this->market();
        // Keep this market on the first dashboard page without altering other markets.
        request()->query->set('search', $market->name);
        foreach ([[false, 'online', false], [true, 'stopped', false], [true, 'online', true]] as [$enabled, $status, $expected]) {
            $market->update(['liq' => $enabled]);
            $supervisor = Mockery::mock(Supervisor::class);
            $supervisor->shouldReceive('list')->once()->andReturn([$this->process($market, $status)]);
            $request = Request::create('/exchange-control-panel/liquidity', 'GET');
            $request->headers->set('X-Inertia', 'true');
            $data = $this->controller($supervisor)->index()->toResponse($request)->getData(true);
            $row = collect($data['props']['markets']['data'])->firstWhere('name', $market->name);
            $this->assertNotNull($row);
            $this->assertSame($expected, $row['liq_enabled']);
        }
    }
}
