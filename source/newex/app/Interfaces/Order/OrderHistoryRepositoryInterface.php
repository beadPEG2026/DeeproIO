<?php

namespace App\Interfaces\Order;

interface OrderHistoryRepositoryInterface
{
    public function insert($insert);

    public function delete($uuid);
}
