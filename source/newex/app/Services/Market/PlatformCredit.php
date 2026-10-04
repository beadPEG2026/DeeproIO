<?php
namespace App\Services\Market;

use App\Models\{Market\Market,Order\Order,Order\OrderHistory,Wallet\Wallet};
use App\Services\Order\SpotFunding;
use Illuminate\Support\Facades\DB;

/** Signed platform positions offset customer claims. Credit is not a wallet reserve. */
final class PlatformCredit
{
    public const POOL_ID = 1;
    public function pool(bool $lock=false): object
    {
        $q=DB::table('platform_credit_pools')->where('id',self::POOL_ID);
        return ($lock?$q->lockForUpdate():$q)->firstOrFail();
    }
    public function positions(): array
    {
        return DB::table('platform_credit_positions')->where('pool_id',self::POOL_ID)->get()->keyBy('currency_id')->map(fn($p)=>(array)$p)->all();
    }
    public function used(string $cash,array $positions): string
    {
        $used=bccomp($cash,'0',18)<0?bcsub('0',$cash,18):'0';
        foreach ($positions as $p) if (bccomp($p['quantity'],'0',18)<0) {
            $used=bcadd($used,bcmul(bcsub('0',$p['quantity'],18),$this->max($p['average_price'],$p['risk_price']),18),18);
        }
        return $used;
    }
    private function max(string $a,string $b): string {return bccomp($a,$b,18)>0?$a:$b;}
    private function min(string $a,string $b): string {return bccomp($a,$b,18)<0?$a:$b;}
    private function abs(string $n): string {return bccomp($n,'0',18)<0?bcsub('0',$n,18):$n;}

    /** Weighted entry cost; a closed position releases exposure but never erases realized losses. */
    public function project(string $cash,array $positions,Market $m,string $side,string $quantity,string $price): array
    {
        $inverse=StablecoinOrientation::inverse($m);
        $cashCost=$inverse?$quantity:bcmul($quantity,$price,18);
        $id=$inverse?$m->quote_currency_id:$m->base_currency_id;
        if ($inverse) {
            $quantity=bcmul($quantity,$price,18);
            $price=StablecoinOrientation::reciprocal($price);
            $side=$side==='buy'?'sell':'buy';
        }
        $p=$positions[$id]??['quantity'=>'0','average_price'=>'0','risk_price'=>'0','market_id'=>$m->id,'currency_id'=>$id];
        $old=$p['quantity'];$delta=$side==='buy'?$quantity:bcsub('0',$quantity,18);
        $new=bcadd($old,$delta,18);$pnl='0';
        if (bccomp($old,'0',18)===0 || (bccomp($old,'0',18)>0)===(bccomp($delta,'0',18)>0)) {
            $cost=bcadd(bcmul($this->abs($old),$p['average_price'],18),$cashCost,18);
            $average=bcdiv($cost,$this->abs($new),18);
        } else {
            $closed=$this->min($quantity,$this->abs($old));
            $spread=bccomp($old,'0',18)>0?bcsub($price,$p['average_price'],18):bcsub($p['average_price'],$price,18);
            $pnl=bcmul($closed,$spread,18);
            $average=bccomp($new,'0',18)===0?'0':((bccomp($old,'0',18)>0)===(bccomp($new,'0',18)>0)?$p['average_price']:$price);
        }
        $p['quantity']=$new;$p['average_price']=$average;
        $p['risk_price']=bccomp($new,'0',18)<0?$this->max($price,$p['risk_price']):'0';
        $positions[$id]=$p;
        $p['market_id']=$m->id;$positions[$id]=$p;
        $cost=$cashCost;
        $cash=$side==='buy'?bcsub($cash,$cost,18):bcadd($cash,$cost,18);
        return ['cash'=>$cash,'positions'=>$positions,'pnl'=>$pnl,'used'=>$this->used($cash,$positions)];
    }

