<?php
namespace App\Services\Market\ExternalReferenceDepth;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** Parser for explicitly authorized data access. Parsing never evaluates website JavaScript. */
final class LongbridgeDepthProvider
{
    public function url(string $code): string {return 'https://longbridge.com/zh-CN/quote/'.ltrim($code,'0').'.HK';}

    public function fetch(string $code): array
    {
        if (!config('hk-price-products.external_depth.enabled') || !config('hk-price-products.external_depth.source_permission_confirmed'))
            throw new \RuntimeException('source_permission_required');
        $response=Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects'=>false])
            ->withHeaders(['User-Agent'=>'DeeproReferenceData/1.0'])->get($this->url($code));
        if (!$response->successful() || strlen($response->body())>2000000) throw new \RuntimeException('provider_unavailable');
        return $this->parseHtml($response->body(),$code);
    }

    public function parseHtml(string $html,string $code): array
    {
        if (!preg_match('/^\d{5}$/D',$code) || strlen($html)>2000000) throw new \RuntimeException('invalid_source');
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~si',$html,$scripts);
        $prefix='window.__TANSTACK_DEHYDRATED__.queries.push(';
        $found=[];
        foreach ($scripts[1] as $script) {
            $script=trim($script);
            if (!str_starts_with($script,$prefix)) continue;
            $tail=rtrim(substr($script,strlen($prefix)));
            if (str_ends_with($tail,';')) $tail=rtrim(substr($tail,0,-1));
            if (!str_ends_with($tail,')')) throw new \RuntimeException('invalid_source');
            try {$queries=json_decode('['.substr($tail,0,-1).']',true,64,JSON_THROW_ON_ERROR);}
            catch (\JsonException $e) {throw new \RuntimeException('invalid_source');}
            foreach ($queries as $query) {
                if (($query['queryKey']??null)!==['stock','quote-overview','ST/HK/'.ltrim($code,'0'),'detail']) continue;
                $found[]=$query['state']['data']['data']??[];
            }
        }
        if (count($found)!==1) throw new \RuntimeException('source_identity_mismatch');
        return $this->normalize($found[0],$code);
    }

    public function normalize(array $data,string $code): array
    {
        if (($data['counter_id']??null)!=='ST/HK/'.ltrim($code,'0') || ($data['currency']??null)!=='HKD'
            || ($data['latency']??null)!==true || ($data['available_levels']??null)!==['Delay'])
            throw new \RuntimeException('source_identity_mismatch');
        $timestamp=$data['timestamp']??null;
        if (!preg_match('/^[0-9]{10}$/D',(string)$timestamp) || (int)$timestamp>CarbonImmutable::now()->timestamp+5)
            throw new \RuntimeException('invalid_source_time');
        $lot=$data['unit']??null;
        if (!is_int($lot) || $lot<=0) throw new \RuntimeException('invalid_source_unit');
        $book=[];
        foreach (['bids'=>'bid_depths','asks'=>'ask_depths'] as $side=>$field) {
            $rows=$data[$field]??null;
            if (!is_array($rows) || count($rows)!==1 || !isset($rows[0]) || ($rows[0]['price_level']??null)!==1)
                throw new \RuntimeException('unsupported_depth');
            $price=$rows[0]['price']??null; $volume=$rows[0]['volume']??null;
            if (!is_string($price) || !preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D',$price) || bccomp($price,'0',8)<=0
                || !preg_match('/^[1-9]\d{0,15}$/D',(string)$volume)) throw new \RuntimeException('invalid_depth');
            // Source volume is already a share count; unit is the independent board-lot size.
            $book[$side]=[['price'=>$price,'quantity'=>(string)$volume,'raw_volume'=>(string)$volume]];
        }
        if (bccomp($book['bids'][0]['price'],$book['asks'][0]['price'],8)>=0) throw new \RuntimeException('crossed_depth');
        return ['source_time'=>(int)$timestamp,'received_at'=>CarbonImmutable::now()->toIso8601String(),
            'lot_size'=>$lot,'market_status'=>($data['trade_status']??null)===108?'closed':'unknown',
            'source_market_status'=>$data['trade_status']??null,'data'=>$book];
    }
}
