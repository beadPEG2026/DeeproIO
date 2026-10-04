<?php
namespace App\Services\Market;
use App\Models\Market\Market;
use Illuminate\Support\Facades\Cache;

final class OrderBookStatus
{
    public static function metadata(Market $market, array $book): array
    {
        $snapshot = Cache::get("markets_liquidity.{$market->name}.executable");
        $received = $snapshot['received_at'] ?? Cache::get("markets_liquidity.{$market->name}.received_at");
        $hasOrders = count($book['bids'] ?? []) > 0 || count($book['asks'] ?? []) > 0;
        $stale = $market->liq && !$market->stock_token
            && (!is_numeric($received) || $received < time()-20 || $received > time()+5);
        return ['state'=>$hasOrders ? 'ready' : ($stale ? 'stale' : 'empty'),
            'source_updated_at'=>is_numeric($received) ? (int)$received : null];
    }
}
