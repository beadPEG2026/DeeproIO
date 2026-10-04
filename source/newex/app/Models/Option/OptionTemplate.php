<?php

namespace App\Models\Option;

use App\Models\Option\Traits\Relations\OptionTemplateRelation;
use Illuminate\Database\Eloquent\Model;

class OptionTemplate extends Model
{
    use OptionTemplateRelation;

    protected $table = 'options_templates';

    protected $casts = [
        'is_redeemed' => 'boolean',
    ];

    public $fillable = [
        'market_id',
        'type',
        'is_redeemed',
        'period',
        'amount',
        'action'
    ];
}
