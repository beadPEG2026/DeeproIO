<?php
namespace App\Console\Commands\Ethereum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/** Compatibility entry point: never run the old unverified explorer credit path. */
class MonitorErcDepositsCommand extends Command {
    protected $signature='ethereum:monitor-erc-deposits';
    protected $description='Compatibility alias for verified ethereum deposit scanning';
    public function handle():int {return $this->call('deepro:scan-evm-deposits',['chain'=>'ethereum']);}
    public function check($address,$currency=null):bool {
        $result=Artisan::call('deepro:scan-evm-deposits',['chain'=>'ethereum']);
        if($result!==0)throw new \RuntimeException('DEPOSIT_SCAN_REQUIRES_REVIEW');
        return true;
    }
}
