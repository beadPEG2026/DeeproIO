<?php

namespace App\Services\Tron;

use App\Services\Deposit\TronGridClient;

class TronService
{
    public function getNowBlock(): array
    {
        return app(TronGridClient::class)->request('wallet/getnowblock', []);
    }

    public function getCurrentBlockNumber(): int
    {
        $block = $this->getNowBlock();
        return (int) data_get($block, 'block_header.raw_data.number', 0);
    }

    public function getBlockByNumber(int $blockNumber): array
    {
        return app(TronGridClient::class)->request('wallet/getblockbynum', ['num'=>$blockNumber]);
    }

    public function getBlockHashByNumber(int $blockNumber): ?string
    {
        $block = $this->getBlockByNumber($blockNumber);
        return $block['blockID'] ?? null;
    }
}