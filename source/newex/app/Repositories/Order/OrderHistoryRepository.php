<?php

namespace App\Repositories\Order;

use App\Interfaces\Order\OrderHistoryRepositoryInterface;
use App\Models\Order\OrderHistory;
use App\Models\User\User;
use Auth;

class OrderHistoryRepository implements OrderHistoryRepositoryInterface
{
    public function insert($insert) {
        return OrderHistory::insert($insert);
    }

    public function delete($uuid) {
        return OrderHistory::find($uuid)->delete();
    }

    public function getReportUser(User $user, $limit = 15, $start = false, $end = false, $paginate = false) {

        $orders = OrderHistory::query();

        $orders->closed();

        $orders->filterUser(request()->only(['market', 'side']))->orderByLatest();

        $orders->has('market');

        $orders->with(['market.quoteCurrency', 'market.baseCurrency', 'transactions.market']);

        $orders->where('user_id', $user->id);

        if($start && $end) {
            $orders->whereBetween('created_at', [$start, $end]);
        }

        if($paginate) {
            return $orders->simplePaginate($limit);
        }

        return $orders->paginate(20)->withQueryString();
    }
}
