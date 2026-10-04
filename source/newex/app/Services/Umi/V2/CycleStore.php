<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Cycle;
use App\Domain\Umi\V2\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transactional projection of the isolated v2 cycle domain. No route invokes
 * this yet; activation, source eligibility and funding must be verified by a
 * separate application workflow before it is exposed to users.
 */
final class CycleStore
{
    public function releaseStatic(
        int $cycleId,
        string $requestKey,
        string $day,
        mixed $rate,
        int $policyVersionId,
        ?int $actorId = null,
    ): array {
        return $this->release($cycleId, $requestKey, $policyVersionId, null, $actorId,
            static fn (Cycle $cycle): array => $cycle->releaseStatic($requestKey, $day, $rate, (string) $policyVersionId));
    }

    public function releaseTeam(
        int $cycleId,
        string $requestKey,
        string $day,
        int $sourceMemberId,
        string $sourceEventId,
        mixed $base,
        mixed $differentialRate,
        int $policyVersionId,
        ?int $actorId = null,
    ): array {
        return $this->release($cycleId, $requestKey, $policyVersionId, $sourceMemberId, $actorId,
            static fn (Cycle $cycle): array => $cycle->releaseTeam($requestKey, $day, (string) $sourceMemberId,
                $sourceEventId, $base, $differentialRate, (string) $policyVersionId));
    }

    public function releaseDirectReferral(
        int $cycleId,
        string $requestKey,
        string $day,
        int $sourceMemberId,
        string $sourcePurchaseId,
        mixed $base,
        mixed $rate,
        int $policyVersionId,
        ?int $actorId = null,
    ): array {
        return $this->release($cycleId, $requestKey, $policyVersionId, $sourceMemberId, $actorId,
            static fn (Cycle $cycle): array => $cycle->releaseDirectReferral($requestKey, $day, (string) $sourceMemberId,
                $sourcePurchaseId, $base, $rate, (string) $policyVersionId));
    }

    public function grantReferralQuota(int $cycleId, string $requestKey,
        int $sourceMemberId, int $sourceCycleId, string $amount,
        int $policyVersionId): array
    {
        return DB::transaction(function () use ($cycleId, $requestKey, $sourceMemberId,
            $sourceCycleId, $amount, $policyVersionId): array {
            [$row, $cycle] = $this->lockedCycle($cycleId);
            [$next, $event] = $cycle->grantReferralQuota($requestKey,
                (string) $sourceMemberId, 'activation:' . $sourceCycleId,
                $amount, (string) $policyVersionId);
            $existing = DB::table('umi_v2_live_quota_bonuses')->where('request_key', $requestKey)->first();
            if ($existing) {
                if ((int) $existing->cycle_id !== $cycleId ||
                    !hash_equals((string) $existing->event_sha256, self::hash($event))) {
                    throw new RuntimeException('UMI referral quota bonus differs from saved event');
                }
                return $this->result($cycle, $event, (int) $row->version_no, true);
            }
            if ($row->status !== 'active' || $sourceMemberId === (int) $row->member_id
                || DB::table('umi_v2_release_events')->where('request_key', $requestKey)->exists()) {
                throw new DomainException('UMI referral quota bonus is not eligible');
            }
            $now = now()->toDateTimeString();
            DB::table('umi_v2_live_quota_bonuses')->insert([
                'cycle_id' => $cycleId, 'source_member_id' => $sourceMemberId,
                'source_cycle_id' => $sourceCycleId, 'request_key' => $requestKey,
                'added_quota_umi' => $amount, 'event_sha256' => self::hash($event),
                'created_at' => FundedTime::database($now),
            ]);
            $this->updateCycle($row, $next, null, $now);
            return $this->result($next, $event, (int) $row->version_no + 1, false);
        }, 3);
    }

