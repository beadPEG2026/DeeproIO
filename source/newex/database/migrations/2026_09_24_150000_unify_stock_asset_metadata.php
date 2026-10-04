<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->string('asset_category', 16)->default('crypto');
            $table->string('asset_issuer', 120)->nullable();
            $table->string('asset_unit', 32)->default('token');
            $table->json('asset_reference')->nullable();
            $table->boolean('asset_display_enabled')->default(true);
            $table->string('asset_chart_interval', 8)->default('1h');
        });
        // Preserve currency IDs, contracts, wallets, orders, channels and all monetary rows.
        $settings = DB::table('stock_display_settings')->get()->keyBy('symbol');
        foreach (DB::table('stock_token_catalog')->get() as $row) {
            $a = json_decode($row->asset, true, 512, JSON_THROW_ON_ERROR);
            $currency = DB::table('currencies')->where('symbol', $row->symbol)->sole();
            if ((int)$row->chain_id !== 56 || strtolower($currency->bep_contract ?? '') !== strtolower($row->contract)
                || strtolower($row->contract) !== strtolower($a['contract']) || (int)$currency->decimals !== (int)$a['decimals']) {
                throw new RuntimeException('Asset identity mismatch: '.$row->symbol);
            }
            $setting = $settings->get($row->symbol);
            $reference = array_diff_key($a, array_flip(['id','symbol','name','issuer','decimals','contract','chain','chainId','assetType']));
            DB::table('currencies')->where('id', $currency->id)->update([
                'asset_category' => $a['assetType'], 'asset_issuer' => $a['issuer'],
                'asset_unit' => 'token', 'asset_reference' => json_encode($reference, JSON_THROW_ON_ERROR),
                'asset_display_enabled' => $setting ? (bool)$setting->display_enabled : true,
                'asset_chart_interval' => $setting->default_interval ?? '1h',
            ]);
        }
    }
    public function down(): void
    {
        // Production rollback retains the additive columns and uses a compatible code release.
        throw new RuntimeException('Use the forward-compatible rollback runbook; do not discard current asset settings.');
    }
};
