<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Order\OptionsStoreRequest;
use App\Http\Resources\Option\OptionCollection;
use App\Repositories\Option\OptionRepository;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @tags Options Trading
 */
class OptionController extends Controller
{
    /**
     * @var OptionRepository
     */
    private $optionRepository;

    /**
     * OptionController constructor.
     * @param OptionRepository $optionRepository
     */
    public function __construct(OptionRepository $optionRepository)
    {
        $this->middleware(['auth:sanctum','maintenance'])->only(['store']);

        $this->middleware(\App\Http\Middleware\ReplayOrderRequest::class.':options')->only(['store']);

        $this->optionRepository = $optionRepository;
    }

    /**
     * Create Options Contract
     *
     * Places a new binary options contract. Options allow traders to predict
     * whether the price of an asset will go up or down within a specific timeframe.
     *
     * **Contract Types (type):** 1-5 representing different durations
     *
     * **Position Types (side):**
     * - `buy` (Call): Predicts price will increase
     * - `sell` (Put): Predicts price will decrease
     *
     * **Timeframe Options (timeframeSeconds):** 60, 120, 180, 300, 600 seconds
     *
     * @operationId createOptionsContract
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[BodyParameter('market', description: 'The options market pair name', required: true, type: 'string', example: 'BTC-USDT')]
    #[BodyParameter('type', description: 'Option type (1-5 representing different durations)', required: true, type: 'integer', example: 1)]
    #[BodyParameter('side', description: 'Prediction side: "buy" (Call) or "sell" (Put)', required: true, type: 'string', example: 'buy')]
    #[BodyParameter('quantity', description: 'Investment amount in quote currency', required: true, type: 'string', example: '100.00')]
    #[BodyParameter('startAt', description: 'Contract start time in milliseconds timestamp', required: true, type: 'integer', example: 1705312200000)]
    #[BodyParameter('timeframeSeconds', description: 'Contract duration in seconds (60, 120, 180, 300, 600)', required: true, type: 'integer', example: 60)]
    public function store(OptionsStoreRequest $request)
    {
        if(!$request->user()->tokenCan('trade')) {
            return response()->json(['message' => 'Unauthorized'], STATUS_FORBIDDEN);
        }

        try {
            return response()->json(['message' => $this->optionRepository->store()]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['message' => 'System Error'], STATUS_VALIDATION_ERROR);
        }
    }

    #[ExcludeRouteFromDocs]
    /**
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * @return \Illuminate\Http\Response
     */
    public function edit()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    /**
     * Get Open Options Contracts
     *
     * Retrieves all active (open) options contracts for the authenticated user.
     * Active contracts are those that have not yet expired.
     *
     * @operationId getOpenOptionsContracts
     * @authenticated
     *
     * @return OptionCollection
     */
    #[QueryParameter('market', description: 'Filter by market pair name', type: 'string', example: 'BTC-USDT')]
    public function open(Request $request)
    {
        $market = $request->get('market', null);

        return new OptionCollection($this->optionRepository->open($market));
    }

    /**
     * Get Options Trade History
     *
     * Retrieves the complete options trading history for the authenticated user.
     * Includes both winning and losing contracts with their final PNL.
     *
     * **Contract Statuses:** active, won, lost
     *
     * @operationId getOptionsTradeHistory
     * @authenticated
     *
     * @return OptionCollection
     */
    #[QueryParameter('market', description: 'Filter by market pair name', type: 'string', example: 'BTC-USDT')]
    public function trades(Request $request) {

        $market = $request->get('market', null);

        return new OptionCollection($this->optionRepository->trades($market));
    }
}
