<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void {
        Schema::create('bitcoin_wallets', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique(); $t->string('address',120)->unique();
            $t->string('status',16)->default('standby'); $t->boolean('scan_enabled')->default(true);
            $t->unsignedBigInteger('created_by')->nullable(); $t->timestamp('backup_at')->nullable();
            $t->string('backup_reference')->nullable(); $t->timestamps();
        });
        Schema::create('bitcoin_wallet_control', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); $t->unsignedBigInteger('active_wallet_id')->nullable();
            $t->unsignedBigInteger('proposed_wallet_id')->nullable(); $t->unsignedBigInteger('proposed_by')->nullable();
            $t->string('legacy_wallet_name')->nullable(); $t->unsignedBigInteger('approved_by')->nullable(); $t->unsignedInteger('revision')->default(0);
            $t->decimal('max_fee_rate',16,4)->default(100); $t->boolean('auto_cold')->default(false);
            $t->timestamps();
        });
        DB::table('bitcoin_wallet_control')->insert(['id'=>1,'created_at'=>now(),'updated_at'=>now()]);
        Schema::table('custody_transfers', fn(Blueprint $t)=>$t->unsignedBigInteger('bitcoin_wallet_id')->nullable());
        Schema::create('bitcoin_utxo_reservations', function (Blueprint $t) {
            $t->string('txn',64); $t->unsignedInteger('vout'); $t->unsignedBigInteger('transfer_id')->index();
            $t->timestamp('created_at'); $t->primary(['txn','vout']);
        });
        Schema::create('bitcoin_deposit_outputs', function (Blueprint $t) {
            $t->string('txn',64); $t->unsignedInteger('vout'); $t->unsignedBigInteger('wallet_id');
            $t->unsignedBigInteger('deposit_id')->nullable(); $t->timestamps(); $t->primary(['txn','vout']);
        });
        DB::table('custody_networks')->insertOrIgnore(['chain'=>'bitcoin','enabled'=>false,'auto_sweep'=>false,
            'max_fee'=>'0.0001','native_max_fee'=>'0.0001','daily_gas_limit'=>0,'confirmations'=>6,'created_at'=>now(),'updated_at'=>now()]);
    }
    public function down(): void { throw new RuntimeException('Retain wallet routes and signed transaction history. Use a forward migration.'); }
};
