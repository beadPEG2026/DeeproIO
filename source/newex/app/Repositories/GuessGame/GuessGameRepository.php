<?php

namespace App\Repositories\GuessGame;

use App\Models\GuessGame\GuessGame;

class GuessGameRepository
{
    public function get($paginate = true)
    {
        $query = GuessGame::query()->orderBy('id', 'desc');

        return $paginate ? $query->paginate(20) : $query->get();
    }

    public function getActiveByCode(string $code): ?GuessGame
    {
        return GuessGame::query()
            ->where('code', $code)
            ->where('status', 'active')
            ->first();
    }

    public function findById(int $id): ?GuessGame
    {
        return GuessGame::query()->find($id);
    }

    public function store(array $data): GuessGame
    {
        return GuessGame::query()->create($data);
    }

    public function update(int $id, array $data): bool
    {
        return GuessGame::query()->where('id', $id)->update($data) > 0;
    }
}