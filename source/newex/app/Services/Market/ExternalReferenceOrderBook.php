<?php
namespace App\Services\Market;

use App\Services\Market\ExternalReferenceDepth\LongbridgeDepthProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/** Display-only data. Nothing in the execution or funded-liquidity paths calls this service. */
final class ExternalReferenceOrderBook
{
    public function get(array $asset): array
    {
        app(HongKongPriceProduct::class)->definition($asset);
        $config=config('hk-price-products.external_depth',[]);
        $provider=$config['provider']??'longbridge_public';
        $reader=app(LongbridgeDepthProvider::class);
        $result=['symbol'=>$asset['symbol'],'security_code'=>$asset['securityCode'],'currency'=>'HKD','quantity_unit'=>'share',
            'reference_only'=>true,'executable'=>false,'state'=>'disabled','source'=>['provider'=>$provider,'name'=>'Longbridge',
            'source_url'=>$reader->url($asset['securityCode']),'attribution'=>'Longbridge public delayed reference'],
            'source_time'=>null,'source_time_scope'=>'quote_snapshot','received_at'=>null,'delay'=>['declared'=>true,'seconds'=>null],
            'depth_levels'=>1,'market_status'=>'unknown','lot_size'=>null,'is_demo'=>false,'data'=>['bids'=>[],'asks'=>[]]];
        // Permission is checked before cache access or any network operation.
        if (!($config['enabled']??false)) return $result;
        $demo=$provider==='development_fixture';
        if ($demo && !app()->environment(['local','testing'])) return $result+['reason'=>'demo_not_allowed'];
        if (!$demo && !($config['source_permission_confirmed']??false)) return $result+['reason'=>'source_permission_required'];
        if (!in_array($provider,['longbridge_public','development_fixture'],true)) return $result+['reason'=>'unsupported_provider'];
        try {
            $snapshot=Cache::remember('external-reference-depth.'.$provider.'.'.$asset['securityCode'],10,function() use($demo,$reader,$asset) {
                if (!$demo) return $reader->fetch($asset['securityCode']);
                // A minimal observed quote, never a claim of a fresh market request.
                $data=json_decode(file_get_contents(base_path('tests/Fixtures/hk08379-depth-observed.json')),true,64,JSON_THROW_ON_ERROR);
                return $reader->normalize($data,$asset['securityCode']);
            });
            $result['is_demo']=$demo;
            if ($demo) $result['source']['attribution']='Observed public snapshot · development fixture';
            $result['source_time']=gmdate('c',$snapshot['source_time']);$result['received_at']=$snapshot['received_at'];
            $result['lot_size']=$snapshot['lot_size'];$result['market_status']=$snapshot['market_status'];
            $age=CarbonImmutable::now()->timestamp-$snapshot['source_time'];
            $result['age_seconds']=$age;
            if ($age< -5) throw new \RuntimeException('invalid_source_time');
            if ($this->isRecentClose($snapshot,$config)) $result['state']='closed_snapshot';
            elseif ($age>(int)($config['max_age_seconds']??1200)) {$result['state']='stale';$result['reason']='source_overdue';return $result;}
            else $result['state']='delayed';
            $result['data']=$snapshot['data'];
            return $result;
        } catch (\Throwable $e) {
            $result['state']='unavailable';$result['reason']='source_unavailable_or_invalid';return $result;
        }
    }

    private function isRecentClose(array $snapshot,array $config): bool
    {
        $now=CarbonImmutable::now('Asia/Hong_Kong');
        if ($snapshot['market_status']!=='closed' || app(HongKongPriceProduct::class)->sessionOpen($now)
            || $now->timestamp-$snapshot['source_time']>(int)($config['closed_snapshot_max_age_seconds']??604800)) return false;
        // Keep only the most recent completed session, not an arbitrary old closed quote.
        for($days=0;$days<8;$days++) {
            $day=$now->startOfDay()->subDays($days);
            if (!in_array($day->year,config('hk-price-products.calendar_years',[]),true)) return false;
            if ($day->isWeekend() || in_array($day->toDateString(),config('hk-price-products.holidays',[]),true)) continue;
            $close=$day->addHours(in_array($day->toDateString(),config('hk-price-products.half_days',[]),true)?12:16);
            if ($close>$now) continue;
            return $snapshot['source_time'] >= $close->timestamp
                && CarbonImmutable::createFromTimestampUTC($snapshot['source_time'])->setTimezone('Asia/Hong_Kong')->toDateString()===$day->toDateString();
        }
        return false;
    }
}
