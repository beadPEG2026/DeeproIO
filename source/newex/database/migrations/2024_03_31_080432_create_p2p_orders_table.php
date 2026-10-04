<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $currencyLength = 36;
        $currencyDecimals = 18;

        Schema::create('peer_orders', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->uuid('id')->primary();
            $table->bigInteger('user_id')->index();
            $table->uuid('ad_id')->index();
            $table->bigInteger('ad_user_id')->index();
            $table->integer('payment_method_id')->index();
            $table->integer('user_payment_method_id')->index();
            $table->string('pair')->nullable();
            $table->integer('base_currency_id')->index();
            $table->integer('quote_currency_id')->index();
            $table->string('timeframe')->nullable();
            $table->text('type')->nullable();
            $table->decimal('amount', $currencyLength, 8)->default(0);
            $table->decimal('quote_amount', $currencyLength, 3)->default(0);
            $table->decimal('price', $currencyLength, 3)->default(0);
            $table->decimal('fee_maker', $currencyLength, 8)->default(0);
            $table->decimal('fee_taker', $currencyLength, 8)->default(0);
            $table->text('status')->nullable();
            $table->text('previous_status')->nullable();
            $table->boolean('isQuoteAmount')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->bigInteger('guilty_user_id')->nullable();
            $table->text('appeal_stage')->nullable()->index();
            $table->timestamp('appealed_at')->nullable();
            $table->integer('appealed_by')->nullable();
            $table->integer('appealed_user')->nullable();
            $table->boolean('appeal_notified')->default(false)->nullable();
            $table->integer('paid_duration')->nullable()->default(0);
            $table->integer('release_duration')->nullable()->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('peer_orders');
    }
};
