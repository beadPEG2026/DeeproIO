<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Merchant Acquiring Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains all configuration options for the Crypto Acquiring
    | module that allows merchants to accept cryptocurrency payments.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | General Settings
    |--------------------------------------------------------------------------
    */

    'enabled' => env('MERCHANT_ACQUIRING_ENABLED', false),
    'environment' => env('MERCHANT_ACQUIRING_ENV', 'live'), // live, sandbox
    'checkout_base_url' => env('MERCHANT_CHECKOUT_URL', 'https://pay.example.com'),
    'api_version' => '2024-01-01',

    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    */

    'invoice' => [
        // Default expiration times (in seconds)
        'selection_expiry' => env('INVOICE_SELECTION_EXPIRY', 3600), // 60 minutes to select currency
        'payment_expiry' => env('INVOICE_PAYMENT_EXPIRY', 1800), // 30 minutes to complete payment
        'rate_validity' => env('INVOICE_RATE_VALIDITY', 900), // 15 minutes rate lock

        // Rate extension
        'max_rate_extensions' => 2,
        'rate_extension_duration' => 300, // 5 minutes per extension

        // Grace period for late payments
        'grace_period' => 300, // 5 minutes after expiry
        'monitor_window' => 86400, // 24 hours to monitor for late payments

        // Amount limits (USD)
        'min_amount_usd' => env('INVOICE_MIN_AMOUNT', 1.00),
        'max_amount_usd' => env('INVOICE_MAX_AMOUNT', 100000.00),

        // Default tolerance for payment matching
        'underpayment_tolerance_percent' => 1.00, // Accept payments 1% under
        'overpayment_tolerance_percent' => 5.00, // Minor overpayment threshold
        'dust_threshold_usd' => 0.50, // Ignore amounts below this
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Classification
    |--------------------------------------------------------------------------
    */

    'payment' => [
        'exact_tolerance_percent' => 1.00,
        'minor_overpayment_percent' => 5.00,
        'moderate_overpayment_percent' => 20.00,
        // Above moderate = major overpayment

        'underpayment_topup_window' => 86400, // 24 hours to top up
        'auto_refund_overpayment_above_percent' => 20.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate/Pricing Settings
    |--------------------------------------------------------------------------
    */

    'pricing' => [
        'default_rate_source' => env('RATE_SOURCE', 'binance'), // binance, coingecko, internal, fixed
        'rate_cache_ttl' => 30, // seconds
        'rate_stale_threshold' => 60, // seconds before rate is considered stale

        'spread_percent' => 0.50, // Default spread applied to rates
        'use_ask_for_buy' => true, // Use ask price when buyer is paying

        // Precision
        'usd_precision' => 2,
        'crypto_precision' => 8,
        'rate_precision' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    */

    'webhook' => [
        'enabled' => true,
        'timeout' => 10, // HTTP request timeout in seconds
        'connect_timeout' => 5,

        // Retry configuration
        'max_attempts' => 5,
        'retry_delays' => [
            1 => 30,      // 30 seconds
            2 => 120,     // 2 minutes
            3 => 600,     // 10 minutes
            4 => 3600,    // 1 hour
            5 => 14400,   // 4 hours
        ],

        // Circuit breaker
        'circuit_breaker_threshold' => 5, // Consecutive failures
        'circuit_breaker_reset_time' => 3600, // 1 hour

        // Signature
        'signature_algorithm' => 'sha256',
        'signature_header' => 'X-Webhook-Signature',
        'timestamp_header' => 'X-Webhook-Timestamp',
        'timestamp_tolerance' => 300, // 5 minutes

        // Events that trigger webhooks
        'events' => [
            'invoice.created',
            'invoice.pending',
            'invoice.payment_detecting',
            'invoice.confirming',
            'invoice.paid',
            'invoice.underpaid',
            'invoice.overpaid',
            'invoice.settled',
            'invoice.expired',
            'invoice.cancelled',
            'invoice.failed',
            'invoice.late_payment',
            'refund.initiated',
            'refund.completed',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Blockchain Settings
    |--------------------------------------------------------------------------
    */

    'blockchain' => [
        // Detection
        'mempool_detection' => true,
        'detection_interval' => 10, // seconds between checks
        'confirmation_check_interval' => 15, // seconds

        // Node failover
        'node_health_check_interval' => 30,
        'node_failover_threshold' => 3, // consecutive failures

        // Reorg protection
        'reorg_protection_confirmations' => 6,
        'deep_reorg_threshold' => 3, // blocks
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    */

    'security' => [
        // API Authentication
        'api_timestamp_tolerance' => 30, // seconds
        'nonce_expiry' => 300, // 5 minutes
        'signature_algorithm' => 'sha256',

        // Rate limiting
        'rate_limit' => [
            'invoice_creation_per_minute' => 10,
            'invoice_creation_per_hour' => 100,
            'invoice_creation_per_day' => 500,
            'api_requests_per_minute' => 60,
            'api_requests_per_hour' => 1000,
        ],

        // Address policy
        'address_reuse' => false, // Never reuse addresses
        'address_expiry_after_use' => 86400, // 24 hours before release

        // Velocity checks
        'velocity_check_window' => 3600, // 1 hour
        'max_invoices_per_email_per_hour' => 10,
        'suspicious_amount_threshold_usd' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Merchant Settings
    |--------------------------------------------------------------------------
    */

    'merchant' => [
        // Default limits for new merchants
        'default_daily_limit_usd' => 10000.00,
        'default_monthly_limit_usd' => 100000.00,
        'default_single_invoice_limit_usd' => 10000.00,

        // Default fees
        'default_fee_percent' => 1.00,
        'default_fee_fixed_usd' => 0.00,

        // KYC requirements
        'require_kyc_above_monthly_volume' => 50000.00,

        // API keys
        'max_api_keys_per_merchant' => 10,
        'api_key_default_rate_limit_per_minute' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | SLA Configuration
    |--------------------------------------------------------------------------
    */

    'sla' => [
        // Detection SLA (seconds)
        'detection' => [
            'bitcoin' => 120,
            'ethereum' => 60,
            'tron' => 30,
            'bsc' => 30,
            'polygon' => 30,
            'solana' => 15,
            'litecoin' => 120,
            'default' => 60,
        ],

        // Confirmation SLA (seconds) - after required confirmations
        'confirmation' => [
            'bitcoin' => 3600, // ~6 blocks * 10 min
            'ethereum' => 240, // ~20 blocks * 12 sec
            'tron' => 60, // ~20 blocks * 3 sec
            'bsc' => 45, // ~15 blocks * 3 sec
            'polygon' => 120, // ~60 blocks * 2 sec
            'solana' => 30, // ~30 slots
            'litecoin' => 900, // ~6 blocks * 2.5 min
            'default' => 300,
        ],

        // Webhook delivery SLA (seconds)
        'webhook_delivery' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Alerts
    |--------------------------------------------------------------------------
    */

    'monitoring' => [
        'alert_on_failed_webhooks' => true,
        'alert_on_high_failure_rate' => true,
        'failure_rate_threshold' => 5.00, // percent

        'alert_on_large_transaction' => true,
        'large_transaction_threshold_usd' => 50000.00,

        'alert_on_suspicious_activity' => true,
        'alert_on_orphan_payment' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */

    'queues' => [
        'webhook_delivery' => env('QUEUE_WEBHOOK', 'merchant-webhooks'),
        'payment_detection' => env('QUEUE_PAYMENT_DETECTION', 'merchant-payments'),
        'confirmation_check' => env('QUEUE_CONFIRMATION', 'merchant-confirmations'),
        'invoice_expiry' => env('QUEUE_INVOICE_EXPIRY', 'merchant-expiry'),
        'rate_update' => env('QUEUE_RATE_UPDATE', 'merchant-rates'),
    ],

];
