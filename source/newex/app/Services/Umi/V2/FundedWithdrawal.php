<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;

use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Full W reaches Deepro wallet once the separate 30% UMI is funded. */
final class FundedWithdrawal
{
    public function __construct(
        private readonly FundedReadiness $gate,
        private readonly FundedWallet $wallet,
        private readonly FundedIntake $intake,
        private readonly FundedSettlement $settlement,
        private readonly CycleStore $cycles,
    ) {}

    public function transfer(int $userId, int $cycleId, string $pocket,
        string $amount, string $key): array
    {
        $this->gate->require('withdrawal');
        if (!in_array($pocket, ['static', 'team', 'referral'], true)) {
            throw new DomainException('请选择收益类别。');
        }
        $member = $this->member($userId);
        $valid = DB::table('umi_v2_cycles as c')
            ->join('umi_v2_live_intents as i', 'i.cycle_id', '=', 'c.id')
            ->where('c.id', $cycleId)->where('c.member_id', $member->id)->exists();
        if (!$valid) { throw new DomainException('该轮次不属于当前账户。'); }
        if (!DB::table('umi_v2_income_transfers')->where('request_key',$key)->exists()) app(\App\Services\Deposit\DepositRisk::class)->assertClear($userId);
        $result = $this->cycles->transfer($cycleId, $key, $pocket,
            Decimal::amount($amount, true), $userId);
        $result['receipt'] = DB::table('umi_v2_income_transfers')->where('request_key', $key)
            ->where('member_id', $member->id)->first(['id', 'amount_umi', 'available_after_umi', 'created_at']);
        return $result;
    }

    public function preview(int $userId, string $amount): array
    {
        $this->gate->require('withdrawal');
        $amount = Decimal::amount($amount, true);
        $member = $this->member($userId);
        $income = DB::table('umi_v2_income_accounts')->where('member_id', $member->id)->first();
        $available = (string) ($income->available ?? '0');
        if (Decimal::cmp($available, $amount) < 0) {
            throw new DomainException('可提现收益不足。');
        }
        $burn = Decimal::mul($amount, '0.3');
        $afterW = Decimal::sub($available, $amount);
        $fromIncome = Decimal::min($burn, $afterW);
        $walletBefore = $this->wallet->balance($userId, 'trade');
        $fromWallet = Decimal::min(Decimal::sub($burn, $fromIncome), $walletBefore);
        return ['income_before_umi' => Decimal::display($available),
            'income_after_umi' => Decimal::sub($afterW, $fromIncome),
            'wallet_before_umi' => $walletBefore,
            'wallet_after_funding_umi' => Decimal::sub($walletBefore, $fromWallet),
            'pay_to_user_umi' => $amount, 'extra_b_umi' => $burn,
            'from_income_umi' => $fromIncome, 'from_wallet_umi' => $fromWallet,
            'external_shortfall_umi' => Decimal::sub(Decimal::sub($burn, $fromIncome), $fromWallet)];
    }