    // Mark all outstanding shorts from fresh asks. Missing marks stop additional risk,
    // while orders that strictly reduce already booked exposure remain possible.
    private function marked(array $positions,bool $displayClosed=false): array
    {
        $fresh=true;
        foreach ($positions as &$p) if (bccomp($p['quantity'],'0',18)<0) {
            $m=Market::find($p['market_id']);
            if ($m && $m->name==='USDC-USDT' && !$m->status) {
                $m=Market::whereName('USDT-USDC')->where('status',true)->first();
            }
            // Closed HK marks value existing risk only. They never authorize an HK fill,
            // and max() below cannot lower the previously booked short risk price.
            $closedHk=$m && app(HongKongPlatformQuote::class)->enabled($m) && !app(HongKongPriceProduct::class)->sessionOpen();
            $book=$m?app(FundedLiquidity::class)->reference($m,$displayClosed || $closedHk):[];
            $price=$book['asks'][0]['price']??null;
            if ($m && StablecoinOrientation::inverse($m)) {
                $bid=$book['bids'][0]['price']??null;
                $price=$bid!==null?StablecoinOrientation::reciprocal($bid):null;
            }
            if ($price===null) {$fresh=false;continue;}
            $p['risk_price']=$this->max($p['risk_price'],$price);
        }
        return [$positions,$fresh];
    }
    private function allowed(array $next,string $before,string $limit,bool $fresh): bool
    {
        return ($fresh && bccomp($next['used'],$limit,18)<=0) || bccomp($next['used'],$before,18)<0;
    }
    private function quantity(string $max,string $cash,array $positions,Market $m,string $side,string $price,string $limit,bool $fresh): string
    {
        $precision=min(18,(int)$m->base_precision);$max=bcadd($max,'0',$precision);
        $before=$this->used($cash,$positions);
        if (bccomp($max,'0',18)<=0)return '0';
        if ($this->allowed($this->project($cash,$positions,$m,$side,$max,$price),$before,$limit,$fresh))return $max;
        $lo='0';$hi=$max;
        // Exposure is piecewise convex; zero-to-maximum search finds the affordable end.
        for ($i=0;$i<100;$i++) {
            $mid=bcdiv(bcadd($lo,$hi,18),'2',$precision);
            if (bccomp($mid,$lo,18)===0 || bccomp($mid,$hi,18)===0)break;
            if ($this->allowed($this->project($cash,$positions,$m,$side,$mid,$price),$before,$limit,$fresh))$lo=$mid;else $hi=$mid;
        }
        return $lo;
    }
    public function levels(Market $m,object $policy,string $domain,bool $displayClosed=false): array
    {
        $out=['asks'=>[],'bids'=>[]];$pool=$this->pool();
        if (!$pool->enabled || $domain!=='real' || ($m->quoteCurrency->symbol!=='USDT' && !StablecoinOrientation::inverse($m)))return $out;
        [$positions,$fresh]=$this->marked($this->positions(),$displayClosed);
        $book=app(FundedLiquidity::class)->reference($m,$displayClosed);
        foreach (['asks'=>'sell','bids'=>'buy'] as $s=>$side) {
            $cash=$pool->quote_position;$ps=$positions;
            foreach ($book[$s] as $r) {
                $max=$this->min($r['quantity'],(StablecoinOrientation::inverse($m)?(string)$policy->max_quote_per_fill:bcdiv((string)$policy->max_quote_per_fill,$r['price'],18)));
                $q=$this->quantity($max,$cash,$ps,$m,$side,$r['price'],$pool->credit_limit,$fresh);
                if (bccomp($q,'0',18)<=0)continue;
                $out[$s][]=['price'=>$r['price'],'quantity'=>$q];
                $next=$this->project($cash,$ps,$m,$side,$q,$r['price']);$cash=$next['cash'];$ps=$next['positions'];
            }
        }
        return $out;
    }
    public function reserve(Order $t,Order $quote,string $needed): ?Order
    {
        if (DB::transactionLevel()===0)throw new \LogicException('CREDIT_TRANSACTION_REQUIRED');
        $this->pool(true); // One row lock serializes every market sharing this credit line.
        if (SpotFunding::domain($t)!=='real')return null;
        $fresh=app(FundedLiquidity::class)->cursor($t);
        if (!$fresh || bccomp($fresh->price,$quote->price,18)!==0)return null;
        $q=bcadd($this->min($needed,$fresh->quantity),'0',min(18,(int)$t->market->base_precision));
        if (bccomp($q,'0',18)<=0)return null;
        $data=['id'=>generate_uuid(),'user_id'=>null,'platform_pool_id'=>self::POOL_ID,'market_id'=>$t->market_id,'type'=>'limit','side'=>$quote->side,'initial_quantity'=>$q,'quantity'=>$q,'initial_quote_quantity'=>'0','quote_quantity'=>'0','price'=>$quote->price,'fee'=>'0','fee_rate'=>'0','base_currency_id'=>$t->base_currency_id,'quote_currency_id'=>$t->quote_currency_id,'settlement_domain'=>'real','created_at'=>now(),'updated_at'=>now()];
        Order::insert($data);OrderHistory::insert($data);
        app(FundedLiquidity::class)->consumeReference($t,$quote,$q);
        DB::table('platform_credit_fills')->insert(['pool_id'=>self::POOL_ID,'market_id'=>$t->market_id,'taker_order_id'=>$t->id,'maker_order_id'=>$data['id'],'user_id'=>$t->user_id,'maker_side'=>$quote->side,'price'=>$quote->price,'quantity'=>$q,'created_at'=>now(),'updated_at'=>now()]);
        return Order::findOrFail($data['id']);
    }
    public function settle(Order $t,Order $maker,string $base,string $quote,string $fee): void
    {
        if (DB::transactionLevel()===0 || (int)$maker->platform_pool_id!==self::POOL_ID || $maker->user_id!==null || SpotFunding::domain($t)!=='real')throw new \RuntimeException('CREDIT_IDENTITY_INVALID');
        $pool=$this->pool(true);
        $fill=DB::table('platform_credit_fills')->where('maker_order_id',$maker->id)->lockForUpdate()->first();
        if (!$fill || $fill->status!=='reserved' || $fill->taker_order_id!==$t->id || bccomp($fill->quantity,$base,18)!==0 || bccomp(bcmul($base,$maker->price,18),$quote,18)!==0)throw new \RuntimeException('CREDIT_RESERVATION_INVALID');
        [$positions,$fresh]=$this->marked($this->positions());
        $next=$this->project($pool->quote_position,$positions,$t->market,$maker->side,$base,$maker->price);
        if (!$pool->enabled || !$this->allowed($next,$this->used($pool->quote_position,$positions),$pool->credit_limit,$fresh))throw new \RuntimeException('CREDIT_LIMIT_EXCEEDED');
        $buy=$t->side==='buy';$debit=$buy?bcadd($quote,$fee,18):$base;$credit=$buy?$base:bcsub($quote,$fee,18);
        if (bccomp($credit,'0',18)<0)throw new \RuntimeException('CREDIT_NEGATIVE_SETTLEMENT');
        $wallets=Wallet::where('user_id',$t->user_id)->whereIn('currency_id',[$t->base_currency_id,$t->quote_currency_id])->orderBy('id')->lockForUpdate()->get()->keyBy('currency_id');
        $source=$wallets->get($buy?$t->quote_currency_id:$t->base_currency_id);$dest=$wallets->get($buy?$t->base_currency_id:$t->quote_currency_id);
        if (!$source || !$dest || bccomp((string)$source->balance_in_order,$debit,18)<0)throw new \RuntimeException('CREDIT_CUSTOMER_RESERVE_MISSING');
        DB::table('wallets')->where('id',$source->id)->update(['balance_in_order'=>bcsub($source->balance_in_order,$debit,18),'updated_at'=>now()]);
        DB::table('wallets')->where('id',$dest->id)->update(['balance_in_trade'=>bcadd($dest->balance_in_trade,$credit,18),'updated_at'=>now()]);
        $positionCurrency=StablecoinOrientation::inverse($t->market)?$t->quote_currency_id:$t->base_currency_id;
        $p=$next['positions'][$positionCurrency];
        DB::table('platform_credit_positions')->updateOrInsert(['pool_id'=>self::POOL_ID,'currency_id'=>$positionCurrency],['market_id'=>$t->market_id,'quantity'=>$p['quantity'],'average_price'=>$p['average_price'],'risk_price'=>$p['risk_price'],'created_at'=>$p['created_at']??now(),'updated_at'=>now()]);
        foreach ($positions as $id=>$marked) if ($id!=$positionCurrency)DB::table('platform_credit_positions')->where('pool_id',self::POOL_ID)->where('currency_id',$id)->update(['risk_price'=>$marked['risk_price']]);
        DB::table('platform_credit_pools')->where('id',self::POOL_ID)->update(['quote_position'=>$next['cash'],'realized_pnl'=>bcadd($pool->realized_pnl,$next['pnl'],18),'updated_at'=>now()]);
        $baseDelta=$buy?bcsub('0',$base,18):$base;$quoteDelta=$buy?$quote:bcsub('0',$quote,18);
        foreach ([[$t->base_currency_id,$baseDelta,bcsub('0',$baseDelta,18),'0'],[$t->quote_currency_id,$quoteDelta,bcsub(bcsub('0',$quoteDelta,18),$fee,18),$fee]] as [$id,$platform,$user,$fees]) {
            DB::table('platform_credit_entries')->insert(['fill_id'=>$fill->id,'currency_id'=>$id,'user_wallet_id'=>$wallets[$id]->id,'platform_delta'=>$platform,'user_delta'=>$user,'fee_delta'=>$fees,'created_at'=>now()]);
        }
        DB::table('platform_credit_fills')->where('id',$fill->id)->update(['status'=>'settled','credit_after'=>$next['used'],'realized_pnl'=>$next['pnl'],'updated_at'=>now()]);
    }
    public function summary(): array
    {
        $pool=$this->pool();[$positions,$fresh]=$this->marked($this->positions());$used=$this->used($pool->quote_position,$positions);
        return ['id'=>$pool->id,'enabled'=>(bool)$pool->enabled,'default_for_usdt'=>(bool)$pool->default_for_usdt,'credit_limit'=>$pool->credit_limit,'used'=>$used,'available'=>$this->max('0',bcsub($pool->credit_limit,$used,18)),'quote_position'=>$pool->quote_position,'realized_pnl'=>$pool->realized_pnl,'marks_fresh'=>$fresh,'positions'=>array_values($positions),'fills'=>DB::table('platform_credit_fills')->orderByDesc('id')->limit(30)->get(),'audits'=>DB::table('platform_credit_audits')->orderByDesc('id')->limit(20)->get()];
    }
}
