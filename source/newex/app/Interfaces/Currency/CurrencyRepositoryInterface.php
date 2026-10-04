<?php

namespace App\Interfaces\Currency;

interface CurrencyRepositoryInterface
{
    public function get($market);

    public function all($paginate);
}
