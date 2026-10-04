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
        Schema::create('lending_currencies', function (Blueprint $table) {
            $table->id();
            $table->integer('lending_id')->unsigned()->index();
            $table->integer('currency_id')->unsigned()->index();

            // Flexible
            $table->double('flex_initial_ltv')->default(0);
            $table->double('flex_margin_call')->default(0);
            $table->double('flex_liquidation_ltv')->default(0);

            // 7 days Stable
            $table->double('weekly_initial_ltv')->default(0);
            $table->double('weekly_margin_call')->default(0);
            $table->double('weekly_liquidation_ltv')->default(0);

            // 30 days Stable
            $table->double('monthly_initial_ltv')->default(0);
            $table->double('monthly_margin_call')->default(0);
            $table->double('monthly_liquidation_ltv')->default(0);

            $table->timestamps();
            $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lending_currencies');
    }
};
