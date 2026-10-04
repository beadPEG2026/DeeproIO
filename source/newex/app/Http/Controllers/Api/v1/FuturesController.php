<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Order\OrderFuturesCancelRequest;
use App\Http\Requests\Api\Order\OrderFuturesStoreRequest;
use App\Http\Requests\Api\Order\OrderStoreRequest;
use App\Http\Resources\Order\OpenFuturesOrderCollection;
use App\Interfaces\Order\OrderRepositoryInterface;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\QueryParameter;

/**
 * @tags Futures Trading
 */
class FuturesController extends Controller
{
    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * OrdersController constructor.
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(OrderRepositoryInterface $orderRepository)
    {
        $this->middleware(['auth:sanctum','maintenance'])->only(['store']);

        $this->middleware(\App\Http\Middleware\ReplayOrderRequest::class.':futures')->only(['store']);

        $this->orderRepository = $orderRepository;
    }

    /**
     * Create Futures Position
     *
     * Opens a new futures position or places a futures limit order.
     * Supports both market and limit order types with configurable leverage (1x-125x).
     * Take Profit and Stop Loss can be set at order creation.
     *
     * **Precision Rules:**
     * - `quantity` must respect the market's `base_precision`
     * - `price`, `quoteQuantity`, `take_profit_price`, `stop_loss_price` must respect the market's `quote_precision`
     *
     * **Order Types:**
     * - `limit`: Places a limit order at specified price
     * - `market`: Opens position immediately at current market price
     *
     * **Position Types:**
     * - `buy` (Long): Profits when price increases
     * - `sell` (Short): Profits when price decreases
     *
     * @operationId createFuturesPosition
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[BodyParameter('market', description: 'The futures market pair name', required: true, type: 'string', example: 'BTC-USDT')]
    #[BodyParameter('type', description: 'Order type: "limit" or "market"', required: true, type: 'string', example: 'market')]
    #[BodyParameter('side', description: 'Position side: "buy" (long) or "sell" (short)', required: true, type: 'string', example: 'buy')]
    #[BodyParameter('leverage', description: 'Leverage multiplier (1-125)', required: true, type: 'integer', example: 10)]
    #[BodyParameter('quantity', description: 'Quantity of base currency', type: 'string', example: '0.12345')]
    #[BodyParameter('price', description: 'Limit price. Required for limit orders', type: 'string', example: '50000.12')]
    #[BodyParameter('quoteQuantity', description: 'Amount of quote currency for margin', type: 'string', example: '1000.50')]
    #[BodyParameter('enable_tp_sl', description: 'Enable Take Profit / Stop Loss', type: 'boolean', example: true)]
    #[BodyParameter('take_profit_price', description: 'Take Profit price', type: 'string', example: '55000.00')]
    #[BodyParameter('stop_loss_price', description: 'Stop Loss price', type: 'string', example: '48000.00')]
    #[BodyParameter('startAt', description: 'Scheduled start time in milliseconds timestamp', type: 'integer', example: 1705312200000)]
    public function store(OrderFuturesStoreRequest $request)
    {
        if(!$request->user()->tokenCan('trade') || $request->user()->deactivated) {
            return response()->json(['message' => 'Unauthorized'], STATUS_FORBIDDEN);
        }
        
        if(!$request->user()->hasRole('admin') && setting('general.maintenance_status')) {
            return response()->json(['message' => 'Trades are not allowed'], STATUS_FORBIDDEN);
        }
        try {
            $result = $this->orderRepository->store(true);

            if ($result === false) {
                return response()->json([
                    'message' => 'request_was_not_processed',
                ], 422);
            }

            return response()->json(['message' => $result]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], STATUS_SERVER_ERROR);
        }
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

    #[ExcludeRouteFromDocs]
    /**
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    /**
     * Get Open Futures Positions
     *
     * Retrieves all open (active) futures positions for the authenticated user.
     * Includes position details such as entry price, liquidation price, unrealized PNL, etc.
     *
     * @operationId getOpenFuturesPositions
     * @authenticated
     *
     * @return OpenFuturesOrderCollection
     */
    #[QueryParameter('market', description: 'Filter by market pair name', type: 'string', example: 'BTC-USDT')]
    public function open(Request $request)
    {
        $market = request()->get('market', null);

        return new OpenFuturesOrderCollection($this->orderRepository->openFutures($market));
    }

    /**
     * Get Pending Futures Orders
     *
     * Retrieves all pending (unfilled) futures limit orders for the authenticated user.
     * These are orders that have been placed but not yet executed.
     *
     * @operationId getPendingFuturesOrders
     * @authenticated
     *
     * @return OpenFuturesOrderCollection
     */
    #[QueryParameter('market', description: 'Filter by market pair name', type: 'string', example: 'BTC-USDT')]
    public function openOrders(Request $request)
    {
        $market = request()->get('market', null);

        return new OpenFuturesOrderCollection($this->orderRepository->openFuturesOrders($market));
    }

    /**
     * Close Futures Position
     *
     * Cancels a pending futures limit order or closes an active futures position.
     * For pending orders, the locked margin will be returned.
     * For active positions, the position will be closed at market price and PNL settled.
     *
     * @operationId cancelFuturesOrder
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[BodyParameter('uuid', description: 'The UUID of the order/position to cancel/close', required: true, type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public function cancel(OrderFuturesCancelRequest $request)
    {
        if(!$request->user()->tokenCan('trade')) {
            return response()->json(['message' => 'Unauthorized'], STATUS_FORBIDDEN);
        }
        
        $status = $this->orderRepository->cancelFutures();

        return response()->json(['message' => $status ? 'request processed' : 'request_was_not_processed'], $status ? 200 : 422);
    }
}
