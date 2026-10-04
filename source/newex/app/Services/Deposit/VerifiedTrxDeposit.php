<?php
namespace App\Services\Deposit;

use App\Events\DepositUpdated;
use App\Models\Currency\Currency;
use App\Models\Deposit\Deposit;
use App\Models\Network\Network;
use App\Models\Wallet\{Wallet, WalletAddress};
use App\Console\Commands\Tron\MonitorTrxDepositsCommand;
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Support\Str;
use RuntimeException;

class VerifiedTrxDeposit
{
    public function __construct(private TronGridClient $client) {}

    public function process(WalletAddress $address, string $hash, bool $publicRead = false, bool $apply = true): array
    {
        $proof = $this->client->verify($hash, $address->address, $publicRead);
        if (app(MonitorTrxDepositsCommand::class)->shouldExcludeFromAddress($proof['sender'])) {
            return ['result' => 'excluded_sender'];
        }
        return DB::transaction(function () use ($address, $hash, $proof, $apply) {
            // Serializes even two first-time claims before either has created a deposit row.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['trx:'.$hash]);
            $owners = WalletAddress::where('address', $address->address)->whereIn('network_id', [NETWORK_TRX, NETWORK_TRC])->lockForUpdate()->get();
            if ($owners->isEmpty() || $owners->pluck('user_id')->unique()->count() !== 1 || (int)$owners->first()->user_id !== (int)$address->user_id) throw new RuntimeException('TRON_ADDRESS_OWNERSHIP_CONFLICT');
            $created = $owners->min('created_at');
            if ($proof['timestamp'] < $created->getTimestamp()*1000) throw new RuntimeException('TRON_TRANSFER_PREDATES_ADDRESS');
            $currency = Currency::where('symbol', 'TRX')->where('type', 'coin')->lockForUpdate()->firstOrFail();
            $network = Network::where('id', NETWORK_TRX)->firstOrFail();
            if (!$currency->deposit_status || !$network->deposit_status || in_array(NETWORK_TRX, $currency->disabled_deposit_networks)) throw new RuntimeException('TRON_DEPOSITS_DISABLED');
            $wallet = Wallet::where('user_id', $address->user_id)->where('currency_id', $currency->id)->lockForUpdate()->firstOrFail();
            if (config('app.readonly') && !$wallet->user->hasAnyRole(['admin','superadmin'])) throw new RuntimeException('TRON_READONLY');
            $journal = DB::table('tron_deposit_receipts')->where('txn', $hash)->first();
            if ($journal) {
                if ((int)$journal->wallet_id !== (int)$wallet->id) throw new RuntimeException('TRON_RECEIPT_OWNER_CONFLICT');
                return ['result' => 'already_processed', 'deposit_id' => $journal->deposit_id, 'credited' => $journal->credited_amount];
            }
            $existing = Deposit::where('network_id', NETWORK_TRX)->where('txn', $hash)->lockForUpdate()->get();
            // Historical entries may have been manually credited; never guess and replay them.
            if ($existing->isNotEmpty()) {
                $old = $existing->first();
                if ($existing->count() !== 1 || (int)$old->user_id !== (int)$address->user_id || (int)$old->currency_id !== (int)$currency->id || $old->address !== $address->address || bccomp($old->amount, $proof['amount'], 18) !== 0) throw new RuntimeException('TRON_LEGACY_DEPOSIT_CONFLICT');
                if (in_array($old->status, [DEPOSIT_CONFIRMED, DEPOSIT_IGNORED], true)) return ['result' => 'legacy_record_preserved', 'deposit_id' => $old->id];
                throw new RuntimeException('TRON_LEGACY_DEPOSIT_REQUIRES_RECONCILIATION');
            }
            // Legacy Currency casts fixed native fees to percentage precision; use exact stored decimals.
            $rate = (string)$currency->getRawOriginal('deposit_fee');
            $fee = bccomp($rate, '0', 18) === 0 ? (string)$currency->getRawOriginal('deposit_fee_fixed') : bcdiv(bcmul($proof['amount'], $rate, 24), '100', 18);
            if (bccomp($fee, '0', 18) < 0) throw new RuntimeException('TRON_INVALID_DEPOSIT_FEE');
            $eligible = bccomp($proof['amount'], $currency->min_deposit, 18) >= 0 && bccomp($proof['amount'], $fee, 18) > 0;
            $credit = $eligible ? bcsub($proof['amount'], $fee, 18) : '0';
            if (!$apply) return ['result' => 'verified_dry_run', 'amount' => $proof['amount'], 'credited' => $credit, 'block' => $proof['block']];
            $deposit = Deposit::create(['deposit_id' => (string)Str::uuid(), 'txn' => $hash, 'source_id' => 'trx:'.$hash,
                'currency_id' => $currency->id, 'type' => 'coin', 'network_id' => NETWORK_TRX, 'amount' => $proof['amount'],
                'full_amount' => $proof['sun'], 'network_fee' => 0, 'system_fee' => $fee, 'address' => $address->address,
                'user_id' => $address->user_id, 'confirms' => 1, 'status' => $eligible ? DEPOSIT_CONFIRMED : DEPOSIT_IGNORED,
                // Custody sweep is separate from deposit credit. Never trigger the unverified legacy bridge here.
                'wallet_transfer_status' => 'review', 'initial_raw' => json_encode($proof)]);
            $before = $wallet->balance_in_wallet;
            if ($eligible) app(DepositCreditService::class)->credit($wallet, $credit);
            $after = $wallet->fresh()->balance_in_wallet;
            DB::table('tron_deposit_receipts')->insert(['txn' => $hash, 'deposit_id' => $deposit->id, 'wallet_id' => $wallet->id,
                'credited_amount' => $credit, 'evidence' => json_encode(['chain' => $proof, 'balance_before' => $before, 'balance_after' => $after]), 'created_at' => now()]);
            DB::afterCommit(function () use ($deposit) {
                try { app(\App\Services\Performance\ReadModelCacheService::class)->invalidateWallets((int)$deposit->user_id); event(new DepositUpdated($deposit->fresh(), 'received')); }
                catch (\Throwable $e) { Log::warning('TRX deposit committed; notification failed', ['deposit_id' => $deposit->id]); }
            });
            return ['result' => $eligible ? 'credited' : 'below_minimum_or_fee', 'deposit_id' => $deposit->id, 'credited' => $credit];
        }, 3);
    }
}
