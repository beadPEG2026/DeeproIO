<?php

namespace App\Console\Commands\Deepro;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Deposit\DepositChannel;
use App\Services\Deposit\DepositChannelPolicy;
final class ImportDepositChannels extends Command
{
    protected $signature = 'deepro:deposit-channels-import {--apply : Save missing mappings as drafts only}';
    protected $description = 'Preview/import existing asset mappings; never opens a deposit channel';
    public function handle(): int
    {
        $contracts = [3 => 'contract', 6 => 'bep_contract', 8 => 'trc_contract', 16 => 'matic_contract'];
        $suffix = [3 => '_erc', 6 => '_bep', 8 => '_trc', 16 => '_matic'];
        $count = 0;
        $rows = DB::table('currency_networks')->join('currencies', 'currencies.id', '=', 'currency_networks.currency_id')->whereNull('currencies.deleted_at')->whereIn('currency_networks.network_id', array_keys(DepositChannelPolicy::NETWORKS))->select('currencies.*', 'currency_networks.network_id')->get();
        foreach ($rows as $c) {
            if (DepositChannel::where('currency_id', $c->id)->where('network_id', $c->network_id)->exists()) {
                continue;
            }
            [$chain, $kind] = DepositChannelPolicy::NETWORKS[$c->network_id];
            $s = $suffix[$c->network_id] ?? '';
            $data = ['currency_id' => $c->id, 'network_id' => $c->network_id, 'chain' => $chain, 'kind' => $kind, 'contract' => isset($contracts[$c->network_id]) ? $c->{$contracts[$c->network_id]} : null, 'decimals' => $kind === 'native' ? 18 : ($c->symbol === 'USDT' && in_array($c->network_id, [3, 8], true) ? 6 : 18), 'confirmations' => max(20, (int) $c->min_deposit_confirmation), 'minimum' => $c->min_deposit, 'fee_fixed' => $c->{'deposit_fee' . $s . '_fixed'} ?? 0, 'fee_percent' => $c->{'deposit_fee' . $s} ?? 0, 'state' => 'draft'];
            if ($this->option('apply')) {
                DepositChannel::create($data);
            }
            $this->line(json_encode(['symbol' => $c->symbol, 'network_id' => $c->network_id, 'state' => 'draft', 'applied' => (bool) $this->option('apply')]));
            $count++;
        }
        $this->info('Missing channel drafts: ' . $count);
        return self::SUCCESS;
    }
}
