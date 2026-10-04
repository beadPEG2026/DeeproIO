# Crypto Acquiring Module - Platform Owner Guide

## Complete Administration & Operations Manual

**Version:** 1.0.0  
**Last Updated:** January 2026  
**Module Path:** `app/Modules/Merchant`

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Architecture Overview](#2-architecture-overview)
3. [Installation & Setup](#3-installation--setup)
4. [Configuration](#4-configuration)
5. [Database Structure](#5-database-structure)
6. [Merchant Management](#6-merchant-management)
7. [Invoice Lifecycle](#7-invoice-lifecycle)
8. [Supported Currencies & Networks](#8-supported-currencies--networks)
9. [Pricing & FX Configuration](#9-pricing--fx-configuration)
10. [Webhook System](#10-webhook-system)
11. [Security & Compliance](#11-security--compliance)
12. [Monitoring & Alerts](#12-monitoring--alerts)
13. [Background Jobs & Queues](#13-background-jobs--queues)
14. [Troubleshooting](#14-troubleshooting)
15. [Maintenance Operations](#15-maintenance-operations)
16. [API Reference (Admin)](#16-api-reference-admin)

---

## 1. Introduction

### 1.1 What is Crypto Acquiring?

The Crypto Acquiring module enables your CEX platform to offer a payment processing service to merchants. Merchants can accept cryptocurrency payments from their customers while you earn transaction fees.

### 1.2 Business Model

```
┌─────────────────────────────────────────────────────────────────┐
│                      REVENUE STREAMS                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. Transaction Fees    Configurable % per merchant (1-3%)      │
│  2. Network Fees        Pass-through with markup option         │
│  3. FX Spread           Premium on exchange rates               │
│  4. Settlement Fees     Optional fee for fiat settlements       │
│                                                                 │
│  Example: $100 invoice @ 1.5% fee = $1.50 revenue              │
│           Monthly 10,000 invoices = $15,000 revenue            │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 1.3 Key Features

| Feature | Description |
|---------|-------------|
| Multi-Currency | Support BTC, ETH, USDT, USDC, and more |
| Multi-Network | ERC-20, TRC-20, BEP-20, native chains |
| Real-time Pricing | Binance, CoinGecko, internal orderbook |
| Webhook Delivery | Exactly-once with retry & signature |
| Merchant Dashboard | Self-service portal for merchants |
| Payment Widget | Hosted checkout page |
| Full API | REST API for custom integrations |

---

## 2. Architecture Overview

### 2.1 Module Structure

```
app/Modules/Merchant/
├── Config/
│   └── merchant_acquiring.php      # Central configuration
├── Enums/
│   ├── InvoiceStatus.php           # Invoice state machine
│   └── WebhookEvent.php            # Webhook event types
├── Exceptions/
│   ├── MerchantApiException.php    # API error handling
│   └── Handler.php                 # Exception handler
├── Http/
│   ├── Controllers/
│   │   ├── Api/v1/                 # Merchant API
│   │   └── Admin/                  # Admin panel
│   ├── Middleware/
│   │   ├── MerchantApiAuthenticate.php
│   │   ├── MerchantApiRateLimit.php
│   │   └── CheckMerchantPermission.php
│   ├── Requests/                   # Form validation
│   └── Resources/                  # API responses
├── Jobs/
│   ├── CheckInvoiceExpirationJob.php
│   ├── ProcessWebhookDeliveryJob.php
│   ├── RefreshAssetRatesJob.php
│   └── CleanupExpiredDataJob.php
├── Models/
│   ├── Merchant.php
│   ├── MerchantInvoice.php
│   ├── MerchantInvoicePayment.php
│   ├── MerchantDepositAddress.php
│   ├── MerchantWebhook.php
│   ├── MerchantApiKey.php
│   └── Acquiring*.php              # Asset/Currency/Network
├── Repositories/
│   ├── InvoiceRepository.php
│   ├── MerchantRepository.php
│   └── WebhookRepository.php
├── Routes/
│   ├── api.php                     # API routes
│   └── web.php                     # Web routes
├── Services/
│   ├── InvoiceService.php          # Invoice lifecycle
│   ├── PaymentService.php          # Payment processing
│   ├── PricingService.php          # FX & rates
│   ├── WebhookDispatcherService.php
│   ├── AddressManagerService.php
│   └── MerchantAuthService.php
└── Providers/
    └── MerchantServiceProvider.php # Module registration
```

### 2.2 Data Flow

```
┌────────────────────────────────────────────────────────────────────────┐
│                         PAYMENT FLOW                                    │
├────────────────────────────────────────────────────────────────────────┤
│                                                                        │
│  1. INVOICE CREATION                                                   │
│     Merchant API ──▶ InvoiceService ──▶ Database                       │
│                              │                                         │
│                              ▼                                         │
│  2. CURRENCY SELECTION                                                 │
│     Widget ──▶ PricingService ──▶ Rate Lock                           │
│                      │                                                 │
│                      ▼                                                 │
│  3. ADDRESS ASSIGNMENT                                                 │
│     AddressManagerService ──▶ HD Wallet ──▶ Deposit Address           │
│                                                                        │
│  4. PAYMENT MONITORING                                                 │
│     BlockchainListener ──▶ PaymentService ──▶ Confirmation Tracking   │
│                                      │                                 │
│                                      ▼                                 │
│  5. WEBHOOK DISPATCH                                                   │
│     WebhookDispatcher ──▶ Queue ──▶ Merchant Endpoint                 │
│                                                                        │
│  6. SETTLEMENT (Future)                                                │
│     SettlementService ──▶ Internal Transfer ──▶ Merchant Wallet       │
│                                                                        │
└────────────────────────────────────────────────────────────────────────┘
```

### 2.3 Integration Points

| System | Integration |
|--------|-------------|
| CEX Core Wallets | HD wallet derivation, balance queries |
| Blockchain Nodes | Transaction monitoring, confirmations |
| Exchange Engine | Internal orderbook rates |
| User System | Merchant user accounts |
| Admin Panel | Merchant & invoice management |

---

## 3. Installation & Setup

### 3.1 Prerequisites

- PHP 8.1+
- Laravel 10+
- MySQL 8.0+ or PostgreSQL 14+
- Redis (for caching & queues)
- Blockchain node access (or API providers)

### 3.2 Installation Steps

```bash
# 1. Run migrations
php artisan migrate --path=app/Modules/Merchant/Database/Migrations

# 2. Publish configuration
php artisan vendor:publish --tag=merchant-acquiring-config

# 3. Register the module (add to config/app.php providers)
App\Modules\Merchant\Providers\MerchantServiceProvider::class,

# 4. Enable currencies for merchant acquiring (via Admin Dashboard)
# Go to Admin > Currencies and enable 'is_merchant' flag for desired currencies

# 5. Generate encryption keys
php artisan merchant:generate-keys

# 6. Start queue workers
php artisan queue:work --queue=merchant-webhooks,merchant-payments,default
```

### 3.3 Environment Variables

```env
# Required
MERCHANT_ACQUIRING_ENABLED=true
MERCHANT_WEBHOOK_TIMEOUT=10
MERCHANT_RATE_CACHE_TTL=60

# Rate Sources
MERCHANT_BINANCE_API_KEY=your_binance_key
MERCHANT_COINGECKO_API_KEY=your_coingecko_key

# Blockchain Nodes (if self-hosted)
MERCHANT_BTC_NODE_URL=http://localhost:8332
MERCHANT_ETH_NODE_URL=http://localhost:8545

# Webhook Signing
MERCHANT_WEBHOOK_SIGNING_VERSION=v1

# Limits (defaults)
MERCHANT_DEFAULT_DAILY_LIMIT=50000
MERCHANT_DEFAULT_INVOICE_LIMIT=5000
MERCHANT_DEFAULT_FEE_PERCENT=1.5
```

### 3.4 Queue Configuration

Add to `config/queue.php`:

```php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],

// Recommended queue names for the module:
// - merchant-webhooks (high priority)
// - merchant-payments (high priority)
// - merchant-monitoring (normal priority)
// - merchant-cleanup (low priority)
```

---

## 4. Configuration

### 4.1 Main Configuration File

Location: `config/merchant_acquiring.php`

```php
<?php

return [
    // Module toggle
    'enabled' => env('MERCHANT_ACQUIRING_ENABLED', true),

    // Invoice settings
    'invoice' => [
        'default_expiry_minutes' => 60,
        'max_expiry_minutes' => 1440, // 24 hours
        'rate_validity' => 900, // 15 minutes
        'min_amount_usd' => 1.00,
        'max_amount_usd' => 100000.00,
        'underpayment_tolerance_percent' => 1.0, // Accept 99% as paid
        'overpayment_tolerance_percent' => 5.0,  // Flag >105% as overpaid
    ],

    // Webhook settings
    'webhook' => [
        'timeout' => 10,
        'max_attempts' => 5,
        'retry_delays' => [60, 300, 900, 3600, 14400], // seconds
        'signing_version' => 'v1',
        'circuit_breaker' => [
            'failure_threshold' => 5,
            'recovery_time' => 300,
        ],
    ],

    // Rate/pricing settings
    'pricing' => [
        'cache_ttl' => 60,
        'sources' => ['binance', 'coingecko', 'internal'],
        'fallback_enabled' => true,
        'max_rate_age_seconds' => 300,
    ],

    // Security settings
    'security' => [
        'api_timestamp_tolerance' => 300, // 5 minutes
        'nonce_expiry' => 86400, // 24 hours
        'rate_limit' => [
            'merchant_api' => '100,1', // 100 req/min
            'widget' => '60,1',        // 60 req/min
        ],
    ],

    // Merchant defaults
    'merchant' => [
        'default_daily_limit_usd' => 50000.00,
        'default_monthly_limit_usd' => 500000.00,
        'default_single_invoice_limit_usd' => 5000.00,
        'default_fee_percent' => 1.50,
        'require_verification' => true,
    ],
];
```

### 4.2 Per-Merchant Configuration

Each merchant can have custom settings stored in the `merchants` table:

| Setting | Description | Default |
|---------|-------------|---------|
| `fee_percent` | Transaction fee | 1.5% |
| `daily_volume_limit_usd` | Daily limit | $50,000 |
| `monthly_volume_limit_usd` | Monthly limit | $500,000 |
| `single_invoice_limit_usd` | Per-invoice max | $5,000 |
| `min_invoice_amount_usd` | Per-invoice min | $1.00 |
| `ip_whitelist` | API IP whitelist | null |
| `auto_settle` | Auto-settle to wallet | false |
| `settlement_currency` | Settlement currency | USDT |

---

## 5. Database Structure

### 5.1 Entity Relationship Diagram

```
┌─────────────────────┐       ┌──────────────────────┐
│     merchants       │       │  merchant_api_keys   │
├─────────────────────┤       ├──────────────────────┤
│ id (UUID) PK        │───┐   │ id (UUID) PK         │
│ user_id FK          │   │   │ merchant_id FK       │──┐
│ business_name       │   │   │ public_key           │  │
│ status              │   └───│ secret_key_hash      │  │
│ fee_percent         │       │ environment          │  │
│ daily_volume_limit  │       │ is_active            │  │
│ webhook_secret      │       └──────────────────────┘  │
└─────────────────────┘                                 │
         │                                              │
         │ 1:N                                          │
         ▼                                              │
┌─────────────────────┐       ┌──────────────────────┐  │
│  merchant_invoices  │       │ merchant_webhooks    │  │
├─────────────────────┤       ├──────────────────────┤  │
│ id (UUID) PK        │───┐   │ id (UUID) PK         │  │
│ merchant_id FK      │   │   │ merchant_id FK       │◄─┘
│ external_id         │   │   │ invoice_id FK        │
│ status              │   │   │ event_type           │
│ amount_usd          │   │   │ status               │
│ amount_crypto       │   └───│ webhook_url          │
│ deposit_address     │       │ payload              │
│ currency_id  │       │ attempt_count        │
└─────────────────────┘       └──────────────────────┘
         │
         │ 1:N
         ▼
┌─────────────────────────────┐
│ merchant_invoice_payments   │
├─────────────────────────────┤
│ id (UUID) PK                │
│ invoice_id FK               │
│ txn_hash                    │
│ amount_crypto               │
│ confirmations               │
│ status                      │
└─────────────────────────────┘

┌─────────────────────────────┐       ┌──────────────────────────┐
│ acquiring_supported_assets  │       │ acquiring_asset_rates    │
├─────────────────────────────┤       ├──────────────────────────┤
│ id PK                       │       │ id PK                    │
│ currency_id FK              │───────│ currency_id FK    │
│ network_id FK               │       │ rate_usd                 │
│ asset_code (unique)         │       │ source                   │
│ required_confirmations      │       │ valid_until              │
│ precision                   │       └──────────────────────────┘
│ is_active                   │
└─────────────────────────────┘
```

### 5.2 Key Tables

| Table | Purpose | Estimated Rows |
|-------|---------|----------------|
| `merchants` | Merchant accounts | 100-10,000 |
| `merchant_invoices` | Payment invoices | 10K-10M+ |
| `merchant_invoice_payments` | Blockchain payments | 10K-10M+ |
| `merchant_webhooks` | Webhook delivery log | 50K-50M+ |
| `merchant_deposit_addresses` | HD wallet addresses | 10K-1M+ |
| `acquiring_supported_assets` | Supported currencies | 10-100 |
| `acquiring_asset_rates` | Historical rates | 100K+ |

### 5.3 Indexing Strategy

```sql
-- Critical indexes for performance
CREATE INDEX idx_invoices_merchant_status ON merchant_invoices(merchant_id, status);
CREATE INDEX idx_invoices_expires_at ON merchant_invoices(expires_at) WHERE status IN ('awaiting_selection', 'awaiting_payment');
CREATE INDEX idx_invoices_external_id ON merchant_invoices(merchant_id, external_id);
CREATE INDEX idx_payments_address ON merchant_invoice_payments(to_address);
CREATE INDEX idx_payments_txn_hash ON merchant_invoice_payments(txn_hash);
CREATE INDEX idx_webhooks_status_retry ON merchant_webhooks(status, next_retry_at) WHERE status = 'pending_retry';
CREATE INDEX idx_addresses_status ON merchant_deposit_addresses(status, currency_id);
```

---

## 6. Merchant Management

### 6.1 Merchant Lifecycle

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Applied   │────▶│   Pending   │────▶│  Verified   │
└─────────────┘     └─────────────┘     └─────────────┘
                           │                    │
                           ▼                    ▼
                    ┌─────────────┐     ┌─────────────┐
                    │  Rejected   │     │   Active    │
                    └─────────────┘     └─────────────┘
                                               │
                                               ▼
                                        ┌─────────────┐
                                        │  Suspended  │
                                        └─────────────┘
```

### 6.2 Admin Actions

**Approve Merchant:**
```php
// Admin controller
public function approveMerchant(Merchant $merchant)
{
    $merchant->update([
        'status' => 'active',
        'verification_status' => 'verified',
        'verified_at' => now(),
        'verified_by' => auth()->id(),
    ]);

    // Generate webhook secret
    $merchant->rotateWebhookSecret();

    // Notify merchant
    Mail::to($merchant->business_email)->send(new MerchantApprovedMail($merchant));
}
```

**Adjust Limits:**
```php
// Increase merchant limits
$merchant->update([
    'daily_volume_limit_usd' => 100000.00,
    'monthly_volume_limit_usd' => 1000000.00,
    'single_invoice_limit_usd' => 10000.00,
    'fee_percent' => 1.00, // VIP rate
]);
```

**Suspend Merchant:**
```php
// Immediately suspend (stops all operations)
$merchant->update([
    'status' => 'suspended',
    'suspended_at' => now(),
    'suspension_reason' => 'Suspicious activity detected',
]);

// Cancel all pending invoices
$merchant->invoices()
    ->whereIn('status', ['awaiting_selection', 'awaiting_payment'])
    ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
```

### 6.3 Merchant Statistics Queries

```php
// Daily volume
$dailyVolume = MerchantInvoice::where('merchant_id', $merchantId)
    ->whereIn('status', ['paid', 'overpaid', 'settled'])
    ->whereDate('paid_at', today())
    ->sum('amount_usd');

// Conversion rate
$stats = MerchantInvoice::where('merchant_id', $merchantId)
    ->whereDate('created_at', '>=', now()->subDays(30))
    ->selectRaw('
        COUNT(*) as total,
        SUM(CASE WHEN status IN ("paid", "overpaid", "settled") THEN 1 ELSE 0 END) as paid
    ')
    ->first();
$conversionRate = $stats->total > 0 ? ($stats->paid / $stats->total * 100) : 0;

// Revenue earned
$revenue = MerchantInvoice::where('merchant_id', $merchantId)
    ->whereIn('status', ['paid', 'overpaid', 'settled'])
    ->sum('fee_amount_usd');
```

---

## 7. Invoice Lifecycle

### 7.1 State Machine

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        INVOICE STATE MACHINE                            │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│   ┌─────────────────────┐                                              │
│   │  awaiting_selection │  Initial state (no currency selected)        │
│   └─────────┬───────────┘                                              │
│             │ selectCurrency()                                          │
│             ▼                                                           │
│   ┌─────────────────────┐                                              │
│   │   awaiting_payment  │  Address assigned, waiting for payment       │
│   └─────────┬───────────┘                                              │
│             │ paymentDetected()                                         │
│             ▼                                                           │
│   ┌─────────────────────┐                                              │
│   │     detecting       │  Payment in mempool (0 confirmations)        │
│   └─────────┬───────────┘                                              │
│             │ firstConfirmation()                                       │
│             ▼                                                           │
│   ┌─────────────────────┐                                              │
│   │     confirming      │  1+ confirmations, waiting for threshold     │
│   └─────────┬───────────┘                                              │
│             │ requiredConfirmationsReached()                            │
│             ▼                                                           │
│   ┌─────────────────────┐    ┌─────────────┐    ┌─────────────┐       │
│   │        paid         │ OR │   overpaid  │ OR │  underpaid  │       │
│   └─────────────────────┘    └─────────────┘    └─────────────┘       │
│                                                                         │
│   TERMINAL STATES:                                                      │
│   • settled   - Funds transferred to merchant                           │
│   • expired   - Payment window closed                                   │
│   • cancelled - Manually cancelled                                      │
│   • failed    - Unrecoverable error                                     │
│   • refunded  - Funds returned to buyer                                 │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### 7.2 Status Descriptions

| Status | Description | Webhook Event |
|--------|-------------|---------------|
| `awaiting_selection` | Invoice created, buyer hasn't selected currency | `invoice.created` |
| `awaiting_payment` | Currency selected, address displayed | `invoice.pending` |
| `detecting` | Payment detected in mempool | `invoice.payment_detecting` |
| `confirming` | Has confirmations, waiting for threshold | `invoice.confirming` |
| `paid` | Exact amount received, fully confirmed | `invoice.paid` |
| `overpaid` | More than expected received | `invoice.overpaid` |
| `underpaid` | Less than expected received | `invoice.underpaid` |
| `settled` | Funds credited to merchant | `invoice.settled` |
| `expired` | Payment window closed | `invoice.expired` |
| `cancelled` | Manually cancelled by merchant | `invoice.cancelled` |

### 7.3 Expiration Handling

The `CheckInvoiceExpirationJob` runs every minute:

```php
// Expire invoices that have passed their deadline
MerchantInvoice::whereIn('status', ['awaiting_selection', 'awaiting_payment'])
    ->where('expires_at', '<=', now())
    ->each(function ($invoice) {
        $invoice->update([
            'status' => 'expired',
            'expired_at' => now(),
        ]);

        // Dispatch webhook
        WebhookDispatcherService::dispatch(
            $invoice->merchant,
            'invoice.expired',
            ['invoice' => $invoice->toArray()],
            $invoice->id
        );

        // Release address back to pool
        if ($invoice->depositAddress) {
            $invoice->depositAddress->release();
        }
    });
```

---

## 8. Supported Currencies & Networks

### 8.1 Adding a New Currency

**Step 1: Create Currency Record**
```sql
INSERT INTO acquiring_supported_currencies (code, name, is_active)
VALUES ('SOL', 'Solana', true);
```

**Step 2: Create Network Record**
```sql
INSERT INTO acquiring_supported_networks (code, name, explorer_tx_url, is_active)
VALUES ('solana', 'Solana', 'https://solscan.io/tx/{txid}', true);
```

**Step 3: Create Asset Record**
```sql
INSERT INTO acquiring_supported_assets (
    currency_id, network_id, asset_code, 
    required_confirmations, precision, 
    min_amount, max_amount,
    rate_source, is_active
) VALUES (
    (SELECT id FROM acquiring_supported_currencies WHERE code = 'SOL'),
    (SELECT id FROM acquiring_supported_networks WHERE code = 'solana'),
    'SOL_SOLANA',
    32, 9,
    '0.01', '10000',
    'binance', true
);
```

**Step 4: Configure Address Generation**
- Add HD wallet derivation path for SOL
- Implement address generation in `AddressManagerService`

### 8.2 Managing Assets

```php
// Disable a currency for merchant acquiring (no new invoices)
Currency::where('symbol', 'BTC')
    ->update(['is_merchant' => false]);

// Update confirmation requirements
Currency::where('symbol', 'ETH')
    ->update(['merchant_confirmations' => 15]);

// Update fee percent
Currency::where('symbol', 'USDT')
    ->update(['merchant_fee_percent' => 1.0
    ]);
```

### 8.3 Confirmation Requirements

| Asset | Network | Confirmations | ~Time |
|-------|---------|---------------|-------|
| BTC | Bitcoin | 3 | ~30 min |
| ETH | Ethereum | 12 | ~3 min |
| USDT | TRC-20 | 20 | ~1 min |
| USDT | ERC-20 | 12 | ~3 min |
| USDC | ERC-20 | 12 | ~3 min |
| LTC | Litecoin | 6 | ~15 min |
| XRP | Ripple | 1 | ~4 sec |

---

## 9. Pricing & FX Configuration

### 9.1 Rate Sources

| Source | API | Priority | Use Case |
|--------|-----|----------|----------|
| `binance` | Binance Public API | 1 | Primary for liquid pairs |
| `coingecko` | CoinGecko API | 2 | Fallback, supports more coins |
| `internal` | Your exchange orderbook | 3 | Use your own liquidity |
| `fixed` | Static rate | N/A | Stablecoins |

### 9.2 Rate Caching

```php
// Rates are cached with short TTL
Cache::put("acquiring_rate:{$assetId}", [
    'rate_usd' => '42500.50',
    'source' => 'binance',
    'fetched_at' => now()->toIso8601String(),
], config('merchant_acquiring.pricing.cache_ttl', 60));
```

### 9.3 Rate Locking

When a customer selects a currency:

1. Current rate is fetched/cached
2. Rate is "locked" for the invoice (stored in `rate_usd`)
3. Rate expires after `rate_validity` seconds (default 15 min)
4. Customer can extend rate up to 2 times

### 9.4 Spread/Premium Configuration

```php
// Per-currency fee (adds to merchant cost, your revenue)
Currency::where('symbol', 'BTC')
    ->update(['merchant_fee_percent' => 1.5]); // 1.5% fee

// Calculation:
// Invoice: $100 USD
// BTC Rate: $40,000
// Base amount: 0.0025 BTC
// With 0.5% premium: 0.00251250 BTC (customer pays slightly more)
```

---

## 10. Webhook System

### 10.1 Webhook Events

| Event | Trigger | Payload Contains |
|-------|---------|------------------|
| `invoice.created` | Invoice created | Invoice details |
| `invoice.pending` | Currency selected | Invoice + address |
| `invoice.payment_detecting` | Payment in mempool | Invoice + partial payment |
| `invoice.confirming` | First confirmation | Invoice + confirmations |
| `invoice.paid` | Payment complete | Invoice + payment details |
| `invoice.overpaid` | Overpayment detected | Invoice + overpaid amount |
| `invoice.underpaid` | Underpayment confirmed | Invoice + shortfall |
| `invoice.expired` | Invoice expired | Invoice |
| `invoice.cancelled` | Invoice cancelled | Invoice |
| `invoice.settled` | Funds settled | Invoice + settlement |

### 10.2 Retry Strategy

```
Attempt 1: Immediate
Attempt 2: +1 minute
Attempt 3: +5 minutes
Attempt 4: +15 minutes
Attempt 5: +1 hour
Attempt 6: +4 hours (dead letter)
```

### 10.3 Monitoring Webhook Health

```sql
-- Failed webhooks in last 24 hours
SELECT 
    m.business_name,
    COUNT(*) as failed_count,
    MAX(w.updated_at) as last_attempt
FROM merchant_webhooks w
JOIN merchants m ON w.merchant_id = m.id
WHERE w.status = 'failed'
  AND w.updated_at >= NOW() - INTERVAL 24 HOUR
GROUP BY m.id
ORDER BY failed_count DESC;

-- Webhook delivery success rate
SELECT 
    DATE(created_at) as date,
    COUNT(*) as total,
    SUM(status = 'delivered') as delivered,
    ROUND(SUM(status = 'delivered') / COUNT(*) * 100, 2) as success_rate
FROM merchant_webhooks
WHERE created_at >= NOW() - INTERVAL 7 DAY
GROUP BY DATE(created_at)
ORDER BY date DESC;
```

### 10.4 Manual Webhook Retry

```php
// Admin can manually retry failed webhooks
$webhook = MerchantWebhook::find($webhookId);
$webhook->update([
    'status' => 'pending',
    'attempt_count' => 0,
    'next_retry_at' => null,
]);

ProcessWebhookDeliveryJob::dispatch($webhook);
```

---

## 11. Security & Compliance

### 11.1 API Security Layers

```
┌─────────────────────────────────────────────────────────────────┐
│                     API SECURITY STACK                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Layer 1: TLS 1.3               HTTPS required                  │
│                ▼                                                │
│  Layer 2: Rate Limiting         100 req/min per API key        │
│                ▼                                                │
│  Layer 3: API Key Validation    Public key in header           │
│                ▼                                                │
│  Layer 4: Timestamp Check       ±5 minutes tolerance           │
│                ▼                                                │
│  Layer 5: Nonce Validation      Replay attack prevention       │
│                ▼                                                │
│  Layer 6: HMAC Signature        Request integrity              │
│                ▼                                                │
│  Layer 7: IP Whitelist          Optional per-merchant          │
│                ▼                                                │
│  Layer 8: Permission Check      Scoped API key permissions     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 11.2 Sensitive Data Handling

| Data | Storage | Encryption |
|------|---------|------------|
| API Secret Keys | Hashed (SHA-256) | Never stored plaintext |
| Webhook Secrets | Encrypted at rest | AES-256 |
| Customer Email | Plaintext | Consider encryption |
| IP Addresses | Plaintext | Logged for audit |
| Transaction Hashes | Plaintext | Public blockchain data |

### 11.3 Audit Logging

All critical actions are logged:

```php
// Stored in merchant_audit_logs table
[
    'merchant_id' => $merchant->id,
    'user_id' => auth()->id(),
    'action' => 'invoice.created',
    'entity_type' => 'invoice',
    'entity_id' => $invoice->id,
    'ip_address' => request()->ip(),
    'user_agent' => request()->userAgent(),
    'metadata' => ['amount' => 100.00],
    'created_at' => now(),
]
```

### 11.4 Compliance Considerations

| Requirement | Implementation |
|-------------|----------------|
| **KYC/AML** | Merchant verification before activation |
| **Transaction Limits** | Daily/monthly volume caps |
| **Reporting** | Exportable transaction history |
| **Data Retention** | Configurable retention periods |
| **GDPR** | Customer email encryption (optional) |
| **Audit Trail** | Immutable action logs |

---

## 12. Monitoring & Alerts

### 12.1 Key Metrics Dashboard

```
┌─────────────────────────────────────────────────────────────────┐
│                    ACQUIRING DASHBOARD                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  TODAY                           LAST 7 DAYS                    │
│  ────────────────────            ─────────────────────          │
│  Invoices Created: 1,234         Total Volume: $2.4M            │
│  Invoices Paid: 987              Conversion Rate: 78%           │
│  Success Rate: 80%               Avg Invoice: $245              │
│  Total Volume: $342,500          Fees Earned: $36,000           │
│                                                                 │
│  REAL-TIME                       WEBHOOKS                       │
│  ────────────────────            ─────────────────────          │
│  Pending Invoices: 45            Pending: 12                    │
│  Detecting: 8                    Failed (24h): 23               │
│  Confirming: 12                  Success Rate: 99.7%            │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 12.2 Alerting Rules

| Alert | Condition | Severity |
|-------|-----------|----------|
| High Failed Webhooks | >50 failed/hour | Warning |
| Rate Fetch Failure | All sources down | Critical |
| Invoice Backlog | >100 pending >30min | Warning |
| Blockchain Node Down | Connection timeout | Critical |
| Daily Limit Breach | Merchant >90% limit | Info |
| Suspicious Activity | >50 invoices/min/merchant | Warning |

### 12.3 Health Check Endpoint

```php
// GET /api/health/merchant-acquiring
{
    "status": "healthy",
    "checks": {
        "database": "ok",
        "redis": "ok",
        "queue_workers": "ok",
        "rate_sources": {
            "binance": "ok",
            "coingecko": "ok"
        },
        "blockchain_nodes": {
            "bitcoin": "ok",
            "ethereum": "ok"
        }
    },
    "metrics": {
        "pending_invoices": 45,
        "pending_webhooks": 12,
        "queue_size": 156
    }
}
```

---

## 13. Background Jobs & Queues

### 13.1 Scheduled Jobs

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Invoice expiration check (every minute)
    $schedule->job(new CheckInvoiceExpirationJob)
        ->everyMinute()
        ->withoutOverlapping();

    // Rate refresh (every minute)
    $schedule->job(new RefreshAssetRatesJob)
        ->everyMinute()
        ->withoutOverlapping();

    // Webhook processing (every minute)
    $schedule->job(new ProcessPendingWebhooksJob)
        ->everyMinute()
        ->withoutOverlapping();

    // Cleanup (daily at 3 AM)
    $schedule->job(new CleanupExpiredDataJob)
        ->dailyAt('03:00');
}
```

### 13.2 Queue Workers

```bash
# Production setup (supervisor config)
[program:merchant-webhooks]
command=php /var/www/artisan queue:work redis --queue=merchant-webhooks --sleep=1 --tries=1
numprocs=4
autostart=true
autorestart=true

[program:merchant-payments]
command=php /var/www/artisan queue:work redis --queue=merchant-payments --sleep=1 --tries=3
numprocs=2
autostart=true
autorestart=true

[program:merchant-monitoring]
command=php /var/www/artisan queue:work redis --queue=merchant-monitoring --sleep=3 --tries=3
numprocs=2
autostart=true
autorestart=true
```

### 13.3 Job Monitoring

```sql
-- Check queue health
SELECT 
    queue,
    COUNT(*) as jobs,
    MIN(available_at) as oldest_job
FROM jobs
WHERE queue LIKE 'merchant%'
GROUP BY queue;

-- Failed jobs
SELECT * FROM failed_jobs
WHERE payload LIKE '%Merchant%'
ORDER BY failed_at DESC
LIMIT 20;
```

---

## 14. Troubleshooting

### 14.1 Common Issues

**Issue: Invoice stuck in "detecting" state**
```sql
-- Check payment confirmations
SELECT p.*, i.status as invoice_status
FROM merchant_invoice_payments p
JOIN merchant_invoices i ON p.invoice_id = i.id
WHERE i.status = 'detecting'
  AND p.created_at < NOW() - INTERVAL 30 MINUTE;

-- Force recheck confirmations
php artisan merchant:recheck-confirmations --invoice=<uuid>
```

**Issue: Webhooks not delivering**
```sql
-- Check webhook status
SELECT status, COUNT(*), MAX(last_error)
FROM merchant_webhooks
WHERE merchant_id = '<uuid>'
  AND created_at >= NOW() - INTERVAL 1 HOUR
GROUP BY status;

-- Test merchant webhook endpoint
curl -X POST https://merchant.example.com/webhooks \
  -H "Content-Type: application/json" \
  -d '{"test": true}'
```

**Issue: Rate fetch failing**
```php
// Check rate cache
Cache::get("acquiring_rate:{$assetId}");

// Manual rate fetch
$rate = app(PricingService::class)->fetchRateFromSource($asset, 'binance');
```

### 14.2 Diagnostic Commands

```bash
# Check module status
php artisan merchant:status

# Verify configuration
php artisan merchant:verify-config

# Test webhook delivery
php artisan merchant:test-webhook --merchant=<uuid> --url=<url>

# Reprocess failed webhooks
php artisan merchant:retry-webhooks --status=failed --hours=24

# Export merchant report
php artisan merchant:report --merchant=<uuid> --from=2024-01-01 --to=2024-01-31
```

### 14.3 Log Analysis

```bash
# Tail merchant logs
tail -f storage/logs/merchant-acquiring.log

# Search for errors
grep -i "error\|exception\|failed" storage/logs/merchant-acquiring.log | tail -100

# Invoice-specific logs
grep "invoice_id\":\"<uuid>" storage/logs/merchant-acquiring.log
```

---

## 15. Maintenance Operations

### 15.1 Database Maintenance

```sql
-- Archive old invoices (>90 days, terminal status)
INSERT INTO merchant_invoices_archive
SELECT * FROM merchant_invoices
WHERE status IN ('paid', 'expired', 'cancelled', 'settled')
  AND created_at < NOW() - INTERVAL 90 DAY;

DELETE FROM merchant_invoices
WHERE status IN ('paid', 'expired', 'cancelled', 'settled')
  AND created_at < NOW() - INTERVAL 90 DAY;

-- Clean old nonces
DELETE FROM merchant_api_nonces
WHERE created_at < NOW() - INTERVAL 24 HOUR;

-- Optimize tables
OPTIMIZE TABLE merchant_invoices, merchant_webhooks, merchant_invoice_payments;
```

### 15.2 Address Pool Management

```php
// Check address pool levels
$poolStats = MerchantDepositAddress::selectRaw('
    currency_id,
    status,
    COUNT(*) as count
')
->groupBy('currency_id', 'status')
->get();

// Pre-generate addresses
php artisan merchant:generate-addresses --asset=BTC_BITCOIN --count=100
```

### 15.3 Backup Procedures

```bash
# Database backup
mysqldump -u root -p exchange_db \
  merchants merchant_invoices merchant_webhooks \
  merchant_invoice_payments merchant_deposit_addresses \
  > merchant_backup_$(date +%Y%m%d).sql

# Critical data export
php artisan merchant:export --type=invoices --status=paid --format=csv
```

---

## 16. API Reference (Admin)

### 16.1 Merchant Management

```
GET    /admin/api/merchants              List all merchants
GET    /admin/api/merchants/{id}         Get merchant details
POST   /admin/api/merchants/{id}/approve Approve merchant
POST   /admin/api/merchants/{id}/suspend Suspend merchant
PUT    /admin/api/merchants/{id}/limits  Update limits
```

### 16.2 Invoice Management

```
GET    /admin/api/invoices               List all invoices
GET    /admin/api/invoices/{id}          Get invoice details
POST   /admin/api/invoices/{id}/refund   Initiate refund
```

### 16.3 Asset Management

```
GET    /admin/api/assets                 List supported assets
POST   /admin/api/assets                 Add new asset
PUT    /admin/api/assets/{id}            Update asset settings
DELETE /admin/api/assets/{id}            Disable asset
```

### 16.4 Webhook Management

```
GET    /admin/api/webhooks               List webhooks
POST   /admin/api/webhooks/{id}/retry    Retry webhook
GET    /admin/api/webhooks/stats         Webhook statistics
```

### 16.5 Reports

```
GET    /admin/api/reports/volume         Volume report
GET    /admin/api/reports/revenue        Revenue report
GET    /admin/api/reports/merchants      Merchant activity report
GET    /admin/api/reports/export         Export data
```

---

## Appendix A: Glossary

| Term | Definition |
|------|------------|
| **Invoice** | A payment request created by merchant |
| **Widget** | Hosted checkout page for buyers |
| **Rate Lock** | Freezing exchange rate for invoice |
| **Confirmation** | Blockchain block validation |
| **Webhook** | HTTP callback to merchant server |
| **Settlement** | Transfer of funds to merchant wallet |
| **Idempotency** | Preventing duplicate operations |
| **Circuit Breaker** | Pausing webhooks to failing endpoints |

---

## Appendix B: Change Log

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | Jan 2026 | Initial release |

---

**Document End**

*For merchant integration documentation, see `MERCHANT_INTEGRATION_GUIDE.md`*
