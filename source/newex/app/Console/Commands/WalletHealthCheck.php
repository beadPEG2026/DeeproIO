<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Services\Wallet\WalletReadiness;
class WalletHealthCheck extends Command
{
    protected $signature='deepro:wallet-health';
    protected $description='Check wallet bridge protocol and required configuration without submitting transactions';
    public function handle(WalletReadiness $readiness):int {$data=$readiness->inspect();Cache::put('deepro.wallet-readiness',$data,now()->addDay());$this->line(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));return 0;}
}
