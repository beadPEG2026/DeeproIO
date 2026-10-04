<?php

namespace App\Services\SystemMonitor;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Setting;

class SystemMonitorService
{
    /**
     * Service Categories
     */
    const CATEGORY_INFRASTRUCTURE = 'Infrastructure';
    const CATEGORY_TRADING = 'Trading Engine';
    const CATEGORY_BLOCKCHAIN = 'Blockchain Networks';
    const CATEGORY_PAYMENTS = 'Payment Services';
    const CATEGORY_SECURITY = 'Security';
    const CATEGORY_NOTIFICATIONS = 'Notifications';

    /**
     * Get all services status organized by category
     */
    public function getStatus(): array
    {
        $monitor = Setting::get('system-monitor');

        $services = [];

        // ============================================
        // INFRASTRUCTURE SERVICES
        // ============================================

        $services[] = [
            'order' => 1,
            'title' => 'Web Server',
            'description' => 'Handles web page rendering and overall website performance.',
            'status' => $monitor['web_server'] ?? null,
            'icon' => 'server',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => $monitor['web_server_priority'] ?? 'critical',
            'last_checked' => $monitor['web_server_checked_at'] ?? null,
            'error' => $monitor['web_server_error'] ?? null,
        ];

        $services[] = [
            'order' => 2,
            'title' => 'Database Connection',
            'description' => 'MySQL/PostgreSQL database connectivity for all data operations.',
            'status' => $monitor['database'] ?? null,
            'icon' => 'database',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => $monitor['database_priority'] ?? 'critical',
            'last_checked' => $monitor['database_checked_at'] ?? null,
            'error' => $monitor['database_error'] ?? null,
        ];

        $services[] = [
            'order' => 3,
            'title' => 'Redis Server',
            'description' => 'In-memory data store for caching, sessions, and real-time data.',
            'status' => $monitor['redis'] ?? null,
            'icon' => 'memory',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => $monitor['redis_priority'] ?? 'critical',
            'last_checked' => $monitor['redis_checked_at'] ?? null,
            'error' => $monitor['redis_error'] ?? null,
        ];

        $services[] = [
            'order' => 4,
            'title' => 'Website Access',
            'description' => 'Checks if the frontpage is publicly accessible.',
            'status' => $monitor['frontpage'] ?? null,
            'icon' => 'globe',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => $monitor['frontpage_priority'] ?? 'high',
            'last_checked' => $monitor['frontpage_checked_at'] ?? null,
            'error' => $monitor['frontpage_error'] ?? null,
        ];

        $services[] = [
            'order' => 5,
            'title' => 'Encryption Module',
            'description' => 'Data encryption service for sensitive information protection.',
            'status' => $monitor['encryption'] ?? null,
            'icon' => 'lock',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => $monitor['encryption_priority'] ?? 'critical',
            'last_checked' => $monitor['encryption_checked_at'] ?? null,
            'error' => $monitor['encryption_error'] ?? null,
        ];

        $services[] = [
            'order' => 6,
            'title' => 'Cache Service',
            'description' => 'Application cache for accelerated data access and performance.',
            'status' => $monitor['cache'] ?? null,
            'icon' => 'bolt',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => $monitor['cache_priority'] ?? 'high',
            'last_checked' => $monitor['cache_checked_at'] ?? null,
            'error' => $monitor['cache_error'] ?? null,
        ];

        $services[] = [
            'order' => 7,
            'title' => 'API Gateway',
            'description' => 'REST API service for mobile apps, trading bots, and third-party integrations.',
            'status' => $monitor['api'] ?? null,
            'icon' => 'wifi',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => $monitor['api_priority'] ?? 'high',
            'last_checked' => $monitor['api_checked_at'] ?? null,
            'error' => $monitor['api_error'] ?? null,
        ];

        $services[] = [
            'order' => 8,
            'title' => 'Artisan Commands',
            'description' => 'System CLI commands for automated tasks and maintenance.',
            'status' => $monitor['artisan'] ?? null,
            'icon' => 'terminal',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => $monitor['artisan_priority'] ?? 'medium',
            'last_checked' => $monitor['artisan_checked_at'] ?? null,
            'error' => $monitor['artisan_error'] ?? null,
        ];

        $services[] = [
            'order' => 9,
            'title' => 'Job Queue Processor',
            'description' => 'Background job processing for async operations (emails, notifications, etc.).',
            'status' => $monitor['jobs'] ?? null,
            'icon' => 'tasks',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => $monitor['jobs_priority'] ?? 'critical',
            'last_checked' => $monitor['jobs_checked_at'] ?? null,
            'error' => $monitor['jobs_error'] ?? null,
        ];

        $services[] = [
            'order' => 10,
            'title' => 'Task Scheduler',
            'description' => 'Cron scheduler for recurring tasks and automated processes.',
            'status' => $monitor['scheduler'] ?? null,
            'icon' => 'clock',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => $monitor['scheduler_priority'] ?? 'high',
            'last_checked' => $monitor['scheduler_checked_at'] ?? null,
            'error' => $monitor['scheduler_error'] ?? null,
        ];

        $services[] = [
            'order' => 11,
            'title' => 'Websockets',
            'description' => 'Real-time market data streaming and live updates.',
            'status' => $monitor['websocket'] ?? null,
            'icon' => 'sync-alt',
            'category' => self::CATEGORY_INFRASTRUCTURE,
            'priority' => 'high',
            'last_checked' => $monitor['websocket_checked_at'] ?? null,
            'error' => $monitor['websocket_error'] ?? null,
        ];

        // ============================================
        // TRADING ENGINE SERVICES
        // ============================================

        $services[] = [
            'order' => 14,
            'title' => 'Price Feed Service',
            'description' => 'Real-time price data aggregation from multiple sources.',
            'status' => $monitor['price_feed'] ?? null,
            'icon' => 'stream',
            'category' => self::CATEGORY_TRADING,
            'priority' => $monitor['price_feed_priority'] ?? 'high',
            'last_checked' => $monitor['price_feed_checked_at'] ?? null,
            'error' => $monitor['price_feed_error'] ?? null,
        ];

        $services[] = [
            'order' => 15,
            'title' => 'Market Data Service',
            'description' => 'Historical and real-time market data for charts and analytics.',
            'status' => $monitor['market_data'] ?? null,
            'icon' => 'chart-bar',
            'category' => self::CATEGORY_TRADING,
            'priority' => $monitor['market_data_priority'] ?? 'high',
            'last_checked' => $monitor['market_data_checked_at'] ?? null,
            'error' => $monitor['market_data_error'] ?? null,
        ];

        $services[] = [
            'order' => 17,
            'title' => 'Liquidity Provider',
            'description' => 'External liquidity aggregation and market making service.',
            'status' => $monitor['liquidity'] ?? null,
            'icon' => 'water',
            'category' => self::CATEGORY_TRADING,
            'priority' => $monitor['liquidity_priority'] ?? 'medium',
            'last_checked' => $monitor['liquidity_checked_at'] ?? null,
            'error' => $monitor['liquidity_error'] ?? null,
        ];

        // ============================================
        // BLOCKCHAIN NETWORK SERVICES
        // ============================================

        $services[] = [
            'order' => 18,
            'title' => 'Bitcoin Node',
            'description' => 'Bitcoin blockchain node for BTC transactions and wallet operations.',
            'status' => $monitor['bitcoin'] ?? null,
            'icon' => 'coins',
            'category' => self::CATEGORY_BLOCKCHAIN,
            'priority' => $monitor['bitcoin_priority'] ?? 'high',
            'last_checked' => $monitor['bitcoin_checked_at'] ?? null,
            'error' => $monitor['bitcoin_error'] ?? null,
        ];

        $services[] = [
            'order' => 19,
            'title' => 'Ethereum Node (ERC-20)',
            'description' => 'Ethereum network connectivity for ETH and ERC-20 tokens.',
            'status' => $monitor['ethereum'] ?? null,
            'icon' => 'code-branch',
            'category' => self::CATEGORY_BLOCKCHAIN,
            'priority' => $monitor['ethereum_priority'] ?? 'high',
            'last_checked' => $monitor['ethereum_checked_at'] ?? null,
            'error' => $monitor['ethereum_error'] ?? null,
        ];

        $services[] = [
            'order' => 20,
            'title' => 'Binance Smart Chain (BEP-20)',
            'description' => 'BSC network connectivity for BNB and BEP-20 tokens.',
            'status' => $monitor['bsc'] ?? null,
            'icon' => 'code-branch',
            'category' => self::CATEGORY_BLOCKCHAIN,
            'priority' => $monitor['bsc_priority'] ?? 'high',
            'last_checked' => $monitor['bsc_checked_at'] ?? null,
            'error' => $monitor['bsc_error'] ?? null,
        ];

        $services[] = [
            'order' => 21,
            'title' => 'TRON Network (TRC-20)',
            'description' => 'TRON network connectivity for TRX and TRC-20 tokens.',
            'status' => $monitor['tron'] ?? null,
            'icon' => 'code-branch',
            'category' => self::CATEGORY_BLOCKCHAIN,
            'priority' => $monitor['tron_priority'] ?? 'high',
            'last_checked' => $monitor['tron_checked_at'] ?? null,
            'error' => $monitor['tron_error'] ?? null,
        ];

        $services[] = [
            'order' => 22,
            'title' => 'Solana Network',
            'description' => 'Solana blockchain connectivity for SOL and SPL tokens.',
            'status' => $monitor['solana'] ?? null,
            'icon' => 'code-branch',
            'category' => self::CATEGORY_BLOCKCHAIN,
            'priority' => $monitor['solana_priority'] ?? 'high',
            'last_checked' => $monitor['solana_checked_at'] ?? null,
            'error' => $monitor['solana_error'] ?? null,
        ];

        $services[] = [
            'order' => 23,
            'title' => 'Ripple (XRP) Network',
            'description' => 'XRP Ledger connectivity for XRP transactions.',
            'status' => $monitor['ripple'] ?? null,
            'icon' => 'code-branch',
            'category' => self::CATEGORY_BLOCKCHAIN,
            'priority' => $monitor['ripple_priority'] ?? 'high',
            'last_checked' => $monitor['ripple_checked_at'] ?? null,
            'error' => $monitor['ripple_error'] ?? null,
        ];

        $services[] = [
            'order' => 24,
            'title' => 'TON Network',
            'description' => 'The Open Network connectivity for TON transactions.',
            'status' => $monitor['ton'] ?? null,
            'icon' => 'code-branch',
            'category' => self::CATEGORY_BLOCKCHAIN,
            'priority' => $monitor['ton_priority'] ?? 'high',
            'last_checked' => $monitor['ton_checked_at'] ?? null,
            'error' => $monitor['ton_error'] ?? null,
        ];

        $services[] = [
            'order' => 25,
            'title' => 'Polygon Network',
            'description' => 'Polygon (MATIC) network connectivity for Layer 2 transactions.',
            'status' => $monitor['polygon'] ?? null,
            'icon' => 'code-branch',
            'category' => self::CATEGORY_BLOCKCHAIN,
            'priority' => $monitor['polygon_priority'] ?? 'high',
            'last_checked' => $monitor['polygon_checked_at'] ?? null,
            'error' => $monitor['polygon_error'] ?? null,
        ];

        $services[] = [
            'order' => 26,
            'title' => 'Fireblocks Custody',
            'description' => 'Enterprise-grade digital asset custody and wallet infrastructure.',
            'status' => $monitor['fireblocks'] ?? null,
            'icon' => 'shield-alt',
            'category' => self::CATEGORY_BLOCKCHAIN,
            'priority' => $monitor['fireblocks_priority'] ?? 'high',
            'last_checked' => $monitor['fireblocks_checked_at'] ?? null,
            'error' => $monitor['fireblocks_error'] ?? null,
        ];

        // ============================================
        // PAYMENT SERVICES
        // ============================================

        $services[] = [
            'order' => 27,
            'title' => 'Stripe Payment Gateway',
            'description' => 'Credit/Debit card payment processing for fiat deposits.',
            'status' => $monitor['stripe'] ?? null,
            'icon' => 'credit-card',
            'category' => self::CATEGORY_PAYMENTS,
            'priority' => $monitor['stripe_priority'] ?? 'medium',
            'last_checked' => $monitor['stripe_checked_at'] ?? null,
            'error' => $monitor['stripe_error'] ?? null,
        ];

        // ============================================
        // SECURITY SERVICES
        // ============================================

        $services[] = [
            'order' => 30,
            'title' => 'KYC Verification',
            'description' => 'Know Your Customer identity verification service.',
            'status' => $monitor['kyc_service'] ?? null,
            'icon' => 'id-card',
            'category' => self::CATEGORY_SECURITY,
            'priority' => $monitor['kyc_service_priority'] ?? 'medium',
            'last_checked' => $monitor['kyc_service_checked_at'] ?? null,
            'error' => $monitor['kyc_service_error'] ?? null,
        ];

        $services[] = [
            'order' => 32,
            'title' => 'Two-Factor Authentication',
            'description' => '2FA service for secure user account protection.',
            'status' => $monitor['two_factor_auth'] ?? null,
            'icon' => 'key',
            'category' => self::CATEGORY_SECURITY,
            'priority' => $monitor['two_factor_auth_priority'] ?? 'high',
            'last_checked' => $monitor['two_factor_auth_checked_at'] ?? null,
            'error' => $monitor['two_factor_auth_error'] ?? null,
        ];

        $services[] = [
            'order' => 33,
            'title' => 'Rate Limiter',
            'description' => 'API rate limiting and DDoS protection service.',
            'status' => $monitor['rate_limiter'] ?? null,
            'icon' => 'tachometer-alt',
            'category' => self::CATEGORY_SECURITY,
            'priority' => $monitor['rate_limiter_priority'] ?? 'medium',
            'last_checked' => $monitor['rate_limiter_checked_at'] ?? null,
            'error' => $monitor['rate_limiter_error'] ?? null,
        ];

        // ============================================
        // NOTIFICATION SERVICES
        // ============================================

        $services[] = [
            'order' => 34,
            'title' => 'Email Service',
            'description' => 'Transactional email delivery for notifications and alerts.',
            'status' => $monitor['email'] ?? null,
            'icon' => 'envelope',
            'category' => self::CATEGORY_NOTIFICATIONS,
            'priority' => $monitor['email_priority'] ?? 'high',
            'last_checked' => $monitor['email_checked_at'] ?? null,
            'error' => $monitor['email_error'] ?? null,
        ];

        $services[] = [
            'order' => 35,
            'title' => 'Push Notifications',
            'description' => 'Real-time push notifications for mobile and web clients.',
            'status' => $monitor['notifications'] ?? null,
            'icon' => 'bell',
            'category' => self::CATEGORY_NOTIFICATIONS,
            'priority' => $monitor['notifications_priority'] ?? 'medium',
            'last_checked' => $monitor['notifications_checked_at'] ?? null,
            'error' => $monitor['notifications_error'] ?? null,
        ];

        $services[] = [
            'order' => 36,
            'title' => 'Webhook Delivery',
            'description' => 'Outbound webhook delivery for merchant and API integrations.',
            'status' => $monitor['webhooks'] ?? null,
            'icon' => 'share-alt',
            'category' => self::CATEGORY_NOTIFICATIONS,
            'priority' => $monitor['webhooks_priority'] ?? 'medium',
            'last_checked' => $monitor['webhooks_checked_at'] ?? null,
            'error' => $monitor['webhooks_error'] ?? null,
        ];

        foreach ($services as &$service) {
            try { $stale = empty($service['last_checked']) || Carbon::parse($service['last_checked'])->lt(now()->subMinutes(5)); }
            catch (\Throwable $e) { $stale = true; }
            $service['stale'] = $stale;
            if ($stale) { $service['status'] = 'unknown'; $service['error'] = __('Health check is missing or older than five minutes.'); }
        }
        unset($service);
        return $services;
    }

