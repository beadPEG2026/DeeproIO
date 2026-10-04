<?php
namespace App\Console\Commands\Deepro;

final class SyncOperationsIncidents extends \Illuminate\Console\Command
{
    protected $signature='deepro:sync-operations-incidents';
    protected $description='Import sanitized health samples into the operational incident workflow';
    public function handle():int {
        if(config('app.readonly'))return self::SUCCESS;
        app(\App\Services\Operations\Incidents::class)->sync();
        $this->info('Operational health samples synchronized.');return self::SUCCESS;
    }
}
