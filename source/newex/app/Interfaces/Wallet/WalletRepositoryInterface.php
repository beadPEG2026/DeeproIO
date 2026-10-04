<?php

namespace App\Interfaces\Wallet;

interface WalletRepositoryInterface
{
    public function getWallets($user_id);

    public function getWalletByCurrency($user_id, $currency);

    public function store($data);
}
