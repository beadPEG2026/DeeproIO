<?php
namespace App\Services\SystemMonitor;

use Illuminate\Support\Facades\Redis;

final class QueueHealth
{
    /** Read-only aggregates. Never return payloads, recipient details, or credentials. */
    public function snapshot(): array
    {
        $connection=Redis::connection(config('queue.connections.redis.connection','default'));
        $result=[];
        foreach (['default','market','orders','low'] as $name) {
            $key='queues:'.$name;
            $count=(int)$connection->llen($key);
            $first=$count ? json_decode($connection->lindex($key,0) ?: '{}',true) : [];
            $pushed=is_array($first) ? ($first['pushedAt'] ?? null) : null;
            $age=is_numeric($pushed) && (float)$pushed > 0 && (float)$pushed <= microtime(true)+60
                ? max(0,(int)(microtime(true)-(float)$pushed)) : null;
            $result[$name]=[
                'waiting'=>$count,'oldest_wait_seconds'=>$age,
                'delayed'=>(int)$connection->zcard($key.':delayed'),
                'reserved'=>(int)$connection->zcard($key.':reserved'),
                'expired_reserved'=>(int)$connection->zcount($key.':reserved','-inf',time()),
            ];
        }
        return $result;
    }
}
