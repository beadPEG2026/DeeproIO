<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;

use App\Services\Custody\CustodyNetwork;
use App\Services\Custody\CustodyService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** One custody transfer per lot preserves an exact tx-log-to-liability link. */
final class FundedBurn
{
    public function __construct(
        private readonly FundedReadiness $gate,
        private readonly FundedWallet $wallet,
        private readonly FundedConfiguration $configuration,
        private readonly CustodyService $custody,
        private readonly BurnProof $proof,
        private readonly FundedSettlement $settlement,
    ) {}

    public function queue(int $lotId): object
    {
        $settings = $this->gate->require('burn');
        return DB::transaction(function () use ($lotId, $settings): object {
            $lot = DB::table('umi_v2_live_burn_lots')->where('id', $lotId)
                ->lockForUpdate()->first() ?? throw new DomainException('销毁记录不存在。');
            if ($lot->status === 'confirmed') { return $lot; }
            if ($lot->custody_transfer_id) { return $lot; }
            if ($lot->status !== 'pending') { throw new DomainException('该笔销毁需要人工核对。'); }
            $asset = CustodyNetwork::asset($this->wallet->currencyId(), (int) $settings->umi_network_id);
            if ($asset['chain'] !== 'bnb' || strcasecmp((string) $asset['contract'],
                (string) config('umi.asset.contract')) !== 0) {
                throw new DomainException('UMI 链上资产配置不符。');
            }
            $transfer = $this->custody->create($asset, [
                'key' => 'umi-v2-burn:' . $lotId, 'purpose' => 'umi_v2_burn',
                'sender' => $this->custody->hotSender('bnb'),
                'destination' => FundedReadiness::DEAD,
                'amount' => (string) $lot->amount_umi,
            ]);
            DB::table('umi_v2_live_burn_lots')->where('id', $lotId)->update([
                'custody_transfer_id' => $transfer->id, 'status' => 'custody',
                'updated_at' => FundedTime::database(now()),
            ]);
            return DB::table('umi_v2_live_burn_lots')->find($lotId);
        }, 3);
    }

    public function synchronize(int $lotId): object
    {
        $settings = $this->gate->require('burn');
        $lot = DB::table('umi_v2_live_burn_lots')->where('id', $lotId)->first()
            ?? throw new DomainException('销毁记录不存在。');
        if ($lot->status === 'confirmed') {
            if ($lot->purpose === 'withdrawal') { $this->creditPointsForLot($lotId); }
            return $lot;
        }
        $custody = $lot->custody_transfer_id
            ? DB::table('custody_transfers')->find($lot->custody_transfer_id) : null;
        if (!$custody || $custody->status !== 'completed' || !$custody->txn
            || $custody->purpose !== 'umi_v2_burn'
            || strtolower((string) $custody->destination) !== FundedReadiness::DEAD
            || strcasecmp((string) $custody->contract, (string) config('umi.asset.contract')) !== 0
            || Decimal::cmp((string) $custody->amount, (string) $lot->amount_umi) !== 0) {
            throw new DomainException('链上销毁尚未确认。');
        }
        $proof = $this->proof->verify((string) $custody->txn, (string) $custody->sender,
            (string) $custody->contract, (string) $lot->amount_umi, (int) $custody->confirmations);
        $confirmed = DB::transaction(function () use ($lotId, $custody, $proof, $settings): object {
            $lot = DB::table('umi_v2_live_burn_lots')->where('id', $lotId)->lockForUpdate()->first();
            if ($lot->status === 'confirmed') { return $lot; }
            if ($lot->status !== 'custody' || (int) $lot->custody_transfer_id !== (int) $custody->id) {
                throw new RuntimeException('UMI burn source changed during confirmation');
            }
            if (Decimal::cmp((string) $lot->amount_umi, (string) $proof['amount_umi']) !== 0) {
                throw new RuntimeException('UMI burn proof amount mismatch');
            }
            $evidence = [
                'chain_id' => $proof['chain_id'], 'token_contract' => $proof['token_contract'],
                'tx_hash' => $proof['tx_hash'], 'log_index' => $proof['log_index'],
                'burned_umi' => $proof['amount_umi'], 'block_number' => $proof['block_number'],
                'block_hash' => $proof['block_hash'], 'receipt_sha256' => $proof['receipt_sha256'],
                'finalized_at' => FundedTime::database($proof['finalized_at']), 'created_at' => FundedTime::database(now()),
            ];
            DB::table('umi_v2_live_burn_proofs')->insert(['lot_id' => $lotId] + $evidence);
            $baseId = null;
            if ($lot->purpose !== 'injury') {
                $baseId = DB::table('umi_v2_burn_evidence')->insertGetId([
                    'purpose' => $lot->purpose, 'cycle_id' => $lot->cycle_id,
                    'withdrawal_id' => $lot->withdrawal_id,
                    'finality_status' => 'final',
                ] + $evidence);
            }
            $this->wallet->move((int) $settings->pool_user_id, null,
                (string) $lot->amount_umi, 'burn:' . $lotId,
                'confirmed_chain_burn', 'custody:' . $custody->id,
                $lot->member_id ? (int) $lot->member_id : null);
            DB::table('umi_v2_live_burn_lots')->where('id', $lotId)->update([
                'status' => 'confirmed', 'burn_evidence_id' => $baseId,
                'confirmed_at' => FundedTime::database($proof['finalized_at']), 'updated_at' => FundedTime::database(now()),
            ]);
            if ($lot->purpose === 'activation') {
                $cycle = DB::table('umi_v2_cycles')->where('id', $lot->cycle_id)
                    ->lockForUpdate()->first();
                if (!$cycle || $cycle->status !== 'pending_burn') {
                    throw new RuntimeException('UMI activation cycle state mismatch');
                }
                DB::table('umi_v2_cycles')->where('id', $cycle->id)->update([
                    'status' => 'active', 'activation_burn_ref' =>
                        $proof['tx_hash'] . ':' . $proof['log_index'],
                    'starts_on' => now(config('umi-v2.timezone'))->toDateString(),
                    'updated_at' => FundedTime::database(now()),
                ]);
                DB::table('umi_v2_live_intents')->where('cycle_id', $cycle->id)->update([
                    'status' => 'active', 'updated_at' => FundedTime::database(now()),
                ]);
            } elseif ($lot->purpose === 'withdrawal') {
                DB::table('umi_v2_withdrawals')->where('id', $lot->withdrawal_id)->update([
                    'confirmed_burn_umi' => $lot->amount_umi, 'updated_at' => FundedTime::database(now()),
                ]);
            }
            return DB::table('umi_v2_live_burn_lots')->find($lotId);
        }, 3);
        if ($confirmed->purpose === 'withdrawal') { $this->creditPointsForLot($lotId); }
        if ($confirmed->purpose === 'activation') {
            try { $this->settlement->settleActivatedCycle((int) $confirmed->cycle_id); }
            catch (\Throwable $error) {
                Log::warning('UMI direct reward awaits reconciliation',
                    ['cycle_id' => $confirmed->cycle_id, 'error_class' => get_class($error)]);
            }
        }
        return $confirmed;
    }