    public function transfer(
        int $cycleId,
        string $requestKey,
        string $pocket,
        mixed $amount,
        ?int $actorId = null,
    ): array {
        $kind = self::domainKind($pocket);
        return DB::transaction(function () use ($cycleId, $requestKey, $pocket, $kind, $amount, $actorId): array {
            [$row, $cycle] = $this->lockedCycle($cycleId);
            [$next, $event] = $cycle->transferToWithdrawable($requestKey, $kind, $amount);
            $existing = DB::table('umi_v2_income_transfers')->where('request_key', $requestKey)->first();
            if ($existing) {
                $this->assertExistingEvent($cycle, $requestKey, (int) $existing->member_id, (int) $row->member_id);
                if ($event['type'] !== 'transfer' || $existing->pocket !== $pocket ||
                    Decimal::cmp((string) $existing->amount_umi, $event['amount']) !== 0) {
                    throw new RuntimeException('Persisted UMI transfer differs from cycle event');
                }
                return $this->result($cycle, $event, (int) $row->version_no, true);
            }
            $this->assertFreshEvent($cycle, $requestKey, 'umi_v2_release_events');
            if ($row->status === 'pending_burn') {
                throw new DomainException('Cycle burn is not confirmed');
            }

            $account = $this->lockedIncomeAccount((int) $row->member_id);
            $pendingColumn = $pocket . '_pending';
            $pendingBefore = (string) $account->{$pendingColumn};
            if (Decimal::cmp($pendingBefore, $event['amount']) < 0) {
                throw new RuntimeException('Member pending income is below cycle transfer amount');
            }
            $pendingAfter = Decimal::sub($pendingBefore, $event['amount']);
            $availableBefore = (string) $account->available;
            $availableAfter = Decimal::add($availableBefore, $event['amount']);
            $now = now()->toDateTimeString();
            $transferId = DB::table('umi_v2_income_transfers')->insertGetId([
                'member_id' => $row->member_id, 'request_key' => $requestKey,
                'pocket' => $pocket, 'from_bucket' => 'pending', 'to_bucket' => 'available',
                'amount_umi' => $event['amount'], 'pending_before_umi' => $pendingBefore,
                'pending_after_umi' => $pendingAfter, 'available_before_umi' => $availableBefore,
                'available_after_umi' => $availableAfter, 'created_by' => $actorId,
                'created_at' => FundedTime::database($now),
            ]);
            $this->allocateTransfer($cycleId, $cycle, $pocket, $event['amount'], $transferId, $now);
            $this->updateIncomeAccount($account, [$pendingColumn => $pendingAfter, 'available' => $availableAfter], $now);
            $this->updateCycle($row, $next, null, $now);
            return $this->result($next, $event, (int) $row->version_no + 1, false);
        }, 3);
    }

    private function release(
        int $cycleId,
        string $requestKey,
        int $policyVersionId,
        ?int $sourceMemberId,
        ?int $actorId,
        callable $apply,
    ): array {
        return DB::transaction(function () use ($cycleId, $requestKey, $policyVersionId, $sourceMemberId, $actorId, $apply): array {
            [$row, $cycle] = $this->lockedCycle($cycleId);
            [$next, $event] = $apply($cycle);
            $kind = self::pocketForKind($event['kind']);
            $existing = DB::table('umi_v2_release_events')->where('request_key', $requestKey)->first();
            if ($existing) {
                $this->assertExistingEvent($cycle, $requestKey, (int) $existing->member_id, (int) $row->member_id);
                if ((int) $existing->cycle_id !== $cycleId || $existing->kind !== $kind ||
                    !hash_equals((string) $existing->calculation_sha256, self::hash($event))) {
                    throw new RuntimeException('Persisted UMI release differs from cycle event');
                }
                return $this->result($cycle, $event, (int) $row->version_no, true);
            }
            $this->assertFreshEvent($cycle, $requestKey, 'umi_v2_income_transfers');
            if ($row->status !== 'active') {
                throw new DomainException('Cycle is not active for release');
            }
            if ($sourceMemberId !== null && $sourceMemberId === (int) $row->member_id) {
                throw new DomainException('Dynamic source cannot be this member');
            }

            $account = $this->lockedIncomeAccount((int) $row->member_id);
            $pendingColumn = $kind . '_pending';
            $pendingAfter = Decimal::add((string) $account->{$pendingColumn}, $event['paid']);
            $now = now()->toDateTimeString();
            DB::table('umi_v2_release_events')->insert([
                'cycle_id' => $cycleId, 'member_id' => $row->member_id,
                'policy_version_id' => $policyVersionId,
                'source_member_id' => $sourceMemberId, 'kind' => $kind,
                'business_date' => $event['day'],
                'source_event_id' => $event['source_event_id'] ?? $requestKey,
                'request_key' => $requestKey, 'candidate_umi' => $event['expected'],
                'released_umi' => $event['paid'],
                'cap_before_umi' => Decimal::sub($cycle->effectiveQuota(), $event['quota_before']),
                'cap_after_umi' => Decimal::sub($cycle->effectiveQuota(), $event['quota_after']),
                'status' => 'posted', 'calculation_sha256' => self::hash($event),
                'created_by' => $actorId, 'created_at' => FundedTime::database($now),
            ]);
            $this->updateIncomeAccount($account, [$pendingColumn => $pendingAfter], $now);
            $this->updateCycle($row, $next, $event['day'], $now);
            return $this->result($next, $event, (int) $row->version_no + 1, false);
        }, 3);
    }

