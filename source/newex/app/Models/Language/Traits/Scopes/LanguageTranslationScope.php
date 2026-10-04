<?php
/*
 *  Copyright 2021. Crypto Smart Solutions, LLC
 *  Protected with Proprietary License
 */

namespace App\Models\Language\Traits\Scopes;

trait LanguageTranslationScope
{
    // Order the list by its name in ascending mode
    public function scopeOrderByAsc($query)
    {
        return $query->orderBy('key', 'asc');
    }

    // Search the language file by its name or description
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('key', 'like', '%'.$search.'%');
                $query->orWhere('content', 'like', '%'.$search.'%');
            });
        });
    }
}

