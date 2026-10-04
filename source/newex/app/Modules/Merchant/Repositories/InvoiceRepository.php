<?php

namespace App\Modules\Merchant\Repositories;

use App\Modules\Merchant\Enums\InvoiceStatus;
use App\Modules\Merchant\Models\MerchantInvoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class InvoiceRepository
{
    /**
     * Find invoice by ID
     */
    public function find(string $id): ?MerchantInvoice
    {
        return MerchantInvoice::find($id);
    }

    /**
     * Find invoice by ID for a specific merchant
     */
    public function findForMerchant(string $id, string $merchantId): ?MerchantInvoice
    {
        return MerchantInvoice::where('id', $id)
            ->where('merchant_id', $merchantId)
            ->first();
    }

    /**
     * Find invoice by external ID
     */
    public function findByExternalId(string $merchantId, string $externalId): ?MerchantInvoice
    {
        return MerchantInvoice::where('merchant_id', $merchantId)
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * Find invoice by idempotency key
     */
    public function findByIdempotencyKey(string $merchantId, string $idempotencyKey): ?MerchantInvoice
    {
        return MerchantInvoice::where('merchant_id', $merchantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Find invoice by deposit address
     */
    public function findByDepositAddress(string $address, int $assetId): ?MerchantInvoice
    {
        return MerchantInvoice::where('deposit_address', $address)
            ->where('currency_id', $assetId)
            ->whereIn('status', [
                InvoiceStatus::AWAITING_PAYMENT->value,
                InvoiceStatus::DETECTING->value,
                InvoiceStatus::CONFIRMING->value,
                InvoiceStatus::UNDERPAID->value,
            ])
            ->first();
    }

    /**
     * Get invoices for a merchant with filters
     */
    public function getForMerchant(
        string $merchantId,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = MerchantInvoice::where('merchant_id', $merchantId);

        // Apply filters
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : explode(',', $filters['status']);
            $query->whereIn('status', $statuses);
        }

        if (!empty($filters['external_id'])) {
            $query->where('external_id', $filters['external_id']);
        }

        if (!empty($filters['customer_email'])) {
            $query->where('customer_email', $filters['customer_email']);
        }

        if (!empty($filters['currency'])) {
            $query->whereHas('currencyModel', function ($q) use ($filters) {
                $q->where('symbol', 'like', $filters['currency'] . '%');
            });
        }

        if (!empty($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }

        if (!empty($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        if (!empty($filters['amount_min'])) {
            $query->where('amount_usd', '>=', $filters['amount_min']);
        }

        if (!empty($filters['amount_max'])) {
            $query->where('amount_usd', '<=', $filters['amount_max']);
        }

        if (!empty($filters['environment'])) {
            $query->where('environment', $filters['environment']);
        }

        // Sort
        $sortField = $filters['sort'] ?? 'created_at';
        $sortOrder = $filters['order'] ?? 'desc';
        $query->orderBy($sortField, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * Get invoices that should be expired
     */
    public function getExpiredInvoices(): Collection
    {
        return MerchantInvoice::shouldExpire()->get();
    }

    /**
     * Get invoices with expiring rates
     */
    public function getInvoicesWithExpiringRates(int $minutesThreshold = 2): Collection
    {
        return MerchantInvoice::rateExpiringSoon($minutesThreshold)->get();
    }

    /**
     * Get invoices needing confirmation check
     */
    public function getInvoicesNeedingConfirmationCheck(): Collection
    {
        return MerchantInvoice::needsConfirmationCheck()->get();
    }

    /**
     * Get daily volume for merchant
     */
    public function getDailyVolume(string $merchantId, ?string $date = null): float
    {
        $date = $date ?? now()->toDateString();

        return (float) MerchantInvoice::where('merchant_id', $merchantId)
            ->whereDate('created_at', $date)
            ->whereIn('status', [
                InvoiceStatus::PAID->value,
                InvoiceStatus::OVERPAID->value,
                InvoiceStatus::SETTLED->value,
            ])
            ->sum('amount_usd');
    }

    /**
     * Get monthly volume for merchant
     */
    public function getMonthlyVolume(string $merchantId, ?string $yearMonth = null): float
    {
        $yearMonth = $yearMonth ?? now()->format('Y-m');

        return (float) MerchantInvoice::where('merchant_id', $merchantId)
            ->where('created_at', '>=', $yearMonth . '-01')
            ->where('created_at', '<', now()->addMonth()->startOfMonth())
            ->whereIn('status', [
                InvoiceStatus::PAID->value,
                InvoiceStatus::OVERPAID->value,
                InvoiceStatus::SETTLED->value,
            ])
            ->sum('amount_usd');
    }

    /**
     * Get invoice statistics for merchant
     */
    public function getStatistics(string $merchantId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = MerchantInvoice::where('merchant_id', $merchantId);

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        $total = $query->count();
        $byStatus = $query->clone()->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $paidStatuses = [
            InvoiceStatus::PAID->value,
            InvoiceStatus::OVERPAID->value,
            InvoiceStatus::SETTLED->value,
        ];

        $paid = $query->clone()->whereIn('status', $paidStatuses)->count();
        $volumeUsd = $query->clone()->whereIn('status', $paidStatuses)->sum('amount_usd');
        $feesUsd = $query->clone()->whereIn('status', $paidStatuses)->sum('fee_amount_usd');

        return [
            'total_invoices' => $total,
            'paid_invoices' => $paid,
            'conversion_rate' => $total > 0 ? round(($paid / $total) * 100, 2) : 0,
            'total_volume_usd' => (float) $volumeUsd,
            'total_fees_usd' => (float) $feesUsd,
            'by_status' => $byStatus,
        ];
    }

    /**
     * Get recent invoices for merchant
     */
    public function getRecentInvoices(string $merchantId, int $limit = 10): Collection
    {
        return MerchantInvoice::where('merchant_id', $merchantId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Count invoices by status
     */
    public function countByStatus(string $merchantId): array
    {
        return MerchantInvoice::where('merchant_id', $merchantId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Get active invoices count (not in final state)
     */
    public function getActiveCount(string $merchantId): int
    {
        return MerchantInvoice::where('merchant_id', $merchantId)
            ->pending()
            ->count();
    }
}
