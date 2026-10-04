<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Real-inventory-backed static, grade, direct and peer settlement. */
final class FundedSettlement
{
    public const LEVELS = [1 => '500', 2 => '3000', 3 => '10000',
        4 => '100000', 5 => '300000', 6 => '1000000',
        7 => '3000000', 8 => '5000000', 9 => '10000000'];

    public function __construct(
        private readonly FundedReadiness $gate,
        private readonly FundedWallet $wallet,
        private readonly FundedIntake $intake,
        private readonly CycleStore $cycles,
    ) {}

    /** Release a direct referral as soon as the child's activation burn is final. */
    public function settleActivatedCycle(int $childCycleId): bool
    {
        $settings = $this->gate->require('settlement');
        return DB::transaction(function () use ($childCycleId, $settings): bool {
            DB::table('umi_v2_live_settings')->where('id',1)->lockForUpdate()->first();
            $due=app(FundedCalendar::class)->status();
            if ($due['due']) throw new DomainException('请先完成欠结业务日，再处理新轮次推荐奖励。');
            $child = $this->liveCycles()->where('c.id', $childCycleId)
                ->where('c.status', 'active')->select('c.*')->lockForUpdate()->first();
            if (!$child) { return false; }
            $this->directReward($childCycleId, now(config('umi-v2.timezone'))->toDateString(),
                (int) $this->intake->policy()->id, (int) $settings->pool_user_id, null);
            if (Decimal::cmp($this->freePool((int)$settings->pool_user_id),'0')<0) throw new DomainException('资金池余额不足，推荐收益暂未入账。');
            return DB::table('umi_v2_live_quota_bonuses')
                ->where('source_cycle_id', $childCycleId)->exists();
        }, 3);
    }

    public function runDay(string $day, string $rate, int $actorId): array
    {
        $settings = $this->gate->require('settlement');
        $rate = Decimal::rate($rate);
        if (Decimal::cmp($rate, '0.008') < 0 || Decimal::cmp($rate, '0.015') > 0
            || CarbonImmutable::createFromFormat('!Y-m-d', $day)?->format('Y-m-d') !== $day) {
            throw new DomainException('结算日期或收益率无效。');
        }
        if ($day>=now(config('umi-v2.timezone'))->toDateString()) throw new DomainException('只能结算已经完整结束的业务日。');
        return DB::transaction(function () use ($day, $rate, $actorId, $settings): array {
            DB::table('umi_v2_live_settings')->where('id',1)->lockForUpdate()->first();
            $this->gate->require('settlement');
            $existing = DB::table('umi_v2_live_daily_runs')->where('business_date', $day)
                ->lockForUpdate()->first();
            if ($existing) {
                if (Decimal::cmp((string) $existing->static_rate, $rate) !== 0) {
                    throw new DomainException('该日期已按其他收益率结算。');
                }
                return json_decode((string) $existing->summary_json, true, 512, JSON_THROW_ON_ERROR)
                    + ['replayed' => true];
            }
            $calendar=app(FundedCalendar::class);
            if ($calendar->nextDay()!==$day) throw new DomainException('请先处理最早待结业务日。');
            if (DB::table('umi_v2_live_daily_runs')->where('business_date','>',$day)->exists()) throw new DomainException('发现历史日结缺口，须先按原快照核对差额，不能重算已入账收益。');
            if (Decimal::cmp($rate,$calendar->rate($day))!==0) throw new DomainException('收益率与该业务日已生效规则不一致。');
            $policy = $this->intake->policy();
            $this->refreshRanks($day,$actorId);
            foreach ($this->liveCycles()->where('c.status', 'active')
                ->where('c.starts_on', '<=', $day)->select('c.*')->orderBy('c.id')->get() as $cycle) {
                $this->directReward((int) $cycle->id, $day, (int) $policy->id,
                    (int) $settings->pool_user_id, $actorId);
            }
            $this->refreshRanks($day,$actorId);
            $static = [];
            $released = '0'; $injured = '0'; $gradeCount = 0; $peerCount = 0;
            foreach ($this->liveCycles()->where('c.status', 'active')
                ->where('c.starts_on', '<=', $day)->select('c.*')->orderBy('c.id')->get() as $cycle) {
                $key = 'live:static:' . $cycle->id . ':' . $day;
                $result = $this->cycles->releaseStatic((int) $cycle->id, $key,
                    $day, $rate, (int) $policy->id, $actorId);
                $this->reserveRelease($result, (int) $cycle->member_id,
                    (int) $cycle->id, (int) $settings->pool_user_id);
                $released = Decimal::add($released, $result['event']['paid']);
                $injured = Decimal::add($injured, Decimal::sub(
                    $result['event']['expected'], $result['event']['paid']));
                if (Decimal::cmp($result['event']['paid'], '0') > 0) {
                    $static[] = ['cycle_id' => (int) $cycle->id,
                        'member_id' => (int) $cycle->member_id,
                        'paid' => $result['event']['paid']];
                }
            }
            $parents = DB::table('umi_v2_sponsor_edges')->pluck('parent_member_id', 'child_member_id')->all();
            $levels = DB::table('umi_v2_members')->pluck('level', 'id')->all();
            foreach ($static as $source) {
                $ancestor = $parents[$source['member_id']] ?? null;
                $covered = '0'; $seen = [$source['member_id'] => true];
                while ($ancestor !== null) {
                    $ancestor = (int) $ancestor;
                    if (isset($seen[$ancestor])) { throw new RuntimeException('UMI sponsor graph contains a cycle'); }
                    $seen[$ancestor] = true;
                    $level = (int) ($levels[$ancestor] ?? 0);
                    $rateForLevel = $level ? '0.' . $level : '0';
                    if (Decimal::cmp($rateForLevel, $covered) > 0) {
                        $difference = Decimal::sub($rateForLevel, $covered);
                        $award = $this->dynamic($ancestor, 'team', $day,
                            $source['member_id'], 'grade:static:' . $source['cycle_id'] . ':' . $ancestor . ':' . $day,
                            Decimal::mul($source['paid'], $difference),
                            'grade:' . $source['cycle_id'] . ':' . $day,
                            (int) $policy->id, (int) $settings->pool_user_id, $actorId);
                        if ($award['allocated']) {
                            $released = Decimal::add($released, $award['paid']);
                            $injured = Decimal::add($injured, $award['injured']);
                            $gradeCount++;
                        }
                        $covered = $rateForLevel;
                    }
                    $ancestor = $parents[$ancestor] ?? null;
                }
            }
            foreach (DB::table('umi_v2_sponsor_edges as e')
                ->join('umi_v2_members as a', 'a.id', '=', 'e.parent_member_id')
                ->join('umi_v2_members as b', 'b.id', '=', 'e.child_member_id')
                ->where('a.level', '>=', 4)->whereColumn('b.level', '>=', 'a.level')
                ->orderBy('e.child_member_id')->select('a.id as parent_id',
                    'b.id as child_id')->get() as $edge) {
                $base = '0';
                foreach (DB::table('umi_v2_release_events')->where('member_id', $edge->child_id)
                    ->where('business_date', $day)->where('kind', 'team')->get() as $row) {
                    if (str_starts_with((string) $row->source_event_id, 'grade:static:')) {
                        $base = Decimal::add($base, (string) $row->released_umi);
                    }
                }
                if (Decimal::cmp($base, '0') <= 0) { continue; }
                $candidate = Decimal::mul($base, '0.1');
                $award = $this->dynamic((int) $edge->parent_id, 'team', $day,
                    (int) $edge->child_id, 'peer:' . $edge->child_id . ':' . $day,
                    $candidate, 'peer:' . $edge->child_id . ':' . $day,
                    (int) $policy->id, (int) $settings->pool_user_id, $actorId);
                if (!$award['allocated']) { continue; }
                DB::table('umi_v2_live_peer_sources')->insert([
                    'parent_member_id' => $edge->parent_id, 'child_member_id' => $edge->child_id,
                    'business_date' => $day, 'confirmed_grade_umi' => $base,
                    'candidate_umi' => $candidate, 'released_umi' => $award['paid'],
                    'created_at' => FundedTime::database(now()),
                ]);
                $released = Decimal::add($released, $award['paid']);
                $injured = Decimal::add($injured, $award['injured']);
                $peerCount++;
            }
            $this->refreshRanks($day,$actorId);
            if (Decimal::cmp($this->freePool((int)$settings->pool_user_id),'0')<0) throw new DomainException('资金池余额不足，本日结算暂停。');
            $summary = ['business_date' => $day, 'static_cycles' => count($static),
                'grade_rewards' => $gradeCount, 'peer_rewards' => $peerCount,
                'released_umi' => $released, 'injured_umi' => $injured];
            DB::table('umi_v2_live_daily_runs')->insert([
                'business_date' => $day, 'static_rate' => $rate,
                'policy_version_id' => $policy->id,
                'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
                'actor_id' => $actorId, 'created_at' => FundedTime::database(now()), 'updated_at' => FundedTime::database(now()),
            ]);
            return $summary + ['replayed' => false];
        }, 3);
    }

    private function directReward(int $childCycleId, string $day, int $policyId,
        int $poolId, ?int $actorId): void
    {
        $child = DB::table('umi_v2_cycles')->find($childCycleId);
        $parentId = DB::table('umi_v2_sponsor_edges')->where('child_member_id', $child->member_id)
            ->value('parent_member_id');
        if (!$parentId || DB::table('umi_v2_live_quota_bonuses')
            ->where('source_cycle_id', $childCycleId)->exists()) { return; }
        $parentCycle = $this->liveCycles()->where('c.member_id', $parentId)
            ->where('c.status', 'active')->where('c.starts_on', '<=', $day)
            ->orderBy('c.cycle_number')->select('c.*')->first();
        if (!$parentCycle) { return; }
        $bonus = Decimal::mul((string) $child->principal_umi, '0.1');
        $this->cycles->grantReferralQuota((int) $parentCycle->id,
            'live:quota:' . $childCycleId, (int) $child->member_id,
            $childCycleId, $bonus, $policyId);
        $this->dynamic((int) $parentId, 'referral', $day,
            (int) $child->member_id, 'activation:' . $childCycleId,
            $bonus, 'direct:' . $childCycleId, $policyId, $poolId, $actorId);
    }

    private function dynamic(int $memberId, string $pocket, string $day,
        int $sourceMemberId, string $sourceId, string $candidate,
        string $suffix, int $policyId, int $poolId, ?int $actorId): array
    {
        $rows = $this->liveCycles()->where('c.member_id', $memberId)
            ->where('c.status', 'active')->where('c.starts_on', '<=', $day)
            ->orderBy('c.cycle_number')->orderBy('c.id')->select('c.*')->get()
            ->filter(static fn (object $c): bool => Decimal::cmp(Decimal::sub(
                (string) $c->cap_umi, (string) $c->released_umi), '0') > 0)->values();
        $left = $candidate; $paid = '0'; $injured = '0'; $allocated = false;
        foreach ($rows as $index => $cycle) {
            if (Decimal::cmp($left, '0') <= 0) { break; }
            $capacity = Decimal::sub((string) $cycle->cap_umi, (string) $cycle->released_umi);
            $portion = $index === $rows->count() - 1 ? $left : Decimal::min($left, $capacity);
            $requestKey = 'live:' . $pocket . ':' . $cycle->id . ':' . $suffix;
            $result = $pocket === 'referral'
                ? $this->cycles->releaseDirectReferral((int) $cycle->id, $requestKey,
                    $day, $sourceMemberId, $sourceId, $portion, '1', $policyId, $actorId)
                : $this->cycles->releaseTeam((int) $cycle->id, $requestKey,
                    $day, $sourceMemberId, $sourceId, $portion, '1', $policyId, $actorId);
            $this->reserveRelease($result, $memberId, (int) $cycle->id, $poolId);
            $paid = Decimal::add($paid, $result['event']['paid']);
            $injured = Decimal::add($injured, Decimal::sub(
                $result['event']['expected'], $result['event']['paid']));
            $left = Decimal::sub($left, $portion);
            $allocated = true;
        }
        return compact('paid', 'injured', 'allocated');
    }

    private function reserveRelease(array $result, int $memberId, int $cycleId, int $poolId): void
    {
        if ($result['replayed']) { return; }
        $event = $result['event'];
        $injury = Decimal::sub($event['expected'], $event['paid']);
        if (Decimal::cmp($injury, '0') > 0) {
            $this->intake->assertBurnCapacity($injury);
            DB::table('umi_v2_live_burn_lots')->insert([
                'source_key' => 'live:injury:' . $event['id'], 'purpose' => 'injury',
                'member_id' => $memberId, 'cycle_id' => $cycleId,
                'amount_umi' => $injury, 'status' => 'pending',
                'created_at' => FundedTime::database(now()), 'updated_at' => FundedTime::database(now()),
            ]);
        }

    }

    public function freePool(int $poolId): string
    {
        $balance = $this->wallet->balance($poolId);
        foreach (DB::table('umi_v2_live_burn_lots')->whereIn('status', ['pending', 'custody'])
            ->select('amount_umi')->get() as $lot) {
            $balance = Decimal::sub($balance, (string) $lot->amount_umi);
        }
        foreach (DB::table('umi_v2_income_accounts')->get() as $income) {
            foreach (['static_pending', 'team_pending', 'referral_pending',
                'available', 'withdrawal_reserved'] as $field) {
                $balance = Decimal::sub($balance, (string) $income->{$field});
            }
        }
        return $balance;
    }

    private function refreshRanks(?string $day=null, ?int $actorId=null): void
    {
        $day ??= now(config('umi-v2.timezone'))->toDateString();
        $personal = [];
        foreach ($this->liveCycles()->where('c.status', 'active')->where('c.starts_on','<=',$day)->select('c.*')->get() as $cycle) {
            $personal[$cycle->member_id] = Decimal::add($personal[$cycle->member_id] ?? '0',
                (string) $cycle->principal_usd);
        }
        $effective = array_filter($personal,
            static fn (string $value): bool => Decimal::cmp($value, '100') >= 0);
        $children = [];
        foreach (DB::table('umi_v2_sponsor_edges')->get() as $edge) {
            $children[$edge->parent_member_id][] = (int) $edge->child_member_id;
        }
        $memo=[];
        $branch = function (int $id, array $path) use (&$branch, &$memo, $children, $effective): string {
            if (in_array($id, $path, true)) { throw new RuntimeException('UMI sponsor graph contains a cycle'); }
            if (isset($memo[$id])) return $memo[$id];
            $sum = $effective[$id] ?? '0';
            $path[] = $id;
            foreach ($children[$id] ?? [] as $child) {
                $sum = Decimal::add($sum, $branch($child, $path));
            }
            return $memo[$id]=$sum;
        };
        $basisHash=hash('sha256',json_encode(['personal'=>$personal,'children'=>$children],JSON_THROW_ON_ERROR));
        foreach (DB::table('umi_v2_members')->get() as $member) {
            $team = '0'; $largest = '0';
            foreach ($children[$member->id] ?? [] as $child) {
                $volume = $branch($child, [(int) $member->id]);
                $team = Decimal::add($team, $volume);
                if (Decimal::cmp($volume, $largest) > 0) { $largest = $volume; }
            }
            $small = Decimal::sub($team, $largest);
            $level = 0;
            if (Decimal::cmp($personal[$member->id] ?? '0', '100') >= 0) {
                foreach (self::LEVELS as $candidate => $minimum) {
                    if (Decimal::cmp($small, $minimum) >= 0) { $level = $candidate; }
                }
            }
            if ((int) $member->level !== $level) {
                DB::table('umi_v2_rank_audit')->insert(['member_id'=>$member->id,'business_date'=>$day,
                    'before_level'=>$member->level,'after_level'=>$level,'personal_usdt'=>$personal[$member->id]??'0','small_area_usdt'=>$small,
                    'basis_sha256'=>$basisHash,
                    'actor_id'=>$actorId,'created_at'=>FundedTime::database(now())]);
                DB::table('umi_v2_members')->where('id', $member->id)->update([
                    'level' => $level, 'updated_at' => FundedTime::database(now()),
                ]);
            }
        }
    }

    private function liveCycles(): \Illuminate\Database\Query\Builder
    {
        return DB::table('umi_v2_cycles as c')->join('umi_v2_live_intents as i',
            'i.cycle_id', '=', 'c.id');
    }
}
