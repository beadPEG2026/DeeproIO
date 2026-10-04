<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Order\OrderCancelRequest;
use App\Http\Requests\Api\Order\OrderStoreRequest;
use App\Http\Resources\Order\OrderCollection;
use App\Http\Resources\Order\OrderHistory;
use App\Http\Resources\Transaction\Trades\Trade;
use App\Interfaces\Order\OrderRepositoryInterface;
use App\Repositories\Order\OrderHistoryRepository;
use App\Repositories\Order\OrderRepository;
use App\Repositories\Transaction\TransactionRepository;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @tags Spot Trading
 */
class OrderController extends Controller
{
    /**
     * @var OrderRepository
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * OrdersController constructor.
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(OrderRepositoryInterface $orderRepository)
    {
        
        $this->middleware(['auth:sanctum','language.detect','maintenance'])->only(['store','cancel', 'open']);

        $this->middleware(\App\Http\Middleware\ReplayOrderRequest::class.':spot')->only(['store']);

        $this->orderRepository = $orderRepository;
    }

    /**
     * Create Spot Order
     *
     * Places a new spot order on the exchange. Supports limit, market order types.
     * For market buy orders, use `quoteQuantity` to specify the amount of quote currency to spend.
     * For all other orders, use `quantity` to specify the base currency amount.
     *
     * **Precision Rules:**
     * - `quantity` must respect the market's `base_precision` (e.g., for base_precision=5, max 5 decimal places)
     * - `price` and `quoteQuantity` must respect the market's `quote_precision` (e.g., for quote_precision=2, max 2 decimal places)
     *
     * **Order Types:**
     * - `limit`: Executes at specified price or better. Requires `price` and `quantity`.
     * - `market`: Executes immediately at best available price. Buy orders require `quoteQuantity`, sell orders require `quantity`.
     *
     * **Order Side:**
     *  - buy
     *  - sell
     *
     * @operationId createSpotOrder
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[BodyParameter('market', description: 'The market pair name', required: true, type: 'string', example: 'BTC-USDT')]
    #[BodyParameter('type', description: 'Order type: "limit" or "market"', required: true, type: 'string', example: 'limit')]
    #[BodyParameter('side', description: 'Order side: "buy" or "sell"', required: true, type: 'string', example: 'buy')]
    #[BodyParameter('quantity', description: 'Quantity of base currency. Required for limit/sell market orders', type: 'string', example: '0.12345')]
    #[BodyParameter('price', description: 'Limit price. Required for limit orders', type: 'string', example: '50000.12')]
    #[BodyParameter('quoteQuantity', description: 'Amount of quote currency. Required for market buy orders', type: 'string', example: '1000.50')]
    public function store(OrderStoreRequest $request)
    {

        if(!$request->user()->tokenCan('trade') || $request->user()->deactivated) {
            return response()->json(['message' => 'Unauthorized'], STATUS_FORBIDDEN);
        }

        try {
            $result = $this->orderRepository->store();

            if ($result === false || $result === null) {
                return response()->json([
                    'message' => 'request_was_not_processed',
                ], STATUS_VALIDATION_ERROR);
            }

            return response()->json(['message' => $result]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error($e);

            return response()->json([
                'message' => $e->getMessage() ?: 'Server error',
            ], STATUS_SERVER_ERROR);
        }
    }

    /**
     * Cancel Spot Order
     *
     * Cancels an existing open spot order. Only pending orders can be cancelled.
     * The locked balance will be returned to the user's available balance.
     *
     * @operationId cancelSpotOrder
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[BodyParameter('uuid', description: 'The UUID of the order to cancel', required: true, type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public function cancel(OrderCancelRequest $request)
    {
        
        if(!$request->user()->tokenCan('trade')) {
            return response()->json(['message' => 'Unauthorized'], STATUS_FORBIDDEN);
        }

        $status = $this->orderRepository->cancel();

        return response()->json(['message' => $status ? 'request processed' : 'request_was_not_processed']);
    }

    /**
     * Get Open Orders
     *
     * Retrieves all open (pending) spot orders for the authenticated user.
     * Optionally filter by market pair.
     *
     * @operationId getOpenSpotOrders
     * @authenticated
     *
     * @return OrderCollection
     */
    #[QueryParameter('market', description: 'Filter by market pair name', type: 'string', example: 'BTC-USDT')]
    public function open(Request $request)
    {
        
        $market = request()->get('market', null);
        
        return new OrderCollection($this->orderRepository->open($market));
    }

