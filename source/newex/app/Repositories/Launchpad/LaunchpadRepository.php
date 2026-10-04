<?php

namespace App\Repositories\Launchpad;

use App\Models\Launchpad\Launchpad;
use App\Models\Launchpad\LaunchpadTransaction;
use App\Models\Transaction\ReferralTransaction;
use App\Models\User\User;
use Auth;

class LaunchpadRepository
{
    /**
     * @var Launchpad
     */
    protected $launchpad;

    /**
     * LaunchpadRepository constructor.
     *
     */
    public function __construct()
    {
        $this->launchpad = new Launchpad();
    }

    public function get($paginate = true, $filter = "all", bool $publishedOnly = false) {

        $launchpads = Launchpad::query();

        if ($publishedOnly) $launchpads->published()->where('status', true);

        if($filter && $filter !== "all") {

            if($filter == "upcoming") $filter = 'pending';

            $launchpads->where('progress', $filter);
        }

        $launchpads->orderBy('id', 'desc');

        $launchpads->has('currency');

        $launchpads->with('currency.file');

        if(!$paginate) {
            return $launchpads->get();
        }

        return $launchpads->paginate(100)->withQueryString();
    }

    public function all($onlyActive = false) {

        $launchpads = Launchpad::query();

        if($onlyActive) {
            $launchpads->active();
        }

        return $launchpads->get();

    }

    public function getLaunchpadById($id) {
        return Launchpad::find($id);
    }

    public function store($data) {

        $launchpad = $this->launchpad->create($data);

        return $launchpad->fresh();
    }

    public function update($id, $data) {

        $launchpad = Launchpad::find($id);
        $launchpad->update($data);
        return $launchpad->fresh();
    }

    public function delete($id) {

        $launchpad = Launchpad::find($id);
        $launchpad->delete();

        return true;
    }

public function getReport()
{
    $q = LaunchpadTransaction::query();

    $q->filter(request()->only(['search', 'referrer']))->orderByLatest();
    $q->has('launchpad')->has('user');
    $q->with(['launchpad.currency', 'user']);

    $currentUser = auth()->user();

    if (!$currentUser) {
        return $q->whereRaw('1 = 0')->paginate(50)->withQueryString();
    }

    $currentUser->loadMissing('roles');
    $roleIds = $currentUser->roles->pluck('id')->toArray();

    // 超级管理员：看全部
    if ((auth()->user()?->hasRole('superadmin') ?? false)) {
        return $q->paginate(50)->withQueryString();
    }

    // 普通管理员：只看自己完整团队
    if ((auth()->user()?->hasRole('admin') ?? false)) {
        $teamUserIds = $this->getAllTeamUserIds($currentUser->id);

        if (empty($teamUserIds)) {
            return $q->whereRaw('1 = 0')->paginate(50)->withQueryString();
        }

        $q->whereIn('user_id', $teamUserIds);

        return $q->paginate(50)->withQueryString();
    }

    // 其它角色：不给数据
    return $q->whereRaw('1 = 0')->paginate(50)->withQueryString();
}
protected function getAllTeamUserIds(int $userId): array
{
    $allIds = [$userId];
    $pendingIds = [$userId];

    while (!empty($pendingIds)) {
        $children = \App\Models\User\User::query()
            ->whereIn('referral_id', $pendingIds)
            ->pluck('id')
            ->toArray();

        $children = array_values(array_diff($children, $allIds));

        if (empty($children)) {
            break;
        }

        $allIds = array_merge($allIds, $children);
        $pendingIds = $children;
    }

    return array_unique($allIds);
}
    public function getReportUser(User $user, $limit = 15, $start = false, $end = false, $paginate = false) {

        $transaction = LaunchpadTransaction::query();

        $transaction->filter(request()->only(['search','side']))->orderByLatest();

        $transaction->has('launchpad')->has('user');

        $transaction->with(['launchpad.currency']);

        $transaction->where('user_id', $user->id);

        if($start && $end) {
            $transaction->whereBetween('created_at', [$start, $end]);
        }

        if($paginate) {
            return $transaction->simplePaginate($limit);
        }

        return $transaction->paginate(50)->withQueryString();
    }
}
