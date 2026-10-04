<?php

namespace App\Repositories\Staking;

use App\Models\Staking\Staking;
use Auth;

class StakingRepository
{
    /**
     * @var Staking
     */
    protected $staking;

    /**
     * StakingRepository constructor.
     *
     */
    public function __construct()
    {
        $this->staking = new Staking();
    }

    public function get($isAdmin = true,$type = 0) {

        $stakings = Staking::query();

        if(!$isAdmin) {
            $stakings->visible();
        }
        
        $stakings->has('currency');
        
        $stakings->with([
            'currency.file',
            'currencyd.file'
        ]);

        $stakings->orderBy('id', 'desc');

        return $stakings->where('staking_type',$type)->paginate(50)->withQueryString();
    }

    public function getStakingById($id) {
        return Staking::find($id);
    }

    public function store($data) {

        $staking = $this->staking->create($data);

        return $staking->fresh();
    }

    public function update($id, $data) {
        $staking = Staking::find($id);
        $staking->update($data);
        return $staking->fresh();
    }

    public function delete($id) {

        $staking = Staking::find($id);
        $staking->delete();

        return true;
    }
}