    private function lockedCycle(int $cycleId): array
    {
        $row = DB::table('umi_v2_cycles')->where('id', $cycleId)->lockForUpdate()->first();
        if (!$row) {
            throw new DomainException('UMI cycle does not exist');
        }
        if ($row->snapshot_json === null) {
            if ($row->snapshot_sha256 !== null || (int) $row->version_no !== 0 ||
                Decimal::cmp((string) $row->released_umi, '0') !== 0) {
                throw new RuntimeException('Uninitialized UMI cycle has financial state');
            }
            $cycle = Cycle::open((string) $row->id, (string) $row->member_id,
                (string) $row->principal_umi, (string) $row->usd_quote_per_umi,
                (string) $row->policy_version_id);
            $cycle = Cycle::restore($cycle->snapshot());
        } else {
            if (!$row->snapshot_sha256 || !hash_equals((string) $row->snapshot_sha256, hash('sha256', (string) $row->snapshot_json))) {
                throw new RuntimeException('UMI cycle snapshot checksum mismatch');
            }
            $cycle = Cycle::restore(json_decode((string) $row->snapshot_json, true, 512, JSON_THROW_ON_ERROR));
        }
        $this->assertCycleProjection($row, $cycle);
        return [$row, $cycle];
    }

    private function assertCycleProjection(object $row, Cycle $cycle): void
    {
        $fields = [
            'principal_umi' => $cycle->principal, 'usd_quote_per_umi' => $cycle->priceUsdt,
            'principal_usd' => $cycle->valueUsdt, 'cap_umi' => $cycle->effectiveQuota(),
            'released_umi' => $cycle->used(),
        ];
        $totals = ['static' => '0', 'team' => '0', 'referral' => '0'];
        foreach ($cycle->snapshot()['events'] as $event) {
            if ($event['type'] === 'release') {
                $pocket = self::pocketForKind($event['kind']);
                $totals[$pocket] = Decimal::add($totals[$pocket], $event['paid']);
            }
        }
        foreach ($totals as $pocket => $amount) {
            $fields[$pocket . '_released_umi'] = $amount;
        }
        foreach ($fields as $column => $expected) {
            if (Decimal::cmp((string) $row->{$column}, $expected) !== 0) {
                throw new RuntimeException('UMI cycle projection mismatch: ' . $column);
            }
        }
        if ((int) $row->member_id !== (int) $cycle->accountId ||
            (int) $row->policy_version_id !== (int) $cycle->openingRuleId ||
            (int) $row->multiplier !== $cycle->multiple ||
            ($row->status === 'completed') !== $cycle->complete()) {
            throw new RuntimeException('UMI cycle identity or completion mismatch');
        }
    }

    private function lockedIncomeAccount(int $memberId): object
    {
        $now = now()->toDateTimeString();
        DB::table('umi_v2_income_accounts')->insertOrIgnore([
            'member_id' => $memberId, 'created_at' => FundedTime::database($now), 'updated_at' => FundedTime::database($now),
        ]);
        return DB::table('umi_v2_income_accounts')->where('member_id', $memberId)->lockForUpdate()->first()
            ?? throw new RuntimeException('UMI income account is missing');
    }

    private function updateIncomeAccount(object $account, array $changes, string $now): void
    {
        $updated = DB::table('umi_v2_income_accounts')
            ->where('member_id', $account->member_id)
            ->where('version_no', $account->version_no)
            ->update($changes + ['version_no' => (int) $account->version_no + 1, 'updated_at' => FundedTime::database($now)]);
        if ($updated !== 1) {
            throw new RuntimeException('UMI income account concurrent update');
        }
    }

