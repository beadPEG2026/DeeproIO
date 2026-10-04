<?php

namespace App\Services\Deposit;

use App\Events\DepositUpdated;
use App\Models\Deposit\{Deposit, DepositChannel};
use App\Models\Wallet\{Wallet, WalletAddress};
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Support\Str;
use RuntimeException;
/** Only called with independently verified final receipts, never with indexer amounts. */
final class VerifiedChainDeposit
{
    public function process(DepositChannel $channel, WalletAddress $address, array $proof): array
    {
        return DB::transaction(function () use ($channel, $address, $proof) {
            $channel = DepositChannel::lockForUpdate()->findOrFail($channel->id);
            if ($error = app(DepositChannelPolicy::class)->error($channel->currency_id, $channel->network_id, false, (int) $address->user_id)) {
                throw new RuntimeException('DEPOSIT_CHANNEL_UNAVAILABLE');
            }
            if ($proof['chain'] !== $channel->chain || $proof['address'] !== $address->address || $proof['confirmations'] < $channel->confirmations || ($channel->chain === 'tron' ? ($proof['contract'] ?? '') !== ($channel->contract ?? '') : strtolower($proof['contract'] ?? '') !== strtolower($channel->contract ?? ''))) {
                throw new RuntimeException('DEPOSIT_PROOF_MISMATCH');
            }
            if ($channel->chain === 'solana' && ($proof['finality'] ?? null) !== 'finalized') throw new RuntimeException('DEPOSIT_PROOF_MISMATCH');
            $identity = ['chain' => $channel->chain, 'txn' => $proof['txn'], 'event_index' => (string) $proof['event_index']];
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [implode(':', $identity)]);
            $networks = match ($channel->chain) { 'tron' => [NETWORK_TRX, NETWORK_TRC], 'solana' => [NETWORK_SOL, NETWORK_SOL_SPL], default => config('deposits.evm.' . $channel->chain . '.networks', []) };
            $owners = WalletAddress::whereIn('network_id', $networks)->whereRaw('LOWER(address) = ?', [strtolower($address->address)])->lockForUpdate()->get();
            // TRON Base58 is case sensitive.
            if (in_array($channel->chain, ['tron','solana'], true)) {
                $owners = $owners->where('address', $address->address);
            }
            if ($owners->isEmpty() || $owners->pluck('user_id')->unique()->count() !== 1 || (int) $owners->first()->user_id !== (int) $address->user_id) {
                throw new RuntimeException('DEPOSIT_ADDRESS_OWNERSHIP_CONFLICT');
            }
            if ($proof['timestamp'] < $owners->min('created_at')->getTimestamp() * 1000) {
                throw new RuntimeException('DEPOSIT_PREDATES_ADDRESS');
            }
            $wallets = Wallet::where('user_id', $address->user_id)->where('currency_id', $channel->currency_id)->lockForUpdate()->get();
            if ($wallets->count() !== 1) {
                throw new RuntimeException('DEPOSIT_WALLET_ALLOCATION_REQUIRES_REVIEW');
            }
            $wallet = $wallets->first();
            if ($wallet->user->is_xn || $wallet->user->deleted || $wallet->user->deactivated || config('app.readonly') && !$wallet->user->hasAnyRole(['admin', 'superadmin'])) {
                throw new RuntimeException('DEPOSIT_ACCOUNT_UNAVAILABLE');
            }
            $old = DB::table('chain_deposit_receipts')->where($identity)->first();
            if ($old) {
                if ((int) $old->wallet_id !== (int) $wallet->id || (int) $old->channel_id !== (int) $channel->id) {
                    throw new RuntimeException('DEPOSIT_RECEIPT_CONFLICT');
                }
                return ['result' => 'already_processed', 'deposit_id' => $old->deposit_id];
            }
            // No guessing whether historical rows were already credited or which log they represent.
            if (Deposit::where('network_id', $channel->network_id)->where('txn', $proof['txn'])->where(function ($q) {
                $q->whereNull('source_id')->orWhere('source_id', 'not like', 'verified:%');
            })->exists()) {
                throw new RuntimeException('DEPOSIT_LEGACY_RECONCILIATION_REQUIRED');
            }
            // A delayed pilot receipt keeps its limits and pilot marker after public activation.
            $pilot = $channel->isPilot() || ($channel->pilot_digest && hash_equals($channel->pilot_digest, $channel->pilotDigest())
                && in_array((int) $address->user_id, $channel->pilotUsers(), true)
                && $channel->pilot_started_at && $channel->pilot_expires_at
                && $proof['timestamp'] >= $channel->pilot_started_at->getTimestamp() * 1000
                && $proof['timestamp'] <= $channel->pilot_expires_at->getTimestamp() * 1000);
            $amount = ChainAmount::decimal($proof['raw_amount'], $channel->decimals);
            if (bccomp($amount, '0', 18) <= 0) {
                throw new RuntimeException('DEPOSIT_ZERO_AMOUNT');
            }
            $fee = bccomp((string) $channel->fee_percent, '0', 6) > 0 ? bcdiv(bcmul($amount, (string) $channel->fee_percent, 24), '100', 18) : (string) $channel->fee_fixed;
            $eligible = bccomp($amount, (string) ($pilot ? $channel->pilot_minimum : $channel->minimum), 18) >= 0 && bccomp($amount, $fee, 18) > 0;
            $pilotReason = null;
            if ($pilot) {
                $used = (string) DB::table('chain_deposit_receipts')->join('deposits', 'deposits.id', '=', 'chain_deposit_receipts.deposit_id')
                    ->where('chain_deposit_receipts.channel_id', $channel->id)->where('chain_deposit_receipts.wallet_id', $wallet->id)
                    ->where('chain_deposit_receipts.evidence->pilot_digest', $channel->pilot_digest)->where('chain_deposit_receipts.credited_amount', '>', 0)->sum('deposits.amount');
                if ($proof['timestamp'] < $channel->pilot_started_at->getTimestamp() * 1000 || $proof['timestamp'] > $channel->pilot_expires_at->getTimestamp() * 1000) $pilotReason = 'DEPOSIT_OUTSIDE_PILOT_WINDOW';
                elseif (bccomp(bcadd($used, $amount, 18), (string) $channel->pilot_limit, 18) > 0) $pilotReason = 'DEPOSIT_PILOT_LIMIT_EXCEEDED';
                $proof['deposit_mode'] = 'pilot';
                $proof['pilot_digest'] = $channel->pilot_digest;
                $proof['pilot_reason'] = $pilotReason;
                if ($pilotReason) $eligible = false;
            }
            $credit = $eligible ? bcsub($amount, $fee, 18) : '0';
            $deposit = Deposit::create(['deposit_id' => (string) Str::uuid(), 'source_id' => 'verified:' . implode(':', $identity), 'txn' => $proof['txn'], 'currency_id' => $channel->currency_id, 'network_id' => $channel->network_id, 'type' => 'coin', 'amount' => $amount, 'full_amount' => $proof['raw_amount'], 'network_fee' => 0, 'system_fee' => $fee, 'address' => $address->address, 'user_id' => $address->user_id, 'confirms' => $proof['confirmations'], 'status' => $eligible ? DEPOSIT_CONFIRMED : DEPOSIT_IGNORED, 'wallet_transfer_status' => 'review', 'initial_raw' => json_encode($proof)]);
            $before = $wallet->balance_in_wallet;
            if ($eligible) {
                app(DepositCreditService::class)->credit($wallet, $credit);
            }
            DB::table('chain_deposit_receipts')->insert($identity + ['deposit_id' => $deposit->id, 'wallet_id' => $wallet->id, 'channel_id' => $channel->id, 'credited_amount' => $credit, 'evidence' => json_encode(['chain' => $proof, 'balance_before' => $before, 'balance_after' => $wallet->fresh()->balance_in_wallet, 'config_digest' => $channel->digest(), 'pilot_digest' => $pilot ? $channel->pilot_digest : null]), 'created_at' => now()]);
            DB::afterCommit(function () use ($deposit) {
                try {
                    app(\App\Services\Performance\ReadModelCacheService::class)->invalidateWallets((int) $deposit->user_id);
                    event(new DepositUpdated($deposit->fresh(), 'received'));
                } catch (\Throwable $e) {
                    Log::warning('Verified deposit notification failed', ['deposit_id' => $deposit->id]);
                }
                if (config('umi-v2.funded_enabled') &&
                    (int) $deposit->currency_id === (int) DB::table('currencies')
                        ->where('symbol', 'UMI')->value('id')) {
                    app(\App\Services\Umi\V2\FundedInboundBinder::class)->bind((int) $deposit->id);
                }
            });
            return ['result' => $eligible ? 'credited' : ($pilotReason ?? 'below_minimum_or_fee'), 'deposit_id' => $deposit->id, 'credited' => $credit];
        }, 3);
    }
}
