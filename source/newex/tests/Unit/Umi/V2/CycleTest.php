<?php

namespace Tests\Unit\Umi\V2;

use App\Domain\Umi\V2\Cycle;
use App\Domain\Umi\V2\TierPolicy;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CycleTest extends TestCase
{
    private function cycle(string $principal = '100', string $price = '1'): Cycle
    {
        return Cycle::open('cycle-1', 'account-1', $principal, $price, 'rules-1');
    }

    public function test_tier_boundaries_are_exact_and_purchase_value_is_frozen(): void
    {
        $this->assertSame(3, TierPolicy::multipleForUsdValue('100'));
        $this->assertSame(3, TierPolicy::multipleForUsdValue('1999.999999999999999999999999'));
        $this->assertSame(4, TierPolicy::multipleForUsdValue('2000'));
        $this->assertSame(4, TierPolicy::multipleForUsdValue('4999.999999999999999999999999'));
        $this->assertSame(5, TierPolicy::multipleForUsdValue('5000'));
        $cycle = $this->cycle('1000', '2');
        $this->assertSame('2000', $cycle->valueUsdt);
        $this->assertSame(4, $cycle->multiple);
        $this->assertSame('4000', $cycle->quota);
        $this->expectException(InvalidArgumentException::class);
        TierPolicy::multipleForUsdValue('99.999');
    }

    public function test_static_uses_original_principal_and_applied_day_rate(): void
    {
        $cycle = $this->cycle('1000');
        [$day1, $first] = $cycle->releaseStatic('static-1', '2026-09-30', '0.008', 'rules-1');
        [$day2, $second] = $day1->releaseStatic('static-2', '2026-10-01', '0.01', 'rules-2');
        $this->assertSame('8', $first['paid']);
        $this->assertSame('10', $second['paid']);
        $this->assertSame('3000', $cycle->quota);
        $this->assertSame('18', $day2->used());
        $this->assertSame('18', $day2->pocket(Cycle::STATIC));
        $this->assertSame('0', $cycle->used());
        $this->assertSame('rules-2', $second['rule_id']);
    }

    public function test_quota_and_today_total_derive_from_distinct_synthetic_events(): void
    {
        $cycle = $this->cycle('5400');
        [$cycle] = $cycle->releaseTeam('earlier-team', '2026-09-29', 'child-1', 'child-release-1', '31500', '0.1', 'rules-1');
        [$cycle, $static] = $cycle->releaseStatic('static-today', '2026-09-30', '0.008', 'rules-1');
        [$cycle, $team] = $cycle->releaseTeam('team-today', '2026-09-30', 'child-1', 'child-release-2', '23.3', '0.1', 'rules-1');
        $this->assertSame('43.2', $static['paid']);
        $this->assertSame('2.33', $team['paid']);
        $this->assertSame('27000', $cycle->quota);
        $this->assertSame('3195.53', $cycle->used());
        $this->assertSame('23804.47', $cycle->remaining());
        $this->assertSame('45.53', $cycle->releasedOn('2026-09-30'));
        $this->assertSame('43.2', $cycle->pocket(Cycle::STATIC));
        $this->assertSame('3152.33', $cycle->pocket(Cycle::TEAM_ACCELERATION));
    }

    public function test_direct_reward_accelerates_same_quota_without_adding_capacity(): void
    {
        $cycle = $this->cycle();
        [$cycle, $referral] = $cycle->releaseDirectReferral('direct-1', '2026-09-30', 'child-1', 'purchase-1', '100', '0.1', 'rules-1');
        [$cycle, $static] = $cycle->releaseStatic('static-1', '2026-09-30', '0.01', 'rules-1');
        $this->assertSame('10', $referral['paid']);
        $this->assertSame('1', $static['paid']);
        $this->assertSame('300', $cycle->quota);
        $this->assertSame('11', $cycle->used());
        $this->assertSame('289', $cycle->remaining());
        $this->assertSame('10', $cycle->pocket(Cycle::DIRECT_REFERRAL));
        $this->assertSame('11', $cycle->releasedOn('2026-09-30'));
    }

    public function test_new_funded_referral_bonus_adds_capacity_and_replays_exactly(): void
    {
        $cycle = $this->cycle();
        [$cycle, $bonus] = $cycle->grantReferralQuota('bonus-1', 'child-1',
            'activation:12', '10', 'funded-1');
        [$cycle, $reward] = $cycle->releaseDirectReferral('direct-1',
            '2026-10-01', 'child-1', 'activation:12', '10', '1', 'funded-1');
        $this->assertSame('300', $cycle->quota);
        $this->assertSame('310', $cycle->effectiveQuota());
        $this->assertSame('10', $bonus['amount']);
        $this->assertSame('10', $reward['paid']);
        $this->assertSame('300', $cycle->remaining());
        $this->assertSame($cycle->snapshot(), Cycle::restore($cycle->snapshot())->snapshot());
        $this->expectException(DomainException::class);
        $cycle->grantReferralQuota('bonus-2', 'child-1', 'activation:12', '10', 'funded-1');
    }

    public function test_static_is_truncated_by_dynamic_usage_and_cycle_completes_once(): void
    {
        $cycle = $this->cycle();
        [$cycle] = $cycle->releaseTeam('team-1', '2026-09-30', 'child-1', 'release-1', '2990', '0.1', 'rules-1');
        [$cycle, $static] = $cycle->releaseStatic('static-1', '2026-09-30', '0.02', 'rules-1');
        $this->assertSame('2', $static['expected']);
        $this->assertSame('1', $static['paid']);
        $this->assertSame('QUOTA_LIMITED', $static['reason']);
        $this->assertTrue($cycle->complete());
        $this->assertSame('0', $cycle->remaining());
        $this->expectException(DomainException::class);
        $cycle->releaseStatic('static-2', '2026-10-01', '0.02', 'rules-1');
    }

    public function test_each_pocket_transfers_independently_without_reusing_quota(): void
    {
        $cycle = $this->cycle();
        [$cycle] = $cycle->releaseStatic('static-1', '2026-09-30', '0.01', 'rules-1');
        [$cycle] = $cycle->releaseTeam('team-1', '2026-09-30', 'child-1', 'release-1', '20', '0.1', 'rules-1');
        [$cycle] = $cycle->releaseDirectReferral('direct-1', '2026-09-30', 'child-1', 'purchase-1', '30', '0.1', 'rules-1');
        $used = $cycle->used();
        [$cycle, $transfer] = $cycle->transferToWithdrawable('transfer-1', Cycle::STATIC, '0.4');
        [$cycle] = $cycle->transferToWithdrawable('transfer-2', Cycle::TEAM_ACCELERATION, '2');
        [$cycle] = $cycle->transferToWithdrawable('transfer-3', Cycle::DIRECT_REFERRAL, '3');
        $this->assertSame('0.6', $cycle->pocket(Cycle::STATIC));
        $this->assertSame('0', $cycle->pocket(Cycle::TEAM_ACCELERATION));
        $this->assertSame('0', $cycle->pocket(Cycle::DIRECT_REFERRAL));
        $this->assertSame('5.4', $cycle->withdrawable());
        $this->assertSame($used, $cycle->used());
        $this->assertSame($used, $transfer['quota_before']);
        $this->assertSame($used, $transfer['quota_after']);
        $this->expectException(DomainException::class);
        $cycle->transferToWithdrawable('transfer-4', Cycle::STATIC, '0.7');
    }

    public function test_replay_and_source_keys_prevent_double_release_or_transfer(): void
    {
        $cycle = $this->cycle();
        [$next, $event] = $cycle->releaseDirectReferral('direct-1', '2026-09-30', 'child-1', 'purchase-1', '100', '0.1', 'rules-1');
        [$replay, $same] = $next->releaseDirectReferral('direct-1', '2026-09-30', 'child-1', 'purchase-1', '100.0', '0.10', 'rules-1');
        $this->assertSame($next, $replay);
        $this->assertSame($event, $same);
        $this->assertSame('10', $replay->used());
        try {
            $next->releaseDirectReferral('direct-2', '2026-09-30', 'child-1', 'purchase-1', '100', '0.1', 'rules-1');
            $this->fail('Same purchase must not be rewarded twice');
        } catch (DomainException $expected) {
            $this->assertSame('Source event was already rewarded for this kind', $expected->getMessage());
        }
        [$next, $transfer] = $next->transferToWithdrawable('transfer-1', Cycle::DIRECT_REFERRAL, '4');
        [$replay, $same] = $next->transferToWithdrawable('transfer-1', Cycle::DIRECT_REFERRAL, '4.0');
        $this->assertSame($next, $replay);
        $this->assertSame($transfer, $same);
        $this->assertSame('4', $next->withdrawable());
    }

    public function test_reused_event_id_with_different_input_is_rejected(): void
    {
        [$cycle] = $this->cycle()->releaseStatic('static-1', '2026-09-30', '0.01', 'rules-1');
        $this->expectException(DomainException::class);
        $cycle->releaseStatic('static-1', '2026-09-30', '0.02', 'rules-1');
    }

    public function test_second_static_event_for_same_day_is_rejected(): void
    {
        [$cycle] = $this->cycle()->releaseStatic('static-1', '2026-09-30', '0.01', 'rules-1');
        $this->expectException(DomainException::class);
        $cycle->releaseStatic('static-2', '2026-09-30', '0.01', 'rules-1');
    }

    public function test_decimal_inputs_never_accept_float_or_exponent_notation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cycle('1e3');
    }

    public function test_persisted_cycle_replays_to_identical_state_and_rejects_changed_summary(): void
    {
        $cycle = $this->cycle();
        [$cycle] = $cycle->releaseStatic('static-1', '2026-09-30', '0.01', 'rules-1');
        [$cycle] = $cycle->releaseDirectReferral('direct-1', '2026-09-30', 'child-1', 'purchase-1', '100', '0.1', 'rules-1');
        [$cycle] = $cycle->transferToWithdrawable('transfer-1', Cycle::DIRECT_REFERRAL, '3');
        $snapshot = $cycle->snapshot();
        $this->assertSame($snapshot, Cycle::restore($snapshot)->snapshot());
        $reordered = array_reverse($snapshot, true);
        $reordered['events'][0] = array_reverse($reordered['events'][0], true);
        $this->assertSame($snapshot, Cycle::restore($reordered)->snapshot());
        $snapshot['used'] = '10';
        $this->expectException(DomainException::class);
        Cycle::restore($snapshot);
    }

    public function test_persisted_cycle_rejects_modified_event_amount(): void
    {
        [$cycle] = $this->cycle()->releaseStatic('static-1', '2026-09-30', '0.01', 'rules-1');
        $snapshot = $cycle->snapshot();
        $snapshot['events'][0]['paid'] = '100';
        $this->expectException(DomainException::class);
        Cycle::restore($snapshot);
    }

    public function test_transfer_replay_cannot_change_amount_or_pocket(): void
    {
        [$cycle] = $this->cycle()->releaseStatic('static-1', '2026-09-30', '0.01', 'rules-1');
        [$cycle] = $cycle->transferToWithdrawable('transfer-1', Cycle::STATIC, '0.5');
        $this->expectException(DomainException::class);
        $cycle->transferToWithdrawable('transfer-1', Cycle::STATIC, '0.6');
    }

    public function test_completed_cycle_still_allows_transfer_of_previously_released_income(): void
    {
        $cycle = $this->cycle();
        [$cycle] = $cycle->releaseTeam('team-1', '2026-09-30', 'child-1', 'release-1', '3000', '0.1', 'rules-1');
        $this->assertTrue($cycle->complete());
        [$cycle] = $cycle->transferToWithdrawable('transfer-1', Cycle::TEAM_ACCELERATION, '30');
        $this->assertSame('300', $cycle->used());
        $this->assertSame('270', $cycle->pocket(Cycle::TEAM_ACCELERATION));
        $this->assertSame('30', $cycle->withdrawable());
    }
}
