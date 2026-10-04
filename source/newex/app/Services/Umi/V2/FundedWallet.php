<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;

use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Moves existing Deepro UMI, never mints an internal test balance. Caller owns the DB transaction. */
final class FundedWallet
{
    public function currencyId(): int
    {
        $rows = DB::table('currencies')->where('symbol', 'UMI')->whereNull('deleted_at')->get();
        if ($rows->count() !== 1 || strcasecmp((string) $rows[0]->bep_contract,
            (string) config('umi.asset.contract')) !== 0) {
            throw new DomainException('UMI 资产暂不可用，请稍后再试。');
        }
        return (int) $rows[0]->id;
    }

    public function networkId(): int
    {
        $ids = DB::table('currency_networks as cn')->join('networks as n', 'n.id', '=', 'cn.network_id')
            ->where('cn.currency_id', $this->currencyId())->where('n.slug', 'bep20')
            ->where('n.status', true)->pluck('n.id');
        if ($ids->count() !== 1) throw new DomainException('Deepro UMI 的 BSC 网络配置暂不可用。');
        return (int) $ids[0];
    }

    public function balance(int $userId, string $bucket = 'wallet'): string
    {
        return (string) $this->wallet($userId)->{$this->field($bucket)};
    }

    public function move(?int $fromUserId, ?int $toUserId, string $amount,
        string $key, string $purpose, string $sourceRef, ?int $memberId = null,
        string $fromBucket = 'wallet', string $toBucket = 'wallet'): void
    {
        $fromField = $this->field($fromBucket);
        $toField = $this->field($toBucket);
        $amount = Decimal::amount($amount, true);
        if ($fromUserId === $toUserId || ($fromUserId === null && $toUserId === null)
            || Decimal::cmp($amount, bcadd($amount, '0', 18)) !== 0) {
            throw new DomainException('UMI 划转数量或账户无效。');
        }
        $existing = DB::table('umi_v2_live_wallet_moves')->where('request_key', $key)->first();
        if ($existing) {
            if ((int) $existing->from_user_id !== (int) $fromUserId ||
                (int) $existing->to_user_id !== (int) $toUserId ||
                Decimal::cmp((string) $existing->amount_umi, $amount) !== 0 ||
                $existing->purpose !== $purpose || $existing->source_ref !== $sourceRef ||
                (int) $existing->member_id !== (int) $memberId || $existing->from_bucket !== $fromBucket
                || $existing->to_bucket !== $toBucket) {
                throw new DomainException('该笔划转编号已用于其他操作。');
            }
            return;
        }
        $ids = array_values(array_unique(array_filter([$fromUserId, $toUserId],
            static fn ($id): bool => $id !== null)));
        sort($ids, SORT_NUMERIC);
        $wallets = [];
        foreach ($ids as $id) { $wallets[$id] = $this->wallet($id, true); }
        if ($fromUserId !== null && Decimal::cmp((string) $wallets[$fromUserId]->{$fromField}, $amount) < 0) {
            throw new DomainException('UMI 余额不足。');
        }
        $now = now();
        if ($fromUserId !== null) {
            DB::table('wallets')->where('id', $wallets[$fromUserId]->id)->update([
                $fromField => Decimal::sub((string) $wallets[$fromUserId]->{$fromField}, $amount),
                'updated_at' => FundedTime::database($now),
            ]);
        }
        if ($toUserId !== null) {
            DB::table('wallets')->where('id', $wallets[$toUserId]->id)->update([
                $toField => Decimal::add((string) $wallets[$toUserId]->{$toField}, $amount),
                'updated_at' => FundedTime::database($now),
            ]);
        }
        DB::table('umi_v2_live_wallet_moves')->insert([
            'request_key' => $key, 'purpose' => $purpose, 'member_id' => $memberId,
            'from_user_id' => $fromUserId, 'to_user_id' => $toUserId,
            'from_bucket' => $fromBucket, 'to_bucket' => $toBucket,
            'amount_umi' => $amount, 'source_ref' => $sourceRef, 'created_at' => FundedTime::database($now),
        ]);
        DB::afterCommit(function () use ($ids): void {
            foreach ($ids as $id) {
                try {
                    app(\App\Services\Performance\ReadModelCacheService::class)->invalidateWallets($id);
                } catch (\Throwable) {
                    // A cache refresh cannot reverse a committed asset movement.
                }
            }
        });
    }

    private function field(string $bucket): string
    {
        return match ($bucket) {
            'wallet' => 'balance_in_wallet', 'trade' => 'balance_in_trade',
            default => throw new DomainException('UMI 资金账户类型无效。'),
        };
    }

    private function wallet(int $userId, bool $lock = false): object
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user || (bool) ($user->is_xn ?? false) || (bool) ($user->is_xm ?? false) || (bool) ($user->deleted ?? false)
            || (bool) ($user->deactivated ?? false)) {
            throw new DomainException('该资金账户暂不可用。');
        }
        $query = DB::table('wallets')->where('user_id', $userId)
            ->where('currency_id', $this->currencyId())->orderBy('id');
        if ($lock) { $query->lockForUpdate(); }
        $rows = $query->get();
        if ($rows->count() !== 1) {
            throw new RuntimeException('UMI 钱包分配需要人工核对。');
        }
        return $rows[0];
    }
}
