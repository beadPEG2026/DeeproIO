<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Launchpad\Launchpad;
use App\Http\Resources\Launchpad\LaunchpadTransaction;
use App\Http\Resources\Market\MarketLiteCollection;
use App\Http\Resources\Order\OrderHistory;
use App\Http\Resources\Transaction\FuturesTransaction;
use App\Http\Resources\Transaction\Trades\Trade;
use App\Http\Resources\Wallet\Deposit\Deposit;
use App\Http\Resources\Wallet\Withdrawal\Withdrawal;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Launchpad\LaunchpadRepository;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Order\OrderHistoryRepository;
use App\Repositories\Transaction\FuturesTransactionRepository;
use App\Repositories\Transaction\TransactionRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Setting;

/**
 * @tags Transactions
 */
class TransactionController extends Controller
{
    /**
     * Get Crypto Deposit Transactions
     *
     * Retrieves cryptocurrency deposit transactions for the authenticated user.
     * Period options: today, yesterday, week, month, year
     *
     * @operationId getCryptoDepositTransactions
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('period', description: 'Filter by time period: today, yesterday, week, month, year', type: 'string', example: 'week')]
    public function depositsCrypto(Request $request) {

        $start = false;
        $end = false;

        $period = $request->get('period', false);

        $repository = new DepositRepository();

        $limit = 20;

        if($period) {
            list($start, $end) = get_date_period($period);
        }

        $records = Deposit::collection($repository->getReportUser(auth()->user(), false, $limit, $start, $end, true))->response()->getData(true);

        return response()->json([
            'items' => $records['data']
        ]);
    }

    /**
     * Get Crypto Withdrawal Transactions
     *
     * Retrieves cryptocurrency withdrawal transactions for the authenticated user.
     * Period options: today, yesterday, week, month, year
     *
     * @operationId getCryptoWithdrawalTransactions
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('period', description: 'Filter by time period: today, yesterday, week, month, year', type: 'string', example: 'month')]
    public function withdrawalsCrypto(Request $request) {

        $start = false;
        $end = false;

        $period = $request->get('period', false);

        $repository = new WithdrawalRepository();

        $limit = 20;

        if($period) {
            list($start, $end) = get_date_period($period);
        }

        $records = Withdrawal::collection($repository->getReportUser(auth()->user(), false, $limit, $start, $end, true))->response()->getData(true);

        return response()->json([
            'items' => $records['data']
        ]);
    }

    /**
     * Get Spot Trade Transactions
     *
     * Retrieves spot trading transaction history for the authenticated user.
     * Period options: today, yesterday, week, month, year
     *
     * @operationId getSpotTradeTransactions
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('period', description: 'Filter by time period: today, yesterday, week, month, year', type: 'string', example: 'week')]
    public function trades(Request $request) {

        $start = false;
        $end = false;

        $period = $request->get('period', false);

        $limit = 20;

        if($period) {
            list($start, $end) = get_date_period($period);
        }

        $repository = new TransactionRepository();

        $records = Trade::collection($repository->getReportUser(auth()->user(), $limit, $start, $end, true))->response()->getData(true);

        return response()->json([
            'items' => $records['data']
        ]);
    }

    /**
     * Get Futures Trade Transactions
     *
     * Retrieves futures trading transaction history for the authenticated user.
     * Period options: today, yesterday, week, month, year
     *
     * @operationId getFuturesTradeTransactions
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('period', description: 'Filter by time period: today, yesterday, week, month, year', type: 'string', example: 'week')]
    public function futuresTrades(Request $request) {

        $start = false;
        $end = false;

        $period = $request->get('period', false);

        $limit = 20;

        if($period) {
            list($start, $end) = get_date_period($period);
        }

        $repository = new FuturesTransactionRepository();

        $records = FuturesTransaction::collection($repository->getReportUser(auth()->user(), $limit, $start, $end, true))->response()->getData(true);

        return response()->json([
            'items' => $records['data']
        ]);
    }

    /**
     * Get Order Transactions
     *
     * Retrieves order history for the authenticated user.
     * Period options: today, yesterday, week, month, year
     *
     * @operationId getOrderTransactions
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('period', description: 'Filter by time period: today, yesterday, week, month, year', type: 'string', example: 'month')]
    public function orders(Request $request) {

        $start = false;
        $end = false;

        $period = $request->get('period', false);

        $limit = 20;

        if($period) {
            list($start, $end) = get_date_period($period);
        }

        $repository = new OrderHistoryRepository();

        $records = OrderHistory::collection($repository->getReportUser(auth()->user(), $limit, $start, $end, true))->response()->getData(true);

        return response()->json([
            'items' => $records['data']
        ]);
    }

    /**
     * Get Launchpad Transactions
     *
     * Retrieves launchpad/IEO purchase transactions for the authenticated user.
     * Period options: today, yesterday, week, month, year
     *
     * @operationId getLaunchpadTransactions
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('period', description: 'Filter by time period: today, yesterday, week, month, year', type: 'string', example: 'year')]
    public function launchpads(Request $request) {

        $start = false;
        $end = false;

        $period = $request->get('period', false);

        $limit = 20;

        if($period) {
            list($start, $end) = get_date_period($period);
        }

        $repository = new LaunchpadRepository();

        $records = LaunchpadTransaction::collection($repository->getReportUser(auth()->user(), $limit, $start, $end, true))->response()->getData(true);

        return response()->json([
            'items' => $records['data']
        ]);
    }
}
