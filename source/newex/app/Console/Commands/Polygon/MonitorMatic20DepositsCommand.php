<?php
namespace App\Console\Commands\Polygon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/** Compatibility entry point: never run the old unverified explorer credit path. */
class MonitorMatic20DepositsCommand extends Command {
    protected $signature='matic:monitor-matic-20-deposits';
    protected $description='Compatibility alias for verified polygon deposit scanning';
    public function handle():int {return $this->call('deepro:scan-evm-deposits',['chain'=>'polygon']);}
    public function check($address,$currency=null):bool {
        $result=Artisan::call('deepro:scan-evm-deposits',['chain'=>'polygon']);
        if($result!==0)throw new \RuntimeException('DEPOSIT_SCAN_REQUIRES_REVIEW');
        return true;
    }
}
