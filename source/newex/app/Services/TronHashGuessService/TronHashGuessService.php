<?php

namespace App\Services\TronHashGuessService;

use App\Repositories\GuessGame\GuessGameRepository;
use App\Repositories\GuessOrder\GuessOrderRepository;
use App\Services\Tron\TronService;
use Illuminate\Support\Facades\DB;

class TronHashGuessService
{
    protected  $guessGameRepository;
    protected  $guessOrderRepository;
    protected  $tronService;

    public function __construct(
        GuessGameRepository $guessGameRepository,
        GuessOrderRepository $guessOrderRepository,
        TronService $tronService
    ) {
        $this->guessGameRepository = $guessGameRepository;
        $this->guessOrderRepository = $guessOrderRepository;
        $this->tronService = $tronService;
    }

    public function submit(array $data)
    {
        $game = $this->guessGameRepository->getActiveByCode('tron_hash_guess');

        if (!$game) {
            throw new \Exception('Game is not available');
        }

        if (bccomp((string)$data['amount'], (string)$game->min_amount, 18) < 0) {
            throw new \Exception('Amount is below minimum limit');
        }

        if (bccomp((string)$data['amount'], (string)$game->max_amount, 18) > 0) {
            throw new \Exception('Amount exceeds maximum limit');
        }

        $currentBlock = $this->tronService->getCurrentBlockNumber();
        $targetBlock = $currentBlock + (int)$game->settle_delay_blocks;

        return DB::transaction(function () use ($data, $game, $targetBlock) {
            return $this->guessOrderRepository->store([
                'user_id' => $data['user_id'],
                'game_id' => $game->id,
                'wallet_id' => $data['wallet_id'] ?? null,
                'bet_no' => $this->makeBetNo(),
                'chain' => 'tron',
                'currency_symbol' => 'USDT',
                'bet_type' => $data['bet_type'],
                'bet_value' => $data['bet_value'],
                'amount' => $data['amount'],
                'odds' => $data['odds'],
                'target_block' => $targetBlock,
                'status' => 'pending',
            ]);
        });
    }

    protected function makeBetNo(): string
    {
        return 'TG' . now()->format('YmdHis') . mt_rand(1000, 9999);
    }
}