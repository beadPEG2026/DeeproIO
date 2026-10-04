<?php

namespace App\Modules\Merchant\Providers;

use App\Modules\Merchant\Console\Commands\CollectMerchantFundsCommand;
use App\Modules\Merchant\Console\Commands\MonitorMerchantBepDepositsCommand;
use App\Modules\Merchant\Console\Commands\MonitorMerchantBtcDepositsCommand;
use App\Modules\Merchant\Console\Commands\MonitorMerchantDepositsCommand;
use App\Modules\Merchant\Console\Commands\MonitorMerchantErcDepositsCommand;
use App\Modules\Merchant\Console\Commands\MonitorMerchantMaticDepositsCommand;
use App\Modules\Merchant\Console\Commands\MonitorMerchantSolDepositsCommand;
use App\Modules\Merchant\Console\Commands\MonitorMerchantTrcDepositsCommand;
use App\Modules\Merchant\Console\Commands\ProcessMerchantWebhooksCommand;
use App\Modules\Merchant\Console\Commands\ProcessMerchantPayoutsCommand;
use App\Modules\Merchant\Console\Commands\ProcessMerchantRefundsCommand;
use App\Modules\Merchant\Console\Commands\RetryDeadLetterWebhooksCommand;
use App\Modules\Merchant\Repositories\InvoiceRepository;
use App\Modules\Merchant\Repositories\MerchantRepository;
use App\Modules\Merchant\Repositories\PaymentRepository;
use App\Modules\Merchant\Repositories\WebhookRepository;
use App\Modules\Merchant\Services\AddressManagerService;
use App\Modules\Merchant\Services\InvoiceService;
use App\Modules\Merchant\Services\MerchantAuthService;
use App\Modules\Merchant\Services\MerchantNotificationService;
use App\Modules\Merchant\Services\PaymentService;
use App\Modules\Merchant\Services\PricingService;
use App\Modules\Merchant\Services\FundCollectionService;
use App\Modules\Merchant\Services\PayoutService;
use App\Modules\Merchant\Services\PayoutTransferService;
use App\Modules\Merchant\Services\RefundService;
use App\Modules\Merchant\Services\RefundTransferService;
use App\Modules\Merchant\Services\WebhookDispatcherService;
use Illuminate\Support\ServiceProvider;

class MerchantServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register config
        $this->mergeConfigFrom(
            config_path('merchant_acquiring.php'),
            'merchant_acquiring'
        );

        // Register repositories as singletons
        $this->app->singleton(InvoiceRepository::class);
        $this->app->singleton(MerchantRepository::class);
        $this->app->singleton(PaymentRepository::class);
        $this->app->singleton(WebhookRepository::class);

        // Register services as singletons
        $this->app->singleton(PricingService::class);
        $this->app->singleton(AddressManagerService::class);
        $this->app->singleton(MerchantAuthService::class);

        // Register services with dependencies
        $this->app->singleton(WebhookDispatcherService::class);
        $this->app->singleton(FundCollectionService::class);
        $this->app->singleton(MerchantNotificationService::class);
        
        // Register payout services
        $this->app->singleton(PayoutTransferService::class, function ($app) {
            return new PayoutTransferService(
                $app->make(MerchantNotificationService::class)
            );
        });
        
        $this->app->singleton(PayoutService::class, function ($app) {
            return new PayoutService(
                $app->make(MerchantNotificationService::class)
            );
        });
        
        // Register refund services
        $this->app->singleton(RefundTransferService::class, function ($app) {
            return new RefundTransferService(
                $app->make(MerchantNotificationService::class)
            );
        });
        
        $this->app->singleton(RefundService::class, function ($app) {
            return new RefundService(
                $app->make(MerchantNotificationService::class),
                $app->make(RefundTransferService::class)
            );
        });

        $this->app->singleton(InvoiceService::class, function ($app) {
            return new InvoiceService(
                $app->make(InvoiceRepository::class),
                $app->make(PricingService::class),
                $app->make(AddressManagerService::class),
                $app->make(WebhookDispatcherService::class),
                $app->make(MerchantNotificationService::class)
            );
        });

        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService(
                $app->make(InvoiceRepository::class),
                $app->make(PaymentRepository::class),
                $app->make(InvoiceService::class),
                $app->make(PricingService::class),
                $app->make(WebhookDispatcherService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/web.php');

        // Publish config
        $this->publishes([
            config_path('merchant_acquiring.php') => config_path('merchant_acquiring.php'),
        ], 'merchant-acquiring-config');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                MonitorMerchantDepositsCommand::class,
                MonitorMerchantErcDepositsCommand::class,
                MonitorMerchantTrcDepositsCommand::class,
                MonitorMerchantBepDepositsCommand::class,
                MonitorMerchantMaticDepositsCommand::class,
                MonitorMerchantSolDepositsCommand::class,
                MonitorMerchantBtcDepositsCommand::class,
                CollectMerchantFundsCommand::class,
                ProcessMerchantWebhooksCommand::class,
                ProcessMerchantPayoutsCommand::class,
                ProcessMerchantRefundsCommand::class,
                RetryDeadLetterWebhooksCommand::class,
            ]);

            // Schedule jobs
            $this->registerScheduledJobs();
        }
    }

    /**
     * Register scheduled jobs
     */
    protected function registerScheduledJobs(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        // Monitor merchant deposits every minute (separate from platform's watchers)
        $schedule->command('merchant:monitor-erc-deposits')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('merchant:monitor-trc-deposits')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('merchant:monitor-bep-deposits')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('merchant:monitor-matic-deposits')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('merchant:monitor-sol-deposits')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('merchant:monitor-btc-deposits')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Check invoice expirations every minute
        $schedule->job(new \App\Modules\Merchant\Jobs\CheckInvoiceExpirationJob())
            ->everyMinute()
            ->withoutOverlapping();

        // Process pending webhooks every minute
        $schedule->job(new \App\Modules\Merchant\Jobs\ProcessPendingWebhooksJob())
            ->everyMinute()
            ->withoutOverlapping();

        // Refresh asset rates every 30 seconds
        $schedule->job(new \App\Modules\Merchant\Jobs\RefreshAssetRatesJob())
            ->everyThirtySeconds()
            ->withoutOverlapping();

        // Cleanup expired data daily
        $schedule->job(new \App\Modules\Merchant\Jobs\CleanupExpiredDataJob())
            ->daily()
            ->at('03:00');

        // Collect merchant funds to hot wallet every 5 minutes
        $schedule->command('merchant:collect-funds')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Process approved payouts every 2 minutes
        $schedule->command('merchant:process-payouts --limit=10')
            ->everyTwoMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Process approved refunds every 2 minutes
        $schedule->command('merchant:process-refunds --limit=10')
            ->everyTwoMinutes()
            ->withoutOverlapping()
            ->runInBackground();
    }
}
