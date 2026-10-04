<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('withdrawal_address_book',function(Blueprint $t) {
            $t->id();$t->unsignedBigInteger('user_id')->index();$t->unsignedBigInteger('currency_id');
            $t->unsignedBigInteger('network_id');$t->string('label',60);$t->string('address',255);
            $t->string('payment_id')->nullable();$t->string('fingerprint',64);$t->timestamps();
            $t->unique(['user_id','fingerprint']);
        });
    }
    public function down(): void { Schema::dropIfExists('withdrawal_address_book'); }
};
