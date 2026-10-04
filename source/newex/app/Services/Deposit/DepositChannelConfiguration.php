<?php
namespace App\Services\Deposit;

use App\Models\Deposit\DepositChannel;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** Shared admin/operations configuration path. Activation does not certify a funded test. */
final class DepositChannelConfiguration
{
    public function save(array $data, ?int $actorId, string $source = 'admin-form'): DepositChannel
    {
        abort_if(config('app.readonly'), 403);
        $data = Validator::make($data, ['currency_id' => 'required|integer|exists:currencies,id', 'network_id' => 'required|integer|exists:networks,id', 'contract' => 'nullable|string|max:100', 'decimals' => 'required|integer|min:0|max:18', 'confirmations' => 'required|integer|min:1|max:100000', 'minimum' => ['required', 'regex:/^\d{1,18}(\.\d{1,18})?$/'], 'fee_fixed' => ['required', 'regex:/^\d{1,18}(\.\d{1,18})?$/'], 'fee_percent' => ['required', 'regex:/^\d{1,2}(\.\d{1,6})?$/'], 'start_block' => 'nullable|integer|min:0', 'state' => 'required|in:draft,validated,pilot,tested,active,maintenance', 'acceptance_reference' => 'nullable|string|max:1000', 'pilot_user_ids' => 'nullable|array|max:20', 'pilot_user_ids.*' => 'integer|min:1|distinct|exists:users,id', 'pilot_minimum' => ['nullable', 'regex:/^\d{1,18}(\.\d{1,18})?$/'], 'pilot_limit' => ['nullable', 'regex:/^\d{1,18}(\.\d{1,18})?$/']])->validate();
        $map = DepositChannelPolicy::NETWORKS[(int) $data['network_id']] ?? null;
        if (!$map) {
            throw ValidationException::withMessages(['network_id' => __('Unsupported scanner')]);
        }
        $currency = \App\Models\Currency\Currency::with('networks')->find($data['currency_id']);
        $allowedRoutes = $currency ? \App\Services\Wallet\AssetNetworkOptions::forCurrency($currency, array_keys(DepositChannelPolicy::NETWORKS)) : [];
        if (!in_array((int) $data['network_id'], array_column($allowedRoutes, 'id'), true)) {
            throw ValidationException::withMessages(['network_id' => __('Asset is not linked to this network')]);
        }
        [$data['chain'], $data['kind']] = $map;
        if ((int)$data['confirmations'] < ConfirmationPolicy::minimum($data['chain'])) throw ValidationException::withMessages(['confirmations'=>__('Confirmations must be at least :minimum for this network.',['minimum'=>ConfirmationPolicy::minimum($data['chain'])])]);
        if (!in_array($data['chain'], ['tron','solana'], true) && !empty($data['contract'])) {
            $data['contract'] = strtolower($data['contract']);
        }
        if ($data['kind'] === 'native') {
            $data['contract'] = null;
        }
        return DB::transaction(function () use ($data, $actorId, $source) {
            DB::select('SELECT pg_advisory_xact_lock(77321,99)');
            $c = DepositChannel::where('currency_id', $data['currency_id'])->where('network_id', $data['network_id'])->lockForUpdate()->first() ?? new DepositChannel();
            $before = $c->exists ? $c->toArray() : null;
            $c->fill($data);
            foreach (['minimum' => 18, 'fee_fixed' => 18, 'fee_percent' => 6, 'pilot_minimum' => 18, 'pilot_limit' => 18] as $key => $scale) {
                if ($c->{$key} !== null) $c->{$key} = bcadd((string) $c->{$key}, '0', $scale);
            }
            $promoting = in_array($c->state, ['pilot', 'tested'], true);
            if ($promoting) {
                $allowed = ['pilot' => ['validated', 'pilot', 'tested'], 'tested' => ['pilot', 'tested']][$c->state];
                if (!$before || !in_array($before['state'], $allowed, true) || !hash_equals((string) ($before['config_digest'] ?? ''), $c->digest())) {
                    throw ValidationException::withMessages(['state' => __('Validate the configuration before recording tests; activate only after testing')]);
                }
            }
            if ($c->isPilot()) {
                if (!$c->pilotUsers() || bccomp((string) ($c->pilot_minimum ?? 0), (string) $c->fee_fixed, 18) <= 0 || bccomp((string) ($c->pilot_limit ?? 0), (string) $c->pilot_minimum, 18) < 0) {
                    throw ValidationException::withMessages(['pilot_user_ids' => __('Invalid deposit limits')]);
                }
                if (DB::table('users')->whereIn('id', $c->pilotUsers())->where(fn($q) => $q->where('deleted', true)->orWhere('deactivated', true)->orWhere('is_xn', true))->exists()) {
                    throw ValidationException::withMessages(['pilot_user_ids' => __('Invalid deposit limits')]);
                }
                if (($before['state'] ?? '') !== 'validated' && !hash_equals((string) ($before['pilot_digest'] ?? ''), $c->pilotDigest())) {
                    throw ValidationException::withMessages(['state' => __('Validate the configuration before recording tests; activate only after testing')]);
                }
            }
            if ($c->state === 'tested' && !$c->hasPilotEvidence()) {
                throw ValidationException::withMessages(['state' => __('Acceptance evidence is required')]);
            }
            if ($c->state !== 'draft' && $c->state !== 'maintenance') {
                if ($error = app(DepositChannelPolicy::class)->configurationError($c)) {
                    throw ValidationException::withMessages(['state' => __($error)]);
                }
                $same = DepositChannel::where('chain', $c->chain)->where('kind', $c->kind)->where('id', '!=', $c->id ?? 0);
                if ($c->kind === 'token') {
                    $same->where('contract', $c->contract);
                }
                if ($same->exists()) {
                    throw ValidationException::withMessages(['contract' => __('This chain asset already has a deposit channel')]);
                }
                try {
                    if ($c->chain === 'tron') {
                        $result = app(TronGridClient::class)->request('wallet/triggerconstantcontract', ['owner_address' => TronGridClient::hexAddress($c->contract), 'contract_address' => TronGridClient::hexAddress($c->contract), 'function_selector' => 'decimals()', 'visible' => false]);
                        if (($result['result']['result'] ?? false) !== true || ChainAmount::integer($result['constant_result'][0] ?? '') !== (string) $c->decimals) {
                            throw new \RuntimeException('precision');
                        }
                    } elseif ($c->chain === 'solana') {
                        app(SolanaDepositClient::class)->ready();
                    } else {
                        app(EvmDepositClient::class)->validateToken($c);
                    }
                } catch (\Throwable $e) {
                    throw ValidationException::withMessages(['state' => __('Chain identity or token precision verification failed')]);
                }
                if ($c->state === 'tested' && mb_strlen(trim((string) $c->acceptance_reference)) < 8) {
                    throw ValidationException::withMessages(['acceptance_reference' => __('Acceptance evidence is required')]);
                }
            }
            if ($c->state === 'pilot' && ($before['state'] ?? '') === 'validated') {
                $c->pilot_started_at = now()->startOfSecond();
                $c->pilot_expires_at = now()->startOfSecond()->addDay();
                $c->pilot_start_block = in_array($c->chain, ['tron','solana'], true) ? null : max(0, app(EvmDepositClient::class)->height($c->chain) - $c->confirmations + 1);
            }
            if (in_array($c->state, ['draft', 'validated', 'maintenance'], true)) {
                $c->pilot_started_at = $c->pilot_expires_at = $c->pilot_digest = $c->pilot_start_block = null;
            }
            if ($c->state === 'active' && (mb_strlen(trim((string) $c->acceptance_reference)) < 8 || ($before['config_digest'] ?? null) !== $c->digest())) {
                $c->acceptance_reference = 'Technical configuration verified for public deposits; funded test is optional and is not implied by activation.';
            }
            $c->reviewed_by = $actorId;
            $c->reviewed_at = now();
            $c->config_digest = null;
            $c->save();
            $c->refresh();
            if (in_array($c->state, ['validated', 'pilot', 'tested', 'active'], true)) {
                $c->config_digest = $c->digest();
                if ($c->isPilot()) $c->pilot_digest = $c->pilotDigest();
                $c->save();
            }
            // Limits/fees affect admission, not discovery. Do not rewind years of blocks
            // or replay ignored receipts when an operator changes the minimum.
            $discoveryChanged = $before && collect(['currency_id', 'network_id', 'chain', 'kind', 'contract', 'decimals', 'confirmations', 'start_block'])
                ->contains(fn($key) => (string)($before[$key] ?? '') !== (string)$c->{$key});
            if ($discoveryChanged && $c->chain !== 'tron') {
                DB::table('chain_deposit_scan_states')->where('chain', $c->chain)->where('scope', $c->scanScope())->update(['scanned_through' => null, 'window_start' => null, 'window_end' => null, 'realtime_from' => null, 'realtime_through' => null, 'realtime_last_success_at' => null, 'realtime_last_error' => null, 'backfill_attempted_at' => null, 'backfill_range_cap' => null, 'last_success_at' => null, 'last_error' => 'DEPOSIT_CONFIGURATION_CHANGED', 'updated_at' => now()]);
            }
            DB::table('deposit_channel_audits')->insert(['channel_id' => $c->id, 'actor_id' => $actorId, 'before' => json_encode($before), 'after' => json_encode($c->toArray() + ['operation_source' => $source, 'funded_test_required_for_activation' => false]), 'created_at' => now()]);
            return $c;
        });
    }
}