    public function request(int $userId, string $amount, string $key): object
    {
        $amount = Decimal::amount($amount, true);
        if (Decimal::cmp($amount, bcadd($amount, '0', 18)) !== 0) {
            throw new DomainException('提现最多支持 18 位小数。');
        }
        return DB::transaction(function () use ($userId, $amount, $key): object {
            DB::table('umi_v2_live_settings')->where('id', 1)->lockForUpdate()->first();
            $member = $this->member($userId, true);
            $existing = DB::table('umi_v2_withdrawals')->where('request_key', $key)->first();
            if ($existing) {
                if ((int) $existing->member_id !== (int) $member->id ||
                    Decimal::cmp((string) $existing->requested_umi, $amount) !== 0) {
                    throw new DomainException('该提现编号已用于其他订单。');
                }
                return $existing;
            }
            $settings = $this->gate->require('withdrawal');
            app(\App\Services\Deposit\DepositRisk::class)->assertClear($userId);
            $income = DB::table('umi_v2_income_accounts')->where('member_id', $member->id)
                ->lockForUpdate()->first();
            if (!$income || Decimal::cmp((string) $income->available, $amount) < 0) {
                throw new DomainException('可提现收益不足。');
            }
            $burn = Decimal::mul($amount, '0.3');
            $afterW = Decimal::sub((string) $income->available, $amount);
            $fromIncome = Decimal::min($burn, $afterW);
            $need = Decimal::sub($burn, $fromIncome);
            $fromWallet = Decimal::min($need, $this->wallet->balance($userId, 'trade'));
            $remaining = Decimal::sub($need, $fromWallet);
            $status = Decimal::cmp($remaining, '0') === 0 ? 'funded' : 'awaiting_topup';
            $now = now();
            $id = DB::table('umi_v2_withdrawals')->insertGetId([
                'member_id' => $member->id,
                'policy_version_id' => $this->intake->policy()->id,
                'request_key' => $key, 'status' => $status,
                'requested_umi' => $amount, 'required_burn_umi' => $burn,
                'confirmed_burn_umi' => '0', 'paid_umi' => '0',
                'requested_by' => $userId, 'requested_at' => FundedTime::database($now),
                'created_at' => FundedTime::database($now), 'updated_at' => FundedTime::database($now),
            ]);
            DB::table('umi_v2_income_accounts')->where('member_id', $member->id)->update([
                'available' => Decimal::sub($afterW, $fromIncome),
                'withdrawal_reserved' => Decimal::add((string) $income->withdrawal_reserved, $amount),
                'version_no' => (int) $income->version_no + 1, 'updated_at' => FundedTime::database($now),
            ]);
            if (Decimal::cmp($fromWallet, '0') > 0) {
                $this->wallet->move($userId, (int) $settings->pool_user_id,
                    $fromWallet, 'withdraw-b:' . $key, 'withdrawal_extra_b',
                    'withdrawal:' . $id, (int) $member->id, 'trade', 'wallet');
            }
            DB::table('umi_v2_live_withdrawal_funding')->insert([
                'withdrawal_id' => $id, 'from_income_umi' => $fromIncome,
                'from_wallet_umi' => $fromWallet, 'remaining_umi' => $remaining,
                'created_at' => FundedTime::database($now), 'updated_at' => FundedTime::database($now),
            ]);
            if ($status === 'funded') { $this->pay($id, $userId, (int) $settings->pool_user_id); }
            return DB::table('umi_v2_withdrawals')->find($id);
        }, 3);
    }

    /** Direct chain credits never enter UMI; users explicitly transfer from spot. */
    public function bindVerifiedTopup(int $depositId): ?object
    {
        return null;
    }

    public function topupFromSpot(int $userId, int $withdrawalId, string $key): object
    {
        return DB::transaction(function () use ($userId, $withdrawalId, $key): object {
            DB::table('umi_v2_live_settings')->where('id', 1)->lockForUpdate()->first();
            $member = $this->member($userId, true);
            $withdrawal = DB::table('umi_v2_withdrawals')->where('id', $withdrawalId)
                ->where('member_id', $member->id)->lockForUpdate()->first();
            if (!$withdrawal) throw new DomainException('未找到当前账户的提取订单。');
            if ($withdrawal->status === 'paid') return $withdrawal;
            $settings = $this->gate->require('withdrawal');
            app(\App\Services\Deposit\DepositRisk::class)->assertClear($userId);
            $funding = DB::table('umi_v2_live_withdrawal_funding')->where('withdrawal_id', $withdrawalId)
                ->lockForUpdate()->first();
            if ($withdrawal->status !== 'awaiting_topup' || !$funding || Decimal::cmp((string) $funding->remaining_umi, '0') <= 0) {
                throw new DomainException('该提取订单不需要补充 UMI。');
            }
            $amount = (string) $funding->remaining_umi;
            $this->wallet->move($userId, (int) $settings->pool_user_id, $amount,
                'withdraw-spot-topup:' . $key, 'withdrawal_spot_topup', 'withdrawal:' . $withdrawalId,
                (int) $member->id, 'trade', 'wallet');
            DB::table('umi_v2_live_withdrawal_funding')->where('withdrawal_id', $withdrawalId)->update([
                'from_wallet_umi' => Decimal::add((string) $funding->from_wallet_umi, $amount),
                'remaining_umi' => '0', 'updated_at' => FundedTime::database(now()),
            ]);
            DB::table('umi_v2_withdrawals')->where('id', $withdrawalId)->update([
                'status' => 'funded', 'updated_at' => FundedTime::database(now()),
            ]);
            $this->pay($withdrawalId, $userId, (int) $settings->pool_user_id);
            return DB::table('umi_v2_withdrawals')->find($withdrawalId);
        }, 3);
    }

