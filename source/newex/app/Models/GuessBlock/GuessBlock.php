<?php

namespace App\Models\GuessBlock;

use Illuminate\Database\Eloquent\Model;

class GuessBlock extends Model
{
    protected $table = 'guess_blocks';

    protected $fillable = [
        'chain',
        'block_number',
        'block_hash',
        'raw_data',
    ];
}