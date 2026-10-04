<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;

use App\Domain\Umi\V2\Cycle;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/** Deepro spot-account intake; burn finality activates the cycle. */
final class FundedIntake
{
    public function __construct(
        private readonly FundedReadiness $gate,
        private readonly FundedConfiguration $configuration,
        private readonly FundedWallet $wallet,
    ) {}

    public function enroll(int $userId, ?string $sponsorCode, string $key): object
    {
        return app(MemberEnrollment::class)->enroll($userId, $sponsorCode, $key);
    }

    public function preview(int $userId, string $amount): array
    {
        $this->gate->require('intake');
        $member = $this->member($userId);
        $quote = $this->configuration->quote('UMI_USDT');
        $pending = $this->pendingPrincipal((int) $member->id);
        $cycle = Cycle::open('preview', (string) $member->id, Decimal::add(Decimal::amount($amount, true), $pending),
            (string) $quote->price, 'preview');
        return ['topup_umi' => $amount, 'pending_principal_umi' => $pending, 'principal_umi' => $cycle->principal,
            'principal_usd' => $cycle->valueUsdt, 'multiplier' => $cycle->multiple,
            'cap_umi' => $cycle->quota, 'wallet_umi' => $this->wallet->balance($userId, 'trade'),
            'quote' => (string) $quote->price, 'quote_at' => FundedTime::database($quote->observed_at)];
    }

    public function create(int $userId, string $source, string $amount, string $key): object
    {
        if (!in_array($source, ['spot'], true)) {
            throw new DomainException('UMI 仅支持从 Deepro 现货账户划入。');
        }
        $amount = Decimal::amount($amount, true);
        return DB::transaction(function () use ($userId, $source, $amount, $key): object {
            DB::table('umi_v2_live_settings')->where('id',1)->lockForUpdate()->first();
            $member = $this->member($userId, true);
            $existing = DB::table('umi_v2_live_intents')->where('request_key', $key)->first();
            if ($existing) {
                if ((int) $existing->member_id !== (int) $member->id || $existing->source !== $source
                    || Decimal::cmp((string) $existing->amount_umi, $amount) !== 0) {
                    throw new DomainException('该笔充值编号已用于其他订单。');
                }
                return $existing;
            }
            $settings = $this->gate->require('intake');
            app(\App\Services\Deposit\DepositRisk::class)->assertClear($userId);
            $pending = $this->pendingPrincipal((int) $member->id);
            if (Decimal::cmp($pending,'0')>0 && DB::table('umi_v2_live_intents')->where('member_id',$member->id)
                ->whereIn('status',['awaiting_credit','pending_burn'])->exists()) {
                throw new DomainException('待激活本金已关联充值订单，请先完成该订单。');
            }
            $quote = $this->configuration->quote('UMI_USDT');
            Cycle::open('preview', (string) $member->id, Decimal::add($amount,$pending),
                (string) $quote->price, (string) $this->policy()->id);
            $now = now();
            $id = DB::table('umi_v2_live_intents')->insertGetId([
                'member_id' => $member->id, 'request_key' => $key, 'source' => $source,
                'status' => 'pending_burn',
                'amount_umi' => $amount, 'umi_usd_quote' => $quote->price,
                'quote_source' => $quote->source . ':' . $quote->source_ref,
                'quote_at' => FundedTime::database($quote->observed_at),
                'expires_at' => null,
                'created_at' => FundedTime::database($now), 'updated_at' => FundedTime::database($now),
            ]);
            $this->activateIntent($id, $userId, (int) $settings->pool_user_id);
            return DB::table('umi_v2_live_intents')->find($id);
        }, 3);
    }

    /** Old deposit callbacks cannot debit any account after spot-only cutover. */
    public function bindVerifiedDeposit(int $depositId): ?object
    {
        return null;
    }

