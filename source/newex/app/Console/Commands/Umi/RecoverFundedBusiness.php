<?php
namespace App\Console\Commands\Umi;
use App\Services\Umi\V2\{FundedRecovery,FundedRuntime};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
final class RecoverFundedBusiness extends Command
{
    protected $signature='umi:recover-funded';
    protected $description='Discover and retry confirmed UMI burn point credits from original evidence';
    public function handle(FundedRecovery $recovery): int
    {
        if(config('app.readonly') || !config('umi-v2.funded_enabled') || !FundedRuntime::schemaReady()) return self::SUCCESS;
        $lock=Cache::lock('deepro.umi.funded-recovery',300);
        if(!$lock->get()) return self::SUCCESS;
        try {$this->info(json_encode($recovery->run(),JSON_THROW_ON_ERROR));return self::SUCCESS;}
        finally {$lock->release();}
    }
}
