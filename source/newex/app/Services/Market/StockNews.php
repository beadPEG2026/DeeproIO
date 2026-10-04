<?php

namespace App\Services\Market;

use Illuminate\Support\Facades\{Cache, Http};

/** Headline links only. Fetch once per region, independent from orders and market quotes. */
final class StockNews
{
    public const CACHE_PREFIX = 'stock-news.v3.';
    public const TICKERS = [
        'US'=>'AAPL,MSFT,NVDA,TSLA,AMZN,GOOGL,META,AMD,NFLX,CRCL',
        'HK'=>'0700.HK,9988.HK,3690.HK,1810.HK,9618.HK,9999.HK,1024.HK,1211.HK,0981.HK,0388.HK,1299.HK,0941.HK,8379.HK',
    ];

    public function snapshot(string $region): array
    {
        if (!isset(self::TICKERS[$region])) throw new \InvalidArgumentException('Unsupported news region');
        // Page requests never wait for an upstream publisher. The scheduled command owns refreshes.
        return Cache::get(self::CACHE_PREFIX.$region, ['items'=>[], 'updatedAt'=>null]);
    }

    public function refresh(string $region): array
    {
        $saved=$this->snapshot($region);
        $key=self::CACHE_PREFIX.$region;
        if (Cache::get($key.'.cooldown')===intdiv(now()->timestamp,300)) return $saved;
        $lock=Cache::lock($key.'.lock',60);
        if (!$lock->get()) return $saved;
        try {
            if (Cache::get($key.'.cooldown')===intdiv(now()->timestamp,300)) return Cache::get($key,$saved);
            Cache::put($key.'.cooldown',intdiv(now()->timestamp,300),300);
            $feeds=$region==='HK' ? [
                ['hkex','https://www.hkex.com.hk/Services/RSS-Feeds/News-Releases',['sc_lang'=>'zh-hk']],
                ['rthk','https://rthk.hk/rthk/news/rss/c_expressnews_cfinance.xml',[]],
            ] : [['yahoo','https://feeds.finance.yahoo.com/rss/2.0/headline',['s'=>self::TICKERS[$region],'region'=>'US','lang'=>'en-US']]];
            $success=false;$items=[];$failed=[];
            foreach ($feeds as [$source,$url,$query]) {
                $sourceKey=$key.'.'.$source;
                $rows=Cache::get($sourceKey,[]);
                try {
                    $xml=Http::connectTimeout(3)->timeout(8)->withHeaders(['User-Agent'=>'Deepro Stock Headlines/1.0 (+https://deepro.io)'])
                        ->get($url,$query)->throw()->body();
                    $fresh=$this->parse($xml,$region,$source);
                    if (!$fresh) throw new \UnexpectedValueException('Empty news feed');
                    $rows=$fresh;Cache::put($sourceKey,$rows,604800);$success=true;
                } catch (\Throwable $e) { $failed[]=$source; }
                foreach ($rows as $row) $items[$row['url']]=$row;
            }
            if ($success) {
                usort($items,fn($a,$b)=>strcmp($b['publishedAt'],$a['publishedAt']));
                $saved=['items'=>array_slice($items,0,12),'updatedAt'=>now()->toIso8601String(),'partial'=>(bool)$failed];
                Cache::put($key,$saved,604800);
            }
            return $saved;
        } catch (\Throwable $e) {
            return $saved;
        } finally { $lock->release(); }
    }

    public function parse(string $xml,string $region,?string $source=null): array
    {
        if (strlen($xml)>2000000 || stripos($xml,'<!DOCTYPE')!==false || stripos($xml,'<!ENTITY')!==false)
            throw new \UnexpectedValueException('Invalid news response');
        $previous=libxml_use_internal_errors(true);
        try { $feed=simplexml_load_string($xml,\SimpleXMLElement::class,LIBXML_NONET|LIBXML_NOCDATA); }
        finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
        if (!$feed || !isset($feed->channel)) throw new \UnexpectedValueException('Invalid news feed');
        $items=[];
        foreach ($feed->channel->item as $item) {
            $title=trim(strip_tags((string)$item->title));$url=trim((string)$item->link);
            $parts=parse_url($url);$host=strtolower($parts['host']??'');$time=strtotime((string)$item->pubDate);
            $source=$source??($region==='HK'?'hkex':'yahoo');
            $allowed=$source==='rthk' ? $region==='HK' && $host==='news.rthk.hk'
                : ($region==='HK'?$host==='www.hkex.com.hk':(bool)preg_match('/(?:^|\.)yahoo\.com$/D',$host));
            if (!$title || !in_array($parts['scheme']??'', ['http','https'],true) || !$allowed
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || !$time || $time>time()+300) continue;
            $url=preg_replace('/^http:/','https:',$url);
            $items[hash('sha256',$url)]=['title'=>mb_substr($title,0,240),'url'=>$url,'publishedAt'=>gmdate('c',$time),
                'source'=>$source==='rthk'?'香港电台':($region==='HK'?'香港交易所':'Yahoo Finance'),'region'=>$region];
        }
        usort($items,fn($a,$b)=>strcmp($b['publishedAt'],$a['publishedAt']));
        return array_slice($items,0,12);
    }
}
