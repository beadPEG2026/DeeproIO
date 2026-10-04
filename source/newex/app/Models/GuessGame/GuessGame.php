<?php

namespace App\Models\GuessGame;

use Illuminate\Database\Eloquent\Model;

class GuessGame extends Model
{
    protected $table = 'guess_games';

    protected $fillable = [
        'name',
        'code',
        'hash',
        'status',
        'min_amount',
        'max_amount',
        'settle_delay_blocks',
        'odds_json',
        'remark',
    ];

    protected $casts = [
        'min_amount' => 'decimal:18',
        'max_amount' => 'decimal:18',
    ];
}