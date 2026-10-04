<?php

namespace App\Services\Market;

use App\Models\{Currency\Currency, Market\Market};
use Illuminate\Support\Facades\{Cache, DB};

/** Configuration only: no product issuance, balance changes, network, or deposit channel. */
class HongKongProductListing
{
    public function apply(string $symbol, ?bool $trading = null): Market
    {
        $definition = config('hk-price-products.assets.'.$symbol);
        if (!$definition) throw new \RuntimeException('hk_product_not_registered');
        return DB::transaction(function () use ($symbol, $definition, $trading) {
            DB::statement('SELECT pg_advisory_xact_lock(8192030)');
            $currency = Currency::withTrashed()->where('symbol',$symbol)->first();
            if ($currency) {
                if ($currency->trashed() || !HongKongPriceProduct::isCurrency($currency)
                    || $currency->bep_contract || $currency->contract || $currency->trc_contract || $currency->sol_contract
                    || $currency->matic_contract || $currency->xlayer_contract || $currency->custom_contract
                    || $currency->deposit_status || $currency->withdraw_status || $currency->networks()->exists())
                    throw new \RuntimeException('hk_existing_asset_identity_conflict');
                app(HongKongPriceProduct::class)->definition(AssetProfile::presentation($currency));
            } else {
                $currency = Currency::withoutEvents(fn()=>Currency::forceCreate([
                    'symbol'=>$symbol,'name'=>$definition['name'],'type'=>'coin','is_token'=>false,
                    'decimals'=>8,'status'=>true,'deposit_status'=>false,'withdraw_status'=>false,
                    'rate'=>'0','asset_category'=>'stock','asset_issuer'=>'Deepro',
                    'asset_unit'=>'product_unit','asset_reference'=>$definition,
                    'asset_display_enabled'=>true,'asset_chart_interval'=>'1d',
                ]));
            }
            $quote = Currency::where('symbol','USDT')->sole();
            $market = Market::where('name',$symbol.'-USDT')->first();
            if ($market && ((int)$market->base_currency_id !== $currency->id || (int)$market->quote_currency_id !== $quote->id))
                throw new \RuntimeException('hk_existing_market_identity_conflict');
            if (!$market) $market = Market::forceCreate([
                'name'=>$symbol.'-USDT','base_currency_id'=>$currency->id,'quote_currency_id'=>$quote->id,
                'base_precision'=>8,'quote_precision'=>6,'quote_ticker_size'=>'0.000001',
                'status'=>true,'trade_status'=>false,'buy_order_status'=>true,'sell_order_status'=>true,
                'cancel_order_status'=>true,'chart_source'=>'hk-reference','chart_default_resolution'=>'1D',
                'switch_chart'=>false,'liq'=>true,'custom_liquidity'=>false,'has_futures'=>false,'has_options'=>false,
            ]);
            // Listing is configuration, not execution: it may be prepared outside a session.
            if ($trading === true && ($reason=app(HongKongPriceProduct::class)->tradingReason($market,true,false,false)))
                throw new \RuntimeException($reason);
            if ($trading !== null) {
                $market->trade_status=$trading;
                if ($trading) $market->liq=true;
                $market->save();
            }
            // Use the same existing policy/default USDT credit pool as other listed markets.
            // Re-listing must not reset an explicitly selected execution policy.
            // Normal trading requires zero-balance ledger wallets, not an issuance of product units.
            DB::table('users')->select('id')->whereNotExists(function ($query) use ($currency) {
                $query->selectRaw('1')->from('wallets')->whereColumn('wallets.user_id','users.id')->where('currency_id',$currency->id);
            })->orderBy('id')->chunkById(100,function ($users) use ($currency) {
                foreach ($users as $user) \App\Models\Wallet\Wallet::firstOrCreate(['user_id'=>$user->id,'currency_id'=>$currency->id]);
            });
            Cache::forget('stock.catalog.assets.v1');
            DB::afterCommit(fn()=>(new MarketService())->updateMarketsInfoCache());
            return $market;
        });
    }
}
