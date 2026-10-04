<?php
namespace App\Services\Market;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
/** Receipt metadata is recorded only on a new upstream message, never on display reformatting. */
final class TickerFreshness {
    public function received(int $marketId,string $source,$timestamp=null): void {
        $now=now(); $event=null;
        if(is_numeric($timestamp)) {
            $seconds=(float)$timestamp; if($seconds>100000000000)$seconds/=1000;
            if($seconds>0 && $seconds<=$now->timestamp+2) $event=Carbon::createFromTimestamp((int)$seconds,'UTC')->toISOString();
        }
        Cache::put('market.'.$marketId.'.freshness',['source'=>$source,'source_event_at'=>$event,'received_at'=>$now->toISOString()],86400);
    }
    public function snapshot(int $marketId): array {
        $row=Cache::get('market.'.$marketId.'.freshness',[]); $stamp=$row['source_event_at']??$row['received_at']??null;
        $age=$stamp ? max(0,now()->timestamp-Carbon::parse($stamp)->timestamp) : null;
        return ['updated_at'=>$stamp,'source_event_at'=>$row['source_event_at']??null,'received_at'=>$row['received_at']??null,'served_at'=>now()->toISOString(),'price_source'=>$row['source']??null,'price_age_seconds'=>$age,'price_stale'=>$age===null||$age>15,'price_timestamp_quality'=>isset($row['source_event_at'])?'source':(isset($row['received_at'])?'receipt_only':'unknown')];
    }
}
