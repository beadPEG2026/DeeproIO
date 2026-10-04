<?php

namespace App\Models\GuessOrder;

use App\Models\GuessGame\GuessGame;
use Illuminate\Database\Eloquent\Model;

class GuessOrder extends Model
{
    protected $table = 'guess_orders';

    protected $fillable = [
        'user_id',
        'game_id',
        'wallet_id',
        'bet_no',
        'chain',
        'currency_symbol',
        'bet_type',
        'bet_value',
        'amount',
        'odds',
        'win_amount',
        'target_block',
        'open_block',
        'open_hash',
        'open_result',
        'status',
        'remark',
    ];

    protected $casts = [
        'amount' => 'decimal:18',
        'odds' => 'decimal:8',
        'win_amount' => 'decimal:18',
    ];

    public function game()
    {
        return $this->belongsTo(GuessGame::class, 'game_id');
    }
}