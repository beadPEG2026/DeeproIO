<?php

namespace App\Repositories\Wallet;

use App\Models\Wallet\WalletAdjustment;

class WalletAdjustmentRepository
{
    protected $model;

    public function __construct()
    {
        $this->model = new WalletAdjustment();
    }

    public function store(array $data): WalletAdjustment
    {
        return $this->model->create($data);
    }

    public function getReport()
    {
        $q = WalletAdjustment::query();
        $q->with(['currency', 'user']);
        $q->orderBy('created_at', 'desc');
        return $q->paginate(100)->withQueryString();
    }

    public function getReportForUser(int $userId)
    {
        $q = WalletAdjustment::query();
        $q->where('user_id', $userId);
        $q->with(['currency']);
        $q->orderBy('created_at', 'desc');
        return $q->paginate(50)->withQueryString();
    }
}
