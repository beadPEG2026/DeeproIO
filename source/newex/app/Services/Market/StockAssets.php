<?php
namespace App\Services\Market;
use Illuminate\Support\Facades\{DB, Schema, Cache};
final class StockAssets {
    public static function all(): array {
        if (Schema::hasColumn('currencies', 'asset_reference')) {
            return DB::table('currencies')->whereNull('deleted_at')->whereIn('asset_category',['stock','etf'])
                ->whereNotNull('asset_reference')->orderBy('symbol')->get()
                ->map(fn($currency)=>AssetProfile::presentation($currency))->all();
        }
        return Cache::remember('stock.catalog.assets.v1',5,function() {
        if (!Schema::hasTable('stock_token_catalog')) return config('stock-tokens.assets', []);
        return DB::table('stock_token_catalog')->orderBy('created_at')->orderBy('symbol')->get()
            ->map(fn($r)=>json_decode($r->asset,true,512,JSON_THROW_ON_ERROR))->all();
        });
    }
    public static function find(string $symbol): ?array {
        return collect(self::all())->firstWhere('symbol',$symbol);
    }
    public static function supports(string $name): bool {
        return str_ends_with($name,'-USDT') && self::find(substr($name,0,-5)) !== null;
    }
}
