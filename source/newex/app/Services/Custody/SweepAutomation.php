<?php

namespace App\Services\Custody;

use App\Models\Wallet\WalletAddress;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Only deposit-to-hot-wallet sweeps; never cold transfers, withdrawals or gas funding. */
final class SweepAutomation
{
    public function approve(int $id, bool $new = false): bool
    {
        return DB::transaction(function () use ($id, $new) {
            $candidate = DB::table('custody_transfers')->find($id);
            if (!$candidate || !$candidate->deposit_id || $candidate->purpose !== 'sweep') return false;
            // Match sweep planning's lock order, including callers already holding the deposit lock.
            $deposit = DB::table('deposits')->where('id', $candidate->deposit_id)->lockForUpdate()->first();
            $task = DB::table('custody_transfers')->where('id', $id)->lockForUpdate()->first();
            if (!$task || $task->status !== 'awaiting_approval') return false;
            $network = DB::table('custody_networks')->where('chain', $task->chain)->lockForUpdate()->first();
            if (!$network || !$network->enabled || !$network->auto_sweep) return false;
            $proof = json_decode($deposit->initial_raw ?? '', true);
            if (($network->auto_sweep_scope ?? 'new_live') !== 'all_verified' && (!$new || ($proof['deposit_mode'] ?? null) === 'pilot')) return false;
            if ($task->signed_payload || $task->txn || $task->broadcast_at || $task->completed_at || $task->requested_by || $task->approved_by
                || DB::table('custody_transfer_attempts')->where('transfer_id', $id)->exists()
                || DB::table('custody_audits')->where('transfer_id', $id)->whereIn('action', ['transfer.cancelled', 'transfer.resumed'])->exists()) {
                return $this->hold($id);
            }
            try {
                $this->verify($task, $deposit, $proof);
            } catch (\Throwable $e) {
                return $this->hold($id);
            }
            $fee = bccomp($task->max_fee, CustodyNetwork::feeCap($network, $task->contract), 18) < 0 ? $task->max_fee : CustodyNetwork::feeCap($network, $task->contract);
            if (bccomp($fee, '0', 18) <= 0) return $this->hold($id);
            DB::table('custody_transfers')->where('id', $id)->update([
                'status' => 'approved', 'approved_by' => null, 'max_fee' => $fee,
                'confirmations' => max($task->confirmations, $network->confirmations), 'last_error' => null, 'updated_at' => now(),
            ]);
            DB::table('custody_audits')->insert([
                'actor_id' => null, 'action' => 'transfer.auto_approved', 'transfer_id' => $id,
                'detail' => json_encode(['scope' => $network->auto_sweep_scope ?? 'new_live', 'new' => $new, 'policy_updated_by' => $network->updated_by,
                    'policy_updated_at' => $network->updated_at, 'deposit_mode' => $proof['deposit_mode'] ?? 'live', 'max_fee' => $fee]),
                'created_at' => now(),
            ]);
            return true;
        });
    }

    public function assertRunnable(object $task): void
    {
        $audit = DB::table('custody_audits')->where('transfer_id', $task->id)->where('action', 'transfer.auto_approved')->orderByDesc('id')->first();
        if (!$audit) return;
        $network = DB::table('custody_networks')->where('chain', $task->chain)->first();
        $detail = json_decode($audit->detail, true);
        if (!$network || !$network->enabled || !$network->auto_sweep
            || ($network->auto_sweep_scope ?? 'new_live') !== 'all_verified' && (empty($detail['new']) || ($detail['deposit_mode'] ?? '') === 'pilot')) {
            throw new RuntimeException('CUSTODY_AUTO_SWEEP_PAUSED');
        }
        $deposit = DB::table('deposits')->find($task->deposit_id);
        try { $this->verify($task, $deposit, json_decode($deposit->initial_raw ?? '', true)); }
        catch (\Throwable $e) { throw new RuntimeException('CUSTODY_AUTO_SWEEP_REVIEW_REQUIRED'); }
    }

