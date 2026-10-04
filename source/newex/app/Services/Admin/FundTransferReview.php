<?php

namespace App\Services\Admin;

use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Events\WalletUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A real credit always has a funded debit, an independent reviewer and a journal. */
final class FundTransferReview
{
    public function propose(User $actor, array $data): object
    {
        abort_unless($actor->hasRole('superadmin') && !$actor->deleted && !$actor->deactivated, 403);
        $data = validator($data, [
            'idempotency_key' => 'required|uuid', 'wallet' => 'required|integer',
            'source_wallet_id' => 'required|integer|different:wallet',
            'balance_type' => 'required|in:wallet,trade', 'source_balance_type' => 'required|in:wallet,trade',
            'amount' => ['required','regex:/^\d{1,18}(?:\.\d{1,18})?$/D'],
            'note' => 'required|string|min:10|max:1000', 'reference' => 'required|string|min:5|max:200',
        ])->validate();
        if (bccomp($data['amount'], '0', 18) <= 0) throw ValidationException::withMessages(['amount' => __('Amount must be positive.')]);
        return DB::transaction(function () use ($actor, $data) {
            // Lock the proposer as well, so simultaneous retries cannot create two proposals.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('admin_fund_transfers')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($old) {
                abort_unless($old->proposed_by == $actor->id && $old->source_wallet_id == $data['source_wallet_id'] && $old->target_wallet_id == $data['wallet']
                    && bccomp($old->amount, $data['amount'], 18) === 0 && $old->source_field === 'balance_in_' . $data['source_balance_type']
                    && $old->target_field === 'balance_in_' . $data['balance_type'] && $old->reference === $data['reference'] && $old->reason === $data['note'], 409);
                return $old;
            }
            $source = Wallet::findOrFail($data['source_wallet_id']);
            $target = Wallet::findOrFail($data['wallet']);
            $allowed = array_map('intval', config('admin-controls.funding_user_ids', []));
            abort_unless((int) $source->user_id === (int) $actor->id || in_array((int) $source->user_id, $allowed, true), 403);
            $this->sameRealCurrency($source, $target);
            $id = (string) Str::uuid();
            DB::table('admin_fund_transfers')->insert([
                'id' => $id, 'idempotency_key' => $data['idempotency_key'], 'proposed_by' => $actor->id,
                'source_wallet_id' => $source->id, 'target_wallet_id' => $target->id, 'currency_id' => $source->currency_id,
                'source_field' => 'balance_in_' . $data['source_balance_type'], 'target_field' => 'balance_in_' . $data['balance_type'],
                'amount' => $data['amount'], 'reference' => $data['reference'], 'reason' => $data['note'],
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return DB::table('admin_fund_transfers')->where('id', $id)->first();
        }, 3);
    }

    public function review(User $actor, string $id, bool $approve, string $reason): object
    {
        abort_unless($actor->hasRole('superadmin') && !$actor->deleted && !$actor->deactivated, 403);
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 1000) throw ValidationException::withMessages(['reason' => __('A review reason of at least 10 characters is required.')]);
        return DB::transaction(function () use ($actor, $id, $approve, $reason) {
            $row = DB::table('admin_fund_transfers')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if((int) $row->proposed_by === (int) $actor->id, 403, 'Independent reviewer required.');
            if ($row->status !== 'pending') {
                abort_unless($row->reviewed_by == $actor->id && $row->status === ($approve ? 'completed' : 'rejected'), 409);
                return $row;
            }
            $changes = ['status' => $approve ? 'completed' : 'rejected', 'reviewed_by' => $actor->id, 'review_reason' => $reason, 'updated_at' => now()];
            if ($approve) {
                $wallets = Wallet::whereIn('id', [$row->source_wallet_id, $row->target_wallet_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $source = $wallets->get($row->source_wallet_id); $target = $wallets->get($row->target_wallet_id);
                if (!$source || !$target) throw ValidationException::withMessages(['wallet' => __('Wallet not found')]);
                $proposer = User::find($row->proposed_by);
                abort_unless($proposer && !$proposer->deleted && !$proposer->deactivated && $proposer->hasRole('superadmin'), 403);
                abort_unless($source->user_id == $proposer->id || in_array((int) $source->user_id, array_map('intval', config('admin-controls.funding_user_ids', [])), true), 403);
                $this->sameRealCurrency($source, $target);
                if ((int) $row->currency_id !== (int) $source->currency_id) throw ValidationException::withMessages(['wallet' => __('Currency changed; submit a new proposal.')]);
                foreach ([$row->source_field, $row->target_field] as $field) if (!in_array($field, ['balance_in_wallet','balance_in_trade'], true)) throw new \LogicException('Invalid ledger field.');
                $beforeDebit = (string) $source->{$row->source_field}; $beforeCredit = (string) $target->{$row->target_field};
                if (bccomp($beforeDebit, $row->amount, 18) < 0) throw ValidationException::withMessages(['amount' => __('Insufficient balance')]);
                $source->{$row->source_field} = bcsub($beforeDebit, $row->amount, 18);
                $target->{$row->target_field} = bcadd($beforeCredit, $row->amount, 18);
                $source->save(); $target->save();
                $changes['ledger'] = json_encode([
                    'domain' => 'real', 'currency_id' => $row->currency_id,
                    'debit' => ['wallet_id' => $source->id, 'field' => $row->source_field, 'before' => $beforeDebit, 'after' => $source->{$row->source_field}],
                    'credit' => ['wallet_id' => $target->id, 'field' => $row->target_field, 'before' => $beforeCredit, 'after' => $target->{$row->target_field}],
                ]);
                DB::afterCommit(function () use ($source, $target) { event(new WalletUpdated($source)); event(new WalletUpdated($target)); });
            }
            DB::table('admin_fund_transfers')->where('id', $id)->update($changes);
            return DB::table('admin_fund_transfers')->where('id', $id)->first();
        }, 3);
    }

    private function sameRealCurrency(Wallet $source, Wallet $target): void
    {
        if ($source->id === $target->id || $source->currency_id !== $target->currency_id) throw ValidationException::withMessages(['wallet' => __('Source and target must be different wallets in the same currency.')]);
        foreach ([$source, $target] as $wallet) {
            $user = User::whereKey($wallet->user_id)->lockForUpdate()->firstOrFail();
            if ($user->is_xn || $user->is_xm || $user->deleted || $user->deactivated) throw ValidationException::withMessages(['wallet' => __('Real transfers require active real accounts.')]);
        }
    }
}
