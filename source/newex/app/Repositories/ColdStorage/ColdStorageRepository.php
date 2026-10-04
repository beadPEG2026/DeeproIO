<?php

namespace App\Repositories\ColdStorage;

use App\Models\ColdStorage\ColdStorage;
use Auth;

class ColdStorageRepository
{
    /**
     * @var ColdStorage
     */
    protected $coldStorage;

    /**
     * ColdStorageRepository constructor.
     */
    public function __construct()
    {
        $this->coldStorage = new ColdStorage();
    }

    public function get()
    {
        $coldStorage = ColdStorage::query();

        /*
         * 预加载币种和网络。
         * 注意：id = 1 是入金银行卡数据，currency_id / network_id 可以为 0，
         * 所以它可能没有 currency / network 关联。
         */
        $coldStorage->with(['currency', 'network']);

        /*
         * 普通冷钱包：必须有 currency 和 network。
         * 入金银行卡：固定允许 id = 1，即使 currency_id / network_id 为 0 也要显示。
         */
        $coldStorage->where(function ($query) {
            $query->where('id', 1)
                ->orWhere(function ($subQuery) {
                    $subQuery->has('network')
                        ->has('currency');
                });
        });

        $coldStorage->orderBy('id', 'desc');

        return $coldStorage->paginate(50)->withQueryString();
    }

    public function all($onlyActive = false)
    {
        $coldStorage = ColdStorage::query();

        if ($onlyActive) {
            $coldStorage->active();
        }

        return $coldStorage->get();
    }

    public function getColdStorageById($id)
    {
        return ColdStorage::find($id);
    }

    public function store($data)
    {
        $coldStorage = $this->coldStorage->create($data);

        return $coldStorage->fresh();
    }

    public function update($id, $data)
    {
        $coldStorage = ColdStorage::find($id);

        if (!$coldStorage) {
            return null;
        }

        $coldStorage->update($data);

        return $coldStorage->fresh();
    }

    public function delete($id)
    {
        $coldStorage = ColdStorage::find($id);

        if ($coldStorage) {
            $coldStorage->delete();
        }

        return true;
    }
}