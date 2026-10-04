<?php

namespace App\Services\Option;

use App\Models\Option\Option;
use App\Models\Wallet\Wallet;
use App\Events\WalletUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OptionFunds
{
    public function wallet(Option $option): Wallet
    {
        if (!in_array($option->funding_domain, ['real', 'virtual'], true) || !$option->funding_wallet_id) {
            throw ValidationException::withMessages(['option' => __('Historical funding source requires review.')]);
        }
        return Wallet::whereKey($option->funding_wallet_id)->where('user_id', $option->user_id)
            ->where('currency_id', $option->currency_id)->lockForUpdate()->firstOrFail();
    }

    /** Must be called under the same transaction and option lock as the status transition. */
    public function credit(Option $option, string $amount): void
    {
        if (DB::transactionLevel() < 1 || bccomp($amount, '0', 18) < 0) throw new \LogicException('Invalid option credit context.');
        $wallet = $this->wallet($option);
        $field = $option->funding_domain === 'virtual' ? 'balance_in_virtual_wallet' : 'balance_in_trade';
        $wallet->{$field} = bcadd((string) ($wallet->{$field} ?? '0'), $amount, 18);
        $wallet->save();
        DB::afterCommit(fn () => event(new WalletUpdated($wallet)));
    }

    public function cancelScheduled(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $option = Option::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($option->status === 'closed') return true;
            if ($option->status !== 'scheduled') throw ValidationException::withMessages(['option' => __('Only scheduled options can be closed.')]);
            $this->credit($option, (string) $option->amount);
            $option->status = 'closed';
            $option->pnl = '0';
            $option->settlement_source = 'admin_scheduled_cancellation:' . auth()->id();
            $option->save();
            return true;
        }, 3);
    }
}
