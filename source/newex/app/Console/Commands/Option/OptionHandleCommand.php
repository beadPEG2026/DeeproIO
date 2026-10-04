<?php

namespace App\Console\Commands\Option;

use App\Events\OptionsStatusUpdated;
use App\Events\WalletUpdated;
use App\Models\Market\Market;
use App\Models\Option\Option;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OptionHandleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'options-watcher:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process options contracts';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $ranges = config('app.options_types');
        $hasEndAtColumn = Schema::hasColumn('options', 'end_at');

        while (true) {
            $now = Carbon::now();

            /*
             * scheduled 订单到开始时间后才转 active
             */
            $scheduled = Option::where('status', 'scheduled')->get();

            foreach ($scheduled as $opt) {
                if ($opt->start_at && Carbon::parse($opt->start_at)->lte($now)) {
                    Option::whereKey($opt->id)->where('status', 'scheduled')->update(['status' => 'active']);

                    $this->info('Option Activated with ID ' . $opt->uuid);
                }
            }

            /*
             * Process active options
             */
            $options = Option::where('status', 'active')
                ->with('currency')
                ->with('user')
                ->get();

            foreach ($options as $option) {
                $now = Carbon::now();

                $baseTime = $option->start_at
                    ? Carbon::parse($option->start_at)
                    : Carbon::parse($option->created_at);

                $processSeconds = (int) ($option->timeframe_seconds ?: ($ranges[$option->period] ?? 0));

                if ($processSeconds <= 0) {
                    $processSeconds = 60;
                }

                /*
                 * 优先使用 end_at 判断是否到期。
                 * 没有 end_at 的旧数据，才使用 start_at + timeframe_seconds。
                 */
                if ($hasEndAtColumn && $option->end_at) {
                    $endAt = Carbon::parse($option->end_at);
                } else {
                    $endAt = $baseTime->copy()->addSeconds($processSeconds);
                }

                /*
                 * 未到开始时间，不处理
                 */
                if ($baseTime->gt($now)) {
                    continue;
                }

                /*
                 * 未到结算时间，不处理
                 */
                if ($endAt->gt($now)) {
                    continue;
                }

                try { DB::transaction(function () use ($option, $ranges, $hasEndAtColumn) {
                    $option = Option::query()
                        ->where('id', $option->id)
                        ->lockForUpdate()
                        ->with('currency')
                        ->with('user')
                        ->first();

                    if (!$option || $option->status !== 'active') {
                        return;
                    }

                    $now = Carbon::now();

                    $baseTime = $option->start_at
                        ? Carbon::parse($option->start_at)
                        : Carbon::parse($option->created_at);

                    $processSeconds = (int) ($option->timeframe_seconds ?: ($ranges[$option->period] ?? 0));

                    if ($processSeconds <= 0) {
                        $processSeconds = 60;
                    }

                    if ($hasEndAtColumn && $option->end_at) {
                        $endAt = Carbon::parse($option->end_at);
                    } else {
                        $endAt = $baseTime->copy()->addSeconds($processSeconds);
                    }

                    if ($baseTime->gt($now) || $endAt->gt($now)) {
                        return;
                    }

                    /*
                     * 获取结算价：
                     * 1. market_get_stats(last)
                     * 2. 自定义行情缓存
                     * 3. 订单簿缓存
                     * 4. markets 表价格字段
                     * 5. 开仓价兜底
                     */
                    $settlement = $this->getSettlementMarketPrice($option);
                    $marketPrice = $settlement['price'];
                    $priceSource = $settlement['source'];

                    if ($this->safeCompare($marketPrice, 0) <= 0) {
                        Log::warning('Option settlement price is empty, fallback to open price', [
                            'option_id' => $option->id,
                            'option_uuid' => $option->uuid,
                            'market_id' => $option->market_id,
                            'open_price' => $option->price,
                            'price_source' => $priceSource,
                        ]);

                        $marketPrice = $option->price;
                        $priceSource = 'open_price_fallback';
                    }

                    Log::info('Option settlement market price', [
                        'option_id' => $option->id,
                        'option_uuid' => $option->uuid,
                        'market_id' => $option->market_id,
                        'open_price' => $option->price,
                        'settlement_price' => $marketPrice,
                        'price_source' => $priceSource,
                    ]);

                    app(\App\Services\Option\OptionFunds::class)->wallet($option);
                    if ($option->funding_domain === 'virtual' && $option->is_exception) {
                        $option->status = $option->is_exception;
                    } else {
                        $outputType = $option->funding_domain === 'virtual' ? setting('trade.options_result_mode') : 'default';

                        if ($outputType == 'win') {
                            $option->status = 'won';
                        } elseif ($outputType == 'lose') {
                            $option->pnl = -1 * abs($option->amount);
                            $option->status = 'lost';
                        } else {
                            if (math_compare($marketPrice, $option->price) >= 0) {
                                if ($option->type == "buy") {
                                    $option->status = 'won';
                                } else {
                                    $option->pnl = -1 * abs($option->amount);
                                    $option->status = 'lost';
                                }
                            } else {
                                if ($option->type == "buy") {
                                    $option->pnl = -1 * abs($option->amount);
                                    $option->status = 'lost';
                                } else {
                                    $option->status = 'won';
                                }
                            }
                        }
                    }

                    $option->settlement_source = $priceSource;
                    $option->market_price = $marketPrice;
                    $option->save();

                    /*
                     * 保险更新：
                     * 直接写入 options 表，避免模型属性、缓存、旧实例导致 market_price 没有落库。
                     */
                    if (Schema::hasColumn('options', 'market_price')) {
                        DB::table('options')
                            ->where('id', $option->id)
                            ->update([
                                'market_price' => $marketPrice,
                                'updated_at' => Carbon::now(),
                            ]);

                        $option->market_price = $marketPrice;
                    } else {
                        Log::warning('options table missing market_price column', [
                            'option_id' => $option->id,
                            'option_uuid' => $option->uuid,
                            'market_id' => $option->market_id,
                            'settlement_price' => $marketPrice,
                        ]);
                    }

                    if ($option->status == 'won') {
                        app(\App\Services\Option\OptionFunds::class)->credit($option, (string) math_sum($option->amount, $option->pnl));

                        $user = $option->user()->lockForUpdate()->first();

                        if ($user) {
                            $current = $user->cumulative_earnings_usd ?? '0';
                            $user->cumulative_earnings_usd = math_sum($current, $option->pnl);
                            $user->save();
                        }
                    }

                    $this->info(
                        'Option Processed with ID ' . $option->uuid .
                        ' | settlement_price=' . $marketPrice .
                        ' | source=' . $priceSource
                    );

                    if ($option->currency) {
                        DB::afterCommit(fn () => event(new OptionsStatusUpdated($option, $option->currency->symbol)));
                    }
                }); } catch (\Illuminate\Validation\ValidationException $e) {
                    if (now()->timestamp > $endAt->timestamp + 30) {
                        app(\App\Services\Option\OptionSettlementReview::class)->enqueue((int)$option->id, $endAt);
                    } else {
                        Cache::put('options.review.' . $option->id, ['reason' => $e->errors(), 'at' => now()->toIso8601String()], 3600);
                    }
                }
            }

            sleep(1);
        }

        return 0;
    }

    /**
     * 获取期权结算价格。
     *
     * 读取顺序：
     * 1. market_get_stats(last)
     * 2. 自定义行情缓存 market_custom_last_price.{market_name}
     * 3. 当前订单簿买一/卖一中间价
     * 4. markets 表里的 bot_current_price / last 等字段
     * 5. 开仓价兜底
     */
    private function getSettlementMarketPrice(Option $option): array
    {
        if ($option->funding_domain !== 'virtual') {
            $market = Market::findOrFail($option->market_id);
            $expiry = $option->end_at ? Carbon::parse($option->end_at)->timestamp : Carbon::parse($option->start_at ?: $option->created_at)->addSeconds((int) ($option->timeframe_seconds ?: 60))->timestamp;
            return app(\App\Services\Option\OptionPrice::class)->quote($market, $expiry);
        }

        $market = Market::query()
            ->where('id', $option->market_id)
            ->first();

        /*
         * 1. 优先使用系统统计价格
         */
        $price = market_get_stats($option->market_id, 'last');

        if ($this->safeCompare($price, 0) > 0) {
            return [
                'price' => $this->safeDecimal($price),
                'source' => 'market_get_stats_last',
            ];
        }

        if ($market) {
            /*
             * 2. 读取自定义行情缓存。
             */
            $customLastPrice = Cache::get("market_custom_last_price.{$market->name}");

            if ($this->safeCompare($customLastPrice, 0) > 0) {
                return [
                    'price' => $this->safeDecimal($customLastPrice),
                    'source' => 'cache_market_custom_last_price',
                ];
            }

            /*
             * 3. 从订单簿缓存取买一/卖一中间价。
             */
            $bids = Cache::get("markets_liquidity.{$market->name}.bids");
            $asks = Cache::get("markets_liquidity.{$market->name}.asks");

            $bestBid = $this->getFirstOrderBookPrice($bids);
            $bestAsk = $this->getFirstOrderBookPrice($asks);

            if ($this->safeCompare($bestBid, 0) > 0 && $this->safeCompare($bestAsk, 0) > 0) {
                $midPrice = math_divide(math_sum($bestBid, $bestAsk), 2);

                if ($this->safeCompare($midPrice, 0) > 0) {
                    return [
                        'price' => $this->safeDecimal($midPrice),
                        'source' => 'orderbook_mid_price',
                    ];
                }
            }

            if ($this->safeCompare($bestBid, 0) > 0) {
                return [
                    'price' => $this->safeDecimal($bestBid),
                    'source' => 'orderbook_best_bid',
                ];
            }

            if ($this->safeCompare($bestAsk, 0) > 0) {
                return [
                    'price' => $this->safeDecimal($bestAsk),
                    'source' => 'orderbook_best_ask',
                ];
            }

            /*
             * 4. 兜底从 markets 表常见字段读取。
             */
            $possibleFields = [
                'bot_current_price',
                'last',
                'last_price',
                'price',
                'close',
                'market_price',
                'custom_liquidity_start_price',
            ];

            foreach ($possibleFields as $field) {
                if (!Schema::hasColumn($market->getTable(), $field)) {
                    continue;
                }

                $value = $market->{$field} ?? 0;

                if ($this->safeCompare($value, 0) > 0) {
                    return [
                        'price' => $this->safeDecimal($value),
                        'source' => 'markets_table_' . $field,
                    ];
                }
            }

            /*
             * 5. 如果配置了地板价和天花板价，用中间价兜底。
             */
            if (
                Schema::hasColumn($market->getTable(), 'bot_price_floor') &&
                Schema::hasColumn($market->getTable(), 'bot_price_ceiling')
            ) {
                $floor = $market->bot_price_floor ?? 0;
                $ceiling = $market->bot_price_ceiling ?? 0;

                if ($this->safeCompare($floor, 0) > 0 && $this->safeCompare($ceiling, 0) > 0) {
                    $middlePrice = math_divide(math_sum($floor, $ceiling), 2);

                    return [
                        'price' => $this->safeDecimal($middlePrice),
                        'source' => 'bot_floor_ceiling_mid_price',
                    ];
                }
            }
        }

        /*
         * 6. 最后兜底开仓价，避免 market_price 继续为 0。
         */
        return [
            'price' => $this->safeDecimal($option->price),
            'source' => 'option_open_price',
        ];
    }

    /**
     * 兼容 Collection / array 格式的订单簿价格。
     */
    private function getFirstOrderBookPrice($orders)
    {
        if (!$orders) {
            return '0';
        }

        if ($orders instanceof \Illuminate\Support\Collection) {
            $first = $orders->first();
        } elseif (is_array($orders)) {
            $first = reset($orders);
        } else {
            return '0';
        }

        if (!$first) {
            return '0';
        }

        if (is_numeric($first)) {
            return $first;
        }

        if (is_array($first)) {
            return $first['price']
                ?? $first['rate']
                ?? $first['p']
                ?? $first[0]
                ?? '0';
        }

        if (is_object($first)) {
            return $first->price
                ?? $first->rate
                ?? $first->p
                ?? '0';
        }

        return '0';
    }

    /**
     * 判断当前钱包是否存在虚拟资产。
     */
    private function hasVirtualAsset($wallet): bool
    {
        if (!$wallet) {
            return false;
        }

        $wallet->refresh();

        $total = '0';

        $fields = [
            'balance_in_virtual_wallet',
            'balance_in_virtual_trade',
            'balance_in_virtual_order',
            'balance_in_virtual_withdraw',
        ];

        foreach ($fields as $field) {
            if (!Schema::hasColumn('wallets', $field)) {
                continue;
            }

            $total = math_sum($total, $wallet->{$field} ?? 0);
        }

        return $this->safeCompare($total, 0) > 0;
    }

    /**
     * 盈利收益回到虚拟钱包余额。
     */
    private function increaseVirtualWallet($wallet, $amount): void
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0) {
            return;
        }

        if (!Schema::hasColumn('wallets', 'balance_in_virtual_wallet')) {
            throw new \Exception('balance_in_virtual_wallet field does not exist');
        }

        DB::statement(
            "UPDATE wallets
             SET balance_in_virtual_wallet = COALESCE(balance_in_virtual_wallet, 0) + ?,
                 updated_at = ?
             WHERE id = ?",
            [
                $amount,
                Carbon::now(),
                $wallet->id,
            ]
        );

        $wallet->refresh();
    }

    private function safeDecimal($value, $scale = 18)
    {
        return \App\Services\Math\ExactDecimal::normalize($value,(int)$scale);
    }

    private function safeCompare($left, $right, $scale = 18)
    {
        return bccomp($this->safeDecimal($left, $right === null ? 18 : $scale), $this->safeDecimal($right, $scale), $scale);
    }
}