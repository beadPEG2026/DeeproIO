<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('futures_contract', function (Blueprint $t) {
            $t->string('referral_balance_domain', 12)->nullable();
        });
    }
    public function down(): void {
        throw new RuntimeException('Preserve fee provenance when rolling back application code.');
    }
};
