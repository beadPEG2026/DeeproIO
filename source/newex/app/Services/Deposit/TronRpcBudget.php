<?php
namespace App\Services\Deposit;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** One provider budget for PHP and Node; never hold its row lock while doing RPC. */
class TronRpcBudget
{
    public function connection(): \Illuminate\Database\Connection
    {
        $name='tron_rpc_governance';
        if (!config('database.connections.'.$name)) {
            config(['database.connections.'.$name => config('database.connections.'.config('database.default'))]);
        }
        $db=DB::connection($name);
        if ($db->transactionLevel() !== 0) throw new RuntimeException('TRONGRID_BUDGET_TRANSACTION_NOT_ALLOWED');
        return $db;
    }

    public function acquire(string $key): void
    {
        $deadline=microtime(true)+5;
        do {
            $row=$this->connection()->selectOne('SELECT * FROM deepro_rpc_reserve(?, ?, ?)', [hash('sha256',$key), max(100,(int)(config('deposits.tron.request_interval',1)*1000)), 'php']);
            if ($row->admitted) {
                if ($row->wait_ms>0) usleep((int)$row->wait_ms*1000);
                // A preceding in-flight request may have published a limit while we waited.
                $blocked=$this->connection()->selectOne('SELECT blocked_until > clock_timestamp() AS blocked FROM rpc_provider_budgets WHERE key_hash=?',[hash('sha256',$key)]);
                if ($blocked?->blocked) throw new RuntimeException('TRONGRID_SHARED_COOLDOWN');
                return;
            }
            $wait=max(1,(int)$row->wait_ms);
            if (microtime(true)+$wait/1000 > $deadline) throw new RuntimeException('TRONGRID_SHARED_COOLDOWN');
            usleep(($wait+random_int(1,30))*1000);
        } while (microtime(true)<$deadline);
        throw new RuntimeException('TRONGRID_SHARED_BUSY');
    }

    public function outcome(string $key, int $status, ?string $retryAfter=null): void
    {
        $retry=is_numeric($retryAfter) ? (int)ceil((float)$retryAfter) : max(0,(strtotime($retryAfter??'')?:0)-time());
        $this->connection()->select('SELECT deepro_rpc_outcome(?, ?, ?)',[hash('sha256',$key),$status,min(2147480000,max(0,$retry))]);
    }
}
