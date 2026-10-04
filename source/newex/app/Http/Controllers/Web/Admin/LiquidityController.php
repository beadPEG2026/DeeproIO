<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Market\Market;
use App\Services\Market\MarketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Services\Supervisor\Supervisor;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LiquidityController extends Controller
{
    protected string $homePath;
    protected string $liqPrefix = 'LIQ-';

    public function __construct()
    {
        putenv('PATH=' . config('pm.usr_path', '/usr/local/bin:/usr/bin:/bin'));

        $this->homePath = config('pm.home_path');
    }

    public function index()
    {
        $marketService = new MarketService();
        $markets = collect($marketService->getMarkets(true, true))->toArray();

        $enabledMarkets = [];

        $flags = [
            'market-watcher' => false,
            'horizon' => false,
            'order-processor' => false,
            'futures-processor' => false,
            'futures-limit-processor' => false,
            'options-processor' => false,
            'futures-tpsl-process' => false,
            'peer-order-processor' => false,
        ];

        $daemons = $this->marketSupervisor()->list();

        foreach ($daemons as $process) {

            if ($process->pm2Env->status !== 'online') {
                continue;
            }

            if (Str::startsWith($process->name, $this->liqPrefix)) {
                $market = Str::after($process->name, $this->liqPrefix);
                $enabledMarkets[$market] = true;
            }

            if (isset($flags[$process->name])) {
                $flags[$process->name] = true;
            }
        }

        foreach ($markets['data'] as &$market) {
            $market['liq_enabled'] = (bool) $market['liq'] && isset(
                $enabledMarkets[market_sanitize($market['name'])]
            );
        }

        $services = [
            $this->service('Market Price Tracker Process', 'market-watcher', 'market-watcher:stats', $flags),
            $this->service('Queue System', 'horizon', 'horizon', $flags),
            $this->service('Spot Order Processor', 'order-processor', 'market:order-process', $flags),
        ];

        if (config('app.plan') == 5) {
            $services = array_merge($services, [
                $this->service('Futures Limit Order Tracker Process', 'futures-limit-processor', 'futures:limit-order-process', $flags),
                $this->service('Futures Liquidation Tracker Process', 'futures-processor', 'market-watcher:liquidation', $flags),
                $this->service('Take Profit & Stop Loss Tracker Process', 'futures-tpsl-process', 'futures:tpsl-process', $flags),
                $this->service('P2P Order Processor', 'peer-order-processor', 'peer-order:watcher', $flags),
                $this->service('Binary Option Trade Tracker Process', 'options-processor', 'options-watcher:process', $flags),
            ]);
        }

        $debug = !config('app.readonly');

        return Inertia::render('Admin/Liquidity/Index', [
            'markets' => $markets,
            'services' => $services,
            'daemons' => $debug ? collect($daemons)->map(fn ($process) => $this->processSummary($process))->values()->all() : [],
            'debug' => $debug,
        ]);
    }

    public function run(Request $request)
    {
        abort_if(config('app.readonly'), 403);

        if ($request->has('service')) {
            return $this->controlService($request, true);
        }

        $data = $request->validate(['market' => ['required', 'string', 'max:100', 'exists:markets,name']]);
        return $this->startMarketLiquidity($data['market']);
    }

    public function stop(Request $request)
    {
        abort_if(config('app.readonly'), 403);
        if ($request->has('service')) {
            return $this->controlService($request, false);
        }
        $data = $request->validate(['market' => ['required', 'string', 'max:100', 'exists:markets,name']]);
        return $this->stopMarketLiquidity($data['market']);
    }

    /* ======================== Helpers ======================== */

    protected function service(string $title, string $name, string $command, array $flags): array
    {
        return [
            'title' => $title,
            'name' => $name,
            'command' => $command,
            'status' => $flags[$name] ?? false,
        ];
    }

    protected function processSummary($process): array
    {
        return ['name' => $process->name, 'pid' => $process->pid,
            'pm2Env' => ['status' => $process->pm2Env->status, 'pm2Home' => $process->pm2Env->pm2Home]];
    }

    protected function serviceCommands(): array
    {
        $commands = [
            'market-watcher' => 'market-watcher:stats',
            'horizon' => 'horizon',
            'order-processor' => 'market:order-process',
        ];
        if (config('app.plan') == 5) {
            $commands += [
                'futures-limit-processor' => 'futures:limit-order-process',
                'futures-processor' => 'market-watcher:liquidation',
                'futures-tpsl-process' => 'futures:tpsl-process',
                'peer-order-processor' => 'peer-order:watcher',
                'options-processor' => 'options-watcher:process',
            ];
        }
        return $commands;
    }

    protected function controlService(Request $request, bool $start)
    {
        $data = $request->validate([
            'service' => ['required', 'string', 'max:80'],
            'command' => ['sometimes', 'nullable', 'string', 'max:100'],
            'market' => ['prohibited'],
        ]);
        $commands = $this->serviceCommands();
        $name = $data['service'];
        // The request selects an existing capability, never an arbitrary PM2 name
        // or Artisan command. Reject mismatched legacy clients before any side effect.
        if (!isset($commands[$name]) || (isset($data['command']) && $data['command'] !== $commands[$name])) {
            throw ValidationException::withMessages(['service' => __('Invalid service selection.')]);
        }
        try {
            return Cache::lock('service-control:'.$name, 30)->block(3, function () use ($name, $commands, $start) {
                $supervisor = $this->marketSupervisor();
                try {
                    if ($supervisor->findBy('name', $name)) {
                        if (!$supervisor->delete($name) || $supervisor->findBy('name', $name)) {
                            throw new \RuntimeException('Process did not stop.');
                        }
                    }
                    if ($start) {
                        $php = config('pm.php_path') ?: '/usr/bin/php';
                        $command = escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' '.$commands[$name];
                        if (!$supervisor->start($command, ['name' => $name, 'interpreter' => 'none'])) {
                            throw new \RuntimeException('Process did not start.');
                        }
                        $process = $supervisor->findBy('name', $name);
                        if (!$process || $process->pm2Env->status !== 'online') {
                            throw new \RuntimeException('Process is not online.');
                        }
                    }
                    return response()->json(['running' => $start, 'status' => $start
                        ? __('The process has been started') : __('The process has been stopped')]);
                } catch (\Throwable $e) {
                    Log::warning('Service control failed', ['service' => $name,
                        'operation' => $start ? 'start' : 'stop', 'exception' => get_class($e)]);
                    return response()->json(['message' => __('Unable to confirm the process state. Refresh the page before retrying.')], 503);
                }
            });
        } catch (LockTimeoutException $e) {
            return response()->json(['message' => __('This process is being updated. Please retry shortly.')], 409);
        }
    }

    protected function marketSupervisor(): Supervisor
    {
        return supervisor($this->homePath);
    }

    protected function clearMarketLiquidityCache(string $market): void
    {
        foreach (['bids', 'asks', 'bids_total', 'asks_total', 'executable', 'received_at'] as $key) {
            Cache::forget("markets_liquidity.$market.$key");
        }
    }

    protected function startMarketLiquidity(string $market)
    {
        return $this->controlMarketLiquidity($market, true);
    }

    protected function stopMarketLiquidity(string $market)
    {
        return $this->controlMarketLiquidity($market, false);
    }

    protected function controlMarketLiquidity(string $market, bool $start)
    {
        try {
            return Cache::lock('market-liquidity-control:'.$market, 30)->block(3, function () use ($market, $start) {
                $model = Market::whereName($market)->firstOrFail();
                $name = $this->liqPrefix.market_sanitize($market);
                $supervisor = $this->marketSupervisor();

                // A restart must not expose a snapshot from the old subscription.
                $model->update(['liq' => false]);
                $this->clearMarketLiquidityCache($market);

                try {
                    if ($supervisor->findBy('name', $name)) {
                        if (! $supervisor->delete($name) || $supervisor->findBy('name', $name)) {
                            throw new \RuntimeException('Could not stop the previous liquidity process.');
                        }
                    }

                    // The stopped worker may have written one final snapshot.
                    $this->clearMarketLiquidityCache($market);

                    if ($start) {
                        $php = config('pm.php_path') ?: '/usr/bin/php';
                        $command = escapeshellarg($php).' '.escapeshellarg(base_path('artisan'))
                            .' market:run-liquidity '.escapeshellarg($market);
                        if (! $supervisor->start($command, ['name' => $name, 'interpreter' => 'none'])) {
                            throw new \RuntimeException('Could not start the liquidity process.');
                        }
                        $process = $supervisor->findBy('name', $name);
                        if (! $process || $process->pm2Env->status !== 'online') {
                            throw new \RuntimeException('The liquidity process is not online.');
                        }

                        // The worker owns chart_symbol, BS and prices; a separate ticker
                        // lookup using the local market name must not gate this flag.
                        $model->update(['liq' => true]);
                    }

                    (new MarketService())->updateMarketsInfoCache();
                    return response()->json([
                        'liq' => (bool) $model->fresh()->liq,
                        'status' => $start
                            ? __('Market liquidity process started; quotes appear when fresh data is available.')
                            : __('Market liquidity was stopped.'),
                    ]);
                } catch (\Throwable $e) {
                    $model->update(['liq' => false]);
                    // Do not re-enable a deleted or failed process by restoring an old flag.
                    try { $supervisor->delete($name); } catch (\Throwable $ignored) { }
                    $this->clearMarketLiquidityCache($market);
                    (new MarketService())->updateMarketsInfoCache();
                    Log::warning('Market liquidity control failed', [
                        'market' => $market, 'operation' => $start ? 'start' : 'stop',
                        'exception' => get_class($e),
                    ]);
                    return response()->json([
                        'liq' => (bool) $model->fresh()->liq,
                        'message' => __('Unable to change the liquidity process. Reference quotes are disabled; check the process status and retry.'),
                    ], 503);
                }
            });
        } catch (LockTimeoutException $e) {
            return response()->json([
                'message' => __('This market is being updated. Please retry shortly.'),
            ], 409);
        }
    }
}
