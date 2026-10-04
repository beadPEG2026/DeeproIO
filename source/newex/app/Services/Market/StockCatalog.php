<?php
namespace App\Services\Market;
use Illuminate\Support\Facades\DB;
final class StockCatalog {
 public function assets(bool $includeHidden=false): array {
  $symbols=array_column(\App\Services\Market\StockAssets::all(),'symbol');
  $currencies=DB::table('currencies')->whereIn('symbol',$symbols)->get()->keyBy('symbol');
  $markets=DB::table('markets')->whereIn('name',array_map(fn($s)=>$s.'-USDT',$symbols))->get()->keyBy('name');
  $ready=DB::table('stock_liquidity_books')->whereIn('market_id',$markets->pluck('id'))->where('expires_at','>',now())->pluck('market_id')->flip();
  $result=[];
  foreach(\App\Services\Market\StockAssets::all() as $asset){
   $currency=$currencies->get($asset['symbol']);$market=$markets->get($asset['symbol'].'-USDT');
   $asset['currencyId']=$currency->id??null;
   $asset['marketId']=$market->id??null;
   $asset['displayEnabled']=(bool)($currency->asset_display_enabled??true);
   $asset['defaultInterval']=$currency->asset_chart_interval??'1h';
   $reference=HongKongPriceProduct::isAsset($asset);
   $matching=$currency && ($reference || strtolower((string)$currency->bep_contract)===strtolower($asset['contract']));
   $asset['depositEnabled']=!$reference && $matching && $currency->status && $currency->deposit_status;
   $asset['withdrawEnabled']=!$reference && $matching && $currency->status && $currency->withdraw_status;
   $asset['tradeEnabled']=$matching && $market && $market->status && $market->trade_status && (int)$market->base_currency_id===(int)$currency->id;
   $asset['depthReady']=$market && $ready->has($market->id);
   if($includeHidden||$asset['displayEnabled'])$result[]=$asset;
  }
  return $result;
 }
}
