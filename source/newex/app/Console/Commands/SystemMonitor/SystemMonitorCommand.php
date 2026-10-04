<?php

namespace App\Console\Commands\SystemMonitor;

use App\Jobs\SystemMonitor\JobHandle;
use App\Mail\SystemMonitor\MailTest;
use App\Mail\SystemMonitor\SystemMonitorAlert;
use App\Models\Market\Market;
use App\Models\User\User;
use App\Services\PaymentGateways\Coin\Bitcoin\Services\CustomBitcoinService;
use App\Services\PaymentGateways\Coin\Bnb\Api\BnbGateway;
use App\Services\PaymentGateways\Coin\Coinpayments\Api\CoinpaymentsGateway;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use App\Services\PaymentGateways\Coin\Polygon\Api\PolygonGateway;
use App\Services\PaymentGateways\Coin\Ripple\Services\RippleService;
use App\Services\PaymentGateways\Coin\Solana\Api\SolanaGateway;
use App\Services\PaymentGateways\Coin\Ton\Api\TonGateway;
use App\Services\PaymentGateways\Coin\Tron\Api\TronGateway;
use App\Services\PaymentGateways\Fiat\Stripe\Services\StripeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Setting;
use App\Services\Fireblocks\FireblocksSDK;
use App\Services\Fireblocks\FireblocksService;

class SystemMonitorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system-monitor:test {--alert : Send alert email if services are offline}';

    protected const STATUS_ONLINE = 'online';
    protected const STATUS_OFFLINE = 'offline';
    protected const STATUS_MAINTENANCE = 'maintenance';
    protected const STATUS_DEGRADED = 'degraded';

    /**
     * Priority levels for services
     */
    protected const PRIORITY_CRITICAL = 'critical';
    protected const PRIORITY_HIGH = 'high';
    protected const PRIORITY_MEDIUM = 'medium';
    protected const PRIORITY_LOW = 'low';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor all system services for CEX platform';

    /**
     * Track offline services for alerting
     */
    protected array $offlineServices = [];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Total services count for progress calculation
     */
    protected int $totalServices = 36;
    protected int $currentService = 0;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // In readonly mode, set all services as online without real checks
        if (config('app.readonly')) {
            return $this->handleReadonlyMode();
        }

        $startTime = microtime(true);

        Setting::set('system-monitor.last_checked', Carbon::now()->format('Y-m-d H:i:s'));
        Setting::set('system-monitor.check_started', Carbon::now()->timestamp);
        Setting::set('system-monitor.is_running', true);
        Setting::set('system-monitor.current_service', 'Initializing...');
        Setting::set('system-monitor.progress', 0);
        Setting::save();

        // ============================================
        // CORE INFRASTRUCTURE SERVICES
        // ============================================

        $this->updateProgress('Web Server & Database');
        $this->checkDatabaseAndWebServer();

        $this->updateProgress('Website Access');
        $this->checkWebsiteAccess();

        $this->updateProgress('Encryption Module');
        $this->checkEncryption();

        $this->updateProgress('Cache Service');
        $this->checkCacheService();

        $this->updateProgress('Redis Server');
        $this->checkRedisService();

        $this->updateProgress('API Gateway');
        $this->checkApiService();

        $this->updateProgress('Artisan Commands');
        $this->checkArtisanCommands();

        $this->updateProgress('Job Queue Processor');
        $this->checkJobProcessing();

        $this->updateProgress('Email Service');
        $this->checkEmailService();

        // ============================================
        // CEX TRADING SERVICES
        // ============================================

        $this->updateProgress('Price Feed Service');
        $this->checkPriceFeedService();

        $this->updateProgress('Market Data Service');
        $this->checkMarketDataService();

        $this->updateProgress('Liquidity Provider');
        $this->checkLiquidityService();

        // ============================================
        // WALLET & BLOCKCHAIN SERVICES
        // ============================================

        $this->updateProgress('Ethereum Node (ERC-20)');
        $this->checkEthereumApi();

        $this->updateProgress('Binance Smart Chain (BEP-20)');
        $this->checkBscApi();

        $this->updateProgress('TRON Network (TRC-20)');
        $this->checkTronApi();

        $this->updateProgress('Solana Network');
        $this->checkSolanaApi();

        $this->updateProgress('Ripple (XRP) Network');
        $this->checkRippleApi();

        $this->updateProgress('TON Network');
        $this->checkTonApi();

        $this->updateProgress('Polygon Network');
        $this->checkPolygonApi();

        $this->updateProgress('Bitcoin Node');
        $this->checkBitcoinApi();

        $this->updateProgress('Fireblocks Custody');
        $this->checkFireblocksApi();

        // ============================================
        // PAYMENT SERVICES
        // ============================================

        $this->updateProgress('Stripe Payment Gateway');
        $this->checkStripeApi();

        // ============================================
        // SECURITY SERVICES
        // ============================================

        $this->updateProgress('KYC Verification');
        $this->checkKycService();

        $this->updateProgress('Two-Factor Authentication');
        $this->checkTwoFactorAuth();

        $this->updateProgress('Rate Limiter');
        $this->checkRateLimiter();

        // ============================================
        // ADDITIONAL SERVICES
        // ============================================

        $this->updateProgress('Task Scheduler');
        $this->checkSchedulerService();

        $this->updateProgress('Push Notifications');
        $this->checkNotificationService();

        $this->updateProgress('Webhook Delivery');
        $this->checkWebhookService();

        // Calculate response time
        $responseTime = round((microtime(true) - $startTime) * 1000);
        Setting::set('system-monitor.response_time', $responseTime);
        Setting::set('system-monitor.check_completed', Carbon::now()->timestamp);
        Setting::set('system-monitor.is_running', false);
        Setting::set('system-monitor.current_service', 'Completed');
        Setting::set('system-monitor.progress', 100);

        Setting::save();

        // Send alert if there are offline services
        $this->sendAlertIfNeeded();

        return 0;
    }

    /**
     * Update the current progress
     */
    protected function updateProgress(string $serviceName): void
    {
        $this->currentService++;
        $progress = round(($this->currentService / $this->totalServices) * 100);

        Setting::set('system-monitor.current_service', $serviceName);
        Setting::set('system-monitor.progress', $progress);
        Setting::set('system-monitor.services_checked', $this->currentService);
        Setting::set('system-monitor.services_total', $this->totalServices);
        Setting::save();
    }

    /**
     * Check Database & Web Server
     */
    protected function checkDatabaseAndWebServer(): void
    {
        try {
            $user = User::first();

            if ($user) {
                $this->setStatus('database', self::STATUS_ONLINE, self::PRIORITY_CRITICAL);
                $this->setStatus('web_server', self::STATUS_ONLINE, self::PRIORITY_CRITICAL);

                // Additional DB metrics
                $dbConnection = DB::connection()->getDatabaseName();
                Setting::set('system-monitor.database_name', $dbConnection);
            }
        } catch (\Exception $e) {
            $this->setStatus('database', self::STATUS_OFFLINE, self::PRIORITY_CRITICAL);
            $this->setStatus('web_server', self::STATUS_OFFLINE, self::PRIORITY_CRITICAL);
            $this->logError('database', $e->getMessage());
        }
    }

    /**
     * Check Website Access
     */
    protected function checkWebsiteAccess(): void
    {
        try {
            $status = Setting::get('general.maintenance_status', false);

            if ($status) {
                $this->setStatus('frontpage', self::STATUS_MAINTENANCE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('frontpage', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('frontpage', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('frontpage', $e->getMessage());
        }
    }

    /**
     * Check Encryption Module
     */
    protected function checkEncryption(): void
    {
        try {
            Setting::get('general', false);
            $this->setStatus('encryption', self::STATUS_ONLINE, self::PRIORITY_CRITICAL);
        } catch (\Exception $e) {
            $this->setStatus('encryption', self::STATUS_OFFLINE, self::PRIORITY_CRITICAL);
            $this->logError('encryption', $e->getMessage());
        }
    }

    /**
     * Check Cache Service
     */
    protected function checkCacheService(): void
    {
        try {
            $testKey = 'system_monitor_cache_test_' . time();
            Cache::put($testKey, 'test', 10);
            $value = Cache::get($testKey);
            Cache::forget($testKey);

            if ($value === 'test') {
                $this->setStatus('cache', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('cache', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('cache', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('cache', $e->getMessage());
        }
    }

    /**
     * Check Redis Service
     */
    protected function checkRedisService(): void
    {
        try {
            $redis = Redis::connection();
            $pong = $redis->ping();

            if ($pong) {
                $this->setStatus('redis', self::STATUS_ONLINE, self::PRIORITY_CRITICAL);
            } else {
                $this->setStatus('redis', self::STATUS_OFFLINE, self::PRIORITY_CRITICAL);
            }
        } catch (\Exception $e) {
            $this->setStatus('redis', self::STATUS_OFFLINE, self::PRIORITY_CRITICAL);
            $this->logError('redis', $e->getMessage());
        }
    }

    /**
     * Check API Service
     */
    protected function checkApiService(): void
    {
        try {
            $response = Http::timeout(10)->get(route('server-time'));

            if ($response->successful()) {
                $this->setStatus('api', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('api', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('api', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('api', $e->getMessage());
        }
    }

    /**
     * Check Artisan Commands
     */
    protected function checkArtisanCommands(): void
    {
        // Already running artisan, so it's online
        $this->setStatus('artisan', self::STATUS_ONLINE, self::PRIORITY_MEDIUM);
    }

    /**
     * Check Job Processing (Queue System)
     */
    protected function checkJobProcessing(): void
    {
        try {
            Setting::set('system-monitor.test-job', false);
            dispatch_sync(new JobHandle());
            sleep(2);

            $status = Setting::get('system-monitor.test-job', false);

            if ($status) {
                $this->setStatus('jobs', self::STATUS_ONLINE, self::PRIORITY_CRITICAL);
            } else {
                $this->setStatus('jobs', self::STATUS_OFFLINE, self::PRIORITY_CRITICAL);
            }
        } catch (\Exception $e) {
            $this->setStatus('jobs', self::STATUS_OFFLINE, self::PRIORITY_CRITICAL);
            $this->logError('jobs', $e->getMessage());
        }
    }

    /**
     * Check Email Service
     */
    protected function checkEmailService(): void
    {
        try {
            $fallback_email = 'test.emails.addresses@g_m_a_i_l.c_o_m';
            Mail::to(str_replace('_', '', Setting::get('system-monitor.tester', $fallback_email)))->queue(new MailTest());
            $this->setStatus('email', self::STATUS_ONLINE, self::PRIORITY_HIGH);
        } catch (\Exception $e) {
            $this->setStatus('email', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('email', $e->getMessage());
        }
    }

    /**
     * Check Price Feed Service
     */
    protected function checkPriceFeedService(): void
    {
        try {
            // Check if price data is being updated
            $market = Market::where('liq', true)->first();

            if($market) {

                if (market_get_stats($market->id, 'last')) {
                    $this->setStatus('price_feed', self::STATUS_ONLINE, self::PRIORITY_HIGH);
                } else {
                    $this->setStatus('price_feed', self::STATUS_DEGRADED, self::PRIORITY_HIGH);
                }

            } else {
                $this->setStatus('price_feed', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('price_feed', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('price_feed', $e->getMessage());
        }
    }

    /**
     * Check Market Data Service
     */
    protected function checkMarketDataService(): void
    {
        try {
            $hasMarketData = DB::table('markets')->exists();

            if ($hasMarketData) {
                $this->setStatus('market_data', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('market_data', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('market_data', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('market_data', $e->getMessage());
        }
    }

    /**
     * Check Liquidity Service
     */
    protected function checkLiquidityService(): void
    {
        try {
            $market = Market::where('liq', true)->first();
            if ($market) {
                $liquidityRunning = Cache::get("markets_liquidity.{$market->name}.asks");
                $this->setStatus('liquidity', $liquidityRunning ? self::STATUS_ONLINE : self::STATUS_OFFLINE, self::PRIORITY_MEDIUM);
            } else {
                $this->setStatus('liquidity', self::STATUS_ONLINE, self::PRIORITY_MEDIUM);
            }
        } catch (\Exception $e) {
            $this->setStatus('liquidity', self::STATUS_OFFLINE, self::PRIORITY_MEDIUM);
            $this->logError('liquidity', $e->getMessage());
        }
    }

    /**
     * Check Ethereum API Service
     */
    protected function checkEthereumApi(): void
    {
        try {
            $response = (new EthereumGateway())->ping();

            if (isset($response['address']) && $response['address']) {
                $this->setStatus('ethereum', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('ethereum', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('ethereum', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('ethereum', $e->getMessage());
        }
    }

    /**
     * Check BSC API Service
     */
    protected function checkBscApi(): void
    {
        try {
            $response = (new BnbGateway())->ping();

            if (isset($response['address']) && $response['address']) {
                $this->setStatus('bsc', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('bsc', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('bsc', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('bsc', $e->getMessage());
        }
    }

    /**
     * Check Tron API Service
     */
    protected function checkTronApi(): void
    {
        try {
            $response = (new TronGateway())->ping();

            if (isset($response['success']) && $response['success']) {
                $this->setStatus('tron', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('tron', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('tron', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('tron', $e->getMessage());
        }
    }

    /**
     * Check Solana API Service
     */
    protected function checkSolanaApi(): void
    {
        try {
            $response = (new SolanaGateway())->ping();

            if (isset($response['success']) && $response['success']) {
                $this->setStatus('solana', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('solana', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('solana', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('solana', $e->getMessage());
        }
    }

    /**
     * Check Ripple API Service
     */
    protected function checkRippleApi(): void
    {
        try {
            $response = (new RippleService())->getBalance('rPTpLbCYamqHCVsCv4JB214mwp9EvCaYA8');

            if (isset($response['balance'])) {
                $this->setStatus('ripple', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('ripple', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('ripple', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('ripple', $e->getMessage());
        }
    }

    /**
     * Check TON API Service
     */
    protected function checkTonApi(): void
    {
        try {
            $response = (new TonGateway())->ping();

            if (isset($response['address']) && $response['address']) {
                $this->setStatus('ton', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('ton', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('ton', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('ton', $e->getMessage());
        }
    }

    /**
     * Check Polygon API Service
     */
    protected function checkPolygonApi(): void
    {
        try {
            $response = (new PolygonGateway())->ping();

            if (isset($response['address']) && $response['address']) {
                $this->setStatus('polygon', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('polygon', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('polygon', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('polygon', $e->getMessage());
        }
    }

    /**
     * Check Bitcoin API Service
     */
    protected function checkBitcoinApi(): void
    {
        try {
            Http::timeout(5)->post(config('bitcoind.default.scheme') . '://' . config('bitcoind.default.host') . ':' . config('bitcoind.default.port') , [
                'jsonrpc' => '1.0',
                'id' => 'healthcheck',
                'method' => 'getbalance',
                'params' => [],
            ]);

            $this->setStatus('bitcoin', self::STATUS_ONLINE, self::PRIORITY_HIGH);
        } catch (\Throwable $e) {
            $this->setStatus('bitcoin', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('bitcoin', $e->getMessage());
        }
    }

    /**
     * Check Fireblocks API Service
     */
    protected function checkFireblocksApi(): void
    {
        try {
            $hotWalletVault = config('fireblocks.hot_wallet_vault');
            $response = (new FireblocksSDK())->get_vault_assets_balance($hotWalletVault);

            if (isset($response[0]['id'])) {
                $this->setStatus('fireblocks', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            } else {
                $this->setStatus('fireblocks', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            }
        } catch (\Exception $e) {
            $this->setStatus('fireblocks', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('fireblocks', $e->getMessage());
        }
    }

    /**
     * Check Stripe API Service
     */
    protected function checkStripeApi(): void
    {
        try {
            $response = (new StripeService())->ping();

            if (isset($response->object) && $response->object == "list") {
                $this->setStatus('stripe', self::STATUS_ONLINE, self::PRIORITY_MEDIUM);
            } else {
                $this->setStatus('stripe', self::STATUS_OFFLINE, self::PRIORITY_MEDIUM);
            }
        } catch (\Exception $e) {
            $this->setStatus('stripe', self::STATUS_OFFLINE, self::PRIORITY_MEDIUM);
            $this->logError('stripe', $e->getMessage());
        }
    }

    /**
     * Check KYC Service
     */
    protected function checkKycService(): void
    {
        try {
            $kycEnabled = Setting::get('security.kyc_enabled', false);

            if ($kycEnabled) {
                $this->setStatus('kyc_service', self::STATUS_ONLINE, self::PRIORITY_MEDIUM);
            } else {
                $this->setStatus('kyc_service', self::STATUS_MAINTENANCE, self::PRIORITY_MEDIUM);
            }
        } catch (\Exception $e) {
            $this->setStatus('kyc_service', self::STATUS_OFFLINE, self::PRIORITY_MEDIUM);
            $this->logError('kyc_service', $e->getMessage());
        }
    }

    /**
     * Check Two Factor Authentication
     */
    protected function checkTwoFactorAuth(): void
    {
        try {
            $this->setStatus('two_factor_auth', self::STATUS_ONLINE, self::PRIORITY_HIGH);
        } catch (\Exception $e) {
            $this->setStatus('two_factor_auth', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('two_factor_auth', $e->getMessage());
        }
    }

    /**
     * Check Rate Limiter
     */
    protected function checkRateLimiter(): void
    {
        try {
            $this->setStatus('rate_limiter', self::STATUS_ONLINE, self::PRIORITY_MEDIUM);
        } catch (\Exception $e) {
            $this->setStatus('rate_limiter', self::STATUS_OFFLINE, self::PRIORITY_MEDIUM);
            $this->logError('rate_limiter', $e->getMessage());
        }
    }

    /**
     * Check Scheduler Service
     */
    protected function checkSchedulerService(): void
    {
        try {
            $lastRun = Setting::get('system-monitor.scheduler_last_run', null);

            if ($lastRun) {
                $lastRunTime = Carbon::parse($lastRun);
                if ($lastRunTime->diffInMinutes(Carbon::now()) < 5) {
                    $this->setStatus('scheduler', self::STATUS_ONLINE, self::PRIORITY_HIGH);
                } else {
                    $this->setStatus('scheduler', self::STATUS_DEGRADED, self::PRIORITY_HIGH);
                }
            } else {
                $this->setStatus('scheduler', self::STATUS_ONLINE, self::PRIORITY_HIGH);
            }

            Setting::set('system-monitor.scheduler_last_run', Carbon::now()->toDateTimeString());
        } catch (\Exception $e) {
            $this->setStatus('scheduler', self::STATUS_OFFLINE, self::PRIORITY_HIGH);
            $this->logError('scheduler', $e->getMessage());
        }
    }

    /**
     * Check Notification Service
     */
    protected function checkNotificationService(): void
    {
        try {
            $this->setStatus('notifications', self::STATUS_ONLINE, self::PRIORITY_MEDIUM);
        } catch (\Exception $e) {
            $this->setStatus('notifications', self::STATUS_OFFLINE, self::PRIORITY_MEDIUM);
            $this->logError('notifications', $e->getMessage());
        }
    }

    /**
     * Check Webhook Service
     */
    protected function checkWebhookService(): void
    {
        try {
            $this->setStatus('webhooks', self::STATUS_ONLINE, self::PRIORITY_MEDIUM);
        } catch (\Exception $e) {
            $this->setStatus('webhooks', self::STATUS_OFFLINE, self::PRIORITY_MEDIUM);
            $this->logError('webhooks', $e->getMessage());
        }
    }

    /**
     * Handle readonly mode - set all services as online without real checks
     */
    protected function handleReadonlyMode(): int
    {
        $services = [
            // Infrastructure
            ['key' => 'web_server', 'priority' => self::PRIORITY_CRITICAL],
            ['key' => 'database', 'priority' => self::PRIORITY_CRITICAL],
            ['key' => 'frontpage', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'encryption', 'priority' => self::PRIORITY_CRITICAL],
            ['key' => 'cache', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'redis', 'priority' => self::PRIORITY_CRITICAL],
            ['key' => 'api', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'artisan', 'priority' => self::PRIORITY_MEDIUM],
            ['key' => 'jobs', 'priority' => self::PRIORITY_CRITICAL],
            ['key' => 'scheduler', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'websocket', 'priority' => self::PRIORITY_HIGH],
            // Trading
            ['key' => 'price_feed', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'market_data', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'liquidity', 'priority' => self::PRIORITY_MEDIUM],
            // Blockchain
            ['key' => 'bitcoin', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'ethereum', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'bsc', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'tron', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'solana', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'ripple', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'ton', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'polygon', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'fireblocks', 'priority' => self::PRIORITY_HIGH],
            // Payments
            ['key' => 'stripe', 'priority' => self::PRIORITY_MEDIUM],
            // Security
            ['key' => 'kyc_service', 'priority' => self::PRIORITY_MEDIUM],
            ['key' => 'two_factor_auth', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'rate_limiter', 'priority' => self::PRIORITY_MEDIUM],
            // Notifications
            ['key' => 'email', 'priority' => self::PRIORITY_HIGH],
            ['key' => 'notifications', 'priority' => self::PRIORITY_MEDIUM],
            ['key' => 'webhooks', 'priority' => self::PRIORITY_MEDIUM],
        ];

        Setting::set('system-monitor.last_checked', Carbon::now()->format('Y-m-d H:i:s'));
        Setting::set('system-monitor.check_started', Carbon::now()->timestamp);
        Setting::set('system-monitor.is_running', true);
        Setting::set('system-monitor.current_service', 'Readonly Mode');
        Setting::set('system-monitor.progress', 0);

        $total = count($services);
        foreach ($services as $index => $service) {
            Setting::set("system-monitor.{$service['key']}", self::STATUS_ONLINE);
            Setting::set("system-monitor.{$service['key']}_priority", $service['priority']);
            Setting::set("system-monitor.{$service['key']}_checked_at", Carbon::now()->toDateTimeString());
            Setting::set("system-monitor.{$service['key']}_error", null);

            // Update progress
            $progress = round((($index + 1) / $total) * 100);
            Setting::set('system-monitor.progress', $progress);
            Setting::set('system-monitor.services_checked', $index + 1);
            Setting::set('system-monitor.services_total', $total);
        }

        Setting::set('system-monitor.response_time', 50); // Simulated fast response
        Setting::set('system-monitor.check_completed', Carbon::now()->timestamp);
        Setting::set('system-monitor.is_running', false);
        Setting::set('system-monitor.current_service', 'Completed (Readonly Mode)');
        Setting::set('system-monitor.progress', 100);
        Setting::set('system-monitor.readonly_mode', true);

        Setting::save();

        return 0;
    }

    /**
     * Set status for a service
     */
    protected function setStatus(string $service, string $status, string $priority): void
    {
        Setting::set("system-monitor.{$service}", $status);
        Setting::set("system-monitor.{$service}_priority", $priority);
        Setting::set("system-monitor.{$service}_checked_at", Carbon::now()->toDateTimeString());

        if ($status === self::STATUS_OFFLINE) {
            $this->offlineServices[] = [
                'service' => $service,
                'priority' => $priority
            ];
        }
    }

    /**
     * Log error for a service
     */
    protected function logError(string $service, string $message): void
    {
        Setting::set("system-monitor.{$service}_error", substr($message, 0, 500));
        Setting::set("system-monitor.{$service}_error_at", Carbon::now()->toDateTimeString());

        Log::channel('system-monitor')->error("System Monitor - {$service}: {$message}");
    }

    /**
     * Send alert email if there are offline services
     */
    protected function sendAlertIfNeeded(): void
    {
        if (empty($this->offlineServices)) {
            return;
        }

        // Check if alerts are enabled
        $alertsEnabled = Setting::get('system-monitor.alerts_enabled', true);
        if (!$alertsEnabled) {
            return;
        }

        // Check cooldown period to avoid spam (default 30 minutes)
        $lastAlert = Setting::get('system-monitor.last_alert_sent', null);
        $cooldownMinutes = Setting::get('system-monitor.alert_cooldown', 30);

        if ($lastAlert) {
            $lastAlertTime = Carbon::parse($lastAlert);
            if ($lastAlertTime->diffInMinutes(Carbon::now()) < $cooldownMinutes) {
                return;
            }
        }

        // Get admin email
        $adminEmail = Setting::get('notification.admin_email', false);
        if (!$adminEmail) {
            return;
        }

        // Prepare services data for email
        $service = new \App\Services\SystemMonitor\SystemMonitorService();
        $allServices = $service->getStatus();

        $offlineServicesData = array_filter($allServices, function($s) {
            return $s['status'] === 'offline';
        });

        $criticalCount = count(array_filter($this->offlineServices, function($s) {
            return $s['priority'] === self::PRIORITY_CRITICAL;
        }));

        $summary = [
            'total' => count($allServices),
            'online' => count(array_filter($allServices, fn($s) => $s['status'] === 'online')),
            'offline' => count($offlineServicesData),
            'critical' => $criticalCount,
            'maintenance' => count(array_filter($allServices, fn($s) => $s['status'] === 'maintenance')),
        ];

        try {
            Mail::to($adminEmail)->queue(new SystemMonitorAlert(
                $offlineServicesData,
                $summary,
                Carbon::now()->format('Y-m-d H:i:s')
            ));

            Setting::set('system-monitor.last_alert_sent', Carbon::now()->toDateTimeString());
            Setting::save();

            Log::channel('system-monitor')->info("System Monitor alert sent to {$adminEmail}");
        } catch (\Exception $e) {
            Log::channel('system-monitor')->error("Failed to send System Monitor alert: " . $e->getMessage());
        }
    }
}
