<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        foreach (['login_ip', 'zc_ip'] as $column) {
            if (!Schema::hasColumn('users', $column)) {
                Schema::table('users', fn(Blueprint $t) => $t->string($column, 45)->nullable());
            }
        }
        foreach (['is_xn', 'is_xm'] as $column) {
            if (!Schema::hasColumn('users', $column)) {
                Schema::table('users', fn(Blueprint $t) => $t->boolean($column)->default(false));
            }
        }
        if (!Schema::hasColumn('staking', 'staking_type')) {
            Schema::table('staking', fn(Blueprint $t) => $t->unsignedSmallInteger('staking_type')->default(0));
        }
        if (!Schema::hasColumn('options', 'fee_rate')) {
            Schema::table('options', fn(Blueprint $t) => $t->decimal('fee_rate', 18, 8)->default(0));
        }
        foreach (['trade_margin_amount', 'auto_invest_margin_amount', 'total_margin_amount'] as $column) {
            if (!Schema::hasColumn('futures_contract', $column)) {
                Schema::table('futures_contract', fn(Blueprint $t) => $t->decimal($column, 36, 18)->default(0));
            }
        }
        foreach (['fee' => [36, 18], 'fee_refund_rate' => [18, 8], 'fee_refund_amount' => [36, 18]] as $column => $precision) {
            if (!Schema::hasColumn('options', $column)) {
                Schema::table('options', fn(Blueprint $t) => $t->decimal($column, ...$precision)->default(0));
            }
        }
        if (!Schema::hasTable('auto_invest_margin_locks')) {
            Schema::create('auto_invest_margin_locks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('auto_invest_order_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('currency_id');
                $t->string('source_type');
                $t->string('source_id');
                foreach (['amount', 'released_amount', 'consumed_amount'] as $column) {
                    $t->decimal($column, 36, 18)->default(0);
                }
                $t->string('status')->default('active');
                $t->timestamp('released_at')->nullable();
                $t->timestamp('consumed_at')->nullable();
                $t->timestamps();
                $t->index(['source_type', 'source_id']);
                $t->index('auto_invest_order_id');
            });
        }
    }
    public function down(): void
    {
        throw new RuntimeException('Retain compatibility columns on code rollback.');
    }
};