    /**
     * Get services grouped by category
     */
    public function getStatusGrouped(): array
    {
        $services = $this->getStatus();
        $grouped = [];

        foreach ($services as $service) {
            $category = $service['category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $service;
        }

        return $grouped;
    }

    /**
     * Get summary statistics
     */
    public function getSummary(): array
    {
        $services = $this->getStatus();
        $monitor = Setting::get('system-monitor');

        $online = 0;
        $offline = 0;
        $maintenance = 0;
        $degraded = 0;
        $notChecked = 0;
        $critical = 0;

        foreach ($services as $service) {
            switch ($service['status']) {
                case 'online':
                    $online++;
                    break;
                case 'offline':
                    $offline++;
                    if ($service['priority'] === 'critical') {
                        $critical++;
                    }
                    break;
                case 'maintenance':
                    $maintenance++;
                    break;
                case 'degraded':
                    $degraded++;
                    break;
                default:
                    $notChecked++;
            }
        }

        return [
            'total' => count($services),
            'online' => $online,
            'offline' => $offline,
            'maintenance' => $maintenance,
            'degraded' => $degraded,
            'not_checked' => $notChecked,
            'critical_offline' => $critical,
            'uptime_percentage' => count($services) > 0
                ? round(($online / count($services)) * 100, 1)
                : 0,
            'last_checked' => $monitor['last_checked'] ?? null,
            'response_time' => $monitor['response_time'] ?? null,
            'last_alert_sent' => $monitor['last_alert_sent'] ?? null,
            'stuck_withdrawals' => $monitor['stuck_withdrawals'] ?? 0,
            'readonly_mode' => config('app.readonly', false),
        ];
    }

    /**
     * Get alert settings
     */
    public function getAlertSettings(): array
    {
        return [
            'enabled' => Setting::get('system-monitor.alerts_enabled', true),
            'cooldown' => Setting::get('system-monitor.alert_cooldown', 30),
            'admin_email' => Setting::get('notification.admin_email', null),
        ];
    }

    /**
     * Update alert settings
     */
    public function updateAlertSettings(array $data): void
    {
        if (isset($data['enabled'])) {
            Setting::set('system-monitor.alerts_enabled', $data['enabled']);
        }
        if (isset($data['cooldown'])) {
            Setting::set('system-monitor.alert_cooldown', $data['cooldown']);
        }
        Setting::save();
    }

    /**
     * Get service history/logs
     */
    public function getRecentAlerts(): array
    {
        // This would typically come from a database table
        // For now, return mock data structure
        return [];
    }

    /**
     * Get current test progress
     */
    public function getTestProgress(): array
    {
        $monitor = Setting::get('system-monitor');

        return [
            'is_running' => $monitor['is_running'] ?? false,
            'current_service' => $monitor['current_service'] ?? null,
            'progress' => $monitor['progress'] ?? 0,
            'services_checked' => $monitor['services_checked'] ?? 0,
            'services_total' => $monitor['services_total'] ?? 0,
            'check_started' => $monitor['check_started'] ?? null,
        ];
    }

    /**
     * Start monitoring test
     */
    public function startTest(): void
    {
        Artisan::call('system-monitor:test');
    }

    /**
     * Start monitoring test with alerts
     */
    public function startTestWithAlerts(): void
    {
        Artisan::call('system-monitor:test', ['--alert' => true]);
    }

    /**
     * Get categories list
     */
    public function getCategories(): array
    {
        return [
            self::CATEGORY_INFRASTRUCTURE,
            self::CATEGORY_TRADING,
            self::CATEGORY_BLOCKCHAIN,
            self::CATEGORY_PAYMENTS,
            self::CATEGORY_SECURITY,
            self::CATEGORY_NOTIFICATIONS,
        ];
    }
}
