<?php

namespace App\Repositories\Lending;

use App\Models\Lending\LendingRepays;
use App\Models\Lending\LendingUser;
use Auth;

class LendingUserRepository
{
    /**
     * @var Lending
     */
    protected $lending;

    /**
     * LendingUserRepository constructor.
     *
     */
    public function __construct()
    {
        $this->lending = new LendingUser();
    }

    public function get($isAdmin = true, $user = false) {

        $lendings = LendingUser::query();

        $lendings->filter(request()->only(['referrer']));

        if($user) {
            $lendings->where('user_id', $user->id);
        }

        $lendings->with('user')->with('currency')->with('collateral');

        $lendings->has('currency')->has('collateral')->has('user');

        $lendings->orderBy('id', 'desc');

        return $lendings->paginate(50)->withQueryString();
    }

    public function repayments($lending, $user) {

        $lendings = LendingRepays::query();

        $lendings->where('lending_user_id', $lending);

        if($user) {
            $lendings->where('user_id', $user->id);
        }

        $lendings->has('lending')->has('user');

        $lendings->orderBy('id', 'desc');

        return $lendings->get();
    }

    public function getLendingById($id, $user = false) {
        $model = LendingUser::query();

        $model->where('id', $id);

        if($user) {
            $model->where('user_id', $user);
        }

        return $model->first();
    }

    public function store($data) {

        $lending = $this->lending->create($data);

        return $lending->fresh();
    }

    public function update($id, $data) {
        $lending = LendingUser::find($id);
        $lending->update($data);
        return $lending->fresh();
    }

    public function delete($id) {

        $lending = LendingUser::find($id);
        $lending->delete();

        return true;
    }
}
