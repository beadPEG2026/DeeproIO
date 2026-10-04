<?php

namespace App\Interfaces\Market;

interface MarketRepositoryInterface
{
    public function get($market);

    public function all($paginate);
}
