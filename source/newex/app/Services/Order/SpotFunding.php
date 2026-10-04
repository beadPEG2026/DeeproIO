<?php
namespace App\Services\Order;
use App\Models\Order\Order;
use App\Models\Wallet\Wallet;
final class SpotFunding {
 public static function domain(Order $order):string {
  if(in_array($order->settlement_domain,['real','virtual'],true))return $order->settlement_domain;
  $w=$order->side==='buy'?$order->walletQuote:$order->walletBase;
  if(!$w)return 'unknown';
  $r=bccomp((string)$w->balance_in_order,'0',18)>0;$v=bccomp((string)($w->balance_in_virtual_order??0),'0',18)>0;
  return $r===$v?'unknown':($v?'virtual':'real');
 }
 public static function requestedDomain(int $userId,int $currencyId):string {
  $w=Wallet::where('user_id',$userId)->where('currency_id',$currencyId)->first();
  return $w&&bccomp((string)($w->balance_in_virtual_trade??0),'0',18)>0?'virtual':'real';
 }
}
