# Crypto Acquiring - Merchant Integration Guide

## Complete API & Integration Documentation for Merchants

**Version:** 1.0.0  
**Last Updated:** January 2026  
**Base URL:** `https://your-exchange.com/api/v1/merchant`

---

## Table of Contents

1. [Getting Started](#1-getting-started)
2. [Authentication](#2-authentication)
3. [API Reference](#3-api-reference)
4. [Webhooks](#4-webhooks)
5. [Payment Widget](#5-payment-widget)
6. [Code Examples](#6-code-examples)
7. [Testing](#7-testing)
8. [Error Handling](#8-error-handling)
9. [Best Practices](#9-best-practices)
10. [FAQ](#10-faq)

---

## 1. Getting Started

### 1.1 Overview

The Crypto Acquiring API allows you to accept cryptocurrency payments on your website, app, or platform. Your customers can pay with Bitcoin, Ethereum, USDT, and other supported cryptocurrencies while you receive settlements in your preferred currency.

### 1.2 How It Works

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        PAYMENT FLOW                                      │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│   1. CREATE INVOICE                                                     │
│      Your Server ──▶ POST /invoices ──▶ Invoice Created                │
│                                                                         │
│   2. REDIRECT CUSTOMER                                                  │
│      Customer ──▶ checkout_url ──▶ Payment Widget                      │
│                                                                         │
│   3. CUSTOMER PAYS                                                      │
│      Customer ──▶ Selects Currency ──▶ Sends Crypto                    │
│                                                                         │
│   4. RECEIVE WEBHOOK                                                    │
│      Our Server ──▶ POST your_webhook_url ──▶ invoice.paid             │
│                                                                         │
│   5. FULFILL ORDER                                                      │
│      Verify Webhook ──▶ Update Order Status ──▶ Deliver Product        │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### 1.3 Quick Start Checklist

- [ ] Sign up for a merchant account
- [ ] Complete verification (if required)
- [ ] Generate API keys from your dashboard
- [ ] Set up your webhook endpoint
- [ ] Test with sandbox/test mode
- [ ] Go live!

### 1.4 Supported Currencies

| Currency | Networks | Confirmations | Approx. Time |
|----------|----------|---------------|--------------|
| BTC | Bitcoin | 3 | ~30 min |
| ETH | Ethereum | 12 | ~3 min |
| USDT | TRC-20, ERC-20, BEP-20 | 20/12/15 | 1-3 min |
| USDC | ERC-20 | 12 | ~3 min |
| LTC | Litecoin | 6 | ~15 min |

*Contact support for additional currencies*

---

## 2. Authentication

### 2.1 API Keys

You need two keys for API authentication:

| Key Type | Format | Purpose |
|----------|--------|---------|
| **Public Key** | `pk_live_xxxxxxxx` or `pk_test_xxxxxxxx` | Identifies your account |
| **Secret Key** | `sk_live_xxxxxxxx` or `sk_test_xxxxxxxx` | Signs requests (keep secret!) |

> ⚠️ **Security Warning**: Never expose your secret key in client-side code, git repositories, or logs.

### 2.2 Request Headers

Every API request must include these headers:

```http
X-API-Key: pk_live_your_public_key
X-Timestamp: 1704067200
X-Nonce: 550e8400-e29b-41d4-a716-446655440000
X-Signature: v1=abc123def456...
Content-Type: application/json
```

| Header | Description |
|--------|-------------|
| `X-API-Key` | Your public API key |
| `X-Timestamp` | Unix timestamp (seconds). Must be within ±5 minutes of server time |
| `X-Nonce` | Unique UUID v4 per request (prevents replay attacks) |
| `X-Signature` | HMAC-SHA256 signature of the request |

### 2.3 Signature Generation

The signature is computed as:

```
signature = HMAC-SHA256(payload, secret_key)
payload = "{timestamp}.{method}.{path}.{body}"
```

**Example:**

```
Timestamp: 1704067200
Method: POST
Path: /api/v1/merchant/invoices
Body: {"amount":100,"description":"Order #123"}

Payload to sign:
"1704067200.POST./api/v1/merchant/invoices.{\"amount\":100,\"description\":\"Order #123\"}"

Signature header:
X-Signature: v1=a1b2c3d4e5f6...
```

### 2.4 Code Examples for Signature

**PHP:**
```php
function generateSignature(
    string $method,
    string $path,
    string $body,
    int $timestamp,
    string $secretKey
): string {
    $payload = "{$timestamp}.{$method}.{$path}.{$body}";
    $hash = hash_hmac('sha256', $payload, $secretKey);
    return "v1={$hash}";
}

// Usage
$timestamp = time();
$nonce = \Ramsey\Uuid\Uuid::uuid4()->toString();
$body = json_encode(['amount' => 100.00]);

$signature = generateSignature('POST', '/api/v1/merchant/invoices', $body, $timestamp, $secretKey);
```

**Node.js:**
```javascript
const crypto = require('crypto');
const { v4: uuidv4 } = require('uuid');

function generateSignature(method, path, body, timestamp, secretKey) {
    const payload = `${timestamp}.${method}.${path}.${body}`;
    const hash = crypto.createHmac('sha256', secretKey).update(payload).digest('hex');
    return `v1=${hash}`;
}

// Usage
const timestamp = Math.floor(Date.now() / 1000);
const nonce = uuidv4();
const body = JSON.stringify({ amount: 100.00 });

const signature = generateSignature('POST', '/api/v1/merchant/invoices', body, timestamp, secretKey);
```

**Python:**
```python
import hmac
import hashlib
import time
import uuid
import json

def generate_signature(method, path, body, timestamp, secret_key):
    payload = f"{timestamp}.{method}.{path}.{body}"
    hash_value = hmac.new(
        secret_key.encode(),
        payload.encode(),
        hashlib.sha256
    ).hexdigest()
    return f"v1={hash_value}"

# Usage
timestamp = int(time.time())
nonce = str(uuid.uuid4())
body = json.dumps({"amount": 100.00})

signature = generate_signature('POST', '/api/v1/merchant/invoices', body, timestamp, secret_key)
```

---

## 3. API Reference

### 3.1 Create Invoice

Creates a new payment invoice.

```http
POST /api/v1/merchant/invoices
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `amount` | number | Yes | Amount in USD (min: 1.00, max: varies) |
| `description` | string | No | What this payment is for |
| `external_id` | string | No | Your internal order/reference ID |
| `customer_email` | string | No | Customer's email address |
| `customer_name` | string | No | Customer's name |
| `redirect_url` | string | No | URL to redirect after successful payment |
| `cancel_url` | string | No | URL to redirect if customer cancels |
| `metadata` | object | No | Custom key-value data (max 10 keys) |

**Request Example:**
```json
{
    "amount": 99.99,
    "description": "Premium Subscription - 1 Year",
    "external_id": "order_12345",
    "customer_email": "customer@example.com",
    "redirect_url": "https://yoursite.com/success?order=12345",
    "cancel_url": "https://yoursite.com/cancel",
    "metadata": {
        "plan": "premium",
        "user_id": "usr_789"
    }
}
```

**Response (201 Created):**
```json
{
    "success": true,
    "data": {
        "invoice": {
            "id": "inv_a1b2c3d4-e5f6-7890-abcd-ef1234567890",
            "external_id": "order_12345",
            "status": "awaiting_selection",
            "amount_usd": "99.99",
            "amount_crypto": null,
            "currency": null,
            "deposit_address": null,
            "description": "Premium Subscription - 1 Year",
            "customer_email": "customer@example.com",
            "expires_at": "2024-01-15T13:00:00Z",
            "created_at": "2024-01-15T12:00:00Z"
        },
        "checkout_url": "https://exchange.com/pay/i/inv_a1b2c3d4...",
        "widget_token": "wgt_xyz789..."
    }
}
```

**Idempotency:**

To prevent duplicate invoices, include an `Idempotency-Key` header:

```http
Idempotency-Key: your-unique-request-id
```

If you send the same idempotency key within 24 hours, you'll receive the original invoice instead of creating a duplicate.

---

### 3.2 Get Invoice

Retrieves details for a specific invoice.

```http
GET /api/v1/merchant/invoices/{invoice_id}
```

**Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "invoice": {
            "id": "inv_a1b2c3d4-e5f6-7890-abcd-ef1234567890",
            "external_id": "order_12345",
            "status": "paid",
            "amount_usd": "99.99",
            "amount_crypto": "0.00235000",
            "currency": {
                "code": "BTC",
                "name": "Bitcoin",
                "network": "Bitcoin"
            },
            "rate_usd": "42550.00",
            "deposit_address": "bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh",
            "amount_received_crypto": "0.00235000",
            "confirmations": 3,
            "required_confirmations": 3,
            "fee_amount_usd": "1.50",
            "net_amount_usd": "98.49",
            "paid_at": "2024-01-15T12:35:00Z",
            "created_at": "2024-01-15T12:00:00Z",
            "payments": [
                {
                    "id": "pay_123...",
                    "txn_hash": "abc123...",
                    "amount_crypto": "0.00235000",
                    "confirmations": 3,
                    "status": "confirmed",
                    "explorer_url": "https://blockstream.info/tx/abc123..."
                }
            ]
        }
    }
}
```

---

### 3.3 List Invoices

Retrieves a paginated list of your invoices.

```http
GET /api/v1/merchant/invoices
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter by status (paid, expired, etc.) |
| `external_id` | string | Filter by your external ID |
| `customer_email` | string | Filter by customer email |
| `created_from` | date | Filter by creation date (YYYY-MM-DD) |
| `created_to` | date | Filter by creation date (YYYY-MM-DD) |
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Items per page (default: 20, max: 100) |

**Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "invoices": {
            "data": [
                { "id": "inv_1...", "status": "paid", ... },
                { "id": "inv_2...", "status": "expired", ... }
            ],
            "meta": {
                "current_page": 1,
                "per_page": 20,
                "total": 156,
                "total_pages": 8
            }
        }
    }
}
```

---

### 3.4 Cancel Invoice

Cancels a pending invoice.

```http
POST /api/v1/merchant/invoices/{invoice_id}/cancel
```

**Request Body:**
```json
{
    "reason": "Customer requested cancellation"
}
```

**Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "invoice": {
            "id": "inv_a1b2c3d4...",
            "status": "cancelled",
            "cancelled_at": "2024-01-15T12:30:00Z"
        }
    }
}
```

> ⚠️ **Note:** Only invoices with status `awaiting_selection` or `awaiting_payment` can be cancelled. Paid invoices cannot be cancelled.

---

### 3.5 Get Available Currencies

Returns list of supported cryptocurrencies and estimated amounts.

```http
GET /api/v1/merchant/currencies
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `amount_usd` | number | Optional - returns estimated crypto amounts |

**Response (200 OK):**
```json
{
    "success": true,
    "data": [
        {
            "asset_code": "BTC_BITCOIN",
            "currency": "BTC",
            "currency_name": "Bitcoin",
            "network": "Bitcoin",
            "network_name": "Bitcoin Mainnet",
            "required_confirmations": 3,
            "estimated_amount": "0.00235",
            "rate_usd": "42550.00",
            "min_amount": "0.0001",
            "max_amount": "10"
        },
        {
            "asset_code": "USDT_TRC20",
            "currency": "USDT",
            "currency_name": "Tether",
            "network": "tron",
            "network_name": "Tron (TRC-20)",
            "required_confirmations": 20,
            "estimated_amount": "100.15",
            "rate_usd": "0.998",
            "min_amount": "1",
            "max_amount": "100000"
        }
    ]
}
```

---

### 3.6 Get Merchant Account

Returns your merchant account information.

```http
GET /api/v1/merchant/account
```

**Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "merchant": {
            "id": "mrc_123...",
            "business_name": "Acme Inc",
            "status": "active",
            "verification_status": "verified",
            "fee_percent": "1.50",
            "daily_volume_limit_usd": "50000.00",
            "daily_volume_used_usd": "12500.00",
            "daily_volume_remaining_usd": "37500.00"
        }
    }
}
```

---

## 4. Webhooks

### 4.1 Overview

Webhooks notify your server when events occur (e.g., payment received). They are essential for automating order fulfillment.

### 4.2 Webhook Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `invoice.created` | Invoice was created | After POST /invoices |
| `invoice.pending` | Customer selected currency | Currency selection |
| `invoice.payment_detecting` | Payment detected in mempool | First detection |
| `invoice.confirming` | Payment has confirmations | Each confirmation |
| `invoice.paid` | Payment fully confirmed | Required confirmations reached |
| `invoice.overpaid` | Customer sent too much | Final confirmation |
| `invoice.underpaid` | Customer sent too little | Final confirmation |
| `invoice.expired` | Invoice expired | Expiration time reached |
| `invoice.cancelled` | Invoice was cancelled | Manual cancellation |
| `invoice.settled` | Funds settled to your account | Settlement complete |

### 4.3 Webhook Payload

```json
{
    "id": "whk_abc123...",
    "timestamp": "2024-01-15T12:35:00Z",
    "event": {
        "type": "invoice.paid",
        "created_at": "2024-01-15T12:35:00Z"
    },
    "data": {
        "invoice": {
            "id": "inv_a1b2c3d4...",
            "external_id": "order_12345",
            "status": "paid",
            "amount_usd": "99.99",
            "amount_crypto": "0.00235000",
            "currency": {
                "code": "BTC",
                "name": "Bitcoin",
                "network": "Bitcoin"
            },
            "rate_usd": "42550.00",
            "deposit_address": "bc1qxy2kgd...",
            "amount_received_crypto": "0.00235000",
            "paid_at": "2024-01-15T12:35:00Z",
            "metadata": {
                "plan": "premium",
                "user_id": "usr_789"
            }
        },
        "payment": {
            "txn_hash": "abc123def456...",
            "amount_crypto": "0.00235000",
            "confirmations": 3
        }
    }
}
```

### 4.4 Webhook Headers

Your endpoint will receive these headers:

| Header | Description |
|--------|-------------|
| `X-Webhook-Signature` | Signature for verification |
| `X-Webhook-Timestamp` | Unix timestamp |
| `X-Webhook-ID` | Unique webhook ID |
| `X-Idempotency-Key` | Use to prevent duplicate processing |
| `Content-Type` | `application/json` |

### 4.5 Verifying Webhook Signatures

**⚠️ Always verify webhook signatures before processing!**

```php
// PHP Example
function verifyWebhookSignature(
    string $payload,
    string $signature,
    string $timestamp,
    string $webhookSecret
): bool {
    // Check timestamp is recent (within 5 minutes)
    if (abs(time() - (int)$timestamp) > 300) {
        return false;
    }

    // Generate expected signature
    $expectedPayload = "{$timestamp}.{$payload}";
    $expectedSignature = 'v1=' . hash_hmac('sha256', $expectedPayload, $webhookSecret);

    // Constant-time comparison
    return hash_equals($expectedSignature, $signature);
}

// Usage in your webhook endpoint
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'];
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'];

if (!verifyWebhookSignature($payload, $signature, $timestamp, $webhookSecret)) {
    http_response_code(401);
    exit('Invalid signature');
}

// Process webhook...
```

```javascript
// Node.js Example
const crypto = require('crypto');

function verifyWebhookSignature(payload, signature, timestamp, webhookSecret) {
    // Check timestamp is recent
    const currentTime = Math.floor(Date.now() / 1000);
    if (Math.abs(currentTime - parseInt(timestamp)) > 300) {
        return false;
    }

    // Generate expected signature
    const expectedPayload = `${timestamp}.${payload}`;
    const expectedSignature = 'v1=' + crypto
        .createHmac('sha256', webhookSecret)
        .update(expectedPayload)
        .digest('hex');

    // Constant-time comparison
    return crypto.timingSafeEqual(
        Buffer.from(expectedSignature),
        Buffer.from(signature)
    );
}

// Express.js example
app.post('/webhooks', express.raw({type: 'application/json'}), (req, res) => {
    const signature = req.headers['x-webhook-signature'];
    const timestamp = req.headers['x-webhook-timestamp'];

    if (!verifyWebhookSignature(req.body.toString(), signature, timestamp, webhookSecret)) {
        return res.status(401).send('Invalid signature');
    }

    const event = JSON.parse(req.body);
    // Process webhook...
    
    res.status(200).send('OK');
});
```

### 4.6 Handling Webhooks

```php
// Complete PHP webhook handler example
<?php

$webhookSecret = 'whsec_your_webhook_secret';

// Get raw payload
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';

// Verify signature
if (!verifyWebhookSignature($payload, $signature, $timestamp, $webhookSecret)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Invalid signature']));
}

// Check for duplicate (using idempotency key)
if (webhookAlreadyProcessed($idempotencyKey)) {
    http_response_code(200);
    exit(json_encode(['status' => 'already_processed']));
}

// Parse event
$event = json_decode($payload, true);
$eventType = $event['event']['type'];
$invoice = $event['data']['invoice'];

// Handle event
try {
    switch ($eventType) {
        case 'invoice.paid':
            // Fulfill the order
            $order = Order::where('external_id', $invoice['external_id'])->first();
            if ($order && $order->status !== 'paid') {
                $order->status = 'paid';
                $order->payment_method = 'crypto';
                $order->payment_currency = $invoice['currency']['code'];
                $order->paid_at = $invoice['paid_at'];
                $order->save();

                // Send confirmation email
                Mail::to($order->customer_email)->send(new OrderConfirmation($order));
            }
            break;

        case 'invoice.expired':
            // Cancel the order or notify customer
            $order = Order::where('external_id', $invoice['external_id'])->first();
            if ($order && $order->status === 'pending') {
                $order->status = 'expired';
                $order->save();
            }
            break;

        case 'invoice.underpaid':
            // Handle partial payment
            $order = Order::where('external_id', $invoice['external_id'])->first();
            if ($order) {
                $order->status = 'underpaid';
                $order->notes = 'Customer sent ' . $invoice['amount_received_crypto'] . ' but needed ' . $invoice['amount_crypto'];
                $order->save();
                
                // Notify support team
                Notification::send(new UnderpaidOrder($order));
            }
            break;
    }

    // Mark webhook as processed
    markWebhookProcessed($idempotencyKey);

    http_response_code(200);
    echo json_encode(['status' => 'processed']);

} catch (Exception $e) {
    // Log error but return 200 to prevent retries for app errors
    Log::error('Webhook processing error: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['status' => 'error_logged']);
}
```

### 4.7 Webhook Best Practices

| ✅ Do | ❌ Don't |
|-------|---------|
| Return 200 quickly | Do heavy processing synchronously |
| Verify signatures | Trust payload without verification |
| Use idempotency keys | Process duplicates |
| Log all webhooks | Ignore webhook errors |
| Handle all event types | Only handle `invoice.paid` |
| Use HTTPS endpoints | Use HTTP (insecure) |

### 4.8 Retry Schedule

If your endpoint returns non-2xx, we retry:

| Attempt | Delay |
|---------|-------|
| 1 | Immediate |
| 2 | 1 minute |
| 3 | 5 minutes |
| 4 | 15 minutes |
| 5 | 1 hour |
| 6 | 4 hours |

After 6 failed attempts, the webhook is marked as failed and moved to the dead letter queue. You can manually retry from your dashboard.

---

## 5. Payment Widget

### 5.1 Hosted Checkout

The simplest integration - redirect customers to our hosted checkout page:

```html
<!-- After creating invoice via API -->
<a href="https://exchange.com/pay/i/inv_a1b2c3d4..." class="pay-button">
    Pay with Crypto
</a>
```

### 5.2 Redirect Flow

```
┌─────────────┐     ┌─────────────────┐     ┌─────────────┐
│  Your Site  │────▶│ Payment Widget  │────▶│ Your Site   │
│  (Checkout) │     │ (Select & Pay)  │     │ (Success)   │
└─────────────┘     └─────────────────┘     └─────────────┘
     │                      │                      ▲
     │ POST /invoices       │                      │
     │ ◀────────────────────┘                      │
     │                                             │
     │ redirect_url ───────────────────────────────┘
```

### 5.3 Widget Customization

The widget automatically uses your merchant name and logo. Additional customization available via dashboard settings:

- Logo URL
- Primary brand color
- Custom CSS (enterprise only)

### 5.4 Deep Link for Mobile Wallets

The widget generates deep links for popular wallets:

```
Bitcoin: bitcoin:bc1qxy2kgd...?amount=0.00235
Ethereum: ethereum:0x123...?value=0.05
```

### 5.5 Widget States

| State | Description |
|-------|-------------|
| Currency Selection | Customer chooses payment currency |
| Payment Details | Address, amount, QR code displayed |
| Detecting | Payment detected, waiting for confirmations |
| Confirming | Showing confirmation progress |
| Success | Payment complete, redirect pending |
| Expired | Invoice expired |
| Cancelled | Invoice cancelled |

---

## 6. Code Examples

### 6.1 Complete PHP Integration

```php
<?php

class CryptoPaymentGateway
{
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl = 'https://exchange.com/api/v1/merchant';

    public function __construct(string $apiKey, string $secretKey)
    {
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
    }

    public function createInvoice(array $data): array
    {
        return $this->request('POST', '/invoices', $data);
    }

    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', "/invoices/{$invoiceId}");
    }

    public function listInvoices(array $filters = []): array
    {
        $query = http_build_query($filters);
        return $this->request('GET', "/invoices?{$query}");
    }

    public function cancelInvoice(string $invoiceId, string $reason = ''): array
    {
        return $this->request('POST', "/invoices/{$invoiceId}/cancel", [
            'reason' => $reason
        ]);
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $url = $this->baseUrl . $path;
        $timestamp = time();
        $nonce = $this->generateUuid();
        $body = $method !== 'GET' && !empty($data) ? json_encode($data) : '';

        // Parse path without query string for signature
        $signPath = parse_url($path, PHP_URL_PATH) ?? $path;
        $fullPath = '/api/v1/merchant' . $signPath;

        $signature = $this->generateSignature($method, $fullPath, $body, $timestamp);

        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Signature: ' . $signature,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode >= 400) {
            throw new Exception(
                $result['error']['message'] ?? 'API request failed',
                $httpCode
            );
        }

        return $result;
    }

    private function generateSignature(string $method, string $path, string $body, int $timestamp): string
    {
        $payload = "{$timestamp}.{$method}.{$path}.{$body}";
        return 'v1=' . hash_hmac('sha256', $payload, $this->secretKey);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// Usage Example
$gateway = new CryptoPaymentGateway(
    'pk_live_your_public_key',
    'sk_live_your_secret_key'
);

try {
    // Create invoice
    $result = $gateway->createInvoice([
        'amount' => 99.99,
        'description' => 'Premium Subscription',
        'external_id' => 'order_' . uniqid(),
        'customer_email' => 'customer@example.com',
        'redirect_url' => 'https://yoursite.com/success',
        'cancel_url' => 'https://yoursite.com/cancel',
    ]);

    // Redirect customer to checkout
    $checkoutUrl = $result['data']['checkout_url'];
    header("Location: {$checkoutUrl}");
    exit;

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### 6.2 Complete Node.js Integration

```javascript
const crypto = require('crypto');
const axios = require('axios');
const { v4: uuidv4 } = require('uuid');

class CryptoPaymentGateway {
    constructor(apiKey, secretKey, baseUrl = 'https://exchange.com/api/v1/merchant') {
        this.apiKey = apiKey;
        this.secretKey = secretKey;
        this.baseUrl = baseUrl;
    }

    async createInvoice(data) {
        return this.request('POST', '/invoices', data);
    }

    async getInvoice(invoiceId) {
        return this.request('GET', `/invoices/${invoiceId}`);
    }

    async listInvoices(filters = {}) {
        const query = new URLSearchParams(filters).toString();
        return this.request('GET', `/invoices${query ? '?' + query : ''}`);
    }

    async cancelInvoice(invoiceId, reason = '') {
        return this.request('POST', `/invoices/${invoiceId}/cancel`, { reason });
    }

    async request(method, path, data = null) {
        const url = this.baseUrl + path;
        const timestamp = Math.floor(Date.now() / 1000);
        const nonce = uuidv4();
        const body = data ? JSON.stringify(data) : '';

        // Parse path for signature (without query string)
        const signPath = '/api/v1/merchant' + path.split('?')[0];
        const signature = this.generateSignature(method, signPath, body, timestamp);

        const headers = {
            'X-API-Key': this.apiKey,
            'X-Timestamp': timestamp.toString(),
            'X-Nonce': nonce,
            'X-Signature': signature,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        };

        try {
            const response = await axios({
                method,
                url,
                headers,
                data: method !== 'GET' ? body : undefined,
            });
            return response.data;
        } catch (error) {
            if (error.response) {
                throw new Error(
                    error.response.data?.error?.message || 'API request failed'
                );
            }
            throw error;
        }
    }

    generateSignature(method, path, body, timestamp) {
        const payload = `${timestamp}.${method}.${path}.${body}`;
        const hash = crypto
            .createHmac('sha256', this.secretKey)
            .update(payload)
            .digest('hex');
        return `v1=${hash}`;
    }
}

// Usage Example (Express.js)
const express = require('express');
const app = express();

const gateway = new CryptoPaymentGateway(
    'pk_live_your_public_key',
    'sk_live_your_secret_key'
);

// Create payment page
app.post('/create-payment', async (req, res) => {
    try {
        const { amount, productId, customerEmail } = req.body;

        const result = await gateway.createInvoice({
            amount: parseFloat(amount),
            description: `Order for Product ${productId}`,
            external_id: `order_${Date.now()}`,
            customer_email: customerEmail,
            redirect_url: `${req.protocol}://${req.get('host')}/payment-success`,
            cancel_url: `${req.protocol}://${req.get('host')}/payment-cancelled`,
            metadata: { product_id: productId }
        });

        res.json({
            success: true,
            checkout_url: result.data.checkout_url
        });
    } catch (error) {
        res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

// Webhook handler
app.post('/webhooks/crypto', express.raw({ type: 'application/json' }), (req, res) => {
    const webhookSecret = 'whsec_your_webhook_secret';
    const signature = req.headers['x-webhook-signature'];
    const timestamp = req.headers['x-webhook-timestamp'];
    const payload = req.body.toString();

    // Verify signature
    const expectedPayload = `${timestamp}.${payload}`;
    const expectedSignature = 'v1=' + crypto
        .createHmac('sha256', webhookSecret)
        .update(expectedPayload)
        .digest('hex');

    if (!crypto.timingSafeEqual(
        Buffer.from(expectedSignature),
        Buffer.from(signature)
    )) {
        return res.status(401).send('Invalid signature');
    }

    const event = JSON.parse(payload);
    
    switch (event.event.type) {
        case 'invoice.paid':
            console.log('Payment received for:', event.data.invoice.external_id);
            // Fulfill order...
            break;
        case 'invoice.expired':
            console.log('Invoice expired:', event.data.invoice.external_id);
            // Handle expiration...
            break;
    }

    res.status(200).send('OK');
});

app.listen(3000);
```

### 6.3 Complete Python Integration

```python
import hmac
import hashlib
import time
import uuid
import json
import requests
from urllib.parse import urlencode, urlparse

class CryptoPaymentGateway:
    def __init__(self, api_key: str, secret_key: str, 
                 base_url: str = 'https://exchange.com/api/v1/merchant'):
        self.api_key = api_key
        self.secret_key = secret_key
        self.base_url = base_url

    def create_invoice(self, data: dict) -> dict:
        return self._request('POST', '/invoices', data)

    def get_invoice(self, invoice_id: str) -> dict:
        return self._request('GET', f'/invoices/{invoice_id}')

    def list_invoices(self, filters: dict = None) -> dict:
        query = f'?{urlencode(filters)}' if filters else ''
        return self._request('GET', f'/invoices{query}')

    def cancel_invoice(self, invoice_id: str, reason: str = '') -> dict:
        return self._request('POST', f'/invoices/{invoice_id}/cancel', {'reason': reason})

    def _request(self, method: str, path: str, data: dict = None) -> dict:
        url = self.base_url + path
        timestamp = int(time.time())
        nonce = str(uuid.uuid4())
        body = json.dumps(data) if data and method != 'GET' else ''

        # Parse path for signature
        parsed = urlparse(path)
        sign_path = '/api/v1/merchant' + parsed.path
        signature = self._generate_signature(method, sign_path, body, timestamp)

        headers = {
            'X-API-Key': self.api_key,
            'X-Timestamp': str(timestamp),
            'X-Nonce': nonce,
            'X-Signature': signature,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        }

        if method == 'GET':
            response = requests.get(url, headers=headers)
        else:
            response = requests.post(url, headers=headers, data=body)

        if response.status_code >= 400:
            error = response.json().get('error', {})
            raise Exception(error.get('message', 'API request failed'))

        return response.json()

    def _generate_signature(self, method: str, path: str, body: str, timestamp: int) -> str:
        payload = f"{timestamp}.{method}.{path}.{body}"
        hash_value = hmac.new(
            self.secret_key.encode(),
            payload.encode(),
            hashlib.sha256
        ).hexdigest()
        return f"v1={hash_value}"


# Flask Webhook Handler
from flask import Flask, request, jsonify

app = Flask(__name__)
gateway = CryptoPaymentGateway(
    'pk_live_your_public_key',
    'sk_live_your_secret_key'
)
WEBHOOK_SECRET = 'whsec_your_webhook_secret'

def verify_webhook_signature(payload: bytes, signature: str, timestamp: str) -> bool:
    """Verify webhook signature."""
    expected_payload = f"{timestamp}.{payload.decode()}"
    expected_signature = 'v1=' + hmac.new(
        WEBHOOK_SECRET.encode(),
        expected_payload.encode(),
        hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected_signature, signature)


@app.route('/create-payment', methods=['POST'])
def create_payment():
    try:
        data = request.json
        result = gateway.create_invoice({
            'amount': float(data['amount']),
            'description': f"Order {data.get('order_id', 'N/A')}",
            'external_id': f"order_{int(time.time())}",
            'customer_email': data.get('email'),
            'redirect_url': request.url_root + 'payment-success',
            'cancel_url': request.url_root + 'payment-cancelled',
        })
        return jsonify({
            'success': True,
            'checkout_url': result['data']['checkout_url']
        })
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500


@app.route('/webhooks/crypto', methods=['POST'])
def webhook_handler():
    signature = request.headers.get('X-Webhook-Signature', '')
    timestamp = request.headers.get('X-Webhook-Timestamp', '')
    payload = request.get_data()

    if not verify_webhook_signature(payload, signature, timestamp):
        return 'Invalid signature', 401

    event = request.json
    event_type = event['event']['type']
    invoice = event['data']['invoice']

    if event_type == 'invoice.paid':
        print(f"Payment received for {invoice['external_id']}")
        # Fulfill order...

    elif event_type == 'invoice.expired':
        print(f"Invoice expired: {invoice['external_id']}")
        # Handle expiration...

    return 'OK', 200


if __name__ == '__main__':
    app.run(port=5000)
```

---

## 7. Testing

### 7.1 Test Mode

Use test API keys (`pk_test_*`, `sk_test_*`) for development:

| Feature | Test Mode | Live Mode |
|---------|-----------|-----------|
| Real blockchain | ❌ | ✅ |
| Real money | ❌ | ✅ |
| Webhooks | ✅ (simulated) | ✅ |
| Dashboard visible | ✅ | ✅ |

### 7.2 Test Invoices

Test invoices auto-complete after a short delay:

```
1. Create invoice with test keys
2. Select any currency on widget
3. Wait 30-60 seconds
4. Invoice automatically moves to "paid"
5. Webhook is sent to your endpoint
```

### 7.3 Webhook Testing

Test your webhook endpoint:

```bash
# Simulate webhook (use your actual endpoint)
curl -X POST https://yoursite.com/webhooks/crypto \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Signature: v1=test_signature" \
  -H "X-Webhook-Timestamp: $(date +%s)" \
  -H "X-Idempotency-Key: test-123" \
  -d '{
    "id": "whk_test123",
    "timestamp": "2024-01-15T12:00:00Z",
    "event": {"type": "invoice.paid"},
    "data": {
      "invoice": {
        "id": "inv_test123",
        "external_id": "order_test",
        "status": "paid",
        "amount_usd": "100.00"
      }
    }
  }'
```

### 7.4 Testing Checklist

- [ ] Create invoice via API
- [ ] Verify checkout_url works
- [ ] Complete test payment flow
- [ ] Receive webhook notification
- [ ] Verify webhook signature
- [ ] Handle duplicate webhooks (idempotency)
- [ ] Handle invoice.expired event
- [ ] Handle error responses
- [ ] Test with all supported currencies

---

## 8. Error Handling

### 8.1 Error Response Format

```json
{
    "success": false,
    "error": {
        "code": "VALIDATION_ERROR",
        "message": "The amount field is required.",
        "details": {
            "errors": {
                "amount": ["The amount field is required."]
            }
        }
    }
}
```

### 8.2 Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `VALIDATION_ERROR` | 422 | Invalid request data |
| `AUTHENTICATION_ERROR` | 401 | Invalid API key or signature |
| `AUTHORIZATION_ERROR` | 403 | Insufficient permissions |
| `NOT_FOUND` | 404 | Resource not found |
| `RATE_LIMIT_EXCEEDED` | 429 | Too many requests |
| `INVALID_STATE` | 400 | Invalid state transition |
| `LIMIT_EXCEEDED` | 400 | Volume or amount limit exceeded |
| `MERCHANT_INACTIVE` | 403 | Merchant account suspended |
| `INTERNAL_ERROR` | 500 | Server error (contact support) |

### 8.3 Rate Limits

| Endpoint | Limit |
|----------|-------|
| POST /invoices | 100/minute |
| GET /invoices | 200/minute |
| GET /invoices/{id} | 300/minute |
| POST /invoices/{id}/cancel | 50/minute |

When rate limited, you'll receive:
```json
{
    "success": false,
    "error": {
        "code": "RATE_LIMIT_EXCEEDED",
        "message": "Rate limit exceeded. Retry after 45 seconds.",
        "details": {
            "retry_after": 45
        }
    }
}
```

---

## 9. Best Practices

### 9.1 Security

| ✅ Do | ❌ Don't |
|-------|---------|
| Store secret key in environment variables | Hardcode secret key in code |
| Use HTTPS for all endpoints | Use HTTP |
| Verify all webhook signatures | Trust webhooks without verification |
| Use idempotency keys | Assume operations are idempotent |
| Implement request timeouts | Wait indefinitely for responses |

### 9.2 Reliability

```php
// Implement retry with exponential backoff
function apiRequestWithRetry($method, $path, $data = [], $maxRetries = 3) {
    $lastException = null;
    
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        try {
            return apiRequest($method, $path, $data);
        } catch (Exception $e) {
            $lastException = $e;
            
            // Don't retry 4xx errors (client errors)
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                throw $e;
            }
            
            // Exponential backoff
            $delay = pow(2, $attempt) * 1000; // 1s, 2s, 4s
            usleep($delay * 1000);
        }
    }
    
    throw $lastException;
}
```

### 9.3 Order Fulfillment

```
IMPORTANT: Only fulfill orders after receiving invoice.paid webhook!

1. Create invoice → Store invoice_id with order
2. Customer pays → We detect payment
3. Wait for confirmations → We verify blockchain
4. invoice.paid webhook → YOU fulfill order
5. (Optional) invoice.settled webhook → Funds in your account
```

### 9.4 Invoice Amount

```
- Always use amount_usd for fixed USD pricing
- The crypto amount is calculated at rate lock time
- Rate is valid for ~15 minutes
- If rate expires before payment, customer can extend
```

---

## 10. FAQ

### Q: How long are invoices valid?
**A:** Default 60 minutes. Can be configured up to 24 hours.

### Q: What happens if customer sends wrong amount?
**A:**
- **Underpayment (<99%):** Invoice marked `underpaid`, webhook sent. Contact support for resolution.
- **Overpayment (>105%):** Invoice marked `overpaid`, webhook sent. Excess can be refunded.
- **Within tolerance (99-105%):** Considered `paid`.

### Q: Can I refund a payment?
**A:** Contact support. Refunds require manual review and customer refund address.

### Q: How do I handle multiple webhook deliveries?
**A:** Use the `X-Idempotency-Key` header to detect duplicates. Store processed keys for at least 24 hours.

### Q: What currencies do you support?
**A:** BTC, ETH, USDT (TRC-20, ERC-20), USDC, LTC, and more. Check `/currencies` endpoint for current list.

### Q: How fast are payments confirmed?
**A:** Depends on blockchain:
- BTC: ~30 min (3 confirmations)
- ETH: ~3 min (12 confirmations)
- USDT TRC-20: ~1 min (20 confirmations)

### Q: Do you support recurring payments?
**A:** Not currently. Create new invoice for each payment.

### Q: What are your fees?
**A:** Typically 1-2% per transaction. Check your merchant dashboard for your specific rate.

### Q: Can I customize the payment widget?
**A:** Yes, basic customization (logo, colors) is available in dashboard. Enterprise plans have more options.

### Q: How do I increase my volume limits?
**A:** Contact support with your business details. Higher limits require verification.

---

## Support

- **Dashboard:** https://exchange.com/merchant
- **Email:** merchant-support@exchange.com
- **Documentation:** https://docs.exchange.com/merchant
- **Status Page:** https://status.exchange.com

---

**Document End**

*Version 1.0.0 | © 2026 Exchange Inc.*
