<?php
namespace App\Console\Commands\Tron;

use App\Models\Network\Network;
use App\Models\Wallet\WalletAddress;
use App\Services\Deposit\{TronGridClient, VerifiedTrxDeposit};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};
use Setting;
use RuntimeException;

class MonitorTrxDepositsCommand extends Command
{
    protected $signature = 'tron:monitor-trx-deposits';
    protected $description = 'Scan confirmed native TRX with durable pagination and atomic credit';

    public function handle(): int
    {
        if (!Network::where('id', NETWORK_TRX)->where('deposit_status', true)->exists()) return self::SUCCESS;
        if (!DB::selectOne("SELECT pg_try_advisory_lock(77321, 7) AS acquired")->acquired) return self::SUCCESS;
        try { return $this->scanAll(); }
        finally { DB::select('SELECT pg_advisory_unlock(77321, 7)'); }
    }

    private function scanAll(): int
    {
        $addresses = WalletAddress::whereIn('network_id', [NETWORK_TRX, NETWORK_TRC])->has('user')->orderBy('id')->get()->unique('address');
        $failed = false;
        foreach ($addresses as $address) {
            try { $this->check($address); }
            catch (\Throwable $e) {
                $failed = true;
                $code = preg_match('/^TRON[A-Z0-9_]+$/', $e->getMessage()) ? $e->getMessage() : 'TRON_SCAN_EXCEPTION';
                DB::table('tron_deposit_scan_states')->updateOrInsert(['address' => $address->address], ['last_error' => $code, 'updated_at' => now()]);
                Log::error('TRX deposit scan failed', ['address_id' => $address->id, 'code' => $code]);
                $this->error($code);
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    public function check(WalletAddress $address, $currency = null): bool
    {
        DB::table('tron_deposit_scan_states')->insertOrIgnore(['address' => $address->address, 'updated_at' => now()]);
        // Each page commits independently. A failed page is retried without losing the checkpoint.
        for ($page = 0; $page < 5; $page++) {
            $more = DB::transaction(function () use ($address) {
                $state = DB::table('tron_deposit_scan_states')->where('address', $address->address)->lockForUpdate()->first();
                $start = $state->window_start ?? max($address->created_at->getTimestamp()*1000, ($state->scanned_through ?? 0) - 86400000);
                $end = $state->window_end ?? now()->subMinute()->getTimestamp()*1000;
                if ($end < $start) return false;
                $query = ['only_confirmed' => 'true', 'only_to' => 'true', 'limit' => 20, 'order_by' => 'block_timestamp,asc', 'min_timestamp' => $start, 'max_timestamp' => $end];
                if ($state->fingerprint) $query['fingerprint'] = $state->fingerprint;
                $data = app(TronGridClient::class)->request('v1/accounts/'.$address->address.'/transactions', $query);
                if (!isset($data['data']) || !is_array($data['data'])) throw new RuntimeException('TRONGRID_INVALID_INDEX');
                foreach ($data['data'] as $tx) {
                    if (($tx['raw_data']['contract'][0]['type'] ?? '') !== 'TransferContract' || ($tx['ret'][0]['contractRet'] ?? '') !== 'SUCCESS') continue;
                    $hash = $tx['txID'] ?? '';
                    if (DB::table('tron_deposit_receipts')->where('txn', $hash)->exists()) continue;
                    app(VerifiedTrxDeposit::class)->process($address, $hash);
                }
                $next = $data['meta']['fingerprint'] ?? null;
                if ($next && $next === $state->fingerprint) throw new RuntimeException('TRONGRID_STALLED_CURSOR');
                DB::table('tron_deposit_scan_states')->where('address', $address->address)->update([
                    'window_start' => $next ? $start : null, 'window_end' => $next ? $end : null, 'fingerprint' => $next,
                    'scanned_through' => $next ? $state->scanned_through : $end, 'last_success_at' => now(), 'last_error' => null, 'updated_at' => now(),
                ]);
                return (bool)$next;
            });
            if (!$more) break;
        }
        return true;
    }

    public function shouldExcludeFromAddress($fromAddress): bool
    {
        $fromAddress = trim((string) $fromAddress);

        if ($fromAddress === '') {
            return true;
        }

        /*
         * 固定排除地址来源：
         *
         * 1. setting('tron.wallet')
         *    你的 TRON 归集钱包或平台钱包。
         *
         * 2. TRX_DEPOSIT_EXCLUDE_FROM_ADDRESSES
         *    .env 里面配置，多个地址用英文逗号、分号、空格或换行分隔。
         *
         * 3. tron.deposit_exclude_from_addresses
         *    settings 表里面配置，多个地址同样支持逗号、分号、空格或换行分隔。
         */
        $rawAddresses = [
            setting('tron.wallet'),
            env('TRX_DEPOSIT_EXCLUDE_FROM_ADDRESSES', ''),
            Setting::get('tron.deposit_exclude_from_addresses', ''),
        ];

        $excludedAddresses = [];

        foreach ($rawAddresses as $rawAddress) {
            if (empty($rawAddress)) {
                continue;
            }

            if (is_array($rawAddress)) {
                $items = $rawAddress;
            } else {
                $items = preg_split('/[\s,;]+/', (string) $rawAddress);
            }

            foreach ($items as $item) {
                $item = trim((string) $item);

                if ($item !== '') {
                    $excludedAddresses[] = $item;
                }
            }
        }

        $excludedAddresses = array_values(array_unique($excludedAddresses));

        return in_array($fromAddress, $excludedAddresses, true);
    }

}
