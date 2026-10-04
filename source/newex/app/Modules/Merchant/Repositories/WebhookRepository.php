<?php

namespace App\Modules\Merchant\Repositories;

use App\Modules\Merchant\Models\MerchantWebhook;
use App\Modules\Merchant\Models\MerchantWebhookDeadLetter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class WebhookRepository
{
    /**
     * Find webhook by ID
     */
    public function find(string $id): ?MerchantWebhook
    {
        return MerchantWebhook::find($id);
    }

    /**
     * Find webhook for merchant
     */
    public function findForMerchant(string $id, string $merchantId): ?MerchantWebhook
    {
        return MerchantWebhook::where('id', $id)
            ->where('merchant_id', $merchantId)
            ->first();
    }

    /**
     * Get webhooks for merchant
     */
    public function getForMerchant(
        string $merchantId,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = MerchantWebhook::where('merchant_id', $merchantId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        if (!empty($filters['invoice_id'])) {
            $query->where('invoice_id', $filters['invoice_id']);
        }

        if (!empty($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }

        if (!empty($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get webhooks for invoice
     */
    public function getForInvoice(string $invoiceId): Collection
    {
        return MerchantWebhook::where('invoice_id', $invoiceId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get pending webhooks
     */
    public function getPending(int $limit = 100): Collection
    {
        return MerchantWebhook::pending()
            ->orderByPriority()
            ->limit($limit)
            ->get();
    }

    /**
     * Get webhooks ready for retry
     */
    public function getReadyForRetry(int $limit = 100): Collection
    {
        return MerchantWebhook::readyForRetry()
            ->orderByPriority()
            ->limit($limit)
            ->get();
    }

    /**
     * Get failed webhooks
     */
    public function getFailed(string $merchantId, int $limit = 50): Collection
    {
        return MerchantWebhook::where('merchant_id', $merchantId)
            ->failed()
            ->orderBy('failed_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get dead letter webhooks
     */
    public function getDeadLetters(
        string $merchantId,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = MerchantWebhookDeadLetter::where('merchant_id', $merchantId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get webhook delivery statistics
     */
    public function getStatistics(string $merchantId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = MerchantWebhook::where('merchant_id', $merchantId);

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        $total = $query->clone()->count();
        $delivered = $query->clone()->delivered()->count();
        $failed = $query->clone()->failed()->count();
        $pending = $query->clone()->whereIn('status', ['pending', 'pending_retry'])->count();

        $byEventType = $query->clone()
            ->selectRaw('event_type, COUNT(*) as count')
            ->groupBy('event_type')
            ->pluck('count', 'event_type')
            ->toArray();

        $avgDeliveryTime = $query->clone()
            ->delivered()
            ->selectRaw('AVG(last_response_time_ms) as avg_time')
            ->value('avg_time');

        return [
            'total' => $total,
            'delivered' => $delivered,
            'failed' => $failed,
            'pending' => $pending,
            'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 2) : 0,
            'by_event_type' => $byEventType,
            'avg_delivery_time_ms' => $avgDeliveryTime ? round($avgDeliveryTime) : null,
        ];
    }

    /**
     * Count webhooks by status
     */
    public function countByStatus(string $merchantId): array
    {
        return MerchantWebhook::where('merchant_id', $merchantId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Get webhooks with circuit breaker active
     */
    public function getCircuitBreakerActive(): Collection
    {
        return MerchantWebhook::circuitBreakerActive()->get();
    }

    /**
     * Check idempotency key exists
     */
    public function idempotencyKeyExists(string $merchantId, string $idempotencyKey): bool
    {
        return MerchantWebhook::where('merchant_id', $merchantId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', MerchantWebhook::STATUS_DELIVERED)
            ->exists();
    }
}
