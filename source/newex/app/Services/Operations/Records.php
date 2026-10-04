<?php
namespace App\Services\Operations;

use App\Models\Deposit\Deposit;
use App\Models\Withdrawal\Withdrawal;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;

/** Reuse report object scopes, including virtual/internal-transfer exclusions. */
final class Records
{
    public static function deposits() {
        return (new class extends DepositRepository {
            public function scoped() {
                $q=Deposit::query()->has('currency')->has('user');
                $this->applyAdminDepositAmountVisibility($q);
                $this->applyRealUserScopeToEloquent($q);
                $this->excludeAdminInternalTransfer($q);
                $this->applyAdminTeamScope($q,'deposits.user_id');
                return $q;
            }
        })->scoped();
    }
    public static function withdrawals() {
        return (new class extends WithdrawalRepository {
            public function scoped() {
                $q=Withdrawal::query()->has('currency')->has('user');
                $this->applyAdminTeamScope($q,'withdrawals.user_id');
                return $q;
            }
        })->scoped();
    }
}
