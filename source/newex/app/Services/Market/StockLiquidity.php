<?php
namespace App\Services\Market;

use App\Models\Market\Market;
use App\Models\Order\Order;
use Illuminate\Support\Facades\DB;

/** Verified public quotes offered by Deepro's internal matching model; no upstream order is implied. */
final class StockLiquidity {
    public function refresh(Market $market): void {
        if (app(HongKongPlatformQuote::class)->enabled($market)) {app(HongKongPlatformQuote::class)->refresh($market); return;}
        if (HongKongPriceProduct::isMarket($market)) {
            $this->store($market,app(HongKongExecutionDepth::class)->fetch($market));
            return;
        }
        $book = app(StockDataClient::class)->call('/v1/stocks/depth', ['symbol'=>$market->baseCurrency->symbol])['data'];
        $this->store($market,$book);
    }
    public function validate(Market $market, array $book): void {
        $a = StockAssets::find($market->baseCurrency->symbol);
        if (HongKongPriceProduct::isMarket($market)) app(HongKongExecutionDepth::class)->validate($market,$book);
        else {
        $source=($a['marketSource']??null)==='binance-spot'?'binance-spot':'binance-alpha';
        if (!$a || ($book['symbol']??null)!==$a['symbol'] || (string)($book['chain_id']??'')!==(string)$a['chainId'] || strtolower($book['contract']??'')!==strtolower($a['contract']) || ($book['quote_currency']??'')!=='USDT'
            || ($book['quantity_unit']??'')!=='token' || ($book['denomination']??0)!=1 || ($book['mul_point']??0)!=1 || ($book['source']??'')!==$source
            || ($source==='binance-spot' && (($book['source_symbol']??null)!==($a['exchangeSymbol']??null) || ($book['event_time_kind']??null)!=='snapshot_received'))) throw new \RuntimeException('stock_depth_identity_or_units_invalid');
        }
        $received = strtotime($book['received_at']??'');
        if (!$received || $received < now()->timestamp-20 || $received > now()->timestamp+5 || ($book['event_time']??0)<(now()->timestamp-900)*1000 || ($book['event_time']??0)>(now()->timestamp+5)*1000 || !isset($book['last_update_id'])) throw new \RuntimeException('stock_depth_expired');
        foreach (['bids','asks'] as $side) {
            if (!isset($book[$side]) || !is_array($book[$side]) || count($book[$side])>20) throw new \RuntimeException('invalid_stock_depth');
            $previous=null;
            foreach ($book[$side] as $r) {
                if (!is_array($r) || count($r)!==2) throw new \RuntimeException('invalid_stock_level');
                foreach ($r as $v) if (!is_string($v) || !preg_match('/^\d{1,16}(?:\.\d{1,18})?$/D',$v) || bccomp($v,'0',18)<=0) throw new \RuntimeException('invalid_stock_level');
                if ($previous!==null && ($side==='bids' ? bccomp($r[0],$previous,18)>=0 : bccomp($r[0],$previous,18)<=0)) throw new \RuntimeException('unsorted_stock_depth');
                $previous=$r[0];
            }
        }
        if ($book['bids'] && $book['asks'] && bccomp($book['bids'][0][0],$book['asks'][0][0],18)>=0) throw new \RuntimeException('crossed_stock_depth');
    }
    public function store(Market $market, array $book): void {
        $this->validate($market,$book);
        DB::transaction(function() use($market,$book) {
            DB::statement('SELECT pg_advisory_xact_lock(8192026, ?)',[(int)$market->id]);
            $old=DB::table('stock_liquidity_books')->where('market_id',$market->id)->lockForUpdate()->first();
            $id=(string)$book['last_update_id'];
            if ($old && bccomp($id,$old->snapshot_id,0)<0) throw new \RuntimeException('stock_depth_out_of_order');
            $remaining=['bids'=>$book['bids'],'asks'=>$book['asks']];
            if ($old && $old->snapshot_id===$id) {
                $original=json_decode($old->book,true);
                if ($original['bids']!==$book['bids'] || $original['asks']!==$book['asks']) throw new \RuntimeException('stock_snapshot_conflict');
                $remaining=json_decode($old->remaining,true); // Re-fetching the same snapshot must not refill consumed levels.
            }
            DB::table('stock_liquidity_books')->updateOrInsert(['market_id'=>$market->id],[
                'snapshot_id'=>$id,'book'=>json_encode($book),'remaining'=>json_encode($remaining),
                'expires_at'=>date('Y-m-d H:i:s',min(strtotime($book['received_at'])+20,intdiv($book['event_time'],1000)+(HongKongPriceProduct::isMarket($market)?20:900),isset($book['fx_event_time'])?$book['fx_event_time']+(int)config('hk-price-products.fx_max_age'):PHP_INT_MAX)),
                'created_at'=>$old->created_at??now(),'updated_at'=>now(),
            ]);
        });
    }
    public function levels(Market $market): array {
        if (!$market->liq || !$market->status || !$market->trade_status) return ['bids'=>[], 'asks'=>[]];
        if (HongKongPriceProduct::isMarket($market) && app(HongKongPriceProduct::class)->tradingReason($market,false)) return ['bids'=>[], 'asks'=>[]];
        $row=DB::table('stock_liquidity_books')->where('market_id',$market->id)->where('expires_at','>',now())->first();
        if (!$row) return ['bids'=>[], 'asks'=>[]];
        if (HongKongPriceProduct::isMarket($market)) {
            try { app(HongKongExecutionDepth::class)->validate($market,json_decode($row->book,true)); }
            catch (\Throwable $e) { return ['bids'=>[], 'asks'=>[]]; }
        }
        return array_map(fn($side)=>array_values(array_map(fn($r)=>['price'=>$r[0],'quantity'=>$r[1]],array_filter($side,fn($r)=>bccomp($r[1],'0',18)>0))),json_decode($row->remaining,true));
    }
    public function cursor(Order $order): ?Order {
        $side=$order->side==='buy'?'asks':'bids';
        foreach ($this->levels($order->market)[$side] as $r) {
            if (order_is_limit($order->type) && ($order->side==='buy' ? bccomp($r['price'],$order->price,18)>0 : bccomp($r['price'],$order->price,18)<0)) break;
            $cursor=new Order(); $cursor->price=$r['price']; $cursor->quantity=$r['quantity'];
            $cursor->side=$order->side==='buy'?'sell':'buy'; $cursor->type='limit'; $cursor->fee_rate=0;
            return $cursor;
        }
        return null;
    }
    public function consume(Order $order, Order $cursor, string $quantity): void {
        if (DB::transactionLevel()===0) throw new \LogicException('stock_fill_requires_transaction');
        $row=DB::table('stock_liquidity_books')->where('market_id',$order->market_id)->where('expires_at','>',now())->lockForUpdate()->first();
        if (!$row) throw new \RuntimeException('stock_depth_expired');
        $remaining=json_decode($row->remaining,true); $side=$order->side==='buy'?'asks':'bids';
        foreach ($remaining[$side] as &$r) {
            if (bccomp($r[0],$cursor->price,18)!==0) continue;
            if (bccomp($quantity,'0',18)<=0 || bccomp($r[1],$quantity,18)<0) throw new \RuntimeException('stock_depth_exhausted');
            $r[1]=bcsub($r[1],$quantity,18);
            DB::table('stock_liquidity_books')->where('market_id',$order->market_id)->update(['remaining'=>json_encode($remaining)]);
            DB::table('stock_liquidity_fills')->insert(['market_id'=>$order->market_id,'order_id'=>$order->id,'snapshot_id'=>$row->snapshot_id,'side'=>$order->side,'price'=>$cursor->price,'quantity'=>$quantity,'execution_mode'=>'platform_internal','created_at'=>now(),'updated_at'=>now()]);
            return;
        }
        throw new \RuntimeException('stock_level_missing');
    }
}
