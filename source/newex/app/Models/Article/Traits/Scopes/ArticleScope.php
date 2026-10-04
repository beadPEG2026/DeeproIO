<?php

namespace App\Models\Article\Traits\Scopes;

trait ArticleScope
{
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'like', '%'.$search.'%');
                $query->orWhere('body', 'like', '%'.$search.'%');
            });
        })->when($filters['trashed'] ?? null, function ($query, $trashed) {
            if ($trashed === 'with') {
                $query->withTrashed();
            } elseif ($trashed === 'only') {
                $query->onlyTrashed();
            }
        })->when($filters['type'] ?? null, function ($query, $type) {
            if($type != "all") {
                $query->whereType($type);
            }
        });
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeFeatured($query)
    {
        return $query->whereFeatured(true);
    }

    public function scopeActive($query)
    {
        return $query->whereStatus(true);
    }

    /**
     * Limit public articles to global entries or entries targeted at the
     * visitor's two-letter country code (for example, ES for Spain).
     */
    public function scopeVisibleToCountry($query, ?string $countryCode)
    {
        $countryCode = strtoupper(trim((string) $countryCode));

        return $query->where(function ($query) use ($countryCode) {
            $query->whereNull('visibility_country');

            if ($countryCode !== '') {
                $query->orWhere('visibility_country', $countryCode);
            }
        });
    }

    /**
     * Apply both optional audience restrictions. A visitor must satisfy every
     * restriction that is set on the article; guests have no referral roots.
     */
    public function scopeVisibleToAudience($query, ?string $countryCode, array $referralRootIds = [])
    {
        $countryCode = strtoupper(trim((string) $countryCode));
        $referralRootIds = array_values(array_filter(array_map('intval', $referralRootIds)));

        return $query->where(function ($query) use ($countryCode, $referralRootIds) {
            $query->whereNull('visibility_referral_user_id')
                ->where(function ($query) use ($countryCode) {
                    $query->whereNull('visibility_country');
                    if ($countryCode !== '') {
                        $query->orWhere('visibility_country', $countryCode);
                    }
                });

            if ($referralRootIds !== []) {
                $query->orWhere(function ($query) use ($countryCode, $referralRootIds) {
                    $query->whereIn('visibility_referral_user_id', $referralRootIds)
                        ->where(function ($query) use ($countryCode) {
                            $query->whereNull('visibility_country');
                            if ($countryCode !== '') {
                                $query->orWhere('visibility_country', $countryCode);
                            }
                        });
                });
            }
        });
    }
}
