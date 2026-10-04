<?php

namespace App\Services\Market;

use App\Models\{Market\Market, Order\Order};
use Illuminate\Support\Facades\{Cache, DB};

/** Prepare closed, observe a fresh book, then switch under the matching lock. */
final class StablecoinMarketCutover
{
    public function snapshot(): array
    {
        $old = Market::whereName('USDC-USDT')->firstOrFail();
        $new = Market::whereName('USDT-USDC')->first();
        $orders = Order::where('market_id', $old->id)->orderBy('id')->get();
        $result = [
            'old' => $old->getAttributes(), 'new' => $new?->getAttributes(),
            'policy' => app(FundedLiquidity::class)->policy($old),
            'open_orders' => $orders->count(), 'orders_sha256' => hash('sha256', $orders->toJson()),
        ];
        // Ticker updates are not configuration changes. Order and policy hashes
        // remain exact so a delayed review cannot authorize a changed cutover.
        $review = $result;
        foreach (['old', 'new'] as $side) {
            if ($review[$side] !== null) unset($review[$side]['last'], $review[$side]['updated_at']);
        }
        return $result + ['sha256' => hash('sha256', json_encode($review))];
    }

    public function prepare(): Market
    {
        return DB::transaction(function () {
            DB::statement('SELECT pg_advisory_xact_lock(8192031)');
            $old = Market::whereName('USDC-USDT')->firstOrFail();
            if ($old->baseCurrency->symbol !== 'USDC' || $old->quoteCurrency->symbol !== 'USDT') {
                throw new \RuntimeException('STABLECOIN_SOURCE_IDENTITY_CONFLICT');
            }
            $existing = Market::whereName('USDT-USDC')->first();
            if ($existing) {
                if (!StablecoinOrientation::inverse($existing) || $existing->chart_symbol !== 'USDCUSDT') {
                    throw new \RuntimeException('STABLECOIN_TARGET_IDENTITY_CONFLICT');
                }
                return $existing;
            }
            $policy = app(FundedLiquidity::class)->policy($old);
            if (!$policy || !in_array($policy->mode, ['platform_credit', 'platform_maker'], true)) {
                throw new \RuntimeException('STABLECOIN_EXECUTION_POLICY_REQUIRED');
            }
            $new = Market::forceCreate([
                'name'=>'USDT-USDC', 'base_currency_id'=>$old->quote_currency_id, 'quote_currency_id'=>$old->base_currency_id,
                'base_precision'=>4, 'quote_precision'=>8, 'base_ticker_size'=>'0.0001', 'quote_ticker_size'=>'0.00000001',
                'min_trade_size'=>'0.01', 'min_trade_value'=>'5', 'min_market_buy_amount'=>'5',
                'status'=>false, 'trade_status'=>false, 'buy_order_status'=>true, 'sell_order_status'=>true, 'cancel_order_status'=>true,
                'liq'=>true, 'custom_liquidity'=>false, 'chart_symbol'=>'USDCUSDT', 'chart_source'=>'binance',
                'switch_chart'=>true, 'chart_default_resolution'=>'15', 'has_futures'=>false, 'has_options'=>false,
            ]);
            DB::table('market_execution_policies')->insert([
                'market_id'=>$new->id, 'mode'=>$policy->mode, 'maker_user_id'=>$policy->maker_user_id,
                'max_quote_per_fill'=>$policy->max_quote_per_fill, 'created_at'=>now(), 'updated_at'=>now(),
            ]);
            return $new;
        });
    }

    public function apply(string $expected): array
    {
        $result = DB::transaction(function () use ($expected) {
            DB::statement('SELECT pg_advisory_xact_lock(8192031)');
            $old = Market::whereName('USDC-USDT')->firstOrFail();
            DB::statement('SELECT pg_advisory_xact_lock(8192026, ?)', [$old->id]);
            $before = $this->snapshot();
            if (!hash_equals($before['sha256'], $expected)) throw new \RuntimeException('STABLECOIN_CUTOVER_STATE_CHANGED');
            $new = Market::whereName('USDT-USDC')->firstOrFail();
            if (!StablecoinOrientation::inverse($new)) throw new \RuntimeException('STABLECOIN_TARGET_IDENTITY_CONFLICT');
            if (!$old->status && $new->status && $new->trade_status) return ['before'=>$before, 'after'=>$before, 'cancelled'=>0];
            $cached = Cache::get('markets_liquidity.USDT-USDC.executable');
            if (!$cached || ($cached['received_at']??0) < time()-20 || ($cached['received_at']??0) > time()+5) {
                throw new \RuntimeException('STABLECOIN_FRESH_REFERENCE_REQUIRED');
            }
            $new->update(['status'=>true, 'trade_status'=>true]);
            $book = app(FundedLiquidity::class)->reference($new);
            if (!$book['bids'] || !$book['asks']) throw new \RuntimeException('STABLECOIN_REFERENCE_INVALID');
            $orders = Order::where('market_id', $old->id)->get();
            foreach ($orders as $order) {
                if ($order->liquidity_id || !$order->user_id) throw new \RuntimeException('STABLECOIN_EXTERNAL_ORDER_REQUIRES_RECONCILIATION');
                $request = request(); $had = $request->exists('uuid'); $previous = $request->input('uuid');
                try {
                    $request->merge(['uuid'=>$order->id]);
                    if (!(new \App\Repositories\Order\OrderRepository())->cancel()) throw new \RuntimeException('STABLECOIN_CANCEL_FAILED');
                } finally {
                    if ($had) $request->merge(['uuid'=>$previous]); else $request->request->remove('uuid');
                }
            }
            $old->update(['status'=>false, 'trade_status'=>false, 'buy_order_status'=>false, 'sell_order_status'=>false, 'liq'=>false]);
            return ['before'=>$before, 'after'=>$this->snapshot(), 'cancelled'=>$orders->count()];
        });
        (new MarketService())->updateMarketsInfoCache();
        Cache::forget('markets:all:active:public');
        Cache::forget('markets:all:active:dashboard');
        return $result;
    }
}
