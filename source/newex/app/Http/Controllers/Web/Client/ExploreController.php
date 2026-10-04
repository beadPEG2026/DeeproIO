<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class ExploreController extends Controller
{
    public function stocks(?string $symbol = null)
    {
        $stocks = app(\App\Services\Market\StockCatalog::class)->assets();
        if ($symbol !== null) {
            $asset = collect($stocks)->firstWhere('id', $symbol);
            abort_unless($asset, 404);
            if ($asset['tradeEnabled'] || \App\Services\Market\HongKongPriceProduct::isAsset($asset)) {
                return redirect()->route('market', ['market' => $asset['id'] . '-USDT', 'asset' => 'info']);
            }
        }

        return Inertia::render('Explore/Stocks', [
            'stocks' => $stocks,
            'indices' => [],
            'selectedSymbol' => $symbol,
        ]);
    }

    public function stockQuotes(\Illuminate\Http\Request $request, \App\Services\Market\StockDataClient $gateway)
    {
        $symbol=$request->validate(['symbol'=>'sometimes|string|max:30'])['symbol'] ?? null;
        $input=['native_only'=>true];
        if ($symbol !== null) {
            $asset=collect(app(\App\Services\Market\StockCatalog::class)->assets())->firstWhere('symbol',$symbol);
            abort_unless($asset,404);
            $input=['assets'=>[$asset],'native_only'=>\App\Services\Market\HongKongPriceProduct::isAsset($asset)];
        }
        try {
            $quotes = \Illuminate\Support\Facades\Cache::remember('deepro.stock-quotes'.($symbol!==null?'.native.'.$symbol:''), 15, fn () => $gateway->call('/v1/stocks/quotes',$input));
            $visible = array_column(app(\App\Services\Market\StockCatalog::class)->assets(), 'symbol');
            $quotes['data'] = array_values(array_filter($quotes['data'] ?? [], fn ($row) => in_array($row['symbol'], $visible, true)));
            return response()->json($quotes);
        } catch (\Throwable $e) { return response()->json(['message' => __('行情暂不可用，请稍后重试。')], 503); }
    }

    public function stockFx(\App\Services\Market\HongKongMarketData $feed)
    {
        $valid = static fn ($fx) => is_array($fx) && is_numeric($fx['hkdPerUsdt'] ?? null)
            && is_finite((float)$fx['hkdPerUsdt']) && (float)$fx['hkdPerUsdt'] > 0
            && is_numeric($fx['eventTime'] ?? null) && $fx['eventTime'] <= now()->timestamp + 5
            && $fx['eventTime'] >= now()->timestamp - 604800;
        try {
            $fx=$feed->fx();
            if (!$valid($fx)) throw new \RuntimeException('Invalid display FX');
            \Illuminate\Support\Facades\Cache::put('hk-display.last-fx',$fx,604800);
        } catch (\Throwable $e) {
            $fx=\Illuminate\Support\Facades\Cache::get('hk-display.last-fx');
        }
        if (!$valid($fx)) return response()->json(['fx'=>null]);
        return response()->json(['fx'=>['base'=>'HKD','quote'=>'USDT','hkdPerUsdt'=>$fx['hkdPerUsdt'],
            'eventTime'=>(int)$fx['eventTime'],'source'=>$fx['source'] ?? null,
            'approximate'=>true]])->header('Cache-Control','no-store');
    }

    public function stockCandles(\Illuminate\Http\Request $request, string $symbol, \App\Services\Market\StockDataClient $gateway)
    {
        abort_unless(collect(app(\App\Services\Market\StockCatalog::class)->assets())->contains('symbol', $symbol), 404);
        $input = $request->validate(['interval' => ['required', \Illuminate\Validation\Rule::in(config('stock-tokens.intervals'))]]);
        try {
            return response()->json($gateway->call('/v1/stocks/candles', ['symbol' => $symbol, 'interval' => $input['interval'], 'limit' => 120]));
        } catch (\Throwable $e) { return response()->json(['message' => __('暂时无法获取 K 线，请稍后重试。')], 503); }
    }

    public function referenceDepth(string $symbol, \App\Services\Market\ExternalReferenceOrderBook $depth)
    {
        $asset=collect(app(\App\Services\Market\StockCatalog::class)->assets())->firstWhere('symbol',$symbol);
        abort_unless($asset && \App\Services\Market\HongKongPriceProduct::isAsset($asset),404);
        return response()->json($depth->get($asset))->header('Cache-Control','no-store');
    }

    public function ecosystem(\Illuminate\Http\Request $request)
    {
        // Keep shared and bookmarked UMI links working after retiring the introduction page.
        return redirect()->route('umi.portfolio', $request->query(), 302);
    }

    public function document(string $document)
    {
        abort_unless(config('stock-tokens.documents_enabled'), 404);
        $filename = $this->documents()[$document] ?? null;
        abort_unless($filename, 404);
        $path = storage_path('app/umi-documents/' . $filename);
        abort_unless(is_file($path), 404);
        return response()->file($path, ['Content-Type' => 'application/pdf']);
    }

    private function documents(): array
    {
        return ['whitepaper' => 'UMI whitepaper.pdf', 'introduction' => 'UMI Program Introduction.pdf'];
    }
}
