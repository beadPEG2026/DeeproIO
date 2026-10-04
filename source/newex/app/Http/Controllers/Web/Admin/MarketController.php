<?php

namespace App\Http\Controllers\Web\Admin;

use App\Services\Market\MarketPriceMultiplier;
use App\Events\MarketStatsLiteUpdated;
use App\Events\MarketTradeLiteUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Market\MarketFormRequest;
use App\Models\Market\MarketAdmin;
use App\Models\Transaction\Transaction;
use App\Services\Market\MarketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class MarketController extends Controller
{
    protected const KLINE_CHANGE_DISPLAY_LIMIT = 15;
    protected const KLINE_CHANGE_CHAIN_SECONDS = 30;

    /**
     * @var MarketService
     */
    protected $marketService;

    /**
     * MarketController Constructor
     *
     * @param MarketService $marketService
     */
    public function __construct(MarketService $marketService)
    {
        $this->marketService = $marketService;
    }

    public function index()
    {
        return Inertia::render('Admin/Markets/Index', [
            'filters' => request()->only(['search', 'trashed']),
            'markets' => $this->marketService->getMarkets(true, true),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Markets/Form');
    }

    public function store(MarketFormRequest $request)
    {
        $this->marketService->storeMarket();

        $this->marketService->updateMarketsInfoCache();

        return Redirect::route('admin.markets')->with('success', 'Target Exchange Added');
    }

    public function edit(MarketAdmin $market)
    {
        $marketData = $this->marketService->getMarket($market->id, true, true);
        $marketData = $this->applyKlineRuntimeConfigToMarketData($marketData, (int) $market->id);

        return Inertia::render('Admin/Markets/Form', [
            'isEdit' => true,
            'market' => $marketData,
            'klineChanges' => $this->getKlineChangesForMarket($market),
        ]);
    }

    public function updateBs(Request $request, MarketAdmin $market)
    {
        // A BS-only edit must not validate or overwrite unrelated legacy market settings.
        $data = $request->validate(['bs' => ['present', 'nullable', 'numeric']]);
        $market->bs = $data['bs'];
        $market->save();

        return response()->json(['bs' => $market->fresh()->getRawOriginal('bs')]);
    }

    public function update(MarketFormRequest $request, MarketAdmin $market)
    {
        $data = $request->validated();
        if (config('admin-controls.simulation_controls')) $this->sleepWhenSubmitHitsMinuteBoundary();

        $table = $market->getTable();
        $primaryKey = $market->getKeyName();
        $marketId = $market->getKey();

        $dbMarket = DB::table($table)
            ->where($primaryKey, $marketId)
            ->first();

        if (!$dbMarket) {
            return Redirect::route('admin.markets')
                ->with('error', 'Market not found.');
        }

        /*
         * 编辑市场时，不允许后台表单更新 last。
         * last 应该由行情、K线、机器人逻辑更新。
         */
        unset($data['last']);

        /*
         * 兼容两种提交结构：
         * 1. bot_price_floor
         * 2. market.bot_price_floor
         */
        if ($request->has('bot_price_floor')) {
            $botPriceFloor = (float) $request->input('bot_price_floor');
        } elseif ($request->has('market.bot_price_floor')) {
            $botPriceFloor = (float) $request->input('market.bot_price_floor');
        } else {
            $botPriceFloor = (float) ($dbMarket->bot_price_floor ?? 0);
        }

        /*
         * 限制百分比范围：
         * 最大 100
         * 最小 -100
         */
        if ($botPriceFloor > 100) {
            $botPriceFloor = 100;
        }

        if ($botPriceFloor < -100) {
            $botPriceFloor = -100;
        }

        /*
         * 防止浮点数出现 0.0000000001 这种幽灵小数。
         */
        if (abs($botPriceFloor) < 0.00000001) {
            $botPriceFloor = 0;
        }

        /*
         * 这些字段不要跟随普通字段一起 save，
         * 防止被表单里的旧值覆盖。
         */
        unset($data['bot_price_floor']);
        unset($data['bot_price_ceiling']);
        unset($data['custom_liquidity_t']);

        if (isset($data['market']) && is_array($data['market'])) {
            unset($data['market']['last']);
            unset($data['market']['bot_price_floor']);
            unset($data['market']['bot_price_ceiling']);
            unset($data['market']['custom_liquidity_t']);
        }

        /*
         * 先保存普通字段。
         * 注意：这里不会保存 last。
         */
        $market->forceFill($data);
        $market->save();
        $market->refresh();

        if (config('admin-controls.simulation_controls')) $this->applyKlinePriceAdjustment($market, $botPriceFloor, $dbMarket);
        $this->marketService->updateMarketsInfoCache();

        return Redirect::back()
            ->with('success', 'Target Exchange updated.');
    }

    public function updateKlinePriceAdjustment(Request $request, MarketAdmin $market)
    {
        abort_unless(config('admin-controls.simulation_controls'), 403, 'Simulation controls are disabled in production.');
        $validated = $request->validate([
            'bot_price_floor' => ['required', 'numeric'],
        ]);

        $table = $market->getTable();
        $primaryKey = $market->getKeyName();
        $marketId = $market->getKey();

        $dbMarket = DB::table($table)
            ->where($primaryKey, $marketId)
            ->first();

        if (!$dbMarket) {
            return response()->json([
                'success' => false,
                'message' => 'Market not found.',
            ], 404);
        }

        $result = $this->applyKlinePriceAdjustment($market, (float) $validated['bot_price_floor'], $dbMarket);

        return response()->json([
            'success' => true,
            'message' => 'Kline price adjustment updated.',
            'market' => [
                'id' => (int) $marketId,
                'name' => $market->name,
                'bot_price_floor' => $result['bot_price_floor'],
                'bot_price_ceiling' => $result['bot_price_ceiling'],
                'custom_liquidity_t' => $result['custom_liquidity_t'],
                'last' => $result['adjusted_price'],
            ],
            'klineChanges' => $this->getKlineChangesForMarket($market),
        ]);
    }

    protected function applyKlinePriceAdjustment(MarketAdmin $market, float $botPriceFloor, $dbMarket = null): array
    {
        $marketId = (int) $market->getKey();

        if (!$dbMarket) {
            $dbMarket = \Illuminate\Support\Facades\DB::table($market->getTable())
                ->where($market->getKeyName(), $marketId)
                ->first();
        }

        /*
         * 限制百分比范围：
         * 最大 100
         * 最小 -100
         */
        if ($botPriceFloor > 100) {
            $botPriceFloor = 100;
        }

        if ($botPriceFloor < -100) {
            $botPriceFloor = -100;
        }

        /*
         * 防止浮点数出现 0.0000000001 这种幽灵小数。
         */
        if (abs($botPriceFloor) < 0.00000001) {
            $botPriceFloor = 0;
        }

        $runtimeConfig = $this->getKlineRuntimeConfig($marketId);

        /*
         * 当前运行中的 bot_price_ceiling 优先读取缓存。
         * 后台调节先写缓存，数据库由行情任务统一同步。
         */
        $oldBotPriceCeiling = array_key_exists('bot_price_ceiling', $runtimeConfig)
            ? (float) $runtimeConfig['bot_price_ceiling']
            : (float) ($dbMarket->bot_price_ceiling ?? 0);

        /*
         * 如果本次输入 0，只关闭本轮调价运行状态。
         * bot_price_ceiling 是累计调价百分比，不能归 0。
         */
        if ($botPriceFloor == 0) {
            $newBotPriceCeiling = $oldBotPriceCeiling;
            $customLiquidityT = false;
        } else {
            $newBotPriceCeiling = $oldBotPriceCeiling + $botPriceFloor;

            /*
             * 累加后的总百分比也限制在 -100 到 100。
             */
            if ($newBotPriceCeiling > 100) {
                $newBotPriceCeiling = 100;
            }

            if ($newBotPriceCeiling < -100) {
                $newBotPriceCeiling = -100;
            }

            $customLiquidityT = true;
        }

        /*
         * 每次非 0 修改都写入一条独立记录到 change_logs.json。
         * 输入 0 是关闭自定义行情：关闭前先把最后一次调节结果固化到缓存和数据库。
         */
        $latestKlineAdjustedPrice = null;

        if ($botPriceFloor != 0) {
            $latestKlineAdjustedPrice = $this->appendKlineChangeLog(
                $market,
                $oldBotPriceCeiling,
                $newBotPriceCeiling,
                $botPriceFloor
            );
        }

        if ($botPriceFloor == 0) {
            $closedAdjustedPrice = $this->closeKlinePriceAdjustment($market, $oldBotPriceCeiling);

            return [
                'bot_price_floor' => $botPriceFloor,
                'bot_price_ceiling' => $newBotPriceCeiling,
                'custom_liquidity_t' => $customLiquidityT,
                'adjusted_price' => $closedAdjustedPrice ?: market_get_stats($marketId, 'last'),
            ];
        }

        $this->putKlineRuntimeConfig($marketId, [
            'bot_price_floor' => $botPriceFloor,
            'bot_price_ceiling' => $newBotPriceCeiling,
            'custom_liquidity_t' => $customLiquidityT,
        ]);

        $market->forceFill([
            'bot_price_floor' => $botPriceFloor,
            'bot_price_ceiling' => $newBotPriceCeiling,
            'custom_liquidity_t' => $customLiquidityT,
        ]);

        /*
         * 调节 K 线后立刻刷新运行缓存和当前价格。
         * 这样前台价格、K 线下次请求都会使用新配置，不再等旧缓存过期。
         */
        $adjustedAt = $this->markKlineAdjustedAt($market);
        $adjustedPrice = $this->updateRuntimePriceAfterKlineAdjustment(
            $market,
            $oldBotPriceCeiling,
            $newBotPriceCeiling,
            $latestKlineAdjustedPrice,
            $adjustedAt
        );
        $finalAdjustedPrice = $adjustedPrice ?: $latestKlineAdjustedPrice;

        return [
            'bot_price_floor' => $botPriceFloor,
            'bot_price_ceiling' => $newBotPriceCeiling,
            'custom_liquidity_t' => $customLiquidityT,
            'adjusted_price' => $finalAdjustedPrice,
        ];
    }

    protected function closeKlinePriceAdjustment(MarketAdmin $market, float $oldPercent): ?float
    {
        $marketId = (int) $market->id;
        $precision = (int) ($market->quote_precision ?? 8);

        if ($marketId <= 0) {
            return null;
        }

        $closedAdjustedPrice = $this->resolveLatestKlineAdjustedPrice($market, $oldPercent);
        $runtimeData = [
            'bot_price_floor' => 0,
            'custom_liquidity_t' => false,
        ];
        $marketData = $runtimeData;

        if ($closedAdjustedPrice !== null && $closedAdjustedPrice > 0) {
            $closedAdjustedPrice = round($closedAdjustedPrice, $precision);
            $adjustedAt = $this->markKlineAdjustedAt($market);

            $this->writeCurrentOneMinuteKlineOverride($market, $closedAdjustedPrice, $closedAdjustedPrice, 0);
            $this->persistKlineAdjustedRuntimePrice($market, $closedAdjustedPrice, $adjustedAt);

            $runtimeData['last'] = $closedAdjustedPrice;
            $runtimeData['bot_current_price'] = (string) $closedAdjustedPrice;
            $runtimeData['bot_momentum'] = 0;

            $marketData['last'] = math_formatter($closedAdjustedPrice, $precision);
            $marketData['bot_current_price'] = (string) $closedAdjustedPrice;
            $marketData['bot_momentum'] = 0;

            $this->broadcastKlineAdjustedMarketStats($market, $closedAdjustedPrice, $adjustedAt);
            $this->broadcastKlineAdjustmentTradeTick($market, $closedAdjustedPrice);
            $this->broadcastOrderbookSnapshotAfterKlineAdjustment($market);
        }

        $this->putKlineRuntimeConfig($marketId, $runtimeData);
        $market->forceFill($marketData);

        $this->persistClosedKlineAdjustmentToDatabase($market, $closedAdjustedPrice);
        $this->persistKlineChangeLogsToDatabase($market);
        $this->stopKlinePriceAdjustment($market, $closedAdjustedPrice);

        return $closedAdjustedPrice;
    }

    public function getKlineChanges(MarketAdmin $market)
    {
        return response()->json([
            'changes' => $this->getKlineChangesForMarket($market),
        ]);
    }

    public function destroyKlineChange(MarketAdmin $market, string $changeId)
    {
        abort_unless(config('admin-controls.simulation_controls'), 403, 'Simulation controls are disabled in production.');
        $deleted = $this->deleteKlineChangesByIds($market, [$changeId]);

        if ($deleted <= 0) {
            return $this->jsonOrBack(false, 'Kline change record not found.');
        }

        /*
         * 删除成功后记录当前服务器时间。
         * 前台交易页会检测这个时间，发现比进入页面时间新，就刷新 K 线 iframe。
         */
        $this->markKlineDeletedAt($market);

        return $this->jsonOrBack(true, 'Kline change deleted.');
    }

    public function clearKlineChanges(Request $request, MarketAdmin $market)
    {
        abort_unless(config('admin-controls.simulation_controls'), 403, 'Simulation controls are disabled in production.');
        $ids = $request->input('ids', []);

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_values(array_filter(array_map('strval', $ids)));

        if (!empty($ids)) {
            $deleted = $this->deleteKlineChangesByIds($market, $ids);

            if ($deleted <= 0) {
                return $this->jsonOrBack(false, 'Kline change record not found.');
            }

            $this->markKlineDeletedAt($market);
            $this->clearKlineRuntimeStateAfterDelete($market);

            return $this->jsonOrBack(true, 'Kline changes deleted.');
        }

        /*
         * clearKlineChanges 固定作为“删除全部 K 线修改数据”的方法。
         * 如果请求没有传 ids，就清空当前市场全部记录。
         *
         * 这里会直接删除：
         * storage/app/market_kline_overrides/{market_id}/
         *
         * 包括：
         * 1.json、5.json、15.json、change_logs.json、percent_state.json、*.tmp 等全部文件。
         */
        $this->clearAllKlineChangeFiles($market);

        /*
         * 清空成功后记录当前服务器时间。
         * 前台交易页会检测这个时间，发现比进入页面时间新，就刷新 K 线 iframe。
         */
        $this->markKlineDeletedAt($market);
        $this->clearKlineRuntimeStateAfterDelete($market);

        return $this->jsonOrBack(true, 'Kline changes cleared.');
    }

    protected function markKlineDeletedAt(MarketAdmin $market): string
    {
        $deletedAt = now()->toIso8601String();

        Cache::put(
            'market_kline_deleted_at_' . (int) $market->id,
            $deletedAt,
            now()->addDays(30)
        );

        return $deletedAt;
    }

    protected function markKlineAdjustedAt(MarketAdmin $market): string
    {
        $adjustedAt = now()->toIso8601String();
        $marketId = (int) $market->id;

        Cache::put(
            'market_kline_adjusted_at_' . $marketId,
            $adjustedAt,
            now()->addDays(30)
        );

        Cache::put(
            'market_stats_config_changed_at',
            $adjustedAt,
            now()->addMinutes(5)
        );

        Cache::put(
            'market_stats_config_changed_at_' . $marketId,
            $adjustedAt,
            now()->addMinutes(5)
        );

        return $adjustedAt;
    }

    protected function updateRuntimePriceAfterKlineAdjustment(MarketAdmin $market, float $oldPercent, float $newPercent, ?float $preferredAdjustedPrice = null, ?string $adjustedAt = null): ?float
    {
        $marketId = (int) $market->id;
        $precision = (int) ($market->quote_precision ?? 8);

        if ($marketId <= 0) {
            return null;
        }

        if ($preferredAdjustedPrice !== null && $preferredAdjustedPrice > 0) {
            $adjustedPrice = round($preferredAdjustedPrice, $precision);
        } else {
            $rawBasePrice = $this->getCurrentRawBasePriceForKlineLog($market, $oldPercent);

            if ($rawBasePrice <= 0) {
                return null;
            }

            $multiplier = 1 + ($newPercent / 100);

            if ($multiplier <= 0) {
                return null;
            }

            $bsMultiplier = $this->getMarketBsMultiplier($market);
            $adjustedPrice = round($rawBasePrice * $multiplier * $bsMultiplier, $precision);
        }

        if ($adjustedPrice <= 0) {
            return null;
        }

        $adjustedAt = $adjustedAt ?: now()->toIso8601String();

        $this->persistKlineAdjustedRuntimePrice($market, $adjustedPrice, $adjustedAt);
        $this->broadcastKlineAdjustedMarketStats($market, $adjustedPrice, $adjustedAt);
        $this->broadcastKlineAdjustmentTradeTick($market, $adjustedPrice);
        $this->broadcastOrderbookSnapshotAfterKlineAdjustment($market);

        return $adjustedPrice;
    }

    protected function resolveLatestKlineAdjustedPrice(MarketAdmin $market, float $oldPercent): ?float
    {
        $marketId = (int) $market->id;
        $precision = (int) ($market->quote_precision ?? 8);

        if ($marketId <= 0) {
            return null;
        }

        $adjustedPrice = Cache::get('market_kline_adjusted_price_' . $marketId, []);

        if (is_array($adjustedPrice) && isset($adjustedPrice['price']) && (float) $adjustedPrice['price'] > 0) {
            return round((float) $adjustedPrice['price'], $precision);
        }

        $runtimeConfig = $this->getKlineRuntimeConfig($marketId);

        if (isset($runtimeConfig['last']) && is_numeric($runtimeConfig['last']) && (float) $runtimeConfig['last'] > 0) {
            return round((float) $runtimeConfig['last'], $precision);
        }

        $latestLog = $this->getLatestKlineChangeLogForMarket($market);
        $logAfterPrice = $latestLog ? (float) ($latestLog['after_price'] ?? 0) : 0;

        if ($logAfterPrice > 0) {
            return round($logAfterPrice, $precision);
        }

        $rawBasePrice = $this->getCurrentRawBasePriceForKlineLog($market, $oldPercent);
        $multiplier = 1 + ($oldPercent / 100);
        $bsMultiplier = $this->getMarketBsMultiplier($market);

        if ($rawBasePrice > 0 && $multiplier > 0 && $bsMultiplier > 0) {
            return round($rawBasePrice * $multiplier * $bsMultiplier, $precision);
        }

        $statsLast = (float) (market_get_stats($marketId, 'last') ?? 0);

        return $statsLast > 0 ? round($statsLast, $precision) : null;
    }

    protected function getLatestKlineChangeLogForMarket(MarketAdmin $market): ?array
    {
        $logs = $this->readJsonFile($this->getKlineChangeLogFilePath($market));

        if (!is_array($logs) || empty($logs)) {
            return null;
        }

        $logs = array_values(array_filter($logs, function ($log) use ($market) {
            return (int) ($log['market_id'] ?? 0) === (int) $market->id;
        }));

        if (empty($logs)) {
            return null;
        }

        usort($logs, [$this, 'compareKlineChangeLogsAscending']);

        return $logs[count($logs) - 1];
    }

    protected function persistClosedKlineAdjustmentToDatabase(MarketAdmin $market, ?float $closedAdjustedPrice = null): void
    {
        $marketId = (int) $market->id;
        $precision = (int) ($market->quote_precision ?? 8);

        if ($marketId <= 0) {
            return;
        }

        $update = [
            'bot_price_floor' => 0,
            'custom_liquidity_t' => false,
            'updated_at' => now(),
        ];

        if ($closedAdjustedPrice !== null && $closedAdjustedPrice > 0) {
            $closedAdjustedPrice = round($closedAdjustedPrice, $precision);
            $update['last'] = math_formatter($closedAdjustedPrice, $precision);
            $update['bot_current_price'] = (string) $closedAdjustedPrice;
            $update['bot_momentum'] = 0;
        }

        try {
            DB::table($market->getTable())
                ->where($market->getKeyName(), $marketId)
                ->update($update);
        } catch (\Throwable $e) {
            //
        }
    }

    protected function persistKlineChangeLogsToDatabase(MarketAdmin $market): int
    {
        if (!$this->ensureKlineChangeLogTableExists()) {
            return 0;
        }

        $logs = $this->readJsonFile($this->getKlineChangeLogFilePath($market));

        if (!is_array($logs) || empty($logs)) {
            return 0;
        }

        $logs = array_values(array_filter($logs, function ($log) use ($market) {
            return (int) ($log['market_id'] ?? 0) === (int) $market->id;
        }));

        if (empty($logs)) {
            return 0;
        }

        usort($logs, [$this, 'compareKlineChangeLogsAscending']);

        $persisted = 0;

        foreach ($logs as $log) {
            $changeId = (string) ($log['id'] ?? '');

            if ($changeId === '') {
                $changeId = 'manual_' . (int) ($log['start_timestamp'] ?? time()) . '_' . substr(md5(json_encode($log)), 0, 8);
            }

            $payload = json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            try {
                DB::table('market_kline_changes')->updateOrInsert(
                    ['change_id' => $changeId],
                    [
                        'market_id' => (int) $market->id,
                        'market_name' => (string) ($log['market_name'] ?? $market->name),
                        'start_timestamp' => (int) ($log['start_timestamp'] ?? 0),
                        'start_time' => $log['start_time'] ?? null,
                        'direction' => $log['direction'] ?? null,
                        'direction_text' => $log['direction_text'] ?? null,
                        'old_percent' => (string) ($log['old_percent'] ?? 0),
                        'new_percent' => (string) ($log['new_percent'] ?? 0),
                        'input_percent' => (string) ($log['input_percent'] ?? $log['change_percent'] ?? 0),
                        'change_percent' => (string) ($log['change_percent'] ?? $log['input_percent'] ?? 0),
                        'bs_multiplier' => (string) ($log['bs_multiplier'] ?? 1),
                        'raw_base_price' => (string) ($log['raw_base_price'] ?? 0),
                        'base_price' => (string) ($log['base_price'] ?? 0),
                        'before_price' => (string) ($log['before_price'] ?? 0),
                        'after_price' => (string) ($log['after_price'] ?? 0),
                        'price_change' => (string) ($log['price_change'] ?? 0),
                        'source' => (string) ($log['source'] ?? 'manual'),
                        'payload' => $payload ?: null,
                        'created_at' => $log['created_at'] ?? now(),
                        'updated_at' => now(),
                    ]
                );

                $persisted++;
            } catch (\Throwable $e) {
                //
            }
        }

        return $persisted;
    }

    protected function ensureKlineChangeLogTableExists(): bool
    {
        try {
            if (Schema::hasTable('market_kline_changes')) {
                return true;
            }

            Schema::create('market_kline_changes', function ($table) {
                $table->bigIncrements('id');
                $table->string('change_id', 80)->unique();
                $table->unsignedBigInteger('market_id')->index();
                $table->string('market_name')->nullable();
                $table->unsignedInteger('start_timestamp')->default(0)->index();
                $table->string('start_time')->nullable();
                $table->string('direction', 16)->nullable();
                $table->string('direction_text', 16)->nullable();
                $table->decimal('old_percent', 24, 10)->default(0);
                $table->decimal('new_percent', 24, 10)->default(0);
                $table->decimal('input_percent', 24, 10)->default(0);
                $table->decimal('change_percent', 24, 10)->default(0);
                $table->decimal('bs_multiplier', 24, 10)->default(1);
                $table->decimal('raw_base_price', 36, 18)->default(0);
                $table->decimal('base_price', 36, 18)->default(0);
                $table->decimal('before_price', 36, 18)->default(0);
                $table->decimal('after_price', 36, 18)->default(0);
                $table->decimal('price_change', 36, 18)->default(0);
                $table->string('source', 32)->default('manual');
                $table->longText('payload')->nullable();
                $table->timestamps();
            });

            return Schema::hasTable('market_kline_changes');
        } catch (\Throwable $e) {
            return Schema::hasTable('market_kline_changes');
        }
    }

    protected function stopKlinePriceAdjustment(MarketAdmin $market, ?float $preservedPrice = null): void
    {
        $marketId = (int) $market->id;
        $precision = (int) ($market->quote_precision ?? 8);

        if ($marketId <= 0) {
            return;
        }

        $hasPreservedPrice = $preservedPrice !== null && $preservedPrice > 0;

        if (!$hasPreservedPrice) {
            Cache::forget('market_kline_adjusted_at_' . $marketId);
            Cache::forget('market_kline_adjusted_price_' . $marketId);
            Cache::forget('market_kline_adjustment_anchor_' . $marketId);

            foreach ($this->getOrderbookCacheMarketKeys($market) as $orderbookKey) {
                Cache::forget('market_orderbook_rebase_' . $orderbookKey);
            }
        }

        if ($hasPreservedPrice) {
            $preservedPrice = round($preservedPrice, $precision);

            $this->putKlineRuntimeConfig($marketId, [
                'last' => $preservedPrice,
                'bot_current_price' => (string) $preservedPrice,
                'bot_momentum' => 0,
            ]);
        } else {
            $this->forgetKlineRuntimeConfigFields($marketId, [
                'last',
                'bot_current_price',
                'bot_momentum',
            ]);
        }

        $changedAt = now()->toIso8601String();

        Cache::put('market_stats_config_changed_at', $changedAt, now()->addMinutes(5));
        Cache::put('market_stats_config_changed_at_' . $marketId, $changedAt, now()->addMinutes(5));
    }

    protected function persistKlineAdjustedRuntimePrice(MarketAdmin $market, float $adjustedPrice, string $adjustedAt): void
    {
        $marketId = (int) $market->id;
        $precision = (int) ($market->quote_precision ?? 8);
        $adjustedPrice = round($adjustedPrice, $precision);

        if ($marketId <= 0 || $adjustedPrice <= 0) {
            return;
        }

        foreach (['last', 'high', 'low'] as $statKey) {
            try {
                $this->marketService->updateStatsForce($marketId, $statKey, $adjustedPrice);
            } catch (\Throwable $e) {
                //
            }
        }

        $this->putKlineRuntimeConfig($marketId, [
            'last' => $adjustedPrice,
            'bot_current_price' => (string) $adjustedPrice,
            'bot_momentum' => 0,
        ]);

        $market->forceFill([
            'bot_current_price' => (string) $adjustedPrice,
            'bot_momentum' => 0,
            'last' => math_formatter($adjustedPrice, $precision),
        ]);

        Cache::put('market_kline_adjusted_price_' . $marketId, [
            'price' => $adjustedPrice,
            'quote_precision' => $precision,
            'adjusted_at' => $adjustedAt,
        ], now()->addDays(30));

        $orderbookRebaseConfig = [
            'last' => $adjustedPrice,
            'quote_precision' => $precision,
            'adjusted_at' => $adjustedAt,
        ];

        foreach ($this->getOrderbookCacheMarketKeys($market) as $orderbookKey) {
            Cache::put('market_orderbook_rebase_' . $orderbookKey, $orderbookRebaseConfig, now()->addDays(30));
        }
    }

    protected function broadcastKlineAdjustedMarketStats(MarketAdmin $market, float $adjustedPrice, string $adjustedAt): void
    {
        $marketId = (int) $market->id;
        $precision = (int) ($market->quote_precision ?? 8);

        if ($marketId <= 0 || $adjustedPrice <= 0) {
            return;
        }

        try {
            event(new MarketStatsLiteUpdated([
                'name' => $market->name,
                'last' => math_formatter($adjustedPrice, $precision),
                'high' => math_formatter($adjustedPrice, $precision),
                'low' => math_formatter($adjustedPrice, $precision),
                'volume' => math_formatter(market_get_stats($marketId, 'volume'), (int) ($market->base_precision ?? 8)),
                'qVolume' => math_formatter(market_get_stats($marketId, 'qVolume'), $precision),
                'change' => is_numeric(market_get_stats($marketId, 'change')) ? math_formatter(market_get_stats($marketId, 'change'), 2) : null,
                'updated_at' => $adjustedAt,
            ]));
        } catch (\Throwable $e) {
            //
        }
    }

    protected function getOrderbookCacheMarketKeys(MarketAdmin $market): array
    {
        $name = trim((string) $market->name);
        $keys = [$name];

        if ($name !== '') {
            $keys[] = market_sanitize($name);
            $keys[] = strtoupper(str_replace(['-', '_', '/', ' '], '', $name));
        }

        if (!empty($market->chart_symbol)) {
            $keys[] = strtoupper(str_replace(['-', '_', '/', ' '], '', (string) $market->chart_symbol));
        }

        return array_values(array_unique(array_filter($keys, fn ($key) => trim((string) $key) !== '')));
    }

    protected function broadcastOrderbookSnapshotAfterKlineAdjustment(MarketAdmin $market): void
    {
        foreach ($this->getOrderbookCacheMarketKeys($market) as $cacheKey) {
            $bids = Cache::get("markets_liquidity.$cacheKey.bids");
            $asks = Cache::get("markets_liquidity.$cacheKey.asks");

            if (empty($bids) || empty($asks)) {
                continue;
            }

            try {
                event(new \App\Events\OrderBookSnapshot($market->name, $bids, $asks));
            } catch (\Throwable $e) {
                //
            }

            return;
        }
    }

    protected function broadcastKlineAdjustmentTradeTick(MarketAdmin $market, float $price): void
    {
        $precision = (int) ($market->quote_precision ?? 8);
        $basePrecision = (int) ($market->base_precision ?? 8);
        $quantity = $this->randomKlineAdjustmentTradeQuantity();

        $transaction = new Transaction();
        $transaction->market_id = (int) $market->id;
        $transaction->order_side = 'buy';
        $transaction->price = math_formatter($price, $precision);
        $transaction->base_currency = math_formatter($quantity, $basePrecision);
        $transaction->quote_currency = math_formatter($price * $quantity, $precision);
        $transaction->created_at = now();
        $transaction->kline_adjustment_tick = true;
        $transaction->setRelation('market', $market);

        try {
            event(new MarketTradeLiteUpdated($transaction, false));
        } catch (\Throwable $e) {
            //
        }
    }

    protected function randomKlineAdjustmentTradeQuantity(): float
    {
        return random_int(1000, 300000) / 100;
    }

    protected function getKlineRuntimeConfigCacheKey(int $marketId): string
    {
        return 'market_kline_runtime_config_' . $marketId;
    }

    protected function getKlineRuntimeConfigIdsCacheKey(): string
    {
        return 'market_kline_runtime_config_ids';
    }

    protected function getKlineRuntimeConfig(int $marketId): array
    {
        if ($marketId <= 0) {
            return [];
        }

        $config = Cache::get($this->getKlineRuntimeConfigCacheKey($marketId), []);

        return is_array($config) ? $config : [];
    }

    protected function putKlineRuntimeConfig(int $marketId, array $data): void
    {
        if ($marketId <= 0) {
            return;
        }

        $config = $this->getKlineRuntimeConfig($marketId);

        foreach ($data as $key => $value) {
            $config[$key] = $value;
        }

        $config['market_id'] = $marketId;
        $config['persist_pending'] = true;
        $config['persist_after'] = time() + 30;
        $config['updated_at'] = now()->toDateTimeString();

        Cache::forever($this->getKlineRuntimeConfigCacheKey($marketId), $config);

        $ids = Cache::get($this->getKlineRuntimeConfigIdsCacheKey(), []);
        $ids = is_array($ids) ? $ids : [];
        $ids[] = $marketId;
        $ids = array_values(array_unique(array_map('intval', $ids)));

        Cache::forever($this->getKlineRuntimeConfigIdsCacheKey(), $ids);
    }

    protected function forgetKlineRuntimeConfigFields(int $marketId, array $fields): void
    {
        if ($marketId <= 0) {
            return;
        }

        $config = $this->getKlineRuntimeConfig($marketId);

        foreach ($fields as $field) {
            unset($config[$field]);
        }

        $config['market_id'] = $marketId;
        $config['persist_pending'] = true;
        $config['persist_after'] = time() + 30;
        $config['updated_at'] = now()->toDateTimeString();

        Cache::forever($this->getKlineRuntimeConfigCacheKey($marketId), $config);

        $ids = Cache::get($this->getKlineRuntimeConfigIdsCacheKey(), []);
        $ids = is_array($ids) ? $ids : [];
        $ids[] = $marketId;
        $ids = array_values(array_unique(array_map('intval', $ids)));

        Cache::forever($this->getKlineRuntimeConfigIdsCacheKey(), $ids);
    }

    protected function applyKlineRuntimeConfigToMarketData($marketData, int $marketId)
    {
        $config = $this->getKlineRuntimeConfig($marketId);

        if (empty($config)) {
            return $marketData;
        }

        $fields = [
            'bot_price_floor',
            'bot_price_ceiling',
            'custom_liquidity_t',
            'last',
            'bot_current_price',
            'bot_momentum',
        ];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $config)) {
                continue;
            }

            if (is_array($marketData)) {
                $marketData[$field] = $config[$field];
            } elseif (is_object($marketData)) {
                $marketData->{$field} = $config[$field];
            }
        }

        return $marketData;
    }

    protected function compareKlineChangeLogsAscending(array $a, array $b): int
    {
        $timestampA = (int) ($a['start_timestamp'] ?? 0);
        $timestampB = (int) ($b['start_timestamp'] ?? 0);

        if ($timestampA !== $timestampB) {
            return $timestampA <=> $timestampB;
        }

        $microtimeA = (float) ($a['created_at_microtime'] ?? 0);
        $microtimeB = (float) ($b['created_at_microtime'] ?? 0);

        if (abs($microtimeA - $microtimeB) > 0.000001) {
            return $microtimeA <=> $microtimeB;
        }

        return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    }

    protected function compareKlineChangeLogsDescending(array $a, array $b): int
    {
        return $this->compareKlineChangeLogsAscending($b, $a);
    }

    protected function appendKlineChangeLog(MarketAdmin $market, float $oldPercent, float $newPercent, float $inputPercent): ?float
    {
        /*
         * 输入 0 是关闭自定义行情，不新增 0% 记录。
         */
        if ($inputPercent == 0 && $newPercent == 0) {
            return null;
        }

        if (abs($oldPercent - $newPercent) < 0.00000001 && abs($inputPercent) < 0.00000001) {
            return null;
        }

        $path = $this->getKlineChangeLogFilePath($market);
        $logs = $this->readJsonFile($path);

        if (!is_array($logs)) {
            $logs = [];
        }

        $nowMicrotime = microtime(true);
        $nowTs = (int) $nowMicrotime;
        $bsMultiplier = $this->getMarketBsMultiplier($market);
        $marketLogs = array_values(array_filter($logs, function ($log) use ($market) {
            return (int) ($log['market_id'] ?? 0) === (int) $market->id;
        }));

        usort($marketLogs, [$this, 'compareKlineChangeLogsAscending']);

        $previousLog = !empty($marketLogs) ? $marketLogs[count($marketLogs) - 1] : null;
        $previousAfterPrice = $previousLog ? (float) ($previousLog['after_price'] ?? 0) : 0;
        $basePriceSource = 'current_runtime';

        if ($previousAfterPrice <= 0 && $previousLog) {
            $previousBasePrice = (float) ($previousLog['base_price'] ?? $previousLog['before_price'] ?? 0);
            $previousPriceChange = (float) ($previousLog['price_change'] ?? 0);

            if ($previousBasePrice > 0) {
                $previousAfterPrice = $previousBasePrice + $previousPriceChange;
            }
        }

        if (!$this->shouldChainKlineChangeFromPrevious($previousLog, $nowTs)) {
            $previousAfterPrice = 0;
        }

        /*
         * 取原始基准价。
         * 非连续快速提交时，必须从实时运行价反推，不能用数据库 last 或旧 K 线文件。
         */
        $rawBasePrice = 0;
        $beforePrice = 0;

        if ($previousAfterPrice > 0) {
            $basePriceSource = 'previous_after_price';
            $beforePrice = $previousAfterPrice;
            $oldMultiplier = 1 + ($oldPercent / 100);

            if ($oldMultiplier > 0 && $bsMultiplier > 0) {
                $rawBasePrice = $beforePrice / $oldMultiplier / $bsMultiplier;
            }
        }

        if ($beforePrice <= 0) {
            $rawBasePrice = $this->getCurrentRawBasePriceForKlineLog($market, $oldPercent);

            /*
             * 展示用基准价：
             * 原始价 * 旧累计百分比 * bs。
             * 当 market.bs > 0 时，必须乘 bs 才是后台要展示的基准价格。
             */
            $beforePrice = $rawBasePrice > 0
                ? $rawBasePrice * (1 + ($oldPercent / 100)) * $bsMultiplier
                : 0;
        }

        /*
         * 变动价格：
         * 基准价格 * 本次输入百分比。
         * 因为基准价格已经乘过 bs，所以价格变化也自然会带上 bs。
         */
        $priceChange = $beforePrice * ($inputPercent / 100);
        $afterPrice = $beforePrice + $priceChange;
        $changePercent = $inputPercent;

        $direction = 'flat';

        if ($priceChange > 0) {
            $direction = 'up';
        } elseif ($priceChange < 0) {
            $direction = 'down';
        }

        $logs[] = [
            'id' => 'manual_' . $nowTs . '_' . substr(md5((string) $market->id . microtime(true)), 0, 8),
            'market_id' => (int) $market->id,
            'market_name' => (string) $market->name,

            /*
             * 页面只展示这个插针时间。
             */
            'start_timestamp' => $nowTs,
            'start_time' => date('Y-m-d H:i:s', $nowTs),
            'created_at_microtime' => $nowMicrotime,

            'direction' => $direction,
            'direction_text' => $direction === 'up' ? '上涨' : ($direction === 'down' ? '下跌' : '持平'),

            'old_percent' => $oldPercent,
            'new_percent' => $newPercent,
            'input_percent' => $inputPercent,
            'change_percent' => $changePercent,

            /*
             * bs 信息也存进去，避免以后排查时像雾里看灯。
             */
            'bs_multiplier' => $bsMultiplier,
            'is_bs_applied' => true,

            /*
             * 页面展示：
             * 基准价格 = 已经乘过旧百分比和 bs 的展示价格
             * 价格变化 = 基准价格 * 本次输入百分比
             */
            'raw_base_price' => $rawBasePrice,
            'base_price' => $beforePrice,
            'before_price' => $beforePrice,
            'after_price' => $afterPrice,
            'price_change' => $priceChange,
            'base_price_source' => $basePriceSource,

            'source' => 'manual',
            'created_at' => date('Y-m-d H:i:s', $nowTs),
            'updated_at' => date('Y-m-d H:i:s', $nowTs),
        ];

        usort($logs, [$this, 'compareKlineChangeLogsAscending']);

        $this->writeJsonFile($path, $logs);

        if ($afterPrice > 0) {
            $this->writeCurrentOneMinuteKlineOverride($market, $beforePrice, $afterPrice, $newPercent);
        }

        return $afterPrice > 0 ? $afterPrice : null;
    }

    protected function shouldChainKlineChangeFromPrevious(?array $previousLog, int $currentTimestamp): bool
    {
        if (!$previousLog || $currentTimestamp <= 0) {
            return false;
        }

        $previousTimestamp = (int) ($previousLog['start_timestamp'] ?? 0);

        if ($previousTimestamp <= 0 && !empty($previousLog['start_time'])) {
            $previousTimestamp = strtotime($previousLog['start_time']) ?: 0;
        }

        if ($previousTimestamp <= 0 || $currentTimestamp < $previousTimestamp) {
            return false;
        }

        if (($currentTimestamp - $previousTimestamp) <= self::KLINE_CHANGE_CHAIN_SECONDS) {
            return true;
        }

        return $this->getAdminResolutionBucketStartTimestamp($previousTimestamp, '1')
            === $this->getAdminResolutionBucketStartTimestamp($currentTimestamp, '1');
    }

    protected function writeCurrentOneMinuteKlineOverride(MarketAdmin $market, float $beforePrice, float $afterPrice, float $percent): void
    {
        if ($afterPrice <= 0) {
            return;
        }

        $precision = (int) ($market->quote_precision ?? 8);
        $bsMultiplier = $this->getMarketBsMultiplier($market);
        $storedBeforePrice = $bsMultiplier > 0 ? $beforePrice / $bsMultiplier : $beforePrice;
        $storedAfterPrice = $bsMultiplier > 0 ? $afterPrice / $bsMultiplier : $afterPrice;
        $nowTs = time();
        $timestamp = $this->getAdminResolutionBucketStartTimestamp($nowTs, '1');
        $path = $this->getKlineOverrideFilePath($market, '1');
        $rows = $this->readJsonFile($path);

        if (!is_array($rows)) {
            $rows = [];
        }

        $key = (string) $timestamp;
        $existing = isset($rows[$key]) && is_array($rows[$key]) ? $rows[$key] : [];

        /*
         * 当前未收盘的 1m K 线只能第一次确定 open。
         * 后续无论怎么改当前价，都只更新 close，不能让 open 跟着价格变。
         */
        $existingOpen = !empty($existing['open_locked'])
            ? (float) ($existing['o'] ?? 0)
            : 0;
        $previousClose = $this->getPreviousOneMinuteKlineClose($rows, $timestamp);

        $open = $existingOpen > 0
            ? $existingOpen
            : ($previousClose > 0 ? $previousClose : ($storedBeforePrice > 0 ? $storedBeforePrice : $storedAfterPrice));

        $existingHigh = (float) ($existing['h'] ?? 0);
        $existingLow = (float) ($existing['l'] ?? 0);
        $high = max($existingHigh > 0 ? $existingHigh : $open, $open, $storedAfterPrice);
        $low = min($existingLow > 0 ? $existingLow : $open, $open, $storedAfterPrice);
        $bodyHigh = max($open, $storedAfterPrice);
        $bodyLow = min($open, $storedAfterPrice);
        $priceTick = $precision > 0 ? pow(10, -$precision) : 1;
        $minWick = max($priceTick, $storedAfterPrice * 0.00005);
        $maxWick = max($minWick, $storedAfterPrice * 0.0003);
        $seedSource = $timestamp . '|' . number_format($open, $precision, '.', '') . '|' . number_format($storedAfterPrice, $precision, '.', '');
        $seed = (int) sprintf('%u', crc32($seedSource));

        $upperRatio = 0.18 + (($seed % 72) / 100);
        $lowerRatio = 0.18 + ((intdiv($seed, 97) % 72) / 100);
        $wickMode = $seed % 10;

        if ($wickMode <= 1) {
            $upperWick = 0;
            $lowerWick = 0;
        } elseif ($wickMode <= 3) {
            $upperWick = min($maxWick, max($minWick, $maxWick * $upperRatio));
            $lowerWick = 0;
        } elseif ($wickMode <= 5) {
            $upperWick = 0;
            $lowerWick = min($maxWick, max($minWick, $maxWick * $lowerRatio));
        } else {
            $upperWick = min($maxWick, max($minWick, $maxWick * $upperRatio));
            $lowerWick = min($maxWick, max($minWick, $maxWick * $lowerRatio));
        }

        $high = $bodyHigh + $upperWick;
        $low = $bodyLow - $lowerWick;

        $rows[$key] = [
            't' => $timestamp,
            'o' => number_format($open, $precision, '.', ''),
            'h' => number_format($high, $precision, '.', ''),
            'l' => number_format($low, $precision, '.', ''),
            'c' => number_format($storedAfterPrice, $precision, '.', ''),
            'v' => $existing['v'] ?? null,
            'percent' => $percent,
            'manual_current' => true,
            'open_locked' => true,
            'updated_at' => now()->toDateTimeString(),
        ];

        ksort($rows);

        if (count($rows) > 10000) {
            $rows = array_slice($rows, -10000, null, true);
        }

        $this->writeJsonFile($path, $rows);
    }

    protected function getPreviousOneMinuteKlineClose(array $rows, int $timestamp): float
    {
        $previousTimestamp = 0;
        $previousClose = 0;

        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowTimestamp = (int) ($row['t'] ?? $key);
            $rowClose = (float) ($row['c'] ?? 0);

            if ($rowTimestamp > 0 && $rowTimestamp < $timestamp && $rowTimestamp > $previousTimestamp && $rowClose > 0) {
                $previousTimestamp = $rowTimestamp;
                $previousClose = $rowClose;
            }
        }

        return $previousClose;
    }

    protected function getKlineChangesForMarket(MarketAdmin $market): array
    {
        $logs = $this->readJsonFile($this->getKlineChangeLogFilePath($market));

        if (!is_array($logs)) {
            $logs = [];
        }

        $logs = array_values(array_filter($logs, function ($log) use ($market) {
            return (int) ($log['market_id'] ?? 0) === (int) $market->id;
        }));

        /*
         * 先按时间从旧到新排序，方便计算每条记录负责的删除区间。
         * 当前记录影响区间：
         * 当前记录 start_timestamp 到下一条记录 start_timestamp。
         */
        usort($logs, [$this, 'compareKlineChangeLogsAscending']);

        $latestEndTs = $this->getLatestOneMinuteKlineEndTimestamp($market);

        if ($latestEndTs <= 0) {
            $latestEndTs = time();
        }

        $count = count($logs);
        $currentBsMultiplier = $this->getMarketBsMultiplier($market);
        $previousAfterPrice = 0;
        $previousLogForDisplay = null;

        for ($i = 0; $i < $count; $i++) {
            $startTs = (int) ($logs[$i]['start_timestamp'] ?? 0);

            if ($startTs <= 0 && !empty($logs[$i]['start_time'])) {
                $startTs = strtotime($logs[$i]['start_time']) ?: 0;
            }

            $nextLog = $logs[$i + 1] ?? null;
            $endTs = 0;

            if ($nextLog) {
                $endTs = (int) ($nextLog['start_timestamp'] ?? 0);

                if ($endTs <= 0 && !empty($nextLog['start_time'])) {
                    $endTs = strtotime($nextLog['start_time']) ?: 0;
                }
            } else {
                $endTs = $latestEndTs;
            }

            if ($startTs > 0 && $endTs <= $startTs) {
                $endTs = $startTs + 60;
            }

            $logs[$i]['start_timestamp'] = $startTs;
            $logs[$i]['start_time'] = $startTs > 0 ? date('Y-m-d H:i:s', $startTs) : '-';

            /*
             * 删除用区间，前端不需要显示。
             */
            $logs[$i]['delete_start_timestamp'] = $startTs;
            $logs[$i]['delete_end_timestamp'] = $endTs;

            $inputPercent = (float) ($logs[$i]['input_percent'] ?? $logs[$i]['change_percent'] ?? 0);
            $oldPercent = (float) ($logs[$i]['old_percent'] ?? 0);

            /*
             * 新记录：直接用已经存好的 base_price。
             * 旧记录：如果没有 is_bs_applied，则这里自动补乘 bs。
             */
            $basePrice = (float) ($logs[$i]['base_price'] ?? 0);
            $beforePrice = (float) ($logs[$i]['before_price'] ?? 0);

            if ($basePrice <= 0 && $beforePrice > 0) {
                $basePrice = $beforePrice;
            }

            if ($beforePrice <= 0 && $basePrice > 0) {
                $beforePrice = $basePrice;
            }

            $isBsApplied = (bool) ($logs[$i]['is_bs_applied'] ?? false);

            /*
             * 兼容旧 change_logs：旧记录没有乘 bs，这里显示时补上。
             */
            if (!$isBsApplied && $currentBsMultiplier !== 1.0 && $basePrice > 0) {
                $basePrice *= $currentBsMultiplier;
                $beforePrice *= $currentBsMultiplier;
                $logs[$i]['bs_multiplier'] = $currentBsMultiplier;
                $logs[$i]['is_bs_applied'] = true;
            }

            /*
             * 如果还是没有基准价，用 raw_base_price 重新计算。
             */
            if ($basePrice <= 0) {
                $rawBasePrice = (float) ($logs[$i]['raw_base_price'] ?? 0);

                if ($rawBasePrice <= 0) {
                    $rawBasePrice = $this->getCurrentRawBasePriceForKlineLog($market, $oldPercent);
                }

                $basePrice = $rawBasePrice > 0
                    ? $rawBasePrice * (1 + ($oldPercent / 100)) * $currentBsMultiplier
                    : 0;

                $beforePrice = $basePrice;
            }

            $basePriceSource = (string) ($logs[$i]['base_price_source'] ?? '');
            $shouldUsePreviousAfterPrice = $previousAfterPrice > 0
                && $basePriceSource !== 'current_runtime'
                && $this->shouldChainKlineChangeFromPrevious($previousLogForDisplay, $startTs);

            if ($shouldUsePreviousAfterPrice) {
                $basePrice = $previousAfterPrice;
                $beforePrice = $previousAfterPrice;
            }

            /*
             * 价格变化统一按：基准价格 * 本次输入百分比。
             * 因为基准价已经乘 bs，所以变动价格也已经乘 bs。
             */
            if ($basePrice > 0) {
                $priceChange = $basePrice * ($inputPercent / 100);
                $afterPrice = $basePrice + $priceChange;
            } else {
                $afterPrice = (float) ($logs[$i]['after_price'] ?? 0);
                $priceChange = $afterPrice - $beforePrice;
            }

            $direction = 'flat';

            if ($priceChange > 0) {
                $direction = 'up';
            } elseif ($priceChange < 0) {
                $direction = 'down';
            }

            $logs[$i]['market_name'] = $logs[$i]['market_name'] ?? $market->name;
            $logs[$i]['base_price'] = $basePrice;
            $logs[$i]['before_price'] = $beforePrice;
            $logs[$i]['after_price'] = $afterPrice;
            $logs[$i]['price_change'] = $priceChange;
            $logs[$i]['direction'] = $direction;
            $logs[$i]['direction_text'] = $direction === 'up' ? '上涨' : ($direction === 'down' ? '下跌' : '持平');

            $previousAfterPrice = $afterPrice > 0 ? $afterPrice : 0;
            $previousLogForDisplay = $logs[$i];
        }

        /*
         * 页面倒序显示，最新记录在上面。
         */
        usort($logs, [$this, 'compareKlineChangeLogsDescending']);

        return array_slice(array_values($logs), 0, self::KLINE_CHANGE_DISPLAY_LIMIT);
    }

    protected function deleteKlineChangesByIds(MarketAdmin $market, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));

        if (empty($ids)) {
            return 0;
        }

        $path = $this->getKlineChangeLogFilePath($market);
        $logs = $this->readJsonFile($path);

        if (!is_array($logs) || empty($logs)) {
            return 0;
        }

        $logs = array_values(array_filter($logs, function ($log) use ($market) {
            return (int) ($log['market_id'] ?? 0) === (int) $market->id;
        }));

        if (empty($logs)) {
            return 0;
        }

        /*
         * 按时间从旧到新排序。
         */
        usort($logs, [$this, 'compareKlineChangeLogsAscending']);

        $allIds = array_values(array_filter(array_map(function ($log) {
            return isset($log['id']) ? (string) $log['id'] : null;
        }, $logs)));

        sort($allIds);

        $selectedIds = $ids;
        sort($selectedIds);

        /*
         * 全选删除：直接清空全部 K 线修改文件。
         * 现在会删除当前 market_id 对应目录下的所有文件，包括 percent_state.json。
         */
        if ($allIds === $selectedIds) {
            $this->clearAllKlineChangeFiles($market);
            return count($ids);
        }

        $latestEndTs = $this->getLatestOneMinuteKlineEndTimestamp($market);

        if ($latestEndTs <= 0) {
            $latestEndTs = time();
        }

        $deleteRanges = [];
        $deleted = 0;

        foreach ($logs as $index => $log) {
            $logId = (string) ($log['id'] ?? '');

            if ($logId === '' || !in_array($logId, $ids, true)) {
                continue;
            }

            $startTs = (int) ($log['start_timestamp'] ?? 0);

            if ($startTs <= 0 && !empty($log['start_time'])) {
                $startTs = strtotime($log['start_time']) ?: 0;
            }

            if ($startTs <= 0) {
                continue;
            }

            $nextLog = $logs[$index + 1] ?? null;
            $endTs = 0;

            if ($nextLog) {
                $endTs = (int) ($nextLog['start_timestamp'] ?? 0);

                if ($endTs <= 0 && !empty($nextLog['start_time'])) {
                    $endTs = strtotime($nextLog['start_time']) ?: 0;
                }
            } else {
                $endTs = $latestEndTs;
            }

            if ($endTs <= $startTs) {
                $endTs = $startTs + 60;
            }

            $deleteRanges[] = [
                'start_timestamp' => $startTs,
                'end_timestamp' => $endTs,
            ];

            $deleted++;
        }

        if (empty($deleteRanges)) {
            return 0;
        }

        /*
         * 删除真实 K 线数据。
         */
        foreach ($deleteRanges as $range) {
            $this->deleteKlineOverridesByTimeRange(
                $market,
                (int) $range['start_timestamp'],
                (int) $range['end_timestamp']
            );
        }

        /*
         * 删除 change_logs.json 里的记录。
         */
        $logs = array_values(array_filter($logs, function ($log) use ($ids) {
            return !in_array((string) ($log['id'] ?? ''), $ids, true);
        }));

        $this->writeJsonFile($path, $logs);

        return $deleted;
    }

    protected function deleteKlineOverridesByTimeRange(MarketAdmin $market, int $startTs, int $endTs): void
    {
        $dir = storage_path("app/market_kline_overrides/" . (int) $market->id);

        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*.json') ?: [];

        foreach ($files as $file) {
            $name = basename($file);

            if (in_array($name, ['percent_state.json', 'change_logs.json'], true)) {
                continue;
            }

            $resolution = basename($file, '.json');
            $rows = $this->readJsonFile($file);

            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            $changed = false;

            foreach ($rows as $key => $row) {
                if (empty($row['t'])) {
                    continue;
                }

                $timestamp = $this->normalizeKlineTimestamp($row['t']);
                $barEndTs = $this->getAdminCandleEndTimestamp($timestamp, $resolution);

                /*
                 * 只要 K 线时间段和删除区间有重叠，就删除。
                 */
                $isOverlap = $timestamp < $endTs && $barEndTs > $startTs;

                if ($isOverlap) {
                    unset($rows[$key]);
                    $changed = true;
                }
            }

            if ($changed) {
                ksort($rows);
                $this->writeJsonFile($file, $rows);
            }
        }
    }

    protected function clearAllKlineChangeFiles(MarketAdmin $market): void
    {
        $dir = storage_path("app/market_kline_overrides/" . (int) $market->id);

        if (!is_dir($dir)) {
            return;
        }

        /*
         * 删除当前 market_id 对应目录下的所有内容。
         * 不再保留 percent_state.json。
         * 不再只删除 .json。
         * 目录里的 .tmp、其他文件、子目录都会一起清掉。
         */
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $path = $item->getPathname();

                if ($item->isDir()) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }

            /*
             * 删除空的 market_id 目录。
             * 后续如果再次写入 K 线文件，writeJsonFile 会自动重新创建目录。
             */
            @rmdir($dir);
        } catch (\Throwable $e) {
            /*
             * 如果递归删除失败，降级处理一层目录下的文件。
             * 避免因为某个异常导致整个清空动作完全失效。
             */
            $files = glob($dir . '/*') ?: [];

            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            @rmdir($dir);
        }
    }

    protected function clearKlineRuntimeStateAfterDelete(MarketAdmin $market): void
    {
        $marketId = (int) $market->id;

        if ($marketId <= 0) {
            return;
        }

        Cache::forget('market_kline_adjusted_at_' . $marketId);
        Cache::forget('market_kline_adjusted_price_' . $marketId);
        Cache::forget('market_kline_adjustment_anchor_' . $marketId);
        Cache::forget('market_kline_runtime_config_' . $marketId);

        foreach ($this->getOrderbookCacheMarketKeys($market) as $orderbookKey) {
            Cache::forget('market_orderbook_rebase_' . $orderbookKey);
        }

        $this->forgetCacheKeysByPattern('market_kline_ohlc_lock_v4_open_' . $marketId . '_*');
        $this->forgetCacheKeysByPattern('market_kline_ohlc_lock_v4_close_' . $marketId . '_*');

        try {
            DB::table($market->getTable())
                ->where($market->getKeyName(), $marketId)
                ->update([
                    'bot_price_floor' => 0,
                    'custom_liquidity_t' => false,
                    'bot_momentum' => 0,
                    'updated_at' => now(),
                ]);

            $market->forceFill([
                'bot_price_floor' => 0,
                'custom_liquidity_t' => false,
                'bot_momentum' => 0,
            ]);
        } catch (\Throwable $e) {
            //
        }

        try {
            $this->marketService->updateMarketsInfoCache();
        } catch (\Throwable $e) {
            //
        }
    }

    protected function forgetCacheKeysByPattern(string $pattern): void
    {
        try {
            $prefix = (string) config('cache.prefix');
            $prefixedPattern = $prefix !== ''
                ? $prefix . ':' . $pattern
                : $pattern;

            $redis = Redis::connection(config('cache.stores.redis.connection', 'cache'));

            $client = method_exists($redis, 'client') ? $redis->client() : null;

            if (class_exists(\RedisCluster::class) && $client instanceof \RedisCluster && method_exists($client, '_masters')) {
                foreach ($client->_masters() as $node) {
                    $this->forgetRedisKeysByScan($redis, $prefixedPattern, $node, true);
                }

                return;
            }

            if ($client instanceof \Traversable) {
                foreach ($client as $nodeRedis) {
                    $this->forgetRedisKeysByScan($nodeRedis, $prefixedPattern, null, true);
                }

                return;
            }

            $this->forgetRedisKeysByScan($redis, $prefixedPattern);
        } catch (\Throwable $e) {
            //
        }
    }

    protected function forgetRedisKeysByScan($redis, string $pattern, $node = null, bool $deleteOneByOne = false): void
    {
        $cursor = 0;

        do {
            $options = [
                'match' => $pattern,
                'count' => 500,
            ];

            if ($node !== null) {
                $options['node'] = $node;
            }

            $scan = $redis->scan($cursor, $options);

            if ($scan === false || ! is_array($scan) || count($scan) < 2) {
                break;
            }

            $cursor = $scan[0] ?? 0;
            $keys = array_values(array_filter((array) ($scan[1] ?? []), function ($key) {
                return is_string($key) && $key !== '';
            }));

            $this->deleteRedisKeys($redis, $keys, $deleteOneByOne);
        } while ((string) $cursor !== '0');
    }

    protected function deleteRedisKeys($redis, array $keys, bool $oneByOne = false): void
    {
        if (empty($keys)) {
            return;
        }

        if ($oneByOne) {
            foreach ($keys as $key) {
                try {
                    $redis->del($key);
                } catch (\Throwable $e) {
                    //
                }
            }

            return;
        }

        foreach (array_chunk($keys, 500) as $chunk) {
            try {
                $redis->del($chunk);
            } catch (\Throwable $e) {
                foreach ($chunk as $key) {
                    try {
                        $redis->del($key);
                    } catch (\Throwable $inner) {
                        //
                    }
                }
            }
        }
    }

    protected function jsonOrBack(bool $success, string $message)
    {
        if (request()->expectsJson() || request()->ajax()) {
            return response()->json([
                'success' => $success,
                'message' => $message,
            ], $success ? 200 : 422);
        }

        return Redirect::back()->with($success ? 'success' : 'error', $message);
    }

    protected function getKlineChangeLogFilePath(MarketAdmin $market): string
    {
        return storage_path("app/market_kline_overrides/" . (int) $market->id . "/change_logs.json");
    }

    protected function getKlineOverrideFilePath(MarketAdmin $market, $resolution): string
    {
        $resolution = $this->normalizeAdminResolutionKey($resolution);
        $safeResolution = preg_replace('/[^A-Za-z0-9_\-]/', '_', $resolution);

        return storage_path("app/market_kline_overrides/" . (int) $market->id . "/{$safeResolution}.json");
    }

    protected function readJsonFile(string $path)
    {
        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);

        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    protected function writeJsonFile(string $path, array $data): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        $tmpPath = $path . '.tmp';

        file_put_contents($tmpPath, $json, LOCK_EX);
        rename($tmpPath, $path);
    }

    protected function normalizeKlineTimestamp($timestamp): int
    {
        $timestamp = (int) $timestamp;

        if ($timestamp > 20000000000) {
            return (int) floor($timestamp / 1000);
        }

        return $timestamp;
    }

    protected function normalizeAdminResolutionKey($resolution): string
    {
        $raw = trim((string) $resolution);

        if ($raw === '') {
            return '1';
        }

        if (is_numeric($raw)) {
            return (string) max(1, (int) $raw);
        }

        $upper = strtoupper($raw);

        if ($upper === 'D') {
            return '1D';
        }

        if ($upper === 'W') {
            return '1W';
        }

        if ($upper === 'M') {
            return '1M';
        }

        if (preg_match('/^(\d+)H$/', $upper, $matches)) {
            return (string) (max(1, (int) $matches[1]) * 60);
        }

        if (preg_match('/^(\d+)D$/', $upper, $matches)) {
            return max(1, (int) $matches[1]) . 'D';
        }

        if (preg_match('/^(\d+)W$/', $upper, $matches)) {
            return max(1, (int) $matches[1]) . 'W';
        }

        if (preg_match('/^(\d+)M$/', $upper, $matches)) {
            return max(1, (int) $matches[1]) . 'M';
        }

        return $upper;
    }

    protected function getAdminResolutionBucketStartTimestamp(int $timestamp, $resolution): int
    {
        $resolutionKey = $this->normalizeAdminResolutionKey($resolution);

        if (is_numeric($resolutionKey)) {
            $seconds = max(1, (int) $resolutionKey) * 60;

            return (int) floor($timestamp / $seconds) * $seconds;
        }

        if (preg_match('/^(\d+)D$/', $resolutionKey, $matches)) {
            $days = max(1, (int) $matches[1]);
            $carbon = \Carbon\Carbon::createFromTimestamp($timestamp)->startOfDay();

            if ($days === 1) {
                return $carbon->timestamp;
            }

            $seconds = $days * 86400;

            return (int) floor($carbon->timestamp / $seconds) * $seconds;
        }

        if (preg_match('/^(\d+)W$/', $resolutionKey, $matches)) {
            $weeks = max(1, (int) $matches[1]);

            $carbon = \Carbon\Carbon::createFromTimestamp($timestamp)
                ->startOfWeek(\Carbon\Carbon::MONDAY);

            if ($weeks === 1) {
                return $carbon->timestamp;
            }

            $seconds = $weeks * 604800;

            return (int) floor($carbon->timestamp / $seconds) * $seconds;
        }

        if (preg_match('/^(\d+)M$/', $resolutionKey, $matches)) {
            return \Carbon\Carbon::createFromTimestamp($timestamp)
                ->startOfMonth()
                ->timestamp;
        }

        return $timestamp;
    }

    protected function getAdminCandleEndTimestamp(int $timestamp, $resolution): int
    {
        $resolutionKey = $this->normalizeAdminResolutionKey($resolution);
        $bucketStart = $this->getAdminResolutionBucketStartTimestamp($timestamp, $resolutionKey);

        if (is_numeric($resolutionKey)) {
            return $bucketStart + (max(1, (int) $resolutionKey) * 60);
        }

        if (preg_match('/^(\d+)D$/', $resolutionKey, $matches)) {
            return \Carbon\Carbon::createFromTimestamp($bucketStart)
                ->addDays(max(1, (int) $matches[1]))
                ->timestamp;
        }

        if (preg_match('/^(\d+)W$/', $resolutionKey, $matches)) {
            return \Carbon\Carbon::createFromTimestamp($bucketStart)
                ->addWeeks(max(1, (int) $matches[1]))
                ->timestamp;
        }

        if (preg_match('/^(\d+)M$/', $resolutionKey, $matches)) {
            return \Carbon\Carbon::createFromTimestamp($bucketStart)
                ->addMonths(max(1, (int) $matches[1]))
                ->timestamp;
        }

        return $bucketStart + 60;
    }

    protected function getLatestOneMinuteKlineEndTimestamp(MarketAdmin $market): int
    {
        $rows = $this->readJsonFile($this->getKlineOverrideFilePath($market, '1'));

        if (empty($rows) || !is_array($rows)) {
            return 0;
        }

        $latest = 0;

        foreach ($rows as $row) {
            if (empty($row['t'])) {
                continue;
            }

            $timestamp = $this->normalizeKlineTimestamp($row['t']);

            if ($timestamp > $latest) {
                $latest = $timestamp;
            }
        }

        return $latest > 0 ? $latest + 60 : 0;
    }

    protected function getCurrentRawBasePriceForKlineLog(MarketAdmin $market, float $currentPercent = 0): float
    {
        $currentPrice = $this->getRealtimePriceForKlineAdjustment($market);

        if ($currentPrice <= 0) {
            return 0;
        }

        $multiplier = 1 + ($currentPercent / 100);
        $bsMultiplier = $this->getMarketBsMultiplier($market);

        if ($multiplier > 0 && $bsMultiplier > 0) {
            return $currentPrice / $multiplier / $bsMultiplier;
        }

        return $currentPrice;
    }

    protected function getRealtimePriceForKlineAdjustment(MarketAdmin $market): float
    {
        $marketId = (int) $market->id;

        if ($marketId <= 0) {
            return 0;
        }

        $runtimeConfig = $this->getKlineRuntimeConfig($marketId);

        /*
         * 和客户端现价接口保持一致：
         * 调价基准先取运行中的实时价格缓存，不能回退到 markets.last。
         */
        if (isset($runtimeConfig['last']) && is_numeric($runtimeConfig['last']) && (float) $runtimeConfig['last'] > 0) {
            return (float) $runtimeConfig['last'];
        }

        try {
            $statsLast = function_exists('market_get_stats')
                ? market_get_stats($marketId, 'last')
                : null;

            if (is_numeric($statsLast) && (float) $statsLast > 0) {
                return (float) $statsLast;
            }
        } catch (\Throwable $e) {
            //
        }

        return 0;
    }

    protected function getMarketBsMultiplier(MarketAdmin $market): float
    {
        return MarketPriceMultiplier::resolve($market->bs);
    }

    protected function sleepWhenSubmitHitsMinuteBoundary(): void
    {
        $second = (int) now()->format('s');

        if (in_array($second, [59, 0, 1], true)) {
            sleep(2);
        }
    }

    public function destroy(MarketAdmin $market)
    {
        $this->marketService->deleteMarket($market->id);

        return Redirect::route('admin.markets');
    }

    public function restore(MarketAdmin $market)
    {
        $this->marketService->restoreMarket($market->id);

        return Redirect::route('admin.markets');
    }
}
