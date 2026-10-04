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
        // Supported Currencies (BTC, ETH, USDT, etc.)
        Schema::create('acquiring_supported_currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // BTC, ETH, USDT
            $table->string('name', 100); // Bitcoin, Ethereum, Tether
            $table->string('icon_url', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('display_order')->unsigned()->default(100);
            $table->timestamps();

            $table->index('is_active');
            $table->index('display_order');
        });

        // Supported Networks (Bitcoin, Ethereum, Tron, BSC, etc.)
        Schema::create('acquiring_supported_networks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique(); // bitcoin, ethereum, tron, bsc
            $table->string('name', 100); // Bitcoin, Ethereum, Tron
            $table->string('display_name', 100)->nullable(); // Bitcoin Mainnet, Ethereum (ERC-20)
            $table->string('explorer_tx_url', 255)->nullable(); // https://etherscan.io/tx/{txid}
            $table->string('explorer_address_url', 255)->nullable();
            $table->integer('avg_block_time_seconds')->unsigned()->default(15);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('display_order')->unsigned()->default(100);
            $table->timestamps();

            $table->index('is_active');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acquiring_supported_networks');
        Schema::dropIfExists('acquiring_supported_currencies');
    }
};
