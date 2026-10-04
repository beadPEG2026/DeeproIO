<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\GrantSuperAdminCommand::class,
        \App\Console\Commands\ConfigureHongKongPriceProduct::class,
        \App\Console\Commands\ListBinanceChainAssets::class,
        \App\Console\Commands\SyncUnlimitConfiguration::class,
        \App\Console\Commands\KlinePruneOverridesCommand::class,
        \App\Console\Commands\Market\SyncKlineRuntimeCacheCommand::class,
        \App\Console\Commands\Futures\ProcessScheduledFuturesCommand::class,
        \App\Console\Commands\Futures\ProcessFundingFeesCommand::class,
        \App\Console\Commands\SystemMonitor\PMWatcher::class,
        \App\Console\Commands\SystemMonitor\PMMarketWatcher::class,
        \App\Console\Commands\SystemMonitor\SystemMonitorCommand::class,
        \App\Console\Commands\Umi\SettleStockShares::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        if(config('app.readonly')) {
            return;
        }

        $schedule->command('cache:prune-stale-tags')->hourly();
        $schedule->command('deepro:sync-operations-incidents')->everyMinute()->withoutOverlapping(2);
        $schedule->command('wallets:custody-run')->everyMinute()->withoutOverlapping(10)->runInBackground();
        $schedule->command('deepro:recheck-credited-deposits')->everyFiveMinutes()->withoutOverlapping(3)->runInBackground();
        $schedule->command('deepro:wallet-health')->everyMinute()->withoutOverlapping(5)->runInBackground();

        // UMI module daily settlement; existing exchange schedules are retained.
        // Once unified accounts are enabled, retire the original calculator.
        if (!config('umi-v2.funded_enabled')) {
            $schedule->command('umi:settle-funded')->everyMinute()->withoutOverlapping();
        } else {
            $schedule->command('umi:settle-unified')->everyMinute()->withoutOverlapping(2);
        }
        $schedule->command('umi:stock-shares-settle')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('umi:recover-funded')->everyFiveMinutes()->withoutOverlapping();

        // System Monitor - runs every 5 minutes with alert checking
        $schedule->command('system-monitor:test --alert')->everyFiveMinutes()->withoutOverlapping();

        // P2P
        $schedule->command('peer-order-appeal:watcher')->everyMinute()->withoutOverlapping();
        $schedule->command('peer-order:profile-stats')->everyFiveMinutes()->withoutOverlapping();

        // Auto withdrawals
        $schedule->command('withdrawal:automate')->everyTwoMinutes()->withoutOverlapping();

        $schedule->command('pm:watcher')->everyMinute()->withoutOverlapping();
        $schedule->command('pm:market-watcher')->everyMinute()->withoutOverlapping();

        // PerfectMoney
        $schedule->command('wallets:perfect-money-wallet-balance')->everyMinute()->withoutOverlapping();
        $schedule->command('wallets:perfect-money-wallet-withdraw-automate')->everyMinute()->withoutOverlapping();

        //Staking Rewards Calculation
        $schedule->command('staking:rewards-calculate')->dailyAt('00:00');

        //Staking Status
        $schedule->command('staking:status')->dailyAt('23:30');

        //Currency Rates
        $schedule->command('staking:funded-settle')->hourly()->withoutOverlapping();
        $schedule->command('market:display-exchange-rates')->hourly()->withoutOverlapping();
        $schedule->command('market:fiat-currency-rates')->dailyAt('23:59');

        // Launchpad
        $schedule->command('launchpad:state-monitor')->everyMinute()->withoutOverlapping();

        //Referral
        $schedule->command('transaction:referral-credits')->everyFiveMinutes()->withoutOverlapping();

        // Wallets
        $schedule->command('wallets:coinpayments-wallet-balance')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('wallets:ethereum-wallet-balance')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('wallets:bnb-wallet-balance')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('wallets:trx-wallet-balance')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('wallets:btc-wallet-balance')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('btc:scan-wallet-deposits')->everyMinute()->withoutOverlapping(60)->runInBackground();
        $schedule->command('wallets:solana-wallet-balance')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('wallets:ripple-wallet-balance')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('wallets:ton-wallet-balance')->everyFiveMinutes()->withoutOverlapping();
        //$schedule->command('wallets:brc-wallet-balance')->everyFifteenMinutes()->withoutOverlapping();

        $schedule->command('wallets:matic-wallet-balance')->everyFifteenMinutes()->withoutOverlapping();

        // Market
        $schedule->command('market-monitor:stats')->daily()->withoutOverlapping();
        $schedule->command('market:last-price-update')->daily()->withoutOverlapping();

        // Chart Cache Warming - keeps external chart data fresh
        $schedule->command('chart:warm-cache --all')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('market:refresh-stock-news')->everyFiveMinutes()->withoutOverlapping(2)->runInBackground();
        $schedule->command('market:refresh-crypto-news')->everyFiveMinutes()->withoutOverlapping(2)->runInBackground();

        // Kline local override cleanup - keep recent 2 days only
        $schedule->command('kline:prune-overrides --days=2')->hourly()->withoutOverlapping();
        $schedule->command('kline:sync-runtime-cache --interval=5 --cycles=12')->everyMinute()->withoutOverlapping();

        // Payeer
        $schedule->command('wallets:payeer-wallet-balance')->everyMinute()->withoutOverlapping();
        $schedule->command('wallets:payeer-wallet-withdraw-automate')->everyMinute()->withoutOverlapping();

        // One entry per chain and lane. Legacy manual commands still delegate to the same lock.
        foreach (array_keys(config('deposits.evm', [])) as $chain) {
            $schedule->command('deepro:scan-evm-deposits ' . $chain . ' --lane=live')->everyMinute()->withoutOverlapping(2)->runInBackground();
            $schedule->command('deepro:scan-evm-deposits ' . $chain . ' --lane=backfill')->everyFiveMinutes()->withoutOverlapping(2)->runInBackground();
        }

        // Bnb

        $schedule->command('bnb:bep-transfer-pending')->everyThreeMinutes()->withoutOverlapping();
        $schedule->command('bnb:bnb-transfer-pending')->everyThreeMinutes()->withoutOverlapping();
        $schedule->command('bnb:bnb-withdrawals-pending')->everyThreeMinutes()->withoutOverlapping();

        // Ethereum

        $schedule->command('ethereum:erc-transfer-pending')->everyThreeMinutes()->withoutOverlapping();
        $schedule->command('ethereum:eth-transfer-pending')->everyThreeMinutes()->withoutOverlapping();
        $schedule->command('ethereum:eth-withdrawals-pending')->everyThreeMinutes()->withoutOverlapping();

        // Unlimit (GateFi) configuration sync
        $schedule->command('unlimit:sync-config')->everyFifteenMinutes()->withoutOverlapping();

        // Polygon

        // Futures funding fees - run hourly to process positions that are due
        $schedule->command('futures:funding-fees')->hourly()->withoutOverlapping();


        $schedule->command('matic:matic-20-transfer-pending')->everyThreeMinutes()->withoutOverlapping();
        $schedule->command('matic:matic-transfer-pending')->everyThreeMinutes()->withoutOverlapping();
        $schedule->command('matic:matic-withdrawals-pending')->everyThreeMinutes()->withoutOverlapping();

        // Tron
        $schedule->command('tron:monitor-trx-deposits')->everyMinute()->withoutOverlapping();
        $schedule->command('tron:monitor-trc-deposits')->everyMinute()->withoutOverlapping();
        $schedule->command('tron:trx-withdrawals-pending')->everyThreeMinutes()->withoutOverlapping();
        $schedule->command('tron:trx-transfer-pending')->everyThreeMinutes()->withoutOverlapping();
        $schedule->command('tron:trc-transfer-pending')->everyThreeMinutes()->withoutOverlapping();

        // Solana
        $schedule->command('solana:monitor-sol-deposits')->everyMinute()->withoutOverlapping();
        $schedule->command('solana:monitor-spl-deposits')->everyMinute()->withoutOverlapping();

        $schedule->command('solana:sol-transfer-pending')->everyMinute()->withoutOverlapping();
        $schedule->command('solana:spl-transfer-pending')->everyMinute()->withoutOverlapping();
        $schedule->command('solana:sol-withdrawals-pending')->everyMinute()->withoutOverlapping();


        // BTC
        //$schedule->command('btc:brc-withdrawals-inscribing')->everyMinute()->withoutOverlapping();
        //$schedule->command('btc:brc-withdrawals-inscribed')->everyMinute()->withoutOverlapping();
        //$schedule->command('btc:brc-deposits-inscribed')->everyTwoMinutes()->withoutOverlapping();
        //$schedule->command('btc:brc-deposits-inscribing')->everyTwoMinutes()->withoutOverlapping();

        // Wallets
        $schedule->command('wallets:fireblocks-balance')->everyMinute()->withoutOverlapping();

        // Customtoken
        //$schedule->command('customtoken:handle-deposit')->everyMinute()->withoutOverlapping();
        //$schedule->command('customtoken:handle-withdrawal')->everyMinute()->withoutOverlapping();
        //$schedule->command('customtoken:sweep')->everyMinute()->withoutOverlapping();
        //$schedule->command('customtoken:sync-system')->everyMinute()->withoutOverlapping();
        //$schedule->command('customtoken:handle-token-deposit')->everyMinute()->withoutOverlapping();
        //$schedule->command('customtoken:token-transfer-pending')->everyThreeMinutes()->withoutOverlapping();
        //$schedule->command('customtoken:token-withdrawals-pending')->everyThreeMinutes()->withoutOverlapping();
        //$schedule->command('wallets:customtoken-token-wallet-balance')->everyFifteenMinutes()->withoutOverlapping();

        // Users: backfill first confirmed deposit baseline
        $schedule->command('users:backfill-first-deposit')->everyMinute()->withoutOverlapping();

        // Deposit bonuses: ensure bonuses are awarded for confirmed deposits
        $schedule->command('bonus:process-deposits')->everyMinute()->withoutOverlapping();

        $schedule->command('staking:apply-configuration')->everyMinute()->withoutOverlapping();

        // Lending
        $schedule->command('lending:interest-taker')->hourly()->withoutOverlapping();
        $schedule->command('lending:liquidation-watcher')->everyMinute()->withoutOverlapping();

        // Merchant Module
        $schedule->command('merchant:monitor-erc-deposits')->everyMinute()->withoutOverlapping();
        $schedule->command('merchant:monitor-trc-deposits')->everyMinute()->withoutOverlapping();
        $schedule->command('merchant:monitor-bep-deposits')->everyMinute()->withoutOverlapping();
        $schedule->command('merchant:monitor-matic-deposits')->everyMinute()->withoutOverlapping();
        $schedule->command('merchant:monitor-sol-deposits')->everyMinute()->withoutOverlapping();
        $schedule->command('merchant:collect-funds')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('merchant:process-webhooks --pending --retry')->everyMinute()->withoutOverlapping();

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
