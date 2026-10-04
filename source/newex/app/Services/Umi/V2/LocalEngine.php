<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Cycle;
use App\Domain\Umi\V2\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runnable UMI acceptance adapter. It never calls Deepro custody, exchange,
 * stock, or chain services. Every balance and burn shown by this adapter is a
 * labelled sandbox simulation, isolated from legacy UMI and exchange wallets.
 */
final class LocalEngine
{
    public const BSC_DEAD_ADDRESS = '0x000000000000000000000000000000000000dEaD';

    private const RANK_SMALL_USD = [1 => '500', 2 => '3000', 3 => '10000',
        4 => '100000', 5 => '300000', 6 => '1000000',
        7 => '3000000', 8 => '5000000', 9 => '10000000'];

    public function __construct(private readonly CycleStore $cycles) {}

    public function assertEnabled(): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('umi-v2.local_acceptance'),
            503, 'UMI 本地验收未启用');
    }

    public function memberForUser(int $userId): ?object
    {
        return DB::table('umi_v2_members')->where('user_id', $userId)->first();
    }

    public function chainSettings(): array
    {
        $this->assertEnabled();
        $row = Schema::hasTable('umi_v2_chain_settings')
            ? DB::table('umi_v2_chain_settings')->where('id', 1)->first() : null;
        return [
            'chain_id' => 56,
            'token_contract' => $row?->token_contract,
            'burn_address' => $row?->burn_address,
            'dedicated_address' => $row?->dedicated_address,
            'payout_source' => 'deepro_pool',
            'reward_source' => 'deepro_pool',
            'proposed_token_contract' => (string) config('umi.asset.contract'),
            'proposed_burn_address' => self::BSC_DEAD_ADDRESS,
            'configured' => $row?->token_contract !== null &&
                $row?->burn_address !== null && $row?->dedicated_address !== null,
            'real_transfer_enabled' => false,
        ];
    }

    /** Stores draft network coordinates only. The sandbox never signs or sends assets. */
    public function saveChainSettings(?string $contract, ?string $burn,
        ?string $dedicated, string $key, int $actorId): array
    {
        $this->assertEnabled();
        if (!Schema::hasTable('umi_v2_chain_settings')) {
            throw new DomainException('UMI chain settings migration is missing');
        }
        $contract = $this->evmAddress($contract);
        $burn = $this->evmAddress($burn);
        $dedicated = $this->evmAddress($dedicated);
        if ($burn !== null && strcasecmp($burn, self::BSC_DEAD_ADDRESS) !== 0) {
            throw new DomainException('This BSC configuration only accepts the verified 0x...dEaD burn address');
        }
        if ($dedicated !== null && ($dedicated === $contract || $dedicated === $burn)) {
            throw new DomainException('The dedicated account must differ from the token and burn addresses');
        }
        return DB::transaction(function () use ($contract, $burn, $dedicated, $key, $actorId): array {
            $candidate = ['chain_id' => 56, 'token_contract' => $contract,
                'burn_address' => $burn, 'dedicated_address' => $dedicated,
                'payout_source' => 'deepro_pool', 'reward_source' => 'deepro_pool'];
            $existing = DB::table('umi_v2_chain_settings_audit')->where('request_key', $key)->first();
            if ($existing) {
                $prior = json_decode((string) $existing->after_json, true, 512, JSON_THROW_ON_ERROR);
                if ($prior !== $candidate) {
                    throw new DomainException('Settings request key conflicts with a different value');
                }
                return $prior + ['replayed' => true];
            }
            $row = DB::table('umi_v2_chain_settings')->where('id', 1)->lockForUpdate()->first();
            if (!$row) { throw new RuntimeException('UMI chain settings row is missing'); }
            $before = ['chain_id' => (int) $row->chain_id,
                'token_contract' => $row->token_contract, 'burn_address' => $row->burn_address,
                'dedicated_address' => $row->dedicated_address,
                'payout_source' => $row->payout_source, 'reward_source' => $row->reward_source];
            DB::table('umi_v2_chain_settings')->where('id', 1)->update($candidate + [
                'updated_by' => $actorId, 'updated_at' => now(),
            ]);
            DB::table('umi_v2_chain_settings_audit')->insert([
                'request_key' => $key, 'before_json' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
                'actor_id' => $actorId, 'created_at' => now(),
            ]);
            return $candidate + ['replayed' => false];
        }, 3);
    }

    private function evmAddress(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        if ($value === null || $value === '') { return null; }
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/D', $value)) {
            throw new DomainException('Enter a full 0x-prefixed 40-hex-character BSC address');
        }
        return strtolower($value);
    }

    public function enroll(int $userId, ?string $sponsorCode, string $key): object
    {
        $this->assertEnabled();
        return app(MemberEnrollment::class)->enrollSandbox($userId, $sponsorCode, $key);
    }

    public function fundTestWallet(int $memberId, string $amount, string $key, int $actorId): void
    {
        $this->assertEnabled();
        if (!DB::table('umi_v2_members')->where('id', $memberId)->exists()) {
            throw new DomainException('UMI member does not exist');
        }
        $amount = Decimal::amount($amount, true);
        DB::transaction(fn () => $this->move('system:issuance', self::wallet($memberId), $amount, $key,
            'sandbox_funding', 'member:' . $memberId, $actorId), 3);
    }

    public function fundTestRewardInventory(string $amount, string $key, int $actorId): void
    {
        $this->assertEnabled();
        DB::transaction(fn () => $this->move('system:issuance', 'system:reward', Decimal::amount($amount, true),
            $key, 'sandbox_reward_inventory', 'inventory', $actorId), 3);
    }

    public function activate(int $userId, string $principal, string $key): object
    {
        $this->assertEnabled();
        $principal = Decimal::amount($principal, true);
        return DB::transaction(function () use ($userId, $principal, $key): object {
            $member = $this->memberForUser($userId);
            if (!$member) {
                throw new DomainException('Open a UMI member account first');
            }
            $replay = DB::table('umi_v2_cycles')->where('activation_request_key', $key)->first();
            if ($replay) {
                if ((int) $replay->member_id !== (int) $member->id ||
                    Decimal::cmp((string) $replay->principal_umi, $principal) !== 0) {
                    throw new DomainException('Activation key conflicts with an existing cycle');
                }
                return $replay;
            }
            $this->assertCommercialOpen();
            DB::table('umi_v2_members')->where('id', $member->id)->lockForUpdate()->first();
            $policy = $this->policy();
            $price = Decimal::amount((string) config('umi-v2.umi_usd_quote'), true);
            $opening = Cycle::open('new', (string) $member->id, $principal, $price, (string) $policy->id);
            $number = 1 + (int) DB::table('umi_v2_cycles')->where('member_id', $member->id)->max('cycle_number');
            $now = now()->toDateTimeString();
            $id = DB::table('umi_v2_cycles')->insertGetId([
                'member_id' => $member->id, 'policy_version_id' => $policy->id,
                'cycle_number' => $number, 'activation_request_key' => $key,
                'multiplier' => $opening->multiple, 'principal_umi' => $principal,
                'usd_quote_per_umi' => $price, 'principal_usd' => $opening->valueUsdt,
                'cap_umi' => $opening->quota, 'status' => 'pending_burn',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->move(self::wallet((int) $member->id), 'system:dedicated', $principal,
                'activation:' . $key, 'activation_pool', 'cycle:' . $id, $userId);
            $this->lot('activation:' . $id, 'activation', (int) $member->id, $id, null, $principal);
            return DB::table('umi_v2_cycles')->find($id);
        }, 3);
    }

    /** A verified-looking test inbound immediately opens one pending cycle.
     * Only the admin sandbox may call this; real exchange and chain events are
     * not accepted by this adapter.
     */
    public function recordSandboxInbound(int $memberId, string $source, string $sourceRef,
        string $amount, int $actorId): object
    {
        $this->assertEnabled();
        if (!in_array($source, ['exchange', 'chain'], true) ||
            !preg_match('/^[A-Za-z0-9:_-]{1,80}$/D', $sourceRef)) {
            throw new DomainException('Invalid sandbox inbound source or reference');
        }
        $amount = Decimal::amount($amount, true);
        $key = 'inbound:' . $source . ':' . $sourceRef;
        return DB::transaction(function () use ($memberId, $source, $sourceRef, $amount, $actorId, $key): object {
            $member = DB::table('umi_v2_members')->where('id', $memberId)->lockForUpdate()->first();
            if (!$member) { throw new DomainException('UMI member does not exist'); }
            $replay = DB::table('umi_v2_cycles')->where('activation_request_key', $key)->first();
            if ($replay) {
                if ((int) $replay->member_id !== $memberId ||
                    Decimal::cmp((string) $replay->principal_umi, $amount) !== 0) {
                    throw new DomainException('Inbound source reference conflicts with an existing cycle');
                }
                return $replay;
            }
            $this->assertCommercialOpen();
            $policy = $this->policy();
            $price = Decimal::amount((string) config('umi-v2.umi_usd_quote'), true);
            $opening = Cycle::open('new', (string) $memberId, $amount, $price, (string) $policy->id);
            $number = 1 + (int) DB::table('umi_v2_cycles')->where('member_id', $memberId)->max('cycle_number');
            $now = now()->toDateTimeString();
            $id = DB::table('umi_v2_cycles')->insertGetId([
                'member_id' => $memberId, 'policy_version_id' => $policy->id,
                'cycle_number' => $number, 'activation_request_key' => $key,
                'multiplier' => $opening->multiple, 'principal_umi' => $amount,
                'usd_quote_per_umi' => $price, 'principal_usd' => $opening->valueUsdt,
                'cap_umi' => $opening->quota, 'status' => 'pending_burn',
                'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $from = $source === 'exchange' ? self::wallet($memberId) : 'system:issuance';
            $this->move($from, 'system:dedicated', $amount,
                $key, 'sandbox_inbound_' . $source,
                'cycle:' . $id . ':source:' . $sourceRef, $actorId);
            $this->lot('activation:' . $id, 'activation', $memberId, $id, null, $amount);
            return DB::table('umi_v2_cycles')->find($id);
        }, 3);
    }

    public function transfer(int $userId, int $cycleId, string $pocket, string $amount, string $key): array
    {
        $this->assertEnabled();
        $amount = Decimal::amount($amount, true);
        $member = $this->memberForUser($userId);
        if (!$member || !DB::table('umi_v2_cycles')->where('id', $cycleId)
            ->where('member_id', $member->id)->exists()) {
            throw new DomainException('Cycle does not belong to this user');
        }
        if (!in_array($pocket, ['static', 'team', 'referral'], true)) {
            throw new DomainException('Unknown UMI income pocket');
        }
        return DB::transaction(function () use ($userId, $member, $cycleId, $pocket, $amount, $key): array {
            $result = $this->cycles->transfer($cycleId, $key, $pocket, $amount, $userId);
            if (!$result['replayed']) {
                $this->move(self::pending((int) $member->id, $pocket), self::available((int) $member->id),
                    $amount, 'ledger:' . $key, 'income_transfer', 'cycle:' . $cycleId, $userId);
            }
            return $result;
        }, 3);
    }

    public function runDay(string $day, string $rate, string $key, int $actorId): array
    {
        $this->assertEnabled();
        $rate = Decimal::rate($rate);
        if (Decimal::cmp($rate, '0.008') < 0 || Decimal::cmp($rate, '0.015') > 0) {
            throw new DomainException('Static rate must be 0.8%–1.5% per day');
        }
        if (!CarbonImmutable::createFromFormat('!Y-m-d', $day) ||
            CarbonImmutable::createFromFormat('!Y-m-d', $day)->format('Y-m-d') !== $day) {
            throw new DomainException('Invalid business date');
        }
        return DB::transaction(function () use ($day, $rate, $key, $actorId): array {
            $existing = DB::table('umi_v2_daily_runs')->where('business_date', $day)->first();
            if ($existing) {
                if (Decimal::cmp((string) $existing->static_rate, $rate) !== 0) {
                    throw new DomainException('This business day was settled with a different rate');
                }
                return json_decode($existing->summary_json, true, 512, JSON_THROW_ON_ERROR) + ['replayed' => true];
            }
            $this->assertCommercialOpen();
            $policy = $this->policy();
            $this->refreshRanks();
            $released = '0'; $injured = '0'; $count = 0; $gradeCount = 0; $peerCount = 0;
            $staticSources = [];
            foreach (DB::table('umi_v2_cycles')->where('status', 'active')
                ->where('starts_on', '<=', $day)->orderBy('id')->get() as $cycle) {
                $eventKey = 'static:' . $cycle->id . ':' . $day;
                $result = $this->cycles->releaseStatic((int) $cycle->id, $eventKey,
                    $day, $rate, (int) $policy->id, $actorId);
                $this->creditRelease($result, (int) $cycle->id, (int) $cycle->member_id, 'static', $actorId);
                $released = Decimal::add($released, $result['event']['paid']);
                $injured = Decimal::add($injured,
                    Decimal::sub($result['event']['expected'], $result['event']['paid']));
                if (Decimal::cmp($result['event']['paid'], '0') > 0) {
                    $staticSources[] = ['cycle_id' => (int) $cycle->id,
                        'member_id' => (int) $cycle->member_id,
                        'paid' => $result['event']['paid']];
                }
                $count++;
            }
            // A descendant's actual static release is the only grade base.
            // Each ancestor receives only the percentage above the highest
            // grade already traversed; the next peer pass never recurses.
            $parents = DB::table('umi_v2_sponsor_edges')
                ->pluck('parent_member_id', 'child_member_id')->all();
            $levels = DB::table('umi_v2_members')->pluck('level', 'id')->all();
            foreach ($staticSources as $source) {
                $ancestor = $parents[$source['member_id']] ?? null;
                $coveredRate = '0';
                $seen = [$source['member_id'] => true];
                while ($ancestor !== null) {
                    $ancestor = (int) $ancestor;
                    if (isset($seen[$ancestor])) {
                        throw new RuntimeException('UMI sponsor graph contains a cycle');
                    }
                    $seen[$ancestor] = true;
                    $level = (int) ($levels[$ancestor] ?? 0);
                    $gradeRate = $level === 0 ? '0' : '0.' . $level;
                    if (Decimal::cmp($gradeRate, $coveredRate) > 0) {
                        $difference = Decimal::sub($gradeRate, $coveredRate);
                        $sourceId = 'grade:static:' . $source['cycle_id'] . ':' . $ancestor . ':' . $day;
                        $award = $this->releaseDynamicAcrossCycles($ancestor, 'team', $day,
                            $source['member_id'], $sourceId,
                            Decimal::mul($source['paid'], $difference),
                            'grade:' . $source['cycle_id'] . ':' . $day,
                            (int) $policy->id, $actorId);
                        if ($award['allocated']) {
                            $released = Decimal::add($released, $award['paid']);
                            $injured = Decimal::add($injured, $award['injured']);
                            $gradeCount++;
                        }
                        $coveredRate = $gradeRate;
                    }
                    $ancestor = $parents[$ancestor] ?? null;
                }
            }
            // A's peer/override base is only direct child B's actual grade
            // differential paid today. Static, direct and peer awards are excluded.
            foreach (DB::table('umi_v2_sponsor_edges as e')
                ->join('umi_v2_members as a', 'a.id', '=', 'e.parent_member_id')
                ->join('umi_v2_members as b', 'b.id', '=', 'e.child_member_id')
                ->where('a.level', '>=', 4)->whereColumn('b.level', '>=', 'a.level')
                ->orderBy('e.child_member_id')->select('a.id as parent_id', 'a.level as parent_level',
                    'b.id as child_id', 'b.level as child_level')->get() as $edge) {
                $base = '0';
                foreach (DB::table('umi_v2_release_events')->where('member_id', $edge->child_id)
                    ->where('business_date', $day)->where('status', 'posted')
                    ->where('kind', 'team')->get() as $source) {
                    if (str_starts_with((string) $source->source_event_id, 'grade:static:')) {
                        $base = Decimal::add($base, (string) $source->released_umi);
                    }
                }
                if (Decimal::cmp($base, '0') <= 0) { continue; }
                $sourceId = 'peer:' . $edge->child_id . ':' . $day;
                $candidate = Decimal::mul($base, '0.1');
                $award = $this->releaseDynamicAcrossCycles((int) $edge->parent_id, 'team',
                    $day, (int) $edge->child_id, $sourceId, $candidate,
                    'peer:' . $edge->child_id . ':' . $day, (int) $policy->id, $actorId);
                if (!$award['allocated']) { continue; }
                DB::table('umi_v2_peer_reward_sources')->insert([
                    'parent_member_id' => $edge->parent_id, 'child_member_id' => $edge->child_id,
                    'business_date' => $day, 'parent_level' => $edge->parent_level,
                    'child_level' => $edge->child_level, 'confirmed_base_umi' => $base,
                    'candidate_umi' => $candidate,
                    'released_umi' => $award['paid'],
                    'source_event_id' => $sourceId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $released = Decimal::add($released, $award['paid']);
                $injured = Decimal::add($injured, $award['injured']);
                $peerCount++;
            }
            // A cycle can finish during today's release. Its performance must
            // stop qualifying the member and ancestors for the next day.
            $this->refreshRanks();
            $summary = ['business_date' => $day, 'cycles' => $count,
                'grade_rewards' => $gradeCount,
                'peer_rewards' => $peerCount, 'released_umi' => $released,
                'injured_umi' => $injured, 'mode' => 'sandbox'];
            DB::table('umi_v2_daily_runs')->insert([
                'business_date' => $day, 'static_rate' => $rate,
                'policy_version_id' => $policy->id,
                'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
                'actor_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $summary + ['replayed' => false];
        }, 3);
    }

    public function setTestLevel(int $memberId, int $level): void
    {
        $this->assertEnabled();
        if ($level < 0 || $level > 9) { throw new DomainException('Level must be V0–V9'); }
        if (!DB::table('umi_v2_members')->where('id', $memberId)->exists()) {
            throw new DomainException('UMI member does not exist');
        }
        DB::table('umi_v2_members')->where('id', $memberId)->update([
            'test_level_override' => $level === 0 ? null : $level,
            'updated_at' => now(),
        ]);
        $this->refreshRanks();
    }

    public function withdraw(int $userId, string $amount, string $key): object
    {
        $this->assertEnabled();
        $amount = Decimal::amount($amount, true);
        return DB::transaction(function () use ($userId, $amount, $key): object {
            $member = $this->memberForUser($userId);
            if (!$member) { throw new DomainException('UMI member account is missing'); }
            $existing = DB::table('umi_v2_withdrawals')->where('request_key', $key)->first();
            if ($existing) {
                if ((int) $existing->member_id !== (int) $member->id ||
                    Decimal::cmp((string) $existing->requested_umi, $amount) !== 0) {
                    throw new DomainException('Withdrawal key conflicts with an existing order');
                }
                return $existing;
            }
            $this->assertCommercialOpen();
            $income = DB::table('umi_v2_income_accounts')->where('member_id', $member->id)
                ->lockForUpdate()->first();
            if (!$income || Decimal::cmp((string) $income->available, $amount) < 0) {
                throw new DomainException('Released and transferred UMI is insufficient for W');
            }
            $burn = Decimal::mul($amount, '0.3');
            $afterW = Decimal::sub((string) $income->available, $amount);
            $fromIncome = Decimal::min($burn, $afterW);
            $needAfterIncome = Decimal::sub($burn, $fromIncome);
            $fromWallet = Decimal::min($needAfterIncome, $this->balance(self::wallet((int) $member->id)));
            $remaining = Decimal::sub($needAfterIncome, $fromWallet);
            $status = Decimal::cmp($remaining, '0') === 0 ? 'funded' : 'awaiting_topup';
            $policy = $this->policy();
            $now = now()->toDateTimeString();
            $id = DB::table('umi_v2_withdrawals')->insertGetId([
                'member_id' => $member->id, 'policy_version_id' => $policy->id,
                'request_key' => $key, 'status' => $status,
                'requested_umi' => $amount, 'required_burn_umi' => $burn,
                'confirmed_burn_umi' => '0', 'paid_umi' => '0',
                'requested_by' => $userId, 'requested_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('umi_v2_income_accounts')->where('member_id', $member->id)->update([
                'available' => Decimal::sub($afterW, $fromIncome),
                'withdrawal_reserved' => Decimal::add((string) $income->withdrawal_reserved, $amount),
                'version_no' => (int) $income->version_no + 1, 'updated_at' => $now,
            ]);
            $this->move(self::available((int) $member->id), self::reserved((int) $member->id),
                $amount, 'withdraw-reserve:' . $key, 'withdrawal_reserve', 'withdrawal:' . $id, $userId);
            if (Decimal::cmp($fromIncome, '0') > 0) {
                $this->move(self::available((int) $member->id), 'system:dedicated', $fromIncome,
                    'withdraw-income-b:' . $key, 'withdrawal_b_existing', 'withdrawal:' . $id, $userId);
            }
            if (Decimal::cmp($fromWallet, '0') > 0) {
                $this->move(self::wallet((int) $member->id), 'system:dedicated', $fromWallet,
                    'withdraw-wallet-b:' . $key, 'withdrawal_b_existing', 'withdrawal:' . $id, $userId);
            }
            DB::table('umi_v2_withdrawal_funding')->insert([
                'withdrawal_id' => $id, 'from_income_umi' => $fromIncome,
                'from_wallet_umi' => $fromWallet, 'external_topup_umi' => '0',
                'remaining_umi' => $remaining, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if (Decimal::cmp($remaining, '0') === 0) {
                $this->lot('withdrawal:' . $id, 'withdrawal', (int) $member->id, null, $id, $burn);
                $this->paySandboxWithdrawal($id, $userId);
            }
            return DB::table('umi_v2_withdrawals')->find($id);
        }, 3);
    }

    public function preview(int $userId, string $action, string $amount): array
    {
        $this->assertEnabled();
        $amount = Decimal::amount($amount, true);
        $member = $this->memberForUser($userId);
        if (!$member) { throw new DomainException('Open a UMI member account first'); }
        if ($action === 'activate') {
            $cycle = Cycle::open('preview', (string) $member->id, $amount,
                Decimal::amount((string) config('umi-v2.umi_usd_quote'), true), 'preview');
            return ['action' => 'activate', 'principal_umi' => $amount,
                'principal_usd' => $cycle->valueUsdt, 'multiplier' => $cycle->multiple,
                'cap_umi' => $cycle->quota, 'wallet_umi' => $this->balance(self::wallet((int) $member->id)),
                'quote_mode' => 'sandbox'];
        }
        if ($action !== 'withdraw') { throw new DomainException('Unknown preview action'); }
        $available = (string) (DB::table('umi_v2_income_accounts')
            ->where('member_id', $member->id)->value('available') ?? '0');
        if (Decimal::cmp($available, $amount) < 0) {
            throw new DomainException('Released and transferred UMI is insufficient for W');
        }
        $burn = Decimal::mul($amount, '0.3');
        $afterW = Decimal::sub($available, $amount);
        $fromIncome = Decimal::min($burn, $afterW);
        $fromWallet = Decimal::min(Decimal::sub($burn, $fromIncome),
            $this->balance(self::wallet((int) $member->id)));
        return ['action' => 'withdraw', 'pay_to_user_umi' => $amount,
            'extra_b_umi' => $burn, 'from_income_umi' => $fromIncome,
            'from_wallet_umi' => $fromWallet,
            'external_shortfall_umi' => Decimal::sub(Decimal::sub($burn, $fromIncome), $fromWallet),
            'mode' => 'sandbox'];
    }

    /** Admin records a test external purchase and inbound transfer, never a real fill. */
    public function simulateExternalTopup(int $withdrawalId, string $key, int $actorId): object
    {
        $this->assertEnabled();
        return DB::transaction(function () use ($withdrawalId, $key, $actorId): object {
            $withdrawal = DB::table('umi_v2_withdrawals')->where('id', $withdrawalId)
                ->lockForUpdate()->first();
            $funding = DB::table('umi_v2_withdrawal_funding')->where('withdrawal_id', $withdrawalId)
                ->lockForUpdate()->first();
            if (!$withdrawal || !$funding) { throw new DomainException('Withdrawal does not exist'); }
            if ($funding->topup_ref !== null) {
                if ($funding->topup_ref !== $key) {
                    throw new DomainException('Withdrawal was already topped up with another reference');
                }
                return $withdrawal;
            }
            $amount = (string) $funding->remaining_umi;
            if ($withdrawal->status !== 'awaiting_topup' || Decimal::cmp($amount, '0') <= 0) {
                throw new DomainException('Withdrawal does not need a test top-up');
            }
            $this->move('system:issuance', 'system:dedicated', $amount,
                'external-topup:' . $key, 'sandbox_external_purchase',
                'withdrawal:' . $withdrawalId, $actorId);
            DB::table('umi_v2_withdrawal_funding')->where('withdrawal_id', $withdrawalId)->update([
                'external_topup_umi' => $amount, 'remaining_umi' => '0',
                'topup_ref' => $key, 'updated_at' => now(),
            ]);
            DB::table('umi_v2_withdrawals')->where('id', $withdrawalId)->update([
                'status' => 'funded', 'updated_at' => now(),
            ]);
            $this->lot('withdrawal:' . $withdrawalId, 'withdrawal',
                (int) $withdrawal->member_id, null, $withdrawalId,
                (string) $withdrawal->required_burn_umi);
            $this->paySandboxWithdrawal($withdrawalId, $actorId);
            return DB::table('umi_v2_withdrawals')->find($withdrawalId);
        }, 3);
    }

    private function paySandboxWithdrawal(int $withdrawalId, int $actorId): void
    {
        $withdrawal = DB::table('umi_v2_withdrawals')->where('id', $withdrawalId)
            ->lockForUpdate()->first() ?? throw new DomainException('Withdrawal does not exist');
        if ($withdrawal->status === 'paid') { return; }
        if ($withdrawal->status !== 'funded') {
            throw new DomainException('The extra 30% must reach the dedicated account before W is paid');
        }
        $amount = (string) $withdrawal->requested_umi;
        $memberId = (int) $withdrawal->member_id;
        $income = DB::table('umi_v2_income_accounts')->where('member_id', $memberId)
            ->lockForUpdate()->first() ?? throw new RuntimeException('Income account is missing');
        if (Decimal::cmp((string) $income->withdrawal_reserved, $amount) < 0) {
            throw new RuntimeException('Withdrawal reserve projection is insufficient');
        }
        $this->move(self::reserved($memberId), 'system:paid', $amount,
            'withdraw-paid:' . $withdrawalId, 'sandbox_full_payout',
            'withdrawal:' . $withdrawalId, $actorId);
        DB::table('umi_v2_income_accounts')->where('member_id', $memberId)->update([
            'withdrawal_reserved' => Decimal::sub((string) $income->withdrawal_reserved, $amount),
            'paid_total' => Decimal::add((string) $income->paid_total, $amount),
            'version_no' => (int) $income->version_no + 1, 'updated_at' => now(),
        ]);
        DB::table('umi_v2_withdrawals')->where('id', $withdrawalId)->update([
            'status' => 'paid', 'paid_umi' => $amount,
            'payout_ref' => 'sandbox-payout:' . $withdrawalId,
            'paid_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function confirmSandboxBatch(string $key, int $actorId): array
    {
        $this->assertEnabled();
        return DB::transaction(function () use ($key, $actorId): array {
            $existing = DB::table('umi_v2_burn_batches')->where('request_key', $key)->first();
            if ($existing) { return ['id' => $existing->id,
                'amount_umi' => (string) $existing->amount_umi, 'replayed' => true]; }
            $lots = DB::table('umi_v2_burn_lots')->where('status', 'pending')
                ->orderBy('id')->lockForUpdate()->get();
            if ($lots->isEmpty()) { throw new DomainException('No UMI lots await the sandbox batch'); }
            $total = '0';
            foreach ($lots as $lot) { $total = Decimal::add($total, (string) $lot->amount_umi); }
            $now = now()->toDateTimeString();
            $id = DB::table('umi_v2_burn_batches')->insertGetId([
                'request_key' => $key, 'mode' => 'sandbox', 'status' => 'simulated_confirmed',
                'amount_umi' => $total, 'simulation_ref' => 'sandbox-batch:' . $key,
                'actor_id' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->move('system:dedicated', 'system:burned', $total,
                'batch:' . $key, 'sandbox_burn', 'batch:' . $id, $actorId);
            $activated = [];
            foreach ($lots as $lot) {
                DB::table('umi_v2_burn_lots')->where('id', $lot->id)->update([
                    'status' => 'confirmed', 'batch_id' => $id,
                    'confirmed_at' => $now, 'updated_at' => $now,
                ]);
                if ($lot->purpose === 'activation') {
                    DB::table('umi_v2_cycles')->where('id', $lot->cycle_id)
                        ->where('status', 'pending_burn')->update([
                            'status' => 'active',
                            'activation_burn_ref' => 'sandbox-batch:' . $id . ':lot:' . $lot->id,
                            'starts_on' => CarbonImmutable::now(config('umi-v2.timezone'))->toDateString(),
                            'updated_at' => $now,
                        ]);
                    $activated[] = (int) $lot->cycle_id;
                } elseif ($lot->purpose === 'withdrawal') {
                    DB::table('umi_v2_withdrawals')->where('id', $lot->withdrawal_id)->update([
                        'confirmed_burn_umi' => $lot->amount_umi, 'updated_at' => $now,
                    ]);
                    $this->creditSandboxPoints($lot, $id);
                }
            }
            $this->refreshRanks();
            foreach ($activated as $cycleId) { $this->directRewardForActivation($cycleId, $actorId); }
            $this->refreshRanks();
            return ['id' => $id, 'amount_umi' => $total,
                'lot_count' => $lots->count(), 'replayed' => false];
        }, 3);
    }

    private function directRewardForActivation(int $childCycleId, int $actorId): void
    {
        $child = DB::table('umi_v2_cycles')->find($childCycleId);
        if (!$child) { return; }
        $edge = DB::table('umi_v2_sponsor_edges')->where('child_member_id', $child->member_id)->first();
        if (!$edge) { return; }
        $policy = $this->policy();
        $this->releaseDynamicAcrossCycles((int) $edge->parent_member_id, 'referral',
            CarbonImmutable::now(config('umi-v2.timezone'))->toDateString(),
            (int) $child->member_id, 'activation:' . $childCycleId,
            Decimal::mul((string) $child->principal_umi, '0.1'),
            'direct:' . $childCycleId, (int) $policy->id, $actorId);
    }

    /** A source creates one candidate. Consume active cycles oldest first and
     * book injury only after their combined available quota is exhausted.
     */
    private function releaseDynamicAcrossCycles(int $memberId, string $pocket, string $day,
        int $sourceMemberId, string $sourceEventId, string $candidate,
        string $requestSuffix, int $policyVersionId, int $actorId): array
    {
        $cycles = DB::table('umi_v2_cycles')->where('member_id', $memberId)
            ->where('status', 'active')->orderBy('cycle_number')->orderBy('id')->get()
            ->filter(static fn (object $cycle): bool => Decimal::cmp(
                Decimal::sub((string) $cycle->cap_umi, (string) $cycle->released_umi), '0') > 0)
            ->values();
        $left = $candidate; $paid = '0'; $injured = '0'; $allocated = false;
        foreach ($cycles as $index => $cycle) {
            if (Decimal::cmp($left, '0') <= 0) { break; }
            $capacity = Decimal::sub((string) $cycle->cap_umi, (string) $cycle->released_umi);
            // The final cycle receives the residual candidate, including any
            // overflow. CycleStore then moves only that overflow to injury.
            $portion = $index === $cycles->count() - 1 ? $left : Decimal::min($left, $capacity);
            $requestKey = $pocket . ':' . $cycle->id . ':' . $requestSuffix;
            $result = $pocket === 'referral'
                ? $this->cycles->releaseDirectReferral((int) $cycle->id, $requestKey,
                    $day, $sourceMemberId, $sourceEventId, $portion, '1', $policyVersionId, $actorId)
                : $this->cycles->releaseTeam((int) $cycle->id, $requestKey,
                    $day, $sourceMemberId, $sourceEventId, $portion, '1', $policyVersionId, $actorId);
            $this->creditRelease($result, (int) $cycle->id, $memberId, $pocket, $actorId);
            $paid = Decimal::add($paid, $result['event']['paid']);
            $injured = Decimal::add($injured,
                Decimal::sub($result['event']['expected'], $result['event']['paid']));
            $left = Decimal::sub($left, $portion);
            $allocated = true;
        }
        return compact('paid', 'injured', 'allocated');
    }

    private function creditSandboxPoints(object $lot, int $batchId): void
    {
        if (DB::table('umi_v2_point_claims')->where('withdrawal_id', $lot->withdrawal_id)->exists()) { return; }
        $umiPrice = Decimal::amount((string) config('umi-v2.umi_usd_quote'), true);
        $sharePrice = Decimal::amount((string) config('umi-v2.share_usd_quote'), true);
        $factor = Decimal::rate((string) config('umi-v2.points_value_factor'));
        $value = Decimal::mul(Decimal::mul((string) $lot->amount_umi, $umiPrice), $factor);
        $points = Decimal::display(bcdiv($value, $sharePrice, Decimal::SCALE));
        $today = CarbonImmutable::now(config('umi-v2.timezone'));
        DB::table('umi_v2_point_claims')->insert([
            'withdrawal_id' => $lot->withdrawal_id, 'member_id' => $lot->member_id,
            'batch_id' => $batchId, 'burned_umi' => $lot->amount_umi,
            'umi_usd_quote' => $umiPrice, 'share_usd_quote' => $sharePrice,
            'value_factor' => $factor, 'points' => $points,
            'status' => 'points_only',
            'proposed_convert_on' => $today->addDays(60)->toDateString(),
            'proposed_unlock_on' => $today->addDays(150)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function dashboard(int $userId): array
    {
        $this->assertEnabled();
        $member = $this->memberForUser($userId);
        if (!$member) { return ['member' => null, 'mode' => 'sandbox',
            'historical_activation' => (Schema::hasTable('umi_legacy_accounts')
                && DB::table('umi_legacy_accounts')->where('user_id', $userId)
                    ->where('activation_status', 'activated')->exists())
                || (Schema::hasTable('umi_business_accounts')
                    && DB::table('umi_business_accounts')->where('user_id', $userId)
                        ->where('fixture', false)->exists()),
            'quote' => (string) config('umi-v2.umi_usd_quote')]; }
        $id = (int) $member->id;
        $income = DB::table('umi_v2_income_accounts')->where('member_id', $id)->first();
        $parent = DB::table('umi_v2_sponsor_edges as e')
            ->join('umi_v2_members as p', 'p.id', '=', 'e.parent_member_id')
            ->where('e.child_member_id', $id)->value('p.member_code');
        $cycles = DB::table('umi_v2_cycles')->where('member_id', $id)
            ->orderByDesc('id')->select('id', 'cycle_number', 'multiplier',
                'principal_umi', 'cap_umi', 'released_umi', 'status', 'starts_on')
            ->get()->map(static function (object $cycle): object {
                $cycle->remaining_umi = Decimal::sub((string) $cycle->cap_umi,
                    (string) $cycle->released_umi);
                return $cycle;
            });
        $cycleSummary = ['active_count' => 0, 'active_remaining_umi' => '0',
            'total_released_umi' => '0'];
        foreach (DB::table('umi_v2_cycles')->where('member_id', $id)
            ->select('status', 'cap_umi', 'released_umi')->get() as $cycle) {
            $cycleSummary['total_released_umi'] = Decimal::add(
                $cycleSummary['total_released_umi'], (string) $cycle->released_umi);
            if ($cycle->status === 'active') {
                $cycleSummary['active_count']++;
                $cycleSummary['active_remaining_umi'] = Decimal::add(
                    $cycleSummary['active_remaining_umi'], Decimal::sub(
                        (string) $cycle->cap_umi, (string) $cycle->released_umi));
            }
        }
        $withdrawals = DB::table('umi_v2_withdrawals as w')
            ->leftJoin('umi_v2_withdrawal_funding as f', 'f.withdrawal_id', '=', 'w.id')
            ->where('w.member_id', $id)->orderByDesc('w.id')->limit(30)
            ->select('w.*', 'f.from_income_umi', 'f.from_wallet_umi',
                'f.external_topup_umi', 'f.remaining_umi')->get();
        return [
            'mode' => 'sandbox', 'member' => $member, 'parent_code' => $parent,
            'direct_count' => DB::table('umi_v2_sponsor_edges')->where('parent_member_id', $id)->count(),
            'performance' => $this->teamMetrics($id),
            'cycles' => $cycles, 'cycle_summary' => $cycleSummary, 'income' => $income,
            'wallet_umi' => $this->balance(self::wallet($id)),
            'releases' => DB::table('umi_v2_release_events')->where('member_id', $id)
                ->orderByDesc('id')->limit(40)->get(),
            'transfers' => DB::table('umi_v2_income_transfers')->where('member_id', $id)
                ->orderByDesc('id')->limit(30)->get(),
            'withdrawals' => $withdrawals,
            'points' => DB::table('umi_v2_point_claims')->where('member_id', $id)
                ->orderByDesc('id')->limit(30)->get(),
            'lots' => DB::table('umi_v2_burn_lots')->where('member_id', $id)
                ->orderByDesc('id')->limit(40)->get(),
            'burn' => $this->burnSummary(),
            'quote' => (string) config('umi-v2.umi_usd_quote'),
            'stock_quote' => (string) config('umi-v2.share_usd_quote'),
            'as_of' => CarbonImmutable::now(config('umi-v2.timezone'))->format('Y-m-d H:i:s'),
        ];
    }

    public function adminDashboard(): array
    {
        $this->assertEnabled();
        $members = DB::table('umi_v2_members')->orderByDesc('id')->limit(60)->get();
        foreach ($members as $member) {
            $member->sandbox_wallet_umi = $this->balance(self::wallet((int) $member->id));
            $member->performance = $this->teamMetrics((int) $member->id);
        }
        return [
            'mode' => 'sandbox', 'members' => $members,
            'chain_settings' => $this->chainSettings(),
            'chain_settings_audit' => Schema::hasTable('umi_v2_chain_settings_audit')
                ? DB::table('umi_v2_chain_settings_audit')->orderByDesc('id')->limit(10)->get()
                : [],
            'cycles' => DB::table('umi_v2_cycles')->orderByDesc('id')->limit(40)->get(),
            'withdrawals' => DB::table('umi_v2_withdrawals as w')
                ->leftJoin('umi_v2_withdrawal_funding as f', 'f.withdrawal_id', '=', 'w.id')
                ->orderByDesc('w.id')->limit(40)
                ->select('w.*', 'f.remaining_umi', 'f.from_income_umi',
                    'f.from_wallet_umi', 'f.external_topup_umi')->get(),
            'lots' => DB::table('umi_v2_burn_lots')->orderByDesc('id')->limit(80)->get(),
            'batches' => DB::table('umi_v2_burn_batches')->orderByDesc('id')->limit(20)->get(),
            'inbounds' => DB::table('umi_v2_sandbox_entries')
                ->whereIn('kind', ['sandbox_inbound_exchange', 'sandbox_inbound_chain'])
                ->orderByDesc('id')->limit(40)->get(),
            'days' => DB::table('umi_v2_daily_runs')->orderByDesc('business_date')->limit(20)->get(),
            'burn' => $this->burnSummary(),
            'reward_inventory_umi' => $this->balance('system:reward'),
            'dedicated_umi' => $this->balance('system:dedicated'),
            'paid_umi' => $this->balance('system:paid'),
            'reconciliation' => $this->reconcile(),
            'as_of' => CarbonImmutable::now(config('umi-v2.timezone'))->format('Y-m-d H:i:s'),
        ];
    }

    public function reconcile(): array
    {
        $this->assertEnabled();
        $expected = [];
        foreach (DB::table('umi_v2_sandbox_entries')->orderBy('id')->get() as $entry) {
            $expected[$entry->from_code] = Decimal::sub($expected[$entry->from_code] ?? '0',
                (string) $entry->amount_umi);
            $expected[$entry->to_code] = Decimal::add($expected[$entry->to_code] ?? '0',
                (string) $entry->amount_umi);
        }
        $issues = [];
        foreach (DB::table('umi_v2_sandbox_accounts')->get() as $account) {
            if (Decimal::cmp((string) $account->balance_umi,
                $expected[$account->code] ?? '0') !== 0) {
                $issues[] = '账本余额不符: ' . $account->code;
            }
            unset($expected[$account->code]);
        }
        foreach ($expected as $code => $value) {
            if (Decimal::cmp($value, '0') !== 0) { $issues[] = '缺少账本账户: ' . $code; }
        }
        $pendingLots = '0'; $confirmedLots = '0'; $partialFunding = '0'; $paidOrders = '0';
        foreach (DB::table('umi_v2_burn_lots')->get() as $lot) {
            if ($lot->status === 'pending') {
                $pendingLots = Decimal::add($pendingLots, (string) $lot->amount_umi);
            } elseif ($lot->status === 'confirmed') {
                $confirmedLots = Decimal::add($confirmedLots, (string) $lot->amount_umi);
            } else { $issues[] = '未知销毁来源状态: ' . $lot->id; }
        }
        foreach (DB::table('umi_v2_withdrawal_funding')->where('remaining_umi', '>', 0)->get() as $funding) {
            $partialFunding = Decimal::add($partialFunding,
                Decimal::add((string) $funding->from_income_umi, (string) $funding->from_wallet_umi));
        }
        foreach (DB::table('umi_v2_withdrawals')->get() as $withdrawal) {
            $paidOrders = Decimal::add($paidOrders, (string) $withdrawal->paid_umi);
            if ($withdrawal->status === 'paid' &&
                Decimal::cmp((string) $withdrawal->paid_umi, (string) $withdrawal->requested_umi) !== 0) {
                $issues[] = '提现实付不等于完整 W: ' . $withdrawal->id;
            }
        }
        if (Decimal::cmp($this->balance('system:dedicated'),
            Decimal::add($pendingLots, $partialFunding)) !== 0) {
            $issues[] = '专户余额不等于待烧来源与未凑齐 B';
        }
        if (Decimal::cmp($this->balance('system:burned'), $confirmedLots) !== 0) {
            $issues[] = '模拟销毁账户不等于已确认来源';
        }
        if (Decimal::cmp($this->balance('system:paid'), $paidOrders) !== 0) {
            $issues[] = '模拟付款账户不等于实付提现';
        }
        foreach (DB::table('umi_v2_income_accounts')->get() as $income) {
            $id = (int) $income->member_id;
            foreach (['static', 'team', 'referral'] as $pocket) {
                if (Decimal::cmp((string) $income->{$pocket . '_pending'},
                    $this->balance(self::pending($id, $pocket))) !== 0) {
                    $issues[] = '收益分户与账本不符: ' . $id . '/' . $pocket;
                }
            }
            if (Decimal::cmp((string) $income->available, $this->balance(self::available($id))) !== 0 ||
                Decimal::cmp((string) $income->withdrawal_reserved, $this->balance(self::reserved($id))) !== 0) {
                $issues[] = '可提或预留与账本不符: ' . $id;
            }
        }
        return ['ok' => !$issues, 'issues' => $issues,
            'ledger_entries' => DB::table('umi_v2_sandbox_entries')->count(),
            'pending_lots_umi' => $pendingLots,
            'partial_b_umi' => $partialFunding,
            'confirmed_lots_umi' => $confirmedLots];
    }

    private function refreshRanks(): void
    {
        foreach (DB::table('umi_v2_members')->orderBy('id')->get() as $member) {
            $metrics = $this->teamMetrics((int) $member->id);
            $rank = 0;
            if (Decimal::cmp($metrics['personal_usd'], '100') >= 0) {
                foreach (self::RANK_SMALL_USD as $level => $minimum) {
                    if (Decimal::cmp($metrics['small_area_usd'], $minimum) >= 0) {
                        $rank = $level;
                    }
                }
            }
            // The local QA override is a visible floor, not historical team
            // performance. Ordinary members always rise and fall with the
            // value of their currently active qualifying cycles.
            $rank = max($rank, (int) ($member->test_level_override ?? 0));
            if ($rank !== (int) $member->level) {
                DB::table('umi_v2_members')->where('id', $member->id)->update([
                    'level' => $rank, 'updated_at' => now(),
                ]);
            }
        }
    }

    private function teamMetrics(int $memberId): array
    {
        $personal = [];
        foreach (DB::table('umi_v2_cycles')->where('status', 'active')->get() as $cycle) {
            $personal[$cycle->member_id] = Decimal::add($personal[$cycle->member_id] ?? '0',
                (string) $cycle->principal_usd);
        }
        $effective = [];
        foreach ($personal as $id => $value) {
            if (Decimal::cmp($value, '100') >= 0) { $effective[$id] = $value; }
        }
        $children = [];
        foreach (DB::table('umi_v2_sponsor_edges')->get() as $edge) {
            $children[$edge->parent_member_id][] = (int) $edge->child_member_id;
        }
        $branch = function (int $id, array $path) use (&$branch, $effective, $children): string {
            if (in_array($id, $path, true)) { throw new RuntimeException('UMI sponsor graph contains a cycle'); }
            $path[] = $id;
            $sum = $effective[$id] ?? '0';
            foreach ($children[$id] ?? [] as $child) {
                $sum = Decimal::add($sum, $branch($child, $path));
            }
            return $sum;
        };
        $team = '0'; $largest = '0'; $direct = count($children[$memberId] ?? []);
        foreach ($children[$memberId] ?? [] as $child) {
            $volume = $branch($child, [$memberId]);
            $team = Decimal::add($team, $volume);
            if (Decimal::cmp($volume, $largest) > 0) { $largest = $volume; }
        }
        return ['personal_usd' => $personal[$memberId] ?? '0',
            'team_usd' => $team, 'small_area_usd' => Decimal::sub($team, $largest),
            'direct_count' => $direct,
            'effective_direct_count' => count(array_filter($children[$memberId] ?? [],
                static fn (int $id): bool => isset($effective[$id])))];
    }

    private function burnSummary(): array
    {
        $now = CarbonImmutable::now(config('umi-v2.timezone'));
        $today = $now->toDateString();
        $cutoff = $now->subHours(24);
        $pending = '0'; $confirmed = '0'; $todayConfirmed = '0'; $past24Hours = '0';
        foreach (DB::table('umi_v2_burn_lots')->get() as $lot) {
            if ($lot->status === 'pending') { $pending = Decimal::add($pending, (string) $lot->amount_umi); }
            if ($lot->status === 'confirmed') {
                $confirmed = Decimal::add($confirmed, (string) $lot->amount_umi);
                $confirmedAt = CarbonImmutable::parse((string) $lot->confirmed_at,
                    config('app.timezone'))->setTimezone(config('umi-v2.timezone'));
                if ($confirmedAt->toDateString() === $today) {
                    $todayConfirmed = Decimal::add($todayConfirmed, (string) $lot->amount_umi);
                }
                if ($confirmedAt->greaterThan($cutoff) && !$confirmedAt->greaterThan($now)) {
                    $past24Hours = Decimal::add($past24Hours, (string) $lot->amount_umi);
                }
            }
        }
        return ['pending_umi' => $pending, 'simulated_confirmed_umi' => $confirmed,
            'today_simulated_umi' => $todayConfirmed,
            'past_24h_simulated_umi' => $past24Hours,
            'onchain_confirmed_umi' => '0'];
    }

    private function creditRelease(array $result, int $cycleId, int $memberId, string $pocket, int $actorId): void
    {
        if ($result['replayed']) { return; }
        $event = $result['event'];
        if (Decimal::cmp($event['paid'], '0') > 0) {
            $this->move('system:reward', self::pending($memberId, $pocket), $event['paid'],
                'release:' . $event['id'], 'reward_release', 'cycle:' . $cycleId, $actorId);
        }
        $injury = Decimal::sub($event['expected'], $event['paid']);
        if (Decimal::cmp($injury, '0') > 0) {
            $this->move('system:reward', 'system:dedicated', $injury,
                'injury:' . $event['id'], 'injury_to_dedicated', 'cycle:' . $cycleId, $actorId);
            $this->lot('injury:' . $event['id'], 'injury', $memberId, $cycleId, null, $injury);
        }
    }

    private function lot(string $key, string $purpose, ?int $memberId, ?int $cycleId,
        ?int $withdrawalId, string $amount): void
    {
        $claimed = '0';
        foreach (DB::table('umi_v2_burn_lots')->select('amount_umi')->get() as $existing) {
            $claimed = Decimal::add($claimed, (string) $existing->amount_umi);
        }
        $burnable = Decimal::sub((string) config('umi-v2.sandbox_initial_supply_umi'),
            (string) config('umi-v2.sandbox_terminal_floor_umi'));
        if (Decimal::cmp(Decimal::add($claimed, $amount), $burnable) > 0) {
            throw new DomainException('Sandbox 21m terminal floor would be crossed');
        }
        DB::table('umi_v2_burn_lots')->insert([
            'source_key' => $key, 'purpose' => $purpose, 'member_id' => $memberId,
            'cycle_id' => $cycleId, 'withdrawal_id' => $withdrawalId,
            'amount_umi' => $amount, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function policy(): object
    {
        $policy = DB::table('umi_v2_policy_versions')->where('policy_key', 'v2-local-acceptance')
            ->where('version', 3)->first();
        if ($policy) { return $policy; }
        $rules = ['mode' => 'sandbox', 'static_rate_min' => '0.008',
            'static_rate_max' => '0.015', 'direct_rate' => '0.1',
            'withdrawal_extra_burn' => '0.3', 'points_value_factor' => '0.1',
            'peer_rate' => '0.1', 'peer_min_level' => 4,
            'peer_base' => 'direct_child_paid_grade_only',
            'parallel_cycles' => true, 'dynamic_allocation' => 'oldest_active_first',
            'grade_base' => 'paid_static', 'grade_levels' => [
                'V1' => '0.1', 'V2' => '0.2', 'V3' => '0.3',
                'V4' => '0.4', 'V5' => '0.5', 'V6' => '0.6',
                'V7' => '0.7', 'V8' => '0.8', 'V9' => '0.9'],
            'grade_allocation' => 'incremental_ancestor_difference'];
        $json = json_encode($rules, JSON_THROW_ON_ERROR);
        DB::table('umi_v2_policy_versions')->insertOrIgnore([
            'policy_key' => 'v2-local-acceptance', 'version' => 3,
            'status' => 'approved', 'rules_json' => $json,
            'rules_sha256' => hash('sha256', $json), 'effective_at' => now(),
            'change_reason' => 'Parallel cycles, active performance and grade-only peer rewards',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('umi_v2_policy_versions')->where('policy_key', 'v2-local-acceptance')
            ->where('version', 3)->first() ?? throw new RuntimeException('UMI policy unavailable');
    }

    private function assertCommercialOpen(): void
    {
        $claimed = '0';
        foreach (DB::table('umi_v2_burn_lots')->select('amount_umi')->get() as $lot) {
            $claimed = Decimal::add($claimed, (string) $lot->amount_umi);
        }
        $remaining = Decimal::sub((string) config('umi-v2.sandbox_initial_supply_umi'), $claimed);
        if (Decimal::cmp($remaining, (string) config('umi-v2.sandbox_terminal_floor_umi')) <= 0) {
            throw new DomainException('Sandbox terminal floor reached; new business is closed');
        }
    }

    private function move(string $from, string $to, string $amount, string $key,
        string $kind, string $ref, ?int $actorId): void
    {
        if ($from === $to) { throw new DomainException('Sandbox transfer requires distinct accounts'); }
        $amount = Decimal::amount($amount, true);
        $existing = DB::table('umi_v2_sandbox_entries')->where('request_key', $key)->first();
        if ($existing) {
            if ($existing->from_code !== $from || $existing->to_code !== $to ||
                Decimal::cmp((string) $existing->amount_umi, $amount) !== 0 ||
                $existing->kind !== $kind || $existing->source_ref !== $ref) {
                throw new DomainException('Sandbox ledger key conflicts with existing transfer');
            }
            return;
        }
        $now = now()->toDateTimeString();
        foreach ([$from, $to] as $code) {
            DB::table('umi_v2_sandbox_accounts')->insertOrIgnore([
                'code' => $code, 'balance_umi' => '0', 'version_no' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $rows = DB::table('umi_v2_sandbox_accounts')->whereIn('code', [$from, $to])
            ->orderBy('code')->lockForUpdate()->get()->keyBy('code');
        $source = $rows[$from]; $target = $rows[$to];
        $afterSource = Decimal::sub((string) $source->balance_umi, $amount);
        if ($from !== 'system:issuance' && Decimal::cmp($afterSource, '0') < 0) {
            throw new DomainException('Sandbox asset source has insufficient UMI');
        }
        DB::table('umi_v2_sandbox_accounts')->where('code', $from)->update([
            'balance_umi' => $afterSource, 'version_no' => (int) $source->version_no + 1,
            'updated_at' => $now,
        ]);
        DB::table('umi_v2_sandbox_accounts')->where('code', $to)->update([
            'balance_umi' => Decimal::add((string) $target->balance_umi, $amount),
            'version_no' => (int) $target->version_no + 1, 'updated_at' => $now,
        ]);
        DB::table('umi_v2_sandbox_entries')->insert([
            'request_key' => $key, 'from_code' => $from, 'to_code' => $to,
            'amount_umi' => $amount, 'kind' => $kind,
            'source_ref' => $ref, 'actor_id' => $actorId, 'created_at' => $now,
        ]);
    }

    private function balance(string $code): string
    {
        return Decimal::display((string) (DB::table('umi_v2_sandbox_accounts')
            ->where('code', $code)->value('balance_umi') ?? '0'));
    }

    private static function wallet(int $memberId): string { return 'member:' . $memberId . ':wallet'; }
    private static function available(int $memberId): string { return 'member:' . $memberId . ':available'; }
    private static function reserved(int $memberId): string { return 'member:' . $memberId . ':reserved'; }
    private static function pending(int $memberId, string $pocket): string
    {
        return 'member:' . $memberId . ':pending:' . $pocket;
    }
}
