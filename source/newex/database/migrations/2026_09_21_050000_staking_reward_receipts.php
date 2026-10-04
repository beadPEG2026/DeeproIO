<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staking_reward_receipts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('stake_id');
            $t->date('reward_date');
            $t->unique(['stake_id', 'reward_date']);
            $t->decimal('amount', 36, 18);
            $t->decimal('balance_before', 36, 18);
            $t->decimal('balance_after', 36, 18);
            $t->string('rule');
            $t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        throw new RuntimeException('Retain reward receipts; do not replay historical rewards.');
    }
};