    public function creditPointsForLot(int $lotId): bool
    {
        $lot = DB::table('umi_v2_live_burn_lots')->find($lotId);
        if (!$lot || $lot->purpose !== 'withdrawal' || $lot->status !== 'confirmed') {
            return false;
        }
        if (DB::table('umi_v2_stock_point_entries')
            ->where('request_key', 'live:points:' . $lot->withdrawal_id)->exists()) {
            return true;
        }
        try {
            $umi = $this->configuration->quoteAt('UMI_USDT', (string) $lot->confirmed_at);
            $share = $this->configuration->quoteAt('HK08379_USDT', (string) $lot->confirmed_at);
        } catch (DomainException $error) {
            Log::warning('UMI stock points await an approved quote', ['lot_id' => $lotId]);
            return false;
        }
        DB::transaction(function () use ($lotId, $umi, $share): void {
            $lot = DB::table('umi_v2_live_burn_lots')->where('id', $lotId)->lockForUpdate()->first();
            if (DB::table('umi_v2_stock_point_entries')
                ->where('request_key', 'live:points:' . $lot->withdrawal_id)->exists()) { return; }
            $proof = DB::table('umi_v2_live_burn_proofs')->where('lot_id', $lotId)->first()
                ?? throw new RuntimeException('UMI burn proof missing for points');
            $this->creditPoints($lot, (int) $lot->burn_evidence_id,
                ['tx_hash' => $proof->tx_hash, 'log_index' => $proof->log_index], $umi, $share);
        }, 3);
        return true;
    }

    private function creditPoints(object $lot, int $burnEvidenceId, array $proof,
        object $umi, object $share): void
    {
        $ratio = Decimal::display(bcdiv(Decimal::mul((string) $umi->price, '0.1'),
            (string) $share->price, Decimal::SCALE));
        $points = Decimal::mul((string) $lot->amount_umi, $ratio);
        $prior = '0';
        DB::table('umi_v2_members')->where('id', $lot->member_id)->lockForUpdate()->first();
        foreach (DB::table('umi_v2_stock_point_entries')->where('member_id', $lot->member_id)
            ->orderBy('id')->get() as $entry) {
            $prior = Decimal::add($prior, (string) $entry->delta_points);
        }
        $withdrawal = DB::table('umi_v2_withdrawals')->find($lot->withdrawal_id);
        $source = ['burn_evidence_id' => $burnEvidenceId, 'withdrawal_id' => $lot->withdrawal_id,
            'umi_quote_id' => $umi->id, 'share_quote_id' => $share->id,
            'tx_hash' => $proof['tx_hash'], 'log_index' => $proof['log_index']];
        $acquired = \Carbon\CarbonImmutable::now('UTC')->startOfSecond();
        $entryId = DB::table('umi_v2_stock_point_entries')->insertGetId([
            'member_id' => $lot->member_id, 'withdrawal_id' => $lot->withdrawal_id,
            'burn_evidence_id' => $burnEvidenceId,
            'policy_version_id' => $withdrawal->policy_version_id,
            'request_key' => 'live:points:' . $lot->withdrawal_id,
            'kind' => 'burn_credit', 'program_id' => 'HK08379-display-points',
            'points_per_burned_umi' => $ratio,
            'burn_confirmation_ref' => $proof['tx_hash'] . ':' . $proof['log_index'],
            'delta_points' => $points,
            'balance_after_points' => Decimal::add($prior, $points),
            'source_burned_umi' => $lot->amount_umi,
            'source_sha256' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)),
            'reason' => '股票积分来自已确认的提现销毁',
            'created_at' => FundedTime::database($acquired),
        ]);
        $eligible = $acquired->addDays(60);
        DB::table('umi_v2_live_point_terms')->insert([
            'point_entry_id' => $entryId, 'acquired_at' => FundedTime::database($acquired),
            'eligible_at' => FundedTime::database($eligible),
            'unlock_at' => null,
            'status' => 'points_only', 'created_at' => FundedTime::database(now()), 'updated_at' => FundedTime::database(now()),
        ]);
    }
}