    private function pay(int $withdrawalId, int $userId, int $poolUserId): void
    {
        $withdrawal = DB::table('umi_v2_withdrawals')->where('id', $withdrawalId)
            ->lockForUpdate()->first() ?? throw new RuntimeException('UMI withdrawal disappeared');
        if ($withdrawal->status === 'paid') { return; }
        $funding = DB::table('umi_v2_live_withdrawal_funding')
            ->where('withdrawal_id', $withdrawalId)->lockForUpdate()->first();
        if ($withdrawal->status !== 'funded' || !$funding ||
            Decimal::cmp((string) $funding->remaining_umi, '0') !== 0 ||
            Decimal::cmp(Decimal::add(Decimal::add((string) $funding->from_income_umi,
                (string) $funding->from_wallet_umi), (string) $funding->from_deposit_umi),
                (string) $withdrawal->required_burn_umi) !== 0) {
            throw new RuntimeException('UMI withdrawal 30% funding mismatch');
        }
        $amount = (string) $withdrawal->requested_umi;
        $this->intake->assertBurnCapacity((string) $withdrawal->required_burn_umi);
        $income = DB::table('umi_v2_income_accounts')->where('member_id', $withdrawal->member_id)
            ->lockForUpdate()->first();
        if (!$income || Decimal::cmp((string) $income->withdrawal_reserved, $amount) < 0) {
            throw new RuntimeException('UMI withdrawal reserve mismatch');
        }
        DB::table('umi_v2_live_burn_lots')->insert([
            'source_key' => 'live:withdrawal:' . $withdrawalId,
            'purpose' => 'withdrawal', 'member_id' => $withdrawal->member_id,
            'withdrawal_id' => $withdrawalId, 'amount_umi' => $withdrawal->required_burn_umi,
            'status' => 'pending', 'created_at' => FundedTime::database(now()), 'updated_at' => FundedTime::database(now()),
        ]);
        app(\App\Services\Deposit\DepositRisk::class)->assertClear($poolUserId);
        $this->wallet->move($poolUserId, $userId, $amount,
            'withdraw-paid:' . $withdrawalId, 'withdrawal_payout',
            'withdrawal:' . $withdrawalId, (int) $withdrawal->member_id);
        DB::table('umi_v2_income_accounts')->where('member_id', $withdrawal->member_id)->update([
            'withdrawal_reserved' => Decimal::sub((string) $income->withdrawal_reserved, $amount),
            'paid_total' => Decimal::add((string) $income->paid_total, $amount),
            'version_no' => (int) $income->version_no + 1, 'updated_at' => FundedTime::database(now()),
        ]);
        DB::table('umi_v2_withdrawals')->where('id', $withdrawalId)->update([
            'status' => 'paid', 'paid_umi' => $amount,
            'payout_ref' => 'deepro-wallet:' . $withdrawalId,
            'paid_at' => FundedTime::database(now()), 'updated_at' => FundedTime::database(now()),
        ]);
        if (Decimal::cmp($this->settlement->freePool($poolUserId), '0') < 0) {
            throw new DomainException('资金池余额不足，提现暂未完成。');
        }
    }

    private function member(int $userId, bool $lock = false): object
    {
        $query = DB::table('umi_v2_members')->where('user_id', $userId);
        if ($lock) { $query->lockForUpdate(); }
        return $query->first() ?? throw new DomainException('请先开通 UMI 账户。');
    }
}
