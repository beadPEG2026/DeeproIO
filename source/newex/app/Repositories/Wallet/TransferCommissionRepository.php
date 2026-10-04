<?php

namespace App\Repositories\Wallet;

use App\Models\Wallet\TransferCommission;

class TransferCommissionRepository
{
    protected $model;

    public function __construct()
    {
        $this->model = new TransferCommission();
    }

    public function store(array $data): TransferCommission
    {
        return $this->model->create($data);
    }

    public function getReport()
    {
        $q = TransferCommission::query();
        $q->filter(request()->only(['search', 'referrer']))->orderByLatest();
        $q->has('currency')->has('user');
        $q->with(['currency', 'user']);
        return $q->paginate(50)->withQueryString();
    }
}
