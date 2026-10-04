<?php

namespace App\Console\Commands\SystemMonitor;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PMWatcher extends Command
{
    protected $signature = 'pm:watcher';
    protected $description = 'Watch and restart PM2/Supervisor services if they are not running';

    public function handle()
    {
        try {

            if (!config('pm.services_enabled')) {
                $this->info('PM Watcher is disabled.');
                return 0;
            }

            $homePath = config('pm.home_path');
            $services = config('pm.services');
            $plan = config('app.plan');

            $processes = collect(supervisor($homePath)->list())
                ->keyBy(fn($p) => $p->name);

            foreach ($services as $service) {
                if (isset($service['plan']) && $service['plan'] != $plan) {
                    continue;
                }

                $name = $service['name'];
                $command = $service['command'];

                $isRunning = $processes->has($name)
                    && $processes[$name]->pm2Env->status === 'online';

                if ($isRunning) {
                    continue;
                }

                if (supervisor($homePath)->findBy('name', $name)) {
                    supervisor($homePath)->delete($name);
                }

                putenv('PATH=' . config('pm.usr_path', '/usr/local/bin:/usr/bin:/bin'));

                supervisor($homePath)->start(base_path('artisan'), [
                    'name' => $name,
                    'interpreter' => config('pm.php_path', '/usr/bin/php'),
                    ' ' . $command,
                ]);

                $this->warn("Restarted process: {$name}");
            }

            return 0;
        } catch (\Exception $e) {
            Log::error('PM Watcher Error: ' . $e->getMessage());
            return 1;
        }
    }
}