    private function hold(int $id): bool
    {
        DB::table('custody_transfers')->where('id', $id)->update(['last_error' => 'CUSTODY_AUTO_SWEEP_REVIEW_REQUIRED', 'updated_at' => now()]);
        return false;
    }

    private function verify(object $task, ?object $deposit, ?array $proof): void
    {
        if (!$deposit || !$proof || $deposit->status !== DEPOSIT_CONFIRMED || !in_array($deposit->wallet_transfer_status, ['review', 'custody'], true)
            || $task->key !== 'deposit:'.$deposit->id || (int)$task->currency_id !== (int)$deposit->currency_id
            || (int)$task->network_id !== (int)$deposit->network_id || $task->deposit_id != $deposit->id
            || $task->withdrawal_id || $task->rule_id || $task->parent_id || !empty($proof['pilot_reason'])
            || bccomp($task->amount, $deposit->amount, 18) !== 0 || bccomp($task->amount, '0', 18) <= 0) throw new RuntimeException('Invalid intent');
        $asset = CustodyNetwork::asset($deposit->currency_id, $deposit->network_id);
        $same = fn ($a, $b) => in_array($asset['chain'], ['ethereum', 'bnb', 'polygon', 'xlayer'], true) ? strtolower($a) === strtolower($b) : $a === $b;
        if ($asset['chain'] !== $task->chain || ($asset['contract'] ?? null) !== $task->contract
            || !$same($deposit->address, $task->sender) || !$same(trim((string)setting($asset['chain'].'.wallet')), $task->destination)) throw new RuntimeException('Changed route');
        $journal = DB::table('chain_deposit_receipts')->where('deposit_id', $deposit->id)->where('txn', $deposit->txn)->first();
        if (!$journal && $asset['chain'] === 'tron' && !$asset['contract']) $journal = DB::table('tron_deposit_receipts')->where('deposit_id', $deposit->id)->where('txn', $deposit->txn)->first();
        if (!$journal || bccomp($journal->credited_amount, '0', 18) <= 0
            || bccomp(bcadd($journal->credited_amount, (string)$deposit->system_fee, 18), $deposit->amount, 18) !== 0
            || (json_decode($journal->evidence, true)['chain'] ?? null) !== $proof) throw new RuntimeException('Unverified receipt');
        $wallet = DB::table('wallets')->where('id', $journal->wallet_id)->first();
        if (!$wallet || (int)$wallet->user_id !== (int)$deposit->user_id || (int)$wallet->currency_id !== (int)$deposit->currency_id) throw new RuntimeException('Wrong ledger');
        $slugs = array_keys(array_filter(CustodyNetwork::MAP, fn ($m) => $m[0] === $asset['chain']));
        $query = WalletAddress::whereIn('network_id', DB::table('networks')->whereIn('slug', $slugs)->pluck('id'));
        $addresses = (in_array($asset['chain'], ['ethereum', 'bnb', 'polygon', 'xlayer'], true) ? $query->whereRaw('lower(address)=?', [strtolower($deposit->address)]) : $query->where('address', $deposit->address))->get();
        if (!$addresses->contains('id', $task->wallet_address_id) || $addresses->pluck('user_id')->unique()->count() !== 1
            || (int)$addresses->first()->user_id !== (int)$deposit->user_id || $addresses->map(fn ($v) => $v->private_key)->unique()->count() !== 1) throw new RuntimeException('Ambiguous ownership');
    }

    public function pendingReason(object $task): ?string
    {
        if ($task->status !== 'awaiting_approval' || $task->purpose !== 'sweep') return null;
        if ($task->last_error) return $task->last_error;
        $network = DB::table('custody_networks')->where('chain', $task->chain)->first();
        if (!$network || !$network->enabled || !$network->auto_sweep) return 'Automatic sweep is disabled';
        if (($network->auto_sweep_scope ?? 'new_live') === 'all_verified') return 'Awaiting automatic verification';
        return 'Current scope excludes pilot deposits and pending tasks';
    }
}
