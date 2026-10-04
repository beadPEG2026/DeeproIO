<?php
namespace App\Services\Market;
use App\Models\{Market\Market,Order\Order,Order\OrderHistory,Wallet\Wallet};
use App\Services\Order\SpotFunding;
use Illuminate\Support\Facades\{DB,Cache};
/** Public prices need an explicit execution policy and a persisted counterpart. */
final class FundedLiquidity {
 public function policy(Market $market):?object {
  $policy=DB::table('market_execution_policies')->where('market_id',$market->id)->first();
  if(!$policy&&$market->quoteCurrency->symbol==='USDT'&&app(PlatformCredit::class)->pool()->default_for_usdt)$policy=(object)['mode'=>'platform_credit','maker_user_id'=>null,'max_quote_per_fill'=>'1000','inherited'=>true];
  return $policy;
 }
 public function reference(Market $m,bool $displayClosed=false):array {
  if(!$m->liq||!$m->status||!$m->trade_status)return ['bids'=>[],'asks'=>[]];
  $hk=app(HongKongPlatformQuote::class)->enabled($m);
  if(StockAssets::supports($m->name)&&!$hk)return app(StockLiquidity::class)->levels($m);
  $snapshotData=$hk?app(HongKongPlatformQuote::class)->cached($m,$displayClosed):Cache::get("markets_liquidity.{$m->name}.executable");
  if($hk&&!$snapshotData)return ['bids'=>[],'asks'=>[]];
  $at=$snapshotData["received_at"]??Cache::get("markets_liquidity.{$m->name}.received_at",0);
  if(!is_numeric($at)||(!($hk&&$displayClosed&&!app(HongKongPriceProduct::class)->sessionOpen())&&$at<now()->timestamp-20)||$at>now()->timestamp+5)return ['bids'=>[],'asks'=>[]];
  $book=[];
  foreach(['bids','asks']as$s){$rows=$snapshotData[$s]??Cache::get("markets_liquidity.{$m->name}.{$s}",[]);$rows=collect($rows)->take(20)->all();$book[$s]=[];
   foreach($rows as$r){if(!is_array($r)||!preg_match('/^\d{1,16}(?:\.\d{1,18})?$/D',(string)($r['price']??''))||!preg_match('/^\d{1,16}(?:\.\d{1,18})?$/D',(string)($r['quantity']??''))||bccomp((string)$r['price'],'0',18)<=0||bccomp((string)$r['quantity'],'0',18)<=0)return ['bids'=>[],'asks'=>[]];$book[$s][]=['price'=>(string)$r['price'],'quantity'=>(string)$r['quantity']];}
   usort($book[$s],fn($a,$b)=>($s==='asks'?1:-1)*bccomp($a['price'],$b['price'],18));
  }
  if($book['bids']&&$book['asks']&&bccomp($book['bids'][0]['price'],$book['asks'][0]['price'],18)>=0)return ['bids'=>[],'asks'=>[]];
  $snapshot=$snapshotData["snapshot"]??$this->snapshot($m);
  $used=DB::table('platform_reference_consumption')->where('market_id',$m->id)->where('snapshot',$snapshot)->get();
  foreach(['asks','bids'] as $s){foreach($book[$s] as &$r){$n=$used->first(fn($v)=>$v->side===$s&&bccomp($v->price,$r['price'],18)===0);if($n)$r['quantity']=bcsub($r['quantity'],$n->quantity,18);}unset($r);$book[$s]=array_values(array_filter($book[$s],fn($r)=>bccomp($r['quantity'],'0',18)>0));}
  return $book;
 }
 public function snapshot(Market $m):string {$data=Cache::get("markets_liquidity.{$m->name}.executable");if($data)return $data["snapshot"];return hash('sha256',json_encode([Cache::get("markets_liquidity.{$m->name}.bids"),Cache::get("markets_liquidity.{$m->name}.asks")]));}
 public function consumeReference(Order $t,Order $quote,string $q):void {
  if(StockAssets::supports($t->market->name)&&!app(HongKongPlatformQuote::class)->enabled($t->market)){app(StockLiquidity::class)->consume($t,$quote,$q);return;}
  $snapshot=$this->snapshot($t->market);$side=$quote->side==='sell'?'asks':'bids';
  $key=['market_id'=>$t->market_id,'snapshot'=>$snapshot,'side'=>$side,'price'=>$quote->price];
  $old=DB::table('platform_reference_consumption')->where($key)->first();
  DB::table('platform_reference_consumption')->where('market_id',$t->market_id)->where('snapshot','!=',$snapshot)->delete();
  DB::table('platform_reference_consumption')->updateOrInsert($key,['quantity'=>bcadd($old->quantity??'0',$q,18),'updated_at'=>now()]);
 }
 public function levels(Market $m,string $domain='real',?int $excludeUser=null,bool $displayClosed=false):array {
  $empty=['bids'=>[],'asks'=>[]];$p=$this->policy($m);
  if($p&&$p->mode==='platform_credit')return app(PlatformCredit::class)->levels($m,$p,$domain,$displayClosed);
  if(!$p||$p->mode!=='platform_maker'||!$p->maker_user_id||$domain!=='real'||(int)$p->maker_user_id===$excludeUser)return $empty;
  $maker=\App\Models\User\User::find($p->maker_user_id);
  if(!$maker||$maker->deactivated||$maker->is_xn||$maker->is_xm)return $empty;
  $wallets=Wallet::where('user_id',$p->maker_user_id)->whereIn('currency_id',[$m->base_currency_id,$m->quote_currency_id])->get()->keyBy('currency_id');
  if(count($wallets)!==2)return $empty;
  // A maker with virtual balances is not eligible for real quote execution.
  foreach($wallets as$w)if(bccomp((string)($w->balance_in_virtual_trade??0),'0',18)>0||bccomp((string)($w->balance_in_virtual_order??0),'0',18)>0)return $empty;
  $book=$this->reference($m,$displayClosed);$out=$empty;
  foreach(['asks','bids']as$s){$budget=(string)$wallets[$s==='asks'?$m->base_currency_id:$m->quote_currency_id]->balance_in_trade;
   foreach($book[$s]as$r){$q=$s==='asks'?$budget:bcdiv($budget,$r['price'],18);$cap=bcdiv((string)$p->max_quote_per_fill,$r['price'],18);$q=$this->minimum($r['quantity'],$q,$cap);$q=bcadd($q,'0',min(18,(int)$m->base_precision));if(bccomp($q,'0',18)<=0)continue;
    $out[$s][]=['price'=>$r['price'],'quantity'=>$q];$budget=bcsub($budget,$s==='asks'?$q:bcmul($q,$r['price'],18),18);
   }
  }return $out;
 }
 public function cursor(Order $t):?Order {
  foreach($this->levels($t->market,SpotFunding::domain($t),(int)$t->user_id)[$t->side==='buy'?'asks':'bids']as$r){
   if(order_is_limit($t->type)&&($t->side==='buy'?bccomp($r['price'],$t->price,18)>0:bccomp($r['price'],$t->price,18)<0))break;
   $o=new Order();$o->price=$r['price'];$o->quantity=$r['quantity'];$o->side=$t->side==='buy'?'sell':'buy';$o->type='limit';$o->fee_rate=0;return $o;
  }return null;
 }
 public function reserve(Order $t,Order $quote,string $needed):?Order {
  if(DB::transactionLevel()===0)throw new \LogicException('FUNDED_FILL_REQUIRES_TRANSACTION');
  $p=DB::table('market_execution_policies')->where('market_id',$t->market_id)->lockForUpdate()->first();
  if(!$p)$p=$this->policy($t->market);
  if($p&&$p->mode==='platform_credit')return app(PlatformCredit::class)->reserve($t,$quote,$needed);
  if(!$p||$p->mode!=='platform_maker'||!$p->maker_user_id||(int)$p->maker_user_id===(int)$t->user_id||SpotFunding::domain($t)!=='real')return null;
  $m=$t->market;$wallets=Wallet::where('user_id',$p->maker_user_id)->whereIn('currency_id',[$m->base_currency_id,$m->quote_currency_id])->orderBy('id')->lockForUpdate()->get()->keyBy('currency_id');
  if(count($wallets)!==2)return null;
  $fresh=$this->cursor($t);if(!$fresh||bccomp($fresh->price,$quote->price,18)!==0)return null;
  $q=$this->minimum($needed,(string)$fresh->quantity);$q=bcadd($q,'0',min(18,(int)$m->base_precision));if(bccomp($q,'0',18)<=0)return null;
  $funding=$wallets[$quote->side==='buy'?$m->quote_currency_id:$m->base_currency_id];$cost=$quote->side==='buy'?bcmul($q,$quote->price,18):$q;
  $n=DB::update('UPDATE wallets SET balance_in_trade=balance_in_trade-?,balance_in_order=balance_in_order+?,updated_at=? WHERE id=? AND balance_in_trade>=?',[$cost,$cost,now(),$funding->id,$cost]);if($n!==1)throw new \RuntimeException('MAKER_INVENTORY_CHANGED');
  $data=['id'=>generate_uuid(),'user_id'=>$p->maker_user_id,'market_id'=>$m->id,'type'=>'limit','side'=>$quote->side,'initial_quantity'=>$q,'quantity'=>$q,'initial_quote_quantity'=>'0','quote_quantity'=>'0','price'=>$quote->price,'fee'=>'0','fee_rate'=>'0','base_currency_id'=>$m->base_currency_id,'quote_currency_id'=>$m->quote_currency_id,'settlement_domain'=>'real','created_at'=>now(),'updated_at'=>now()];
  Order::insert($data);OrderHistory::insert($data);
  $this->consumeReference($t,$quote,$q);
  DB::table('funded_liquidity_fills')->insert(['market_id'=>$m->id,'taker_order_id'=>$t->id,'maker_order_id'=>$data['id'],'maker_user_id'=>$p->maker_user_id,'domain'=>'real','source'=>StockAssets::supports($m->name)?'stock_reference':'public_reference','price'=>$quote->price,'quantity'=>$q,'reserved'=>$cost,'created_at'=>now()]);
  return Order::findOrFail($data['id']);
 }
 public function publicBook(Market $m):array {
  if(DB::transactionLevel()===0 && app(HongKongPlatformQuote::class)->enabled($m)) {try {app(HongKongPlatformQuote::class)->refresh($m);} catch(\Throwable $e) {\Illuminate\Support\Facades\Log::warning('HK platform quote unavailable',['market'=>$m->name,'reason'=>$e->getMessage()]);}}
  $book=$this->levels($m,'real',null,true);$repo=new \App\Repositories\Order\OrderRepository();
  foreach(['bids'=>'buy','asks'=>'sell'] as$key=>$side){
   $rows=collect($book[$key])->merge($repo->get($m->name,$side)->map(fn($o)=>['price'=>$o->price,'quantity'=>$o->quantity]));
   $rows=$rows->groupBy(fn($r)=>bcadd((string)$r['price'],'0',18))->map(fn($g)=>['price'=>$g->first()['price'],'quantity'=>$g->reduce(fn($n,$r)=>bcadd($n,(string)$r['quantity'],18),'0')]);
   $book[$key]=($key==='bids'?$rows->sortByDesc('price'):$rows->sortBy('price'))->values();
  }return $book;
 }
 private function minimum(string ...$v):string {return array_reduce($v,fn($a,$b)=>$a===null||bccomp($b,$a,18)<0?$b:$a);}
}
