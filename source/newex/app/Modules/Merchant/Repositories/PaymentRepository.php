<?php

namespace App\Modules\Merchant\Repositories;

use App\Modules\Merchant\Enums\PaymentStatus;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PaymentRepository
{
    /**
     * Find payment by ID
     */
    public function find(string $id): ?MerchantInvoicePayment
    {
        return MerchantInvoicePayment::find($id);
    }

    /**
     * Find payment by transaction hash
     */
    public function findByTxnHash(string $txnHash, string $toAddress): ?MerchantInvoicePayment
    {
        return MerchantInvoicePayment::where('txn_hash', $txnHash)
            ->where('to_address', $toAddress)
            ->first();
    }

    /**
     * Get payments for invoice
     */
    public function getForInvoice(string $invoiceId): Collection
    {
        return MerchantInvoicePayment::where('invoice_id', $invoiceId)
            ->orderBy('detected_at', 'asc')
            ->get();
    }

    /**
     * Get payments for merchant
     */
    public function getForMerchant(
        string $merchantId,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = MerchantInvoicePayment::where('merchant_id', $merchantId);

        if (!empty($filters['invoice_id'])) {
            $query->where('invoice_id', $filters['invoice_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['txn_hash'])) {
            $query->where('txn_hash', 'like', '%' . $filters['txn_hash'] . '%');
        }

        if (!empty($filters['from_address'])) {
            $query->where('from_address', $filters['from_address']);
        }

        if (!empty($filters['detected_from'])) {
            $query->where('detected_at', '>=', $filters['detected_from']);
        }

        if (!empty($filters['detected_to'])) {
            $query->where('detected_at', '<=', $filters['detected_to']);
        }

        return $query->orderBy('detected_at', 'desc')->paginate($perPage);
    }

    /**
     * Get payments needing confirmation check
     */
    public function getNeedingConfirmationCheck(): Collection
    {
        return MerchantInvoicePayment::whereIn('status', [
            PaymentStatus::DETECTING->value,
            PaymentStatus::CONFIRMING->value,
        ])
            ->orderBy('detected_at', 'asc')
            ->get();
    }

    /**
     * Get unconfirmed payments for a specific asset
     */
    public function getUnconfirmedByAsset(int $assetId): Collection
    {
        return MerchantInvoicePayment::where('currency_id', $assetId)
            ->whereIn('status', [
                PaymentStatus::DETECTING->value,
                PaymentStatus::CONFIRMING->value,
            ])
            ->orderBy('detected_at', 'asc')
            ->get();
    }

    /**
     * Get confirmed payments total for invoice
     */
    public function getConfirmedTotal(string $invoiceId): array
    {
        $result = MerchantInvoicePayment::where('invoice_id', $invoiceId)
            ->where('status', PaymentStatus::CONFIRMED->value)
            ->where('counted_in_total', true)
            ->selectRaw('SUM(amount_crypto) as total_crypto, SUM(amount_usd) as total_usd')
            ->first();

        return [
            'total_crypto' => $result->total_crypto ?? '0',
            'total_usd' => $result->total_usd ?? '0',
        ];
    }

    /**
     * Get payment count by status
     */
    public function countByStatus(string $merchantId): array
    {
        return MerchantInvoicePayment::where('merchant_id', $merchantId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Get late payments
     */
    public function getLatePayments(string $merchantId, int $limit = 50): Collection
    {
        return MerchantInvoicePayment::where('merchant_id', $merchantId)
            ->where('is_late_payment', true)
            ->orderBy('detected_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Check if transaction hash exists
     */
    public function txnHashExists(string $txnHash): bool
    {
        return MerchantInvoicePayment::where('txn_hash', $txnHash)->exists();
    }

    /**
     * Get average confirmation time for asset
     */
    public function getAverageConfirmationTime(int $assetId, int $sampleSize = 100): ?float
    {
        $payments = MerchantInvoicePayment::where('currency_id', $assetId)
            ->where('status', PaymentStatus::CONFIRMED->value)
            ->whereNotNull('detected_at')
            ->whereNotNull('confirmed_at')
            ->orderBy('confirmed_at', 'desc')
            ->limit($sampleSize)
            ->get();

        if ($payments->isEmpty()) {
            return null;
        }

        $totalSeconds = $payments->sum(function ($payment) {
            return $payment->detected_at->diffInSeconds($payment->confirmed_at);
        });

        return $totalSeconds / $payments->count();
    }
}
