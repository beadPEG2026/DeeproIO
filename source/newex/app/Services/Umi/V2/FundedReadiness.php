<?php

namespace App\Services\Umi\V2;

use App\Services\Custody\CustodyNetwork;
use App\Services\Custody\CustodyService;
use DomainException;
use Illuminate\Support\Facades\DB;

/** A closed gate until wallet, network and approval settings are all coherent. */
class FundedReadiness
{
    public const DEAD = '0x000000000000000000000000000000000000dead';

    public function __construct(private readonly FundedWallet $wallet) {}

    public function settings(): object
    {
        $settings = DB::table('umi_v2_live_settings')->where('id', 1)->first()
            ?? throw new DomainException('UMI 服务正在准备中。');
        try { $settings->umi_network_id = $this->wallet->networkId(); }
        catch (\Throwable) { $settings->umi_network_id = null; }
        return $settings;
    }

    public function report(?object $candidate = null): array
    {
        $issues = [];
        if (!config('umi-v2.funded_enabled')) { $issues[] = 'funded_release_disabled'; }
        if (DB::table('umi_v2_cycles')->where('activation_request_key', 'not like', 'live:%')->exists()) {
            $issues[] = 'local_history_requires_isolation';
        }
        $s = $candidate ?? $this->settings();
        if (!$s->pool_user_id) { $issues[] = 'pool_account_missing'; }
        if (!$s->dedicated_address || !preg_match('/^0x[a-fA-F0-9]{40}$/D', $s->dedicated_address)
            || in_array(strtolower((string) $s->dedicated_address), ['0x' . str_repeat('0', 40), self::DEAD,
                strtolower((string) config('umi.asset.contract'))], true)) {
            $issues[] = 'dedicated_address_missing';
        }
        try {
            $id = $this->wallet->currencyId();
            if ($s->pool_user_id) { $this->wallet->balance((int) $s->pool_user_id); }
            $networkId = $this->wallet->networkId();
            $asset = CustodyNetwork::asset($id, $networkId);
            if ($asset['chain'] !== 'bnb' ||
                strcasecmp((string) $asset['contract'], (string) config('umi.asset.contract')) !== 0) {
                $issues[] = 'bsc_asset_mismatch';
            }
        } catch (\Throwable) { $issues[] = 'wallet_or_asset_unavailable'; }
        try {
            $sender = strtolower((string) app(CustodyService::class)->hotSender('bnb'));
            if (!preg_match('/^0x[a-f0-9]{40}$/D', $sender) || $sender === self::DEAD) {
                $issues[] = 'custody_sender_invalid';
            }
            if ($s->dedicated_address && strcasecmp((string)$s->dedicated_address,$sender)!==0) $issues[]='dedicated_sender_mismatch';
            app(CustodyService::class)->network('bnb');
        } catch (\Throwable) { $issues[] = 'custody_unavailable'; }
        return ['ready' => !$issues, 'issues' => array_values(array_unique($issues)),
            'intake_enabled' => (bool) $s->intake_enabled,
            'settlement_enabled' => (bool) $s->settlement_enabled,
            'withdrawal_enabled' => (bool) $s->withdrawal_enabled];
    }

    public function require(string $operation): object
    {
        $s = $this->settings();
        $flag = match ($operation) {
            'intake' => $s->intake_enabled,
            'settlement' => $s->settlement_enabled,
            'withdrawal' => $s->withdrawal_enabled,
            'burn' => $s->intake_enabled || $s->withdrawal_enabled,
            default => false,
        };
        if (!$this->report()['ready']) {
            throw new DomainException('UMI 资金配置尚未完成，请等待运营开放。');
        }
        if (!$flag) throw new DomainException('该项 UMI 业务暂未开放，请稍后再试。');
        return $s;
    }
}
