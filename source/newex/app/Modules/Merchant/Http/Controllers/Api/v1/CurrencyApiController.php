<?php

namespace App\Modules\Merchant\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Currency\Currency;
use App\Modules\Merchant\Services\PricingService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyApiController extends Controller
{
    public function __construct(
        protected PricingService $pricingService
    ) {}

    #[ExcludeRouteFromDocs]
    /**
     * List available currencies
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // Get all currencies enabled for merchant
        $currencies = Currency::where('is_merchant', true)
            ->where('status', true)
            ->orderBy('name')
            ->get();

        // Add estimated rates for reference
        $currenciesWithRates = $currencies->map(function ($currency) use ($request) {
            $estimatedAmount = $request->get('amount');
            $data = [
                'symbol' => $currency->symbol,
                'name' => $currency->name,
                'type' => $currency->type,
                'decimals' => $currency->decimals,
                'min_amount_usd' => $currency->merchant_min_amount_usd,
                'max_amount_usd' => $currency->merchant_max_amount_usd,
                'required_confirmations' => $currency->merchant_confirmations,
                'fee_percent' => $currency->merchant_fee_percent,
            ];

            if ($estimatedAmount) {
                $rate = $this->pricingService->getEstimatedRate($currency, (float) $estimatedAmount);
                $data['estimated_rate'] = $rate;
            }

            return $data;
        });

        return response()->json([
            'success' => true,
            'data' => $currenciesWithRates,
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get current rate for a specific currency
     *
     * @param Request $request
     * @param string $symbol
     * @return JsonResponse
     */
    public function getRate(Request $request, string $symbol): JsonResponse
    {
        $currency = Currency::where('symbol', strtoupper($symbol))
            ->where('is_merchant', true)
            ->where('status', true)
            ->first();

        if (!$currency) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CURRENCY_NOT_FOUND',
                    'message' => "Currency {$symbol} not found or not available",
                ],
            ], 404);
        }

        $amount = $request->get('amount', 100);
        $rate = $this->pricingService->getEstimatedRate($currency, (float) $amount);

        if (!$rate['available']) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'RATE_UNAVAILABLE',
                    'message' => 'Rate is temporarily unavailable',
                ],
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'symbol' => $currency->symbol,
                'name' => $currency->name,
                'rate_usd' => $rate['rate_usd'],
                'rate_source' => $rate['rate_source'],
                'estimated' => true,
                'sample_amount_usd' => $amount,
                'sample_amount_crypto' => $rate['amount_crypto'],
                'rate_validity_seconds' => $rate['validity_seconds'],
                'min_amount_usd' => $currency->merchant_min_amount_usd,
                'max_amount_usd' => $currency->merchant_max_amount_usd,
                'required_confirmations' => $currency->merchant_confirmations,
                'fetched_at' => now()->toIso8601String(),
            ],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get all rates for available currencies
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllRates(Request $request): JsonResponse
    {
        $amount = $request->get('amount', 100);

        $currencies = Currency::where('is_merchant', true)
            ->where('status', true)
            ->get();

        $rates = $currencies->map(function ($currency) use ($amount) {
            $rate = $this->pricingService->getEstimatedRate($currency, (float) $amount);

            return [
                'symbol' => $currency->symbol,
                'name' => $currency->name,
                'rate_usd' => $rate['rate_usd'] ?? null,
                'amount_crypto' => $rate['amount_crypto'] ?? null,
                'available' => $rate['available'],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'sample_amount_usd' => $amount,
                'rates' => $rates,
                'fetched_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