    private function activateIntent(int $intentId, int $userId, int $poolUserId, ?int $depositId = null): void
    {
        $intent = DB::table('umi_v2_live_intents')->where('id', $intentId)->lockForUpdate()->first()
            ?? throw new RuntimeException('UMI intent disappeared');
        if ($intent->cycle_id) { return; }
        $member = DB::table('umi_v2_members')->where('id', $intent->member_id)->lockForUpdate()->first();
        $policy = $this->policy();
        $pending = $this->pendingPrincipal((int) $member->id);
        $opening = Cycle::open('new', (string) $member->id, Decimal::add((string) $intent->amount_umi,$pending),
            (string) $intent->umi_usd_quote, (string) $policy->id);
        $this->assertBurnCapacity((string) $intent->amount_umi);
        $this->wallet->move($userId, $poolUserId, (string) $intent->amount_umi,
            'intake:' . $intent->request_key, 'activation_intake', 'intent:' . $intentId,
            (int) $member->id, 'trade', 'wallet');
        $cycleNumber = 1 + (int) DB::table('umi_v2_cycles')
            ->where('member_id', $member->id)->max('cycle_number');
        $now = now();
        $cycleId = DB::table('umi_v2_cycles')->insertGetId([
            'member_id' => $member->id, 'policy_version_id' => $policy->id,
            'cycle_number' => $cycleNumber, 'activation_request_key' => 'live:' . $intent->request_key,
            'multiplier' => $opening->multiple, 'principal_umi' => $opening->principal,
            'usd_quote_per_umi' => $opening->priceUsdt, 'principal_usd' => $opening->valueUsdt,
            'cap_umi' => $opening->quota, 'status' => 'pending_burn',
            'created_at' => FundedTime::database($now), 'updated_at' => FundedTime::database($now),
        ]);
        DB::table('umi_v2_live_burn_lots')->insert([
            'source_key' => 'live:activation:' . $cycleId, 'purpose' => 'activation',
            'member_id' => $member->id, 'cycle_id' => $cycleId,
            'amount_umi' => $intent->amount_umi, 'status' => 'pending',
            'created_at' => FundedTime::database($now), 'updated_at' => FundedTime::database($now),
        ]);
        if (Decimal::cmp($pending,'0')>0) {
            DB::table('umi_v2_pending_principals')->where('member_id',$member->id)->update(['amount_umi'=>'0','updated_at'=>FundedTime::database($now)]);
        }
        DB::table('umi_v2_live_intents')->where('id', $intentId)->update([
            'status' => 'pending_burn', 'deposit_id' => $depositId,
            'cycle_id' => $cycleId, 'updated_at' => FundedTime::database($now),
        ]);
    }

    private function pendingPrincipal(int $memberId): string
    {
        return (string) (DB::table('umi_v2_pending_principals')->where('member_id',$memberId)->value('amount_umi') ?? '0');
    }

    public function assertBurnCapacity(string $amount): void
    {
        DB::table('umi_v2_live_settings')->where('id', 1)->lockForUpdate()->first();
        $total = '0';
        foreach (DB::table('umi_v2_live_burn_lots')->where('status','!=','cancelled')->select('amount_umi')->get() as $row) {
            $total = Decimal::add($total, (string) $row->amount_umi);
        }
        if (Decimal::cmp(Decimal::add($total, $amount), '79000000') > 0) {
            throw new DomainException('UMI 已达到本轮充值上限。');
        }
    }

    public function policy(): object
    {
        $row = DB::table('umi_v2_policy_versions')->where('policy_key', 'v2-funded')
            ->where('version', 1)->first();
        if ($row) { return $row; }
        $rules = ['mode' => 'funded', 'static_rate_min' => '0.008',
            'static_rate_max' => '0.015', 'direct_rate' => '0.1',
            'withdrawal_extra_burn' => '0.3', 'points_value_factor' => '0.1',
            'peer_rate' => '0.1', 'peer_min_level' => 4,
            'peer_base' => 'direct_child_paid_grade_only',
            'parallel_cycles' => true, 'dynamic_allocation' => 'oldest_active_first',
            'grade_base' => 'paid_static', 'rank_uses_active_cycles_only' => true];
        $json = json_encode($rules, JSON_THROW_ON_ERROR);
        DB::table('umi_v2_policy_versions')->insertOrIgnore([
            'policy_key' => 'v2-funded', 'version' => 1,
            'status' => 'approved', 'rules_json' => $json,
            'rules_sha256' => hash('sha256', $json), 'effective_at' => FundedTime::database(now()),
            'change_reason' => 'Funded Deepro UMI program',
            'created_at' => FundedTime::database(now()), 'updated_at' => FundedTime::database(now()),
        ]);
        return DB::table('umi_v2_policy_versions')->where('policy_key', 'v2-funded')
            ->where('version', 1)->first() ?? throw new RuntimeException('UMI policy unavailable');
    }

    private function member(int $userId, bool $lock = false): object
    {
        $query = DB::table('umi_v2_members')->where('user_id', $userId);
        if ($lock) { $query->lockForUpdate(); }
        return $query->first() ?? throw new DomainException('请先开通 UMI 账户。');
    }
}