    private function updateCycle(object $row, Cycle $cycle, ?string $day, string $now): void
    {
        $snapshot = self::json($cycle->snapshot());
        $totals = ['static' => '0', 'team' => '0', 'referral' => '0'];
        foreach ($cycle->snapshot()['events'] as $event) {
            if ($event['type'] === 'release') {
                $pocket = self::pocketForKind($event['kind']);
                $totals[$pocket] = Decimal::add($totals[$pocket], $event['paid']);
            }
        }
        $changes = [
            'snapshot_json' => $snapshot, 'snapshot_sha256' => hash('sha256', $snapshot),
            'version_no' => (int) $row->version_no + 1,
            'cap_umi' => $cycle->effectiveQuota(),
            'released_umi' => $cycle->used(),
            'static_released_umi' => $totals['static'],
            'team_released_umi' => $totals['team'],
            'referral_released_umi' => $totals['referral'],
            'status' => $cycle->complete() ? 'completed' : 'active',
            'updated_at' => FundedTime::database($now),
        ];
        if ($cycle->complete() && $row->completed_at === null) {
            $changes['completed_at'] = $now;
        }
        if ($day !== null && ($row->last_settled_on === null || $day > $row->last_settled_on)) {
            $changes['last_settled_on'] = $day;
        }
        $updated = DB::table('umi_v2_cycles')->where('id', $row->id)
            ->where('version_no', $row->version_no)->update($changes);
        if ($updated !== 1) {
            throw new RuntimeException('UMI cycle concurrent update');
        }
    }

    /** Allocate a member's transfer to this cycle's untransferred releases, FIFO. */
    private function allocateTransfer(int $cycleId, Cycle $before, string $pocket, string $amount, int $transferId, string $now): void
    {
        $releases = DB::table('umi_v2_release_events')->where('cycle_id', $cycleId)
            ->where('kind', $pocket)->orderBy('id')->get();
        $ids = $releases->pluck('id')->all();
        $allocated = [];
        if ($ids) {
            foreach (DB::table('umi_v2_income_transfer_allocations')->whereIn('release_event_id', $ids)->get() as $row) {
                $allocated[$row->release_event_id] = Decimal::add($allocated[$row->release_event_id] ?? '0', (string) $row->amount_umi);
            }
        }
        $outstanding = '0';
        foreach ($releases as $release) {
            $left = Decimal::sub((string) $release->released_umi, $allocated[$release->id] ?? '0');
            if (Decimal::cmp($left, '0') < 0) {
                throw new RuntimeException('UMI release was over-allocated');
            }
            $outstanding = Decimal::add($outstanding, $left);
        }
        if (Decimal::cmp($outstanding, $before->pocket(self::domainKind($pocket))) !== 0) {
            throw new RuntimeException('UMI release allocation does not match cycle pocket');
        }
        $remaining = $amount;
        foreach ($releases as $release) {
            if (Decimal::cmp($remaining, '0') === 0) {
                break;
            }
            $left = Decimal::sub((string) $release->released_umi, $allocated[$release->id] ?? '0');
            $take = Decimal::min($left, $remaining);
            if (Decimal::cmp($take, '0') > 0) {
                DB::table('umi_v2_income_transfer_allocations')->insert([
                    'transfer_id' => $transferId, 'release_event_id' => $release->id,
                    'amount_umi' => $take, 'created_at' => FundedTime::database($now),
                ]);
                $remaining = Decimal::sub($remaining, $take);
            }
        }
        if (Decimal::cmp($remaining, '0') !== 0) {
            throw new RuntimeException('UMI transfer could not be allocated to releases');
        }
    }

    private function assertExistingEvent(Cycle $cycle, string $requestKey, int $eventMemberId, int $memberId): void
    {
        if ($eventMemberId !== $memberId || $cycle->event($requestKey) === null) {
            throw new RuntimeException('UMI request key belongs to another event or account');
        }
    }

    private function assertFreshEvent(Cycle $cycle, string $requestKey, string $otherTable): void
    {
        if ($cycle->event($requestKey) !== null || DB::table($otherTable)->where('request_key', $requestKey)->exists()) {
            throw new RuntimeException('UMI request key conflicts with an existing event');
        }
    }

    private function result(Cycle $cycle, array $event, int $version, bool $replayed): array
    {
        return ['replayed' => $replayed, 'event' => $event,
            'cycle' => $cycle->snapshot(), 'version_no' => $version];
    }

    private static function pocketForKind(string $kind): string
    {
        return match ($kind) {
            Cycle::STATIC => 'static', Cycle::TEAM_ACCELERATION => 'team',
            Cycle::DIRECT_REFERRAL => 'referral',
            default => throw new DomainException('Unsupported UMI release kind'),
        };
    }

    private static function domainKind(string $pocket): string
    {
        return match ($pocket) {
            'static' => Cycle::STATIC, 'team' => Cycle::TEAM_ACCELERATION,
            'referral' => Cycle::DIRECT_REFERRAL,
            default => throw new DomainException('Unsupported UMI income pocket'),
        };
    }

    private static function hash(array $event): string { return hash('sha256', self::json($event)); }
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