    #[ExcludeRouteFromDocs]
    public function index()
    {

        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
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
     * Get Order History
     *
     * Retrieves the complete order history for the authenticated user.
     * Includes both completed and cancelled orders with their associated transactions.
     *
     * @operationId getOrderHistory
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "data": [
     *     {
     *       "id": "550e8400-e29b-41d4-a716-446655440000",
     *       "market": "BTC-USDT",
     *       "base_currency": "BTC",
     *       "quote_currency": "USDT",
     *       "type": "limit",
     *       "side": "buy",
     *       "price": "50000.12",
     *       "quantity": "0.12345",
     *       "status": "filled",
     *       "transactions": [
     *         {
     *           "id": "660e8400-e29b-41d4-a716-446655440001",
     *           "market": "BTC-USDT",
     *           "side": "buy",
     *           "type": "limit",
     *           "price": "50000.12",
     *           "fee": "0.00012",
     *           "base_currency": "0.12345",
     *           "quote_currency": "6172.56",
     *           "quantity": "0.12345",
     *           "is_maker": false,
     *           "created_at": "2024-01-15 10:30:00"
     *         }
     *       ],
     *       "created_at": "2024-01-15 10:30:00"
     *     }
     *   ],
     *   "links": {
     *     "first": "https://api.example.com/api/v1/orders/history?page=1",
     *     "last": "https://api.example.com/api/v1/orders/history?page=5",
     *     "prev": null,
     *     "next": "https://api.example.com/api/v1/orders/history?page=2"
     *   },
     *   "meta": {
     *     "current_page": 1,
     *     "from": 1,
     *     "last_page": 5,
     *     "per_page": 15,
     *     "to": 15,
     *     "total": 75
     *   }
     * }
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderHistory() {

        $orderHistoryRepository = new OrderHistoryRepository();

        $orders = OrderHistory::collection($orderHistoryRepository->getReportUser(auth()->user(), false))->response()->getData(true);

        return response()->json($orders);
    }

    /**
     * Get Trade History
     *
     * Retrieves the complete trade (transaction) history for the authenticated user.
     * Each trade represents an executed fill of an order.
     *
     * @operationId getTradeHistory
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "data": [
     *     {
     *       "id": "660e8400-e29b-41d4-a716-446655440001",
     *       "market": "BTC-USDT",
     *       "side": "buy",
     *       "type": "limit",
     *       "price": "50000.12",
     *       "fee": "0.00012",
     *       "base_currency": "0.12345",
     *       "quote_currency": "6172.56",
     *       "quote_symbol": "USDT",
     *       "base_symbol": "BTC",
     *       "quantity": "0.12345",
     *       "is_maker": false,
     *       "created_at": "2024-01-15 10:30:00"
     *     }
     *   ],
     *   "links": {
     *     "first": "https://api.example.com/api/v1/orders/trades?page=1",
     *     "last": "https://api.example.com/api/v1/orders/trades?page=10",
     *     "prev": null,
     *     "next": "https://api.example.com/api/v1/orders/trades?page=2"
     *   },
     *   "meta": {
     *     "current_page": 1,
     *     "from": 1,
     *     "last_page": 10,
     *     "per_page": 15,
     *     "to": 15,
     *     "total": 150
     *   }
     * }
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function tradeHistory() {

        $transactionRepository = new TransactionRepository();

        $transactions = Trade::collection($transactionRepository->getReportUser(auth()->user(), false))->response()->getData(true);

        return response()->json($transactions);
    }
}
