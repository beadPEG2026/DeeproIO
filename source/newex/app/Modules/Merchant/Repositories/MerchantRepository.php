<?php

namespace App\Modules\Merchant\Repositories;

use App\Modules\Merchant\Models\Merchant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class MerchantRepository
{
    /**
     * Find merchant by ID
     */
    public function find(string $id): ?Merchant
    {
        return Merchant::find($id);
    }

    /**
     * Find merchant by user ID
     */
    public function findByUserId(int $userId): ?Merchant
    {
        return Merchant::where('user_id', $userId)->first();
    }

    /**
     * Find merchant by email
     */
    public function findByEmail(string $email): ?Merchant
    {
        return Merchant::where('business_email', $email)->first();
    }

    /**
     * Get all merchants with filters
     */
    public function getAll(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Merchant::query();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['verification_status'])) {
            $query->where('verification_status', $filters['verification_status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhere('business_email', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['needs_review'])) {
            $query->where('manual_review_required', true);
        }

        if (!empty($filters['high_risk'])) {
            $query->where('risk_score', '>=', 70);
        }

        $sortField = $filters['sort'] ?? 'created_at';
        $sortOrder = $filters['order'] ?? 'desc';

        return $query->orderBy($sortField, $sortOrder)->paginate($perPage);
    }

    /**
     * Get active merchants
     */
    public function getActive(): Collection
    {
        return Merchant::active()->verified()->get();
    }

    /**
     * Get merchants pending verification
     */
    public function getPendingVerification(): Collection
    {
        return Merchant::pendingVerification()->get();
    }

    /**
     * Get merchants needing review
     */
    public function getNeedingReview(): Collection
    {
        return Merchant::needsReview()->get();
    }

    /**
     * Get high risk merchants
     */
    public function getHighRisk(int $threshold = 70): Collection
    {
        return Merchant::highRisk($threshold)->get();
    }

    /**
     * Get merchant statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => Merchant::count(),
            'active' => Merchant::active()->count(),
            'pending' => Merchant::byStatus('pending')->count(),
            'suspended' => Merchant::byStatus('suspended')->count(),
            'verified' => Merchant::verified()->count(),
            'pending_verification' => Merchant::pendingVerification()->count(),
            'needs_review' => Merchant::needsReview()->count(),
            'high_risk' => Merchant::highRisk()->count(),
        ];
    }

    /**
     * Get top merchants by volume
     */
    public function getTopByVolume(int $limit = 10, ?string $period = null): Collection
    {
        $query = Merchant::active()
            ->orderBy('total_volume_usd', 'desc')
            ->limit($limit);

        return $query->get();
    }

    /**
     * Update merchant statistics
     */
    public function updateStatistics(Merchant $merchant): void
    {
        $stats = app(InvoiceRepository::class)->getStatistics($merchant->id);

        $merchant->update([
            'total_invoices' => $stats['total_invoices'],
            'paid_invoices' => $stats['paid_invoices'],
            'total_volume_usd' => $stats['total_volume_usd'],
            'total_fees_usd' => $stats['total_fees_usd'],
        ]);
    }

    /**
     * Create merchant
     */
    public function create(array $data): Merchant
    {
        return Merchant::create($data);
    }

    /**
     * Update merchant
     */
    public function update(Merchant $merchant, array $data): Merchant
    {
        $merchant->update($data);
        return $merchant->fresh();
    }

    /**
     * Count merchants by country
     */
    public function countByCountry(): array
    {
        return Merchant::selectRaw('country_code, COUNT(*) as count')
            ->whereNotNull('country_code')
            ->groupBy('country_code')
            ->pluck('count', 'country_code')
            ->toArray();
    }
}
