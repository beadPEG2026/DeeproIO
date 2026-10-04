<?php

namespace App\Interfaces\Order;

interface OrderRepositoryInterface
{
    public function get($market, $type);

    public function findById($uuid);

    public function store();

    public function cancel();

    public function insert($insert);

    public function getMatchedOrder($type, $side, $market, $price);
}
