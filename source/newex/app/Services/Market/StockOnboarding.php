<?php
namespace App\Services\Market;
use App\Models\Market\Market;
use App\Models\Currency\Currency;
use Illuminate\Support\Facades\{DB,Cache};
final class StockOnboarding {
    public function apply(string $contract, ?string $name=null, ?string $assetType=null): array {
        // Complete remote verification before making any configuration changes.
        $verified=app(StockDataClient::class)->call('/v1/stocks/inspect',['contract'=>$contract])['data'];
        $a=$verified['asset'];
        if (strtolower($a['contract'])!==strtolower($contract) || $a['chainId']!==56) throw new \RuntimeException('stock_identity_mismatch');
        if (($a['listingTemplate']??null)==='binance-bstocks-v1') {
            $chain=app(\App\Services\Custody\CustodyBridge::class)->call('bnb','balance',[
                'sender'=>trim((string)setting('bnb.wallet')), 'contract'=>$a['contract'],
            ]);
            if (($chain['success']??false)!==true || ($chain['decimals']??null)!==$a['decimals']) throw new \RuntimeException('stock_chain_identity_mismatch');
            $verified['checks']['chain_decimals']=true;
        }
        if ($name) $a['name']=$name;
        if ($assetType!==null) {if (!in_array($assetType,['stock','etf'],true)) throw new \InvalidArgumentException('invalid_stock_type');$a['assetType']=$assetType;}
        $a['validation']=$verified['checks'];
        return DB::transaction(function() use($a,$verified,$assetType) {
            DB::statement('SELECT pg_advisory_xact_lock(8192025)');
            $old=StockAssets::find($a['symbol']);
            if ($old && strtolower($old['contract'])!==$a['contract']) throw new \RuntimeException('stock_contract_conflict');
            $quote=DB::table('currencies')->where('symbol','USDT')->sole();
            $network=DB::table('networks')->where('slug','bep20')->sole();
            $currency=DB::table('currencies')->where('symbol',$a['symbol'])->first();
            if ($currency && (strtolower((string)$currency->bep_contract)!==$a['contract'] || (int)$currency->decimals!==(int)$a['decimals'])) throw new \RuntimeException('existing_currency_identity_conflict');
            $id=$currency->id ?? DB::table('currencies')->insertGetId([
                'name'=>$a['name'],'symbol'=>$a['symbol'],'type'=>'coin','is_token'=>true,'decimals'=>$a['decimals'],'bep_contract'=>$a['contract'],
                'status'=>true,'deposit_status'=>true,'withdraw_status'=>true,'rate'=>'0','txn_explorer'=>'https://bscscan.com/tx/%txid%', 'created_at'=>now(),'updated_at'=>now(),
            ]);
            // Existing users need zero-balance ledger wallets for a newly listed asset.
            // Creating these records does not credit funds or create blockchain addresses.
            DB::table('users')->select('id')->whereNotExists(function ($query) use ($id) {
                $query->selectRaw('1')->from('wallets')->whereColumn('wallets.user_id','users.id')->where('currency_id',$id);
            })->orderBy('id')->chunkById(100,function ($users) use ($id) {
                foreach ($users as $user) \App\Models\Wallet\Wallet::firstOrCreate(['user_id'=>$user->id,'currency_id'=>$id]);
            });
            if (!DB::table('currency_networks')->where('currency_id',$id)->where('network_id',$network->id)->exists()) DB::table('currency_networks')->insert(['currency_id'=>$id,'network_id'=>$network->id]);
            $model=Currency::findOrFail($id);
            // Shared currency metadata is authoritative. Rechecking does not reset operator preferences.
            $model->forceFill([
                'asset_category'=>$assetType??$old['assetType']??$a['assetType'],
                'asset_issuer'=>$model->asset_issuer??$a['issuer'],
                'asset_reference'=>AssetProfile::providerMetadata($a),
            ])->save();
            app(\App\Services\Custody\AssetAutomation::class)->draft($model);
            Cache::forget('stock.catalog.assets.v1');
            $market=Market::whereName($a['symbol'].'-USDT')->first();
            if ($market && ((int)$market->base_currency_id!==(int)$id || (int)$market->quote_currency_id!==(int)$quote->id)) throw new \RuntimeException('existing_market_identity_conflict');
            if (!$market) {
                $market=new Market();
                $market->forceFill(['name'=>$a['symbol'].'-USDT','base_currency_id'=>$id,'quote_currency_id'=>$quote->id,'base_precision'=>8,'quote_precision'=>2,
                    'status'=>true,'trade_status'=>true,'buy_order_status'=>true,'sell_order_status'=>true,'cancel_order_status'=>true,'chart_source'=>'ondo-reference','chart_default_resolution'=>'5','switch_chart'=>false,'has_futures'=>false,'has_options'=>false,'custom_liquidity'=>false]);
            }
            if ($market->custom_liquidity || $market->chart_symbol) throw new \RuntimeException('existing_custom_market_requires_review');
            $tick=collect($verified['depth']['filters']??[])->firstWhere('filterType','PRICE_FILTER')['tickSize']??null;
            if ($tick && preg_match('/^0?\.0*1(?:0*)$/',$tick)) {
                $decimal=strlen(rtrim(explode('.',$tick)[1],'0'));
                $market->quote_precision=$decimal; $market->quote_ticker_size=$tick;
            }
            $market->liq=true; $market->save();
            app(StockLiquidity::class)->store($market,$verified['depth']);
            DB::afterCommit(fn()=>(new MarketService())->updateMarketsInfoCache());
            return ['symbol'=>$a['symbol'],'market'=>$market->name,'checks'=>$verified['checks']+['market'=>true,'wallet_network'=>true], 'execution_mode'=>'platform_internal', 'chain_transfer_verified'=>false];
        });
    }
}
