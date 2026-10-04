<?php

namespace App\Repositories\GuessOrder;

use App\Models\GuessOrder\GuessOrder;

class GuessOrderRepository
{
    public function get($paginate = true, array $filters = [])
    {
        $query = GuessOrder::query()->with('game')->orderBy('id', 'desc');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['game_id'])) {
            $query->where('game_id', $filters['game_id']);
        }

        return $paginate ? $query->paginate(20) : $query->get();
    }

    public function getPendingOrdersReadyToSettle(int $currentBlock)
    {
        return GuessOrder::query()
            ->where('status', 'pending')
            ->where('target_block', '<=', $currentBlock)
            ->orderBy('id')
            ->get();
    }

    public function store(array $data): GuessOrder
    {
        return GuessOrder::query()->create($data);
    }

    public function update(int $id, array $data): bool
    {
        return GuessOrder::query()->where('id', $id)->update($data) > 0;
    }

    public function findById(int $id): ?GuessOrder
    {
        return GuessOrder::query()->with('game')->find($id);
    }
}