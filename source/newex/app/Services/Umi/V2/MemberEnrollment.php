<?php

namespace App\Services\Umi\V2;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Preserve a verified historical UMI sponsor; new accounts require a UMI-only code. */
final class MemberEnrollment
{
    public function enroll(int $userId, ?string $sponsorCode, string $key): object
    {
        return DB::transaction(fn () => $this->create($userId, trim((string) $sponsorCode), $key, []), 3);
    }

    /** Isolated simulator only; never usable with a real PostgreSQL wallet. */
    public function enrollSandbox(int $userId, ?string $sponsorCode, string $key): object
    {
        if (!app()->environment(['local','testing']) || !config('umi-v2.local_acceptance') || DB::getDriverName()!=='sqlite') {
            throw new DomainException('模拟账户仅在隔离本地环境开放。');
        }
        return DB::transaction(fn ()=>$this->create($userId,trim((string)$sponsorCode),$key,[],true),3);
    }

    public function activateHistorical(int $userId, string $key, int $actorId): object
    {
        FundedRuntime::requireEnabled();
        return DB::transaction(function () use ($userId, $key, $actorId): object {
            $prior = DB::table('umi_v2_member_activation_audit')->where('request_key', $key)->first();
            if ($prior) {
                if ((int) $prior->user_id !== $userId || (int) $prior->actor_id !== $actorId) {
                    throw new DomainException('操作编号已用于其他成员。');
                }
                return DB::table('umi_v2_members')->find($prior->member_id);
            }
            $legacy = Schema::hasTable('umi_legacy_accounts')
                ? DB::table('umi_legacy_accounts')->where('user_id', $userId)
                    ->where('activation_status', 'activated')->exists() : false;
            $business = Schema::hasTable('umi_business_accounts')
                ? DB::table('umi_business_accounts')->where('user_id', $userId)
                    ->where('fixture', false)->exists() : false;
            if (!$legacy && !$business) {
                throw new DomainException('该账户尚无已核验的原 UMI 关系。');
            }
            $member = $this->create($userId, '', 'admin:' . $key, []);
            DB::table('umi_v2_members')->where('id', $member->id)
                ->whereNull('created_by')->update(['created_by' => $actorId]);
            DB::table('umi_v2_member_activation_audit')->insert([
                'request_key' => $key, 'member_id' => $member->id, 'user_id' => $userId,
                'actor_id' => $actorId, 'basis' => $legacy ? 'legacy_account' : 'business_account',
                'created_at' => FundedTime::database(now()),
            ]);
            return DB::table('umi_v2_members')->find($member->id);
        }, 3);
    }

