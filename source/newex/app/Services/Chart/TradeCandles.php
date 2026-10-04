<?php
namespace App\Services\Chart;

use App\Models\Market\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read committed fills only; the taker row counts each match once. */
final class TradeCandles
{
    public const RESOLUTIONS=['1','5','15','60','240','1D'];

    public function history(Market $market,int $from,int $to,string $resolution,?int $countback=null): array
    {
        $seconds=['1'=>60,'5'=>300,'15'=>900,'60'=>3600,'240'=>14400,'D'=>86400,'1D'=>86400][$resolution]??null;
        if (!$seconds || $from<0 || $to<=$from) throw new \InvalidArgumentException('invalid_trade_candle_range');
        $to=min($to,time()+1);
        $lower=$countback!==null?max(0,$to-366*86400):max($from,$to-366*86400);
        $timezone=config('app.timezone','UTC');
        $localDate=fn($stamp)=>CarbonImmutable::createFromTimestampUTC($stamp)->setTimezone($timezone)->format('Y-m-d H:i:s');
        $query=DB::table('transactions')->where('market_id',$market->id)->where('is_maker',false)
            ->where('is_volume',0)->whereNotNull('user_id')->whereNotNull('order_id')
            ->whereExists(function($q){
                $q->selectRaw('1')->from('order_histories')->whereColumn('order_histories.id','transactions.order_id')
                    ->whereColumn('order_histories.market_id','transactions.market_id')->whereColumn('order_histories.user_id','transactions.user_id')
                    ->where('order_histories.settlement_domain','real');
            })
            ->where('price','>',0)->where('base_currency','>',0)
            ->where('created_at','>=',$localDate($lower))->where('created_at','<',$localDate($to))
            ->selectRaw('(floor(extract(epoch from created_at AT TIME ZONE ?) / ?) * ?) AS time',[$timezone,$seconds,$seconds])
            ->selectRaw('(array_agg(price ORDER BY created_at ASC,id ASC))[1] AS open')
            ->selectRaw('(array_agg(price ORDER BY created_at DESC,id DESC))[1] AS close')
            ->selectRaw('MAX(price) AS high,MIN(price) AS low,SUM(base_currency) AS volume')
            ->groupByRaw('time')->orderByDesc('time')->limit(min(300,max(1,$countback??300)));
        $bars=$query->get()->reverse()->values();
        $result=['s'=>$bars->isEmpty()?'no_data':'ok','t'=>[],'o'=>[],'h'=>[],'l'=>[],'c'=>[],'v'=>[],
            'source'=>'Deepro trades','currency'=>$market->quoteCurrency->symbol,'volume_unit'=>$market->baseCurrency->symbol,'reference_only'=>false];
        foreach ($bars as $row) {
            $result['t'][]=(int)$row->time;
            foreach (['o'=>'open','h'=>'high','l'=>'low','c'=>'close','v'=>'volume'] as $key=>$field) $result[$key][]=(float)$row->$field;
        }
        return $result;
    }
}
