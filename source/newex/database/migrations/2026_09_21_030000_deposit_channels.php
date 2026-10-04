<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_channels', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('currency_id');
            $t->unsignedBigInteger('network_id');
            $t->unique(['currency_id', 'network_id']);
            $t->string('chain', 30);
            $t->string('kind', 12);
            $t->string('contract', 100)->nullable();
            $t->unsignedSmallInteger('decimals');
            $t->unsignedInteger('confirmations')->default(20);
            $t->decimal('minimum', 36, 18)->default(0);
            $t->decimal('fee_fixed', 36, 18)->default(0);
            $t->decimal('fee_percent', 10, 6)->default(0);
            $t->string('state', 20)->default('draft');
            $t->text('acceptance_reference')->nullable();
            $t->string('config_digest', 64)->nullable();
            $t->unsignedBigInteger('start_block')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('deposit_channel_audits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('channel_id');
            $t->unsignedBigInteger('actor_id');
            $t->json('before')->nullable();
            $t->json('after');
            $t->timestamp('created_at');
        });
        Schema::create('chain_deposit_receipts', function (Blueprint $t) {
            $t->id();
            $t->string('chain', 30);
            $t->string('txn', 66);
            $t->string('event_index', 24);
            $t->unique(['chain', 'txn', 'event_index'], 'chain_receipt_identity');
            $t->unsignedBigInteger('deposit_id')->unique();
            $t->unsignedBigInteger('wallet_id');
            $t->unsignedBigInteger('channel_id');
            $t->decimal('credited_amount', 36, 18);
            $t->json('evidence');
            $t->timestamp('created_at');
        });
        Schema::create('chain_deposit_scan_states', function (Blueprint $t) {
            $t->id();
            $t->string('chain', 30);
            $t->string('scope', 160);
            $t->unique(['chain', 'scope']);
            $t->unsignedBigInteger('window_start')->nullable();
            $t->unsignedBigInteger('window_end')->nullable();
            $t->text('fingerprint')->nullable();
            $t->unsignedBigInteger('scanned_through')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->string('last_error', 100)->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('unrecognized_deposit_events', function (Blueprint $t) {
            $t->id();
            $t->string('chain', 30);
            $t->string('txn', 66);
            $t->string('event_index', 24);
            $t->unique(['chain', 'txn', 'event_index'], 'unknown_deposit_identity');
            $t->string('address', 100);
            $t->string('contract', 100);
            $t->text('raw_amount');
            $t->string('reason', 100);
            $t->json('evidence');
            $t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        throw new RuntimeException('Retain financial evidence and scanner cursors; use a reviewed forward migration.');
    }
};
