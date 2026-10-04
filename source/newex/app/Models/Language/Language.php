<?php
/*
 *  Copyright 2021. Crypto Smart Solutions, LLC
 *  Protected with Proprietary License
 */

namespace App\Models\Language;

use App\Models\Language\Traits\Scopes\LanguageScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    use HasFactory, LanguageScope;

    public $fillable = [
        'name',
        'slug',
        'status',
        'is_default'
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_default' => 'boolean',
    ];
}
