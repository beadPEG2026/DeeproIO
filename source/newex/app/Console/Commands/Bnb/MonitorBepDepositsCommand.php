<?php
namespace App\Console\Commands\Bnb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/** Compatibility entry point: never run the old unverified explorer credit path. */
class MonitorBepDepositsCommand extends Command {
    protected $signature='bnb:monitor-bep-deposits';
    protected $description='Compatibility alias for verified bsc deposit scanning';
    public function handle():int {return $this->call('deepro:scan-evm-deposits',['chain'=>'bsc']);}
    public function check($address,$currency=null):bool {
        $result=Artisan::call('deepro:scan-evm-deposits',['chain'=>'bsc']);
        if($result!==0)throw new \RuntimeException('DEPOSIT_SCAN_REQUIRES_REVIEW');
        return true;
    }
}
