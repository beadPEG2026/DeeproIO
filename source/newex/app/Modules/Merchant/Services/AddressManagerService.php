<?php

namespace App\Modules\Merchant\Services;

use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantAddressAuditLog;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Services\PaymentGateways\Coin\Bitcoin\Api\BitcoinGateway;
use App\Services\PaymentGateways\Coin\Bnb\Api\BnbGateway;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use App\Services\PaymentGateways\Coin\Polygon\Api\PolygonGateway;
use App\Services\PaymentGateways\Coin\Ripple\Services\RippleService;
use App\Services\PaymentGateways\Coin\Solana\Api\SolanaGateway;
use App\Services\PaymentGateways\Coin\Ton\Services\TonService;
use App\Services\PaymentGateways\Coin\Tron\Api\TronGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class AddressManagerService
{
    /**
     * Minimum addresses to keep in pool per asset
     */
    protected int $minPoolSize = 10;

    /**
     * Assign an address to an invoice
     */
    public function assignAddress(MerchantInvoice $invoice, Currency $currency, ?int $networkId = null): array
    {
        return DB::transaction(function () use ($invoice, $currency, $networkId) {
            // Check if invoice already has an address
            if ($invoice->deposit_address_id) {
                $existing = MerchantDepositAddress::find($invoice->deposit_address_id);
                if ($existing) {
                    return [
                        'address_id' => $existing->id,
                        'address' => $existing->address,
                        'memo' => $existing->memo,
                    ];
                }
            }

            // Get or generate address - always use unique addresses for simplicity
            $address = $this->getUniqueAddress($invoice->merchant, $currency, $networkId);

            // Assign to invoice
            $address->assignToInvoice($invoice->id);

            // Audit log
            $this->logAddressEvent($address, 'assigned', [
                'invoice_id' => $invoice->id,
                'merchant_id' => $invoice->merchant_id,
                'network_id' => $networkId,
            ]);

            Log::info("Address assigned to invoice", [
                'address_id' => $address->id,
                'address' => $address->address,
                'invoice_id' => $invoice->id,
                'network_id' => $networkId,
            ]);

            return [
                'address_id' => $address->id,
                'address' => $address->address,
                'memo' => $address->memo,
            ];
        });
    }

    /**
     * Get or create a unique address for the currency and network
     */
    protected function getUniqueAddress(Merchant $merchant, Currency $currency, ?int $networkId = null): MerchantDepositAddress
    {
        // Build query
        $query = MerchantDepositAddress::where('currency_id', $currency->id)
            ->where('merchant_id', $merchant->id)
            ->where('status', MerchantDepositAddress::STATUS_AVAILABLE);

        if ($networkId) {
            $query->where('network_id', $networkId);
        }

        // Try to get an available address from the pool
        $address = $query->lockForUpdate()->first();

        if ($address) {
            return $address;
        }

        // No available addresses - generate a new one
        return $this->generateNewAddress($merchant, $currency, true, $networkId);
    }

    /**
     * Get shared address with unique memo
     */
    protected function getSharedMemoAddress(Merchant $merchant, Currency $currency, ?int $networkId = null): MerchantDepositAddress
    {
        // Get or create the shared address
        $query = MerchantDepositAddress::where('currency_id', $currency->id)
            ->where('merchant_id', $merchant->id)
            ->whereNull('invoice_id');

        if ($networkId) {
            $query->where('network_id', $networkId);
        }

        $sharedAddress = $query->first();

        if (!$sharedAddress) {
            $sharedAddress = $this->generateNewAddress($merchant, $currency, false, $networkId);
        }

        // Create a new record with unique memo
        $memo = $this->generateUniqueMemo();

        return MerchantDepositAddress::create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'currency_id' => $currency->id,
            'network_id' => $networkId,
            'address' => $sharedAddress->address,
            'memo' => $memo,
            'derivation_path' => $sharedAddress->derivation_path,
            'status' => MerchantDepositAddress::STATUS_AVAILABLE,
        ]);
    }

    /**
     * Get shared address with unique tag (similar to memo)
     */
    protected function getSharedTagAddress(Merchant $merchant, Currency $currency, ?int $networkId = null): MerchantDepositAddress
    {
        return $this->getSharedMemoAddress($merchant, $currency, $networkId);
    }

    /**
     * Generate a new blockchain address
     */
    protected function generateNewAddress(
        Merchant $merchant,
        Currency $currency,
        bool $availableStatus = true,
        ?int $networkId = null
    ): MerchantDepositAddress {
        // Get the next derivation index
        $lastIndex = MerchantDepositAddress::where('currency_id', $currency->id)
            ->max('address_index') ?? 0;

        $newIndex = $lastIndex + 1;

        // Generate address using the appropriate helper based on network
        $addressData = $this->generateBlockchainAddress($currency, $newIndex, $networkId);

        $address = MerchantDepositAddress::create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'currency_id' => $currency->id,
            'network_id' => $networkId,
            'address' => $addressData['address'],
            'private_key' => $addressData['private_key'] ?? null,
            'memo' => $addressData['memo'] ?? null,
            'derivation_path' => $addressData['derivation_path'] ?? null,
            'address_index' => $newIndex,
            'status' => $availableStatus
                ? MerchantDepositAddress::STATUS_AVAILABLE
                : MerchantDepositAddress::STATUS_ASSIGNED,
        ]);

        // Audit log (don't log private key)
        $this->logAddressEvent($address, 'created', [
            'derivation_index' => $newIndex,
            'network_id' => $networkId,
        ]);

        Log::info("New deposit address generated", [
            'address_id' => $address->id,
            'address' => $address->address,
            'currency' => $currency->symbol,
            'network_id' => $networkId,
            'index' => $newIndex,
        ]);

        return $address;
    }

    /**
     * Generate blockchain address using appropriate helper
     * Uses network slug to determine the correct address generator
     */
    protected function generateBlockchainAddress(Currency $currency, int $index, ?int $networkId = null): array
    {
        // Get network slug if network ID is provided
        $networkSlug = null;
        if ($networkId) {
            $network = Network::find($networkId);
            $networkSlug = $network?->slug;
        }

        // Use network slug for address generation
        if ($networkSlug) {
            return match ($networkSlug) {
                'btc', 'brc20' => $this->generateBitcoinAddress($index),
                'eth' => $this->generateEthereumAddress($index),
                'erc20' => $this->generateErcAddress($index),
                'bnb' => $this->generateBnbAddress($index),
                'bep20' => $this->generateBepAddress($index),
                'trx' => $this->generateTrxAddress($index),
                'trc20' => $this->generateTrcAddress($index),
                'matic' => $this->generateMaticAddress($index),
                'matic20' => $this->generateMatic20Address($index),
                'sol', 'solspl' => $this->generateSolanaAddress($index),
                'xrp' => $this->generateRippleAddress($index),
                'ton' => $this->generateTonAddress($index),
                default => $this->generateEthereumAddress($index),
            };
        }

        // Fallback to currency type/symbol
        $type = strtolower($currency->type ?? '');
        $symbol = strtolower($currency->symbol ?? '');

        return match (true) {
            $symbol === 'btc' => $this->generateBitcoinAddress($index),
            $symbol === 'eth' => $this->generateEthereumAddress($index),
            $type === 'erc20' => $this->generateErcAddress($index),
            $symbol === 'bnb' => $this->generateBnbAddress($index),
            $type === 'bep20' => $this->generateBepAddress($index),
            $symbol === 'trx' => $this->generateTrxAddress($index),
            $type === 'trc20' => $this->generateTrcAddress($index),
            $symbol === 'matic' => $this->generateMaticAddress($index),
            $symbol === 'sol' => $this->generateSolanaAddress($index),
            $symbol === 'xrp' => $this->generateRippleAddress($index),
            $symbol === 'ton' => $this->generateTonAddress($index),
            default => $this->generateEthereumAddress($index),
        };
    }

    /**
     * Generate Bitcoin address using BitcoinGateway
     */
    protected function generateBitcoinAddress(int $index): array
    {
        try {
            $address = (new BitcoinGateway())->createBitcoinAddress();
            $privateKey = bitcoind()->dumpprivkey($address); // Get private key from bitcoind

            return [
                'address' => $address,
                'private_key' => $privateKey,
                'derivation_path' => "m/84'/0'/0'/0/{$index}",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate Bitcoin address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate Bitcoin address: ' . $e->getMessage());
        }
    }

    /**
     * Generate Ethereum address using EthereumGateway
     */
    protected function generateEthereumAddress(int $index): array
    {
        try {
            $generatedAddress = (new EthereumGateway())->createEthAddress();

            return [
                'address' => $generatedAddress['address'],
                'private_key' => $generatedAddress['private_key'],
                'derivation_path' => "m/44'/60'/0'/0/{$index}",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate Ethereum address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate Ethereum address: ' . $e->getMessage());
        }
    }

    /**
     * Generate ERC-20 address (same as Ethereum)
     */
    protected function generateErcAddress(int $index): array
    {
        try {
            $generatedAddress = (new EthereumGateway())->createErcAddress();

            return [
                'address' => $generatedAddress['address'],
                'private_key' => $generatedAddress['private_key'],
                'derivation_path' => "m/44'/60'/0'/0/{$index}",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate ERC address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate ERC address: ' . $e->getMessage());
        }
    }

    /**
     * Generate BNB address using BnbGateway
     */
    protected function generateBnbAddress(int $index): array
    {
        try {
            $generatedAddress = (new BnbGateway())->createBnbAddress();

            return [
                'address' => $generatedAddress['address'],
                'private_key' => $generatedAddress['private_key'],
                'derivation_path' => "m/44'/60'/0'/0/{$index}",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate BNB address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate BNB address: ' . $e->getMessage());
        }
    }

    /**
     * Generate BEP-20 address (same as BNB)
     */
    protected function generateBepAddress(int $index): array
    {
        try {
            $generatedAddress = (new BnbGateway())->createBepAddress();

            return [
                'address' => $generatedAddress['address'],
                'private_key' => $generatedAddress['private_key'],
                'derivation_path' => "m/44'/60'/0'/0/{$index}",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate BEP address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate BEP address: ' . $e->getMessage());
        }
    }

    /**
     * Generate TRX address using TronGateway
     */
    protected function generateTrxAddress(int $index): array
    {
        try {
            $generatedAddress = (new TronGateway())->createTrxAddress();

            return [
                'address' => $generatedAddress['address'],
                'private_key' => $generatedAddress['private_key'],
                'derivation_path' => "m/44'/195'/0'/0/{$index}",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate TRX address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate TRX address: ' . $e->getMessage());
        }
    }

    /**
     * Generate TRC-20 address (same as TRX)
     */
    protected function generateTrcAddress(int $index): array
    {
        try {
            $generatedAddress = (new TronGateway())->createTrcAddress();

            return [
                'address' => $generatedAddress['address'],
                'private_key' => $generatedAddress['private_key'],
                'derivation_path' => "m/44'/195'/0'/0/{$index}",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate TRC address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate TRC address: ' . $e->getMessage());
        }
    }

    /**
     * Generate MATIC address using PolygonGateway
     */
    protected function generateMaticAddress(int $index): array
    {
        try {
            $generatedAddress = (new PolygonGateway())->createMaticAddress();

            return [
                'address' => $generatedAddress['address'],
                'private_key' => $generatedAddress['private_key'],
                'derivation_path' => "m/44'/60'/0'/0/{$index}",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate MATIC address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate MATIC address: ' . $e->getMessage());
        }
    }

    /**
     * Generate MATIC-20 address (same as MATIC)
     */
    protected function generateMatic20Address(int $index): array
    {
        try {
            $generatedAddress = (new PolygonGateway())->createMatic20Address();

            return [
                'address' => $generatedAddress['address'],
                'private_key' => $generatedAddress['private_key'],
                'derivation_path' => "m/44'/60'/0'/0/{$index}",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate MATIC20 address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate MATIC20 address: ' . $e->getMessage());
        }
    }

    /**
     * Generate Solana address using SolanaGateway
     */
    protected function generateSolanaAddress(int $index): array
    {
        try {
            $generatedAddress = (new SolanaGateway())->createSolAddress();

            return [
                'address' => $generatedAddress['address'],
                'private_key' => $generatedAddress['private_key'],
                'derivation_path' => "m/44'/501'/{$index}'/0'",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate Solana address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate Solana address: ' . $e->getMessage());
        }
    }

    /**
     * Generate Ripple (XRP) address - uses shared address with unique memo
     */
    protected function generateRippleAddress(int $index): array
    {
        try {
            $rippleService = new RippleService();
            $systemWallet = $rippleService->getSystemWallet();
            $memo = $rippleService->generateMemo();

            return [
                'address' => $systemWallet['address'],
                'private_key' => null, // System wallet, no individual private key
                'memo' => $memo,
                'derivation_path' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate Ripple address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate Ripple address: ' . $e->getMessage());
        }
    }

    /**
     * Generate TON address - uses shared address with unique memo
     */
    protected function generateTonAddress(int $index): array
    {
        try {
            $tonService = new TonService();
            $systemWallet = $tonService->getSystemWallet();
            $memo = $tonService->generateMemo();

            return [
                'address' => $systemWallet['address'],
                'private_key' => null, // System wallet, no individual private key
                'memo' => $memo,
                'derivation_path' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate TON address', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to generate TON address: ' . $e->getMessage());
        }
    }

    /**
     * Release address back to pool
     */
    public function releaseAddress(MerchantInvoice $invoice): void
    {
        if (!$invoice->deposit_address_id) {
            return;
        }

        $address = MerchantDepositAddress::find($invoice->deposit_address_id);

        if (!$address) {
            return;
        }

        // For unique addresses that received payments, mark as used (never reuse)
        if ($address->first_payment_at) {
            $address->markAsUsed();
            $this->logAddressEvent($address, 'marked_used', [
                'invoice_id' => $invoice->id,
            ]);
            return;
        }

        // For unused addresses in non-production, could return to pool
        // In production, we never reuse addresses
        $reuseEnabled = !config('merchant_acquiring.security.address_reuse', false);

        if ($reuseEnabled) {
            // Never reuse in production
            $address->markAsExpired();
        } else {
            $address->markAsExpired();
        }

        $this->logAddressEvent($address, 'released', [
            'invoice_id' => $invoice->id,
            'had_payments' => (bool) $address->first_payment_at,
        ]);

        Log::info("Address released", [
            'address_id' => $address->id,
            'invoice_id' => $invoice->id,
            'new_status' => $address->status,
        ]);
    }

    /**
     * Generate unique memo for shared address
     */
    protected function generateUniqueMemo(): string
    {
        do {
            $memo = (string) random_int(100000000, 999999999);
        } while (MerchantDepositAddress::where('memo', $memo)->exists());

        return $memo;
    }

    /**
     * Pre-generate addresses to fill pool
     */
    public function ensurePoolSize(Currency $currency, int $minSize = null): int
    {
        $minSize = $minSize ?? $this->minPoolSize;

        // Count available addresses per merchant
        $merchants = Merchant::active()->get();
        $generated = 0;

        foreach ($merchants as $merchant) {
            $available = MerchantDepositAddress::where('currency_id', $currency->id)
                ->where('merchant_id', $merchant->id)
                ->where('status', MerchantDepositAddress::STATUS_AVAILABLE)
                ->count();

            $toGenerate = max(0, $minSize - $available);

            for ($i = 0; $i < $toGenerate; $i++) {
                $this->generateNewAddress($merchant, $currency);
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * Mark addresses requiring sweep
     */
    public function markForSweep(float $minBalanceUsd = 10.0): int
    {
        // Find addresses with payments that may have remaining balance
        $addresses = MerchantDepositAddress::whereIn('status', [
            MerchantDepositAddress::STATUS_USED,
            MerchantDepositAddress::STATUS_EXPIRED,
        ])
            ->where('is_sweep_required', false)
            ->whereNotNull('first_payment_at')
            ->whereNull('swept_at')
            ->get();

        $marked = 0;
        foreach ($addresses as $address) {
            $address->markForSweep();
            $this->logAddressEvent($address, 'marked_for_sweep');
            $marked++;
        }

        return $marked;
    }

    /**
     * Log address audit event
     */
    protected function logAddressEvent(MerchantDepositAddress $address, string $eventType, array $data = []): void
    {
        DB::table('merchant_address_audit_logs')->insert([
            'address_id' => $address->id,
            'merchant_id' => $address->merchant_id,
            'invoice_id' => $address->invoice_id,
            'event_type' => $eventType,
            'old_status' => null,
            'new_status' => $address->status,
            'event_data' => json_encode($data),
            'triggered_by' => 'system',
            'created_at' => now(),
        ]);
    }

    /**
     * Get pool status for monitoring
     */
    public function getPoolStatus(): array
    {
        $currencies = Currency::where('is_merchant', true)->where('status', true)->get();
        $status = [];

        foreach ($currencies as $currency) {
            $available = MerchantDepositAddress::where('currency_id', $currency->id)
                ->where('status', MerchantDepositAddress::STATUS_AVAILABLE)
                ->count();

            $assigned = MerchantDepositAddress::where('currency_id', $currency->id)
                ->where('status', MerchantDepositAddress::STATUS_ASSIGNED)
                ->count();

            $used = MerchantDepositAddress::where('currency_id', $currency->id)
                ->where('status', MerchantDepositAddress::STATUS_USED)
                ->count();

            $needsSweep = MerchantDepositAddress::where('currency_id', $currency->id)
                ->where('is_sweep_required', true)
                ->count();

            $status[$currency->symbol] = [
                'available' => $available,
                'assigned' => $assigned,
                'used' => $used,
                'needs_sweep' => $needsSweep,
                'pool_healthy' => $available >= $this->minPoolSize,
            ];
        }

        return $status;
    }
}