    private function create(int $userId, string $code, string $key, array $path, bool $sandbox=false): object
    {
        if (!$sandbox) { FundedRuntime::requireEnabled(); }
        $user=DB::table('users')->where('id',$userId)->lockForUpdate()->first();
        if (!$user || ($user->is_xn??false) || ($user->is_xm??false) || ($user->deleted??false) || ($user->deactivated??false)) {
            throw new DomainException('UMI 账户暂不可用。');
        }
        if (in_array($userId, $path, true) || count($path) > 100) {
            throw new DomainException('原 UMI 邀请关系异常，请联系运营核对。');
        }
        $existing = DB::table('umi_v2_members')->where('user_id', $userId)->lockForUpdate()->first();
        if ($existing) {
            $legacyId = Schema::hasTable('umi_legacy_accounts')
                ? DB::table('umi_legacy_accounts')->where('user_id', $userId)->value('legacy_id') : null;
            $businessId = Schema::hasTable('umi_business_accounts')
                ? DB::table('umi_business_accounts')->where('user_id', $userId)->value('id') : null;
            $this->resolveWaitingChildren((int) $existing->id, $legacyId, $businessId);
            if ($code !== '') {
                $parent = $this->resolveCode($code, $userId, [...$path, $userId],$sandbox);
                $bound = DB::table('umi_v2_sponsor_edges')->where('child_member_id', $existing->id)
                    ->value('parent_member_id');
                if (!$parent || (int) $bound !== (int) $parent->id) {
                    throw new DomainException('邀请关系已绑定，无法更改。');
                }
            }
            return $existing;
        }

        $legacy = Schema::hasTable('umi_legacy_accounts')
            ? DB::table('umi_legacy_accounts')->where('user_id', $userId)->first() : null;
        $business = Schema::hasTable('umi_business_accounts')
            ? DB::table('umi_business_accounts')->where('user_id', $userId)
                ->where('fixture', false)->first() : null;
        if ($legacy && $legacy->activation_status !== 'activated') {
            throw new DomainException('请先在原 UMI 账户完成身份激活。');
        }
        if ($legacy && $business && $business->legacy_id !== null
            && (int) $business->legacy_id !== (int) $legacy->legacy_id) {
            throw new DomainException('原 UMI 账户归属不一致，请联系运营核对。');
        }
        if ($legacy && $business && $business->parent_id) {
            $historicalParent = DB::table('umi_business_accounts')->find($business->parent_id);
            if ($historicalParent && $historicalParent->legacy_id !== null
                && (int) $historicalParent->legacy_id !== (int) $legacy->parent_legacy_id) {
                throw new DomainException('原 UMI 上级关系不一致，请联系运营核对。');
            }
        }
        $historical = $legacy || $business;
        if (!$historical && $code === '' && (!$sandbox || DB::table('umi_v2_members')->exists())) {
            throw new DomainException('请填写 UMI 专属邀请码。');
        }
        if ($historical && $code !== '') {
            throw new DomainException('原 UMI 账户沿用原邀请关系，无需填写新邀请码。');
        }

        $parent = null;
        $parentLegacyId = $legacy?->parent_legacy_id ?? $business?->legacy_parent_id;
        $parentBusinessId = $business?->parent_id;
        if ($historical) {
            $parentUserId = $parentBusinessId && Schema::hasTable('umi_business_accounts')
                ? DB::table('umi_business_accounts')->where('id', $parentBusinessId)->value('user_id') : null;
            if (!$parentUserId && $parentLegacyId && Schema::hasTable('umi_legacy_accounts')) {
                $parentUserId = DB::table('umi_legacy_accounts')->where('legacy_id', $parentLegacyId)
                    ->where('activation_status', 'activated')->value('user_id');
            }
            if ($parentUserId) {
                $parent = $this->create((int) $parentUserId, '', 'historical:' . $parentUserId,
                    [...$path, $userId],$sandbox);
            }
        } elseif ($code!=='') {
            $parent = $this->resolveCode($code, $userId, [...$path, $userId],$sandbox);
            if (!$parent) { throw new DomainException('UMI 专属邀请码无效。'); }
        }

        $memberCode = $business?->code;
        if (!$memberCode || DB::table('umi_v2_members')->where('member_code', $memberCode)->exists()) {
            do { $memberCode = 'U' . strtoupper(Str::random(12)); }
            while (DB::table('umi_v2_members')->where('member_code', $memberCode)->exists());
        }
        $identity = $legacy ? 'legacy:' . $legacy->legacy_id
            : ($business ? 'business:' . $business->id : null);
        $now = now();
        $id = DB::table('umi_v2_members')->insertGetId([
            'user_id' => $userId, 'member_code' => $memberCode,
            'legacy_identity_ref' => $identity,
            'status' => 'active', 'level' => 0, 'joined_at' => FundedTime::database($now),
            'created_at' => FundedTime::database($now), 'updated_at' => FundedTime::database($now),
        ]);
        if ($historical) {
            DB::table('umi_v2_legacy_parent_links')->insert([
                'member_id' => $id, 'parent_legacy_id' => $parentLegacyId,
                'parent_business_id' => $parentBusinessId,
                'status' => $parent ? 'resolved' : (($parentLegacyId || $parentBusinessId) ? 'pending' : 'no_parent'),
                'created_at' => FundedTime::database($now), 'resolved_at' => FundedTime::database($parent ? $now : null),
            ]);
        }
        if ($parent) { $this->attach($id, (int) $parent->id, $key, $historical ? 'historical' : 'invitation'); }
        if ($historical) { $this->resolveWaitingChildren($id, $legacy?->legacy_id, $business?->id); }
        return DB::table('umi_v2_members')->find($id);
    }

    private function resolveCode(string $code, int $userId, array $path, bool $sandbox=false): ?object
    {
        $parent = DB::table('umi_v2_members')->where('member_code', $code)->first();
        if (!$parent && Schema::hasTable('umi_v2_invite_aliases')) {
            $alias = DB::table('umi_v2_invite_aliases')->where('code', $code)->first();
            if ($alias) { $parent = DB::table('umi_v2_members')->find($alias->member_id); }
        }
        if (!$parent && Schema::hasTable('umi_business_accounts')) {
            $old = DB::table('umi_business_accounts')->where('code', $code)
                ->where('fixture', false)->first();
            if ($old && $old->user_id) {
                $parent = $this->create((int) $old->user_id, '', 'historical:' . $old->id, $path,$sandbox);
            }
        }
        if ($parent && (int) $parent->user_id === $userId) {
            throw new DomainException('不能使用自己的 UMI 邀请码。');
        }
        return $parent;
    }

    private function attach(int $childId, int $parentId, string $key, string $reason): void
    {
        if ($childId === $parentId) { throw new DomainException('UMI 邀请关系异常。'); }
        DB::table('umi_v2_sponsor_edges')->insert([
            'child_member_id' => $childId, 'parent_member_id' => $parentId,
            'source_event_id' => 'member:' . $childId . ':' . hash('sha256', $key),
            'assigned_at' => FundedTime::database(now()), 'reason' => $reason,
        ]);
    }

    private function resolveWaitingChildren(int $parentId, ?int $legacyId, ?int $businessId): void
    {
        if (!$legacyId && !$businessId) { return; }
        $links = DB::table('umi_v2_legacy_parent_links')->where('status', 'pending')
            ->where(function ($q) use ($legacyId, $businessId): void {
                if ($legacyId) { $q->orWhere('parent_legacy_id', $legacyId); }
                if ($businessId) { $q->orWhere('parent_business_id', $businessId); }
            })->lockForUpdate()->get();
        foreach ($links as $link) {
            $this->attach((int) $link->member_id, $parentId,
                'historical-parent:' . $parentId, 'historical');
            DB::table('umi_v2_legacy_parent_links')->where('member_id', $link->member_id)
                ->update(['status' => 'resolved', 'resolved_at' => FundedTime::database(now())]);
        }
    }
}
