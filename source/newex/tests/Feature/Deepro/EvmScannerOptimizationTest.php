<?php

namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Currency\Currency;
use App\Models\Wallet\{Wallet, WalletAddress};
use App\Models\Deposit\DepositChannel;
use App\Console\Commands\Deepro\ScanEvmDeposits;
use App\Services\Deposit\{ChainAmount, EvmDepositClient, EvmExplorerClient, EvmLogDiscovery};
use Illuminate\Support\Facades\{DB, Event, Http, Mail, Queue, Schema};
use Illuminate\Support\Str;

final class EvmScannerOptimizationTest extends TestCase
{
    private array $channels = [], $wallets = [], $addresses = [], $logs = [], $calls = [];
    private int $head = 150;
    private string $hash, $blockHash;
    private bool $failCredit = false;
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        if (!Schema::hasColumn('deposit_channels', 'pilot_digest')) (require database_path('migrations/2026_09_23_110000_deposit_channel_pilots.php'))->up();
        if (!Schema::hasColumn('chain_deposit_scan_states', 'realtime_from')) (require database_path('migrations/2026_09_24_100000_evm_scan_lanes.php'))->up();
        if (!Schema::hasColumn('chain_deposit_scan_states', 'realtime_last_success_at')) (require database_path('migrations/2026_09_24_120000_evm_live_health.php'))->up();
        config(['app.readonly' => false, 'cache.default' => 'array', 'deposits.evm.bsc.rpc' => 'https://primary.invalid', 'deposits.evm.bsc.rpc_fallbacks' => [], 'deposits.explorer.key' => null, 'deposits.explorer.logs_fallback' => false, 'deposits.explorer.native_backfill' => false, 'deposits.explorer.interval_seconds' => 0]);
        Event::fake(); Mail::fake(); Queue::fake();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        $this->hash = '0x' . hash('sha256', (string) Str::uuid()); $this->blockHash = '0x' . str_repeat('f', 64);
        DB::table('networks')->where('id', 6)->update(['status' => true, 'deposit_status' => true]);
        for ($n = 0; $n < 2; $n++) {
            $currency = Currency::findOrFail(2)->replicate();
            $currency->forceFill(['symbol' => 'QA' . substr(str_replace('-', '', (string) Str::uuid()), 0, 12), 'bep_contract' => '0x' . str_repeat($n ? 'c' : 'a', 40), 'status' => true, 'deposit_status' => true, 'disabled_deposit_networks' => '', 'asset_category' => 'crypto']); $currency->save();
            DB::table('currency_networks')->insert(['currency_id' => $currency->id, 'network_id' => 6]);
            $c = DepositChannel::create(['currency_id' => $currency->id, 'network_id' => 6, 'chain' => 'bsc', 'kind' => 'token', 'contract' => $currency->bep_contract, 'decimals' => 6, 'confirmations' => 20, 'minimum' => 0, 'fee_fixed' => 0, 'fee_percent' => 0, 'state' => 'active', 'start_block' => 100, 'acceptance_reference' => 'ISOLATED FIXTURE']);
            $c->refresh(); $c->update(['config_digest' => $c->digest()]); $this->channels[] = $c;
        }
        for ($n = 0; $n < 2; $n++) {
            $u = User::withoutEvents(fn() => User::factory()->create(['email' => Str::uuid() . '@example.invalid', 'deleted' => false, 'deactivated' => false, 'is_xn' => false]));
            foreach ($this->channels as $c) $this->wallets[$n][$c->id] = Wallet::create(['user_id' => $u->id, 'currency_id' => $c->currency_id, 'balance_in_wallet' => 0, 'balance_in_trade' => 0, 'balance_in_order' => 0, 'balance_in_withdraw' => 0, 'balance_in_virtual_wallet' => 0]);
            $a = new WalletAddress(); $a->forceFill(['user_id' => $u->id, 'wallet_id' => $this->wallets[$n][$this->channels[0]->id]->id, 'network_id' => 6, 'address' => '0x' . substr(hash('sha256', (string) Str::uuid()), 0, 40), 'created_at' => now()->subHour()])->save(); $this->addresses[] = $a;
            foreach ($this->channels as $c) $this->logs[] = ['transactionHash' => $this->hash, 'blockHash' => $this->blockHash, 'blockNumber' => '0x64', 'logIndex' => '0x' . dechex(count($this->logs)), 'address' => $c->contract, 'topics' => ['0x' . ChainAmount::TRANSFER, '0x' . str_repeat('0', 24) . str_repeat('b', 40), '0x' . str_repeat('0', 24) . substr($a->address, 2)], 'data' => '0x' . str_pad(dechex(30000000), 64, '0', STR_PAD_LEFT)];
        }
        $this->fake();
    }
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }
    private function fake(?callable $override = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function ($r) use ($override) {
            $this->calls[] = $r;
            if ($override && ($response = $override($r)) !== null) return $response;
            $value = match ($r['method']) {
                'eth_chainId' => '0x38', 'eth_blockNumber' => '0x' . dechex($this->head),
                'eth_getLogs' => array_values(array_filter($this->logs, fn($l) => hexdec($l['blockNumber']) >= hexdec($r['params'][0]['fromBlock']) && hexdec($l['blockNumber']) <= hexdec($r['params'][0]['toBlock']))),
                'eth_getTransactionReceipt' => ['status' => '0x1', 'transactionHash' => $this->hash, 'blockHash' => $this->blockHash, 'blockNumber' => '0x64', 'logs' => $this->logs],
                'eth_getBlockByNumber' => ['hash' => $this->blockHash, 'timestamp' => '0x' . dechex(now()->subMinutes(2)->timestamp)],
                default => null,
            };
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $value]);
        });
    }
    private function countCalls(string $method): int { return count(array_filter($this->calls, fn($r) => ($r->data()['method'] ?? null) === $method)); }
    private function state(int $index = 0): object { return DB::table('chain_deposit_scan_states')->where('scope', $this->channels[$index]->scanScope())->first(); }

    public function test_grouped_discovery_matches_all_receipts_with_one_query_and_no_duplicate_credit(): void
    {
        $scanner = app(ScanEvmDeposits::class); $this->assertTrue($scanner->runChannels($this->channels, 'live', true));
        $this->assertSame(1, $this->countCalls('eth_getLogs'));
        $this->assertSame(1, $this->countCalls('eth_getTransactionReceipt'));
        $this->assertSame(1, $this->countCalls('eth_blockNumber'));
        $this->assertSame(4, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
        foreach ($this->wallets as $group) foreach ($group as $w) $this->assertEquals(30, $w->fresh()->balance_in_wallet);
        DB::table('chain_deposit_scan_states')->whereIn('scope', array_map(fn($c) => $c->scanScope(), $this->channels))->update(['scanned_through' => 99]);
        $scanner->runChannels($this->channels, 'live', true);
        foreach ($this->wallets as $group) foreach ($group as $w) $this->assertEquals(30, $w->fresh()->balance_in_wallet);
    }
    public function test_multi_contract_and_recipient_filter_cuts_eighty_queries_to_one(): void
    {
        $this->logs = [];
        $tokens = array_map(fn($n) => '0x' . str_pad(dechex($n), 40, '0', STR_PAD_LEFT), range(1, 16));
        $addresses = array_map(fn($n) => '0x' . str_pad(dechex($n), 40, '0', STR_PAD_LEFT), range(100, 104));
        $client = new EvmDepositClient(); $client->beginRound(100);
        (new EvmLogDiscovery($client, new EvmExplorerClient()))->logs('bsc', $tokens, $addresses, 1, 200);
        $this->assertSame(1, $this->countCalls('eth_getLogs'));
        $r = array_values(array_filter($this->calls, fn($r) => $r['method'] === 'eth_getLogs'))[0];
        $this->assertCount(16, $r['params'][0]['address']); $this->assertCount(5, $r['params'][0]['topics'][2]);
    }
    public function test_provider_range_limit_splits_without_skipping_blocks(): void
    {
        $this->fake(fn($r) => $r['method'] === 'eth_getLogs' && hexdec($r['params'][0]['toBlock']) - hexdec($r['params'][0]['fromBlock']) > 15 ? Http::response(['error' => ['code' => -32005]]) : null);
        $this->assertTrue(app(ScanEvmDeposits::class)->runChannels($this->channels, 'live', true));
        $this->assertSame(131, $this->state()->scanned_through);
        $this->assertSame(4, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
        $this->assertSame(3, $this->countCalls('eth_getLogs'));
    }
    public function test_partial_range_failure_keeps_cursor_and_balances_unchanged(): void
    {
        config(['deposits.scan.live_requests' => 12]);
        $this->fake(fn($r) => $r['method'] === 'eth_getLogs' && hexdec($r['params'][0]['toBlock']) >= 116 ? Http::response(['error' => ['code' => -32005]]) : null);
        $this->assertFalse(app(ScanEvmDeposits::class)->runChannels($this->channels));
        $this->assertNull($this->state()->scanned_through);
        $this->assertSame(0, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
    }
    public function test_live_and_backfill_cursors_preserve_gap_then_join(): void
    {
        config(['deposits.scan.live_window' => 10, 'deposits.scan.backfill_pages' => 1]);
        $scanner = app(ScanEvmDeposits::class); $scanner->runChannels($this->channels, 'live', true);
        $this->assertNull($this->state()->scanned_through); $this->assertSame(122, $this->state()->realtime_from); $this->assertSame(131, $this->state()->realtime_through);
        $this->assertSame(0, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
        $this->head = 155; $scanner->runChannels($this->channels, 'live', true);
        $this->assertSame(136, $this->state()->realtime_through);
        $scanner->runChannels($this->channels, 'backfill', true);
        $this->assertSame(136, $this->state()->scanned_through); $this->assertNull($this->state()->realtime_from);
        $this->assertSame(4, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
    }
    public function test_wrong_chain_primary_fails_over_to_verified_secondary(): void
    {
        config(['deposits.evm.bsc.rpc_fallbacks' => ['https://secondary.invalid']]);
        $this->fake(fn($r) => str_contains($r->url(), 'primary') ? Http::response(['result' => '0x1']) : null);
        $this->assertTrue(app(ScanEvmDeposits::class)->runChannels($this->channels, 'live', true));
        $this->assertSame(4, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
    }
    public function test_budget_exhaustion_does_not_advance_progress(): void
    {
        config(['deposits.scan.live_requests' => 3]);
        $this->assertFalse(app(ScanEvmDeposits::class)->runChannels($this->channels));
        $this->assertNull($this->state()->scanned_through);
        $this->assertSame('DEPOSIT_SCAN_BUDGET', $this->state()->last_error);
    }
    public function test_receipt_cache_is_discarded_between_rounds(): void
    {
        $client = new EvmDepositClient();
        foreach ([1, 2] as $round) { $client->beginRound(10); $client->rpc('bsc', 'eth_getTransactionReceipt', [$this->hash]); $client->rpc('bsc', 'eth_getTransactionReceipt', [$this->hash]); $client->endRound(); }
        $this->assertSame(2, $this->countCalls('eth_getTransactionReceipt'));
    }
    public function test_ethereum_small_pages_preserve_progress_and_resume_after_budget_exhaustion(): void
    {
        $currency = Currency::where('symbol', 'ETH')->firstOrFail();
        $currency->update(['status'=>true, 'deposit_status'=>true, 'disabled_deposit_networks'=>'', 'asset_category'=>'crypto']);
        DB::table('networks')->where('id', 2)->update(['status'=>true, 'deposit_status'=>true]);
        DB::table('currency_networks')->insertOrIgnore(['currency_id'=>$currency->id, 'network_id'=>2]);
        $c = DepositChannel::updateOrCreate(['currency_id'=>$currency->id, 'network_id'=>2], ['chain'=>'ethereum', 'kind'=>'native', 'contract'=>null, 'decimals'=>18, 'confirmations'=>1, 'minimum'=>0, 'fee_fixed'=>0, 'fee_percent'=>0, 'state'=>'active', 'start_block'=>100, 'acceptance_reference'=>'ISOLATED FIXTURE']);
        $c->refresh(); $c->update(['config_digest'=>$c->digest()]);
        DB::table('chain_deposit_scan_states')->where('chain','ethereum')->where('scope',$c->scanScope())->delete();
        config(['deposits.evm.ethereum.rpc'=>'https://ethereum.invalid', 'deposits.evm.ethereum.rpc_fallbacks'=>[], 'deposits.scan.live_requests'=>8]);
        $ranges=[];
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function($r) use (&$ranges) {
            $data=$r->data();
            if (array_is_list($data)) {
                $ranges[]=array_column($data,'id');
                return Http::response(array_map(fn($q)=>['jsonrpc'=>'2.0','id'=>$q['id'],'result'=>['number'=>$q['params'][0],'transactions'=>[]]],$data));
            }
            return Http::response(['jsonrpc'=>'2.0','id'=>1,'result'=>$r['method']==='eth_chainId'?'0x1':'0x6d']);
        });
        $state=fn()=>DB::table('chain_deposit_scan_states')->where('chain','ethereum')->where('scope',$c->scanScope())->first();
        $scanner=app(ScanEvmDeposits::class);
        $this->assertFalse($scanner->runChannels([$c]));
        $this->assertSame(104,$state()->scanned_through);
        $this->assertSame('DEPOSIT_SCAN_BUDGET',$state()->last_error);
        $this->assertTrue($scanner->runChannels([$c]));
        $this->assertSame(109,$state()->scanned_through);
        $this->assertNull($state()->last_error);
        $this->assertSame([range(100,104),range(105,109)],$ranges);
    }
    public function test_explorer_paginates_and_rejects_repeated_full_page(): void
    {
        config(['deposits.explorer.key' => 'test-fixture', 'deposits.explorer.page_size' => 1]);
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::fake(fn() => Http::response(['status' => '1', 'message' => 'OK', 'result' => [$this->logs[0]]]));
        $this->expectExceptionMessage('DEPOSIT_EXPLORER_REPEATED_PAGE');
        (new EvmExplorerClient())->logs('bsc', $this->channels[0]->contract, 100, 101);
    }
    public function test_explorer_quota_error_is_not_an_empty_successful_scan(): void
    {
        config(['deposits.explorer.key' => 'test-fixture']);
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::fake(fn() => Http::response(['status' => '0', 'message' => 'NOTOK', 'result' => 'Max rate limit reached']));
        $this->expectExceptionMessage('DEPOSIT_EXPLORER_FAILED');
        (new EvmExplorerClient())->logs('bsc', $this->channels[0]->contract, 100, 101);
    }
    public function test_explorer_native_history_discovers_hashes_only_and_filters_outgoing(): void
    {
        config(['deposits.explorer.key' => 'test-fixture']);
        $address = $this->addresses[0]->address;
        $rows = [['hash' => $this->hash, 'to' => $address, 'value' => '1000000000000000000', 'blockNumber' => '100', 'isError' => '0'], ['hash' => $this->hash, 'to' => '0x' . str_repeat('9', 40), 'value' => '99', 'blockNumber' => '100', 'isError' => '0']];
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::fake(fn() => Http::response(['status' => '1', 'message' => 'OK', 'result' => $rows]));
        $this->assertSame([$this->hash], (new EvmExplorerClient())->nativeTransactions('bsc', $address, 100, 101));
    }
    public function test_discovery_log_missing_from_receipt_does_not_advance_cursor(): void
    {
        $this->fake(fn($r) => $r['method'] === 'eth_getTransactionReceipt' ? Http::response(['result' => ['status' => '0x1', 'transactionHash' => $this->hash, 'blockHash' => $this->blockHash, 'blockNumber' => '0x64', 'logs' => []]]) : null);
        $this->assertFalse(app(ScanEvmDeposits::class)->runChannels($this->channels));
        $this->assertNull($this->state()->scanned_through);
        $this->assertSame('DEPOSIT_DISCOVERY_RECEIPT_MISMATCH', $this->state()->last_error);
    }
    public function test_pilot_recipient_isolation_survives_grouped_discovery(): void
    {
        $c = $this->channels[0];
        $c->forceFill(['state' => 'pilot', 'pilot_user_ids' => [$this->addresses[0]->user_id], 'pilot_started_at' => now()->subMinutes(10), 'pilot_expires_at' => now()->addMinutes(10), 'pilot_start_block' => 100, 'pilot_minimum' => 1, 'pilot_limit' => 100])->save();
        $c->refresh(); $c->update(['pilot_digest' => $c->pilotDigest()]);
        $this->assertTrue(app(ScanEvmDeposits::class)->runChannels($this->channels, 'live', true));
        $this->assertEquals(30, $this->wallets[0][$c->id]->fresh()->balance_in_wallet);
        $this->assertEquals(0, $this->wallets[1][$c->id]->fresh()->balance_in_wallet);
        $this->assertSame(2, $this->countCalls('eth_getLogs'));
    }
    public function test_etherscan_provider_uses_existing_key_and_all_pages_without_rpc_logs(): void
    {
        config(['deposits.evm.bsc.logs_provider' => 'etherscan', 'deposits.explorer.key' => 'test-fixture', 'deposits.explorer.page_size' => 2]);
        $this->fake(function ($r) {
            if (!str_contains($r->url(), 'api.etherscan.io')) return null;
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);
            $this->assertSame('56', $query['chainid']); $this->assertSame('test-fixture', $query['apikey']);
            $rows = array_values(array_filter($this->logs, fn($l) => strtolower($l['address']) === strtolower($query['address'])));
            return Http::response(['status' => '1', 'message' => 'OK', 'result' => array_slice($rows, ((int)$query['page']-1)*2, 2)]);
        });
        $this->assertTrue(app(ScanEvmDeposits::class)->runChannels($this->channels, 'live', true));
        $this->assertSame(0, $this->countCalls('eth_getLogs'));
        $this->assertSame(4, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
    }
    public function test_reorged_receipt_in_combined_scan_keeps_entire_page_uncredited(): void
    {
        $this->fake(fn($r) => $r['method'] === 'eth_getBlockByNumber' ? Http::response(['result' => ['hash' => '0x' . str_repeat('e', 64), 'timestamp' => '0x1']]) : null);
        $this->assertFalse(app(ScanEvmDeposits::class)->runChannels($this->channels));
        $this->assertNull($this->state()->scanned_through);
        $this->assertSame(0, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
    }

    public function test_explorer_bare_zero_index_matches_receipt_without_duplicate_credit(): void
    {
        config(['deposits.evm.bsc.logs_provider' => 'etherscan', 'deposits.explorer.key' => 'test-fixture']);
        $this->fake(function ($r) {
            if (!str_contains($r->url(), 'api.etherscan.io')) return null;
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);
            $rows = array_values(array_filter($this->logs, fn($l) => strtolower($l['address']) === strtolower($query['address'])));
            foreach ($rows as &$row) if ($row['logIndex'] === '0x0') $row['logIndex'] = '0x';
            unset($row);
            return Http::response(['status' => '1', 'message' => 'OK', 'result' => $rows]);
        });
        $this->assertTrue(app(ScanEvmDeposits::class)->runChannels($this->channels, 'live', true));
        $this->assertSame(4, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
        DB::table('chain_deposit_scan_states')->where('chain', 'bsc')->update(['scanned_through' => null]);
        $this->assertTrue(app(ScanEvmDeposits::class)->runChannels($this->channels, 'live', true));
        $this->assertSame(4, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
        $this->assertEquals(30, $this->wallets[0][$this->channels[0]->id]->fresh()->balance_in_wallet);
    }
    public function test_explorer_empty_index_is_not_treated_as_zero(): void
    {
        config(['deposits.evm.bsc.logs_provider' => 'etherscan', 'deposits.explorer.key' => 'test-fixture']);
        $row = $this->logs[0]; $row['logIndex'] = '';
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn() => Http::response(['status' => '1', 'message' => 'OK', 'result' => [$row]]));
        $this->expectExceptionMessage('DEPOSIT_INVALID_HEX');
        (new EvmLogDiscovery(new EvmDepositClient(), new EvmExplorerClient()))->logs('bsc', [$this->channels[0]->contract], [$this->addresses[0]->address], 100, 100);
    }

    public function test_archive_rpc_failure_falls_back_to_read_only_explorer_proxy(): void
    {
        config(['deposits.explorer.proxy_fallback' => true, 'deposits.explorer.key' => 'test-fixture']);
        $this->fake(function ($r) {
            if (str_contains($r->url(), 'api.etherscan.io')) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
                $this->assertSame('eth_getTransactionReceipt', $q['action']);
                $this->assertSame('56', $q['chainid']);
                return Http::response(['jsonrpc' => '2.0', 'result' => ['transactionHash' => $this->hash]]);
            }
            return $r['method'] === 'eth_getTransactionReceipt' ? Http::response(['result' => null]) : null;
        });
        $client = new EvmDepositClient(); $client->beginRound(10);
        $this->assertSame($this->hash, $client->rpc('bsc', 'eth_getTransactionReceipt', [$this->hash])['transactionHash']);
        $this->expectExceptionMessage('DEPOSIT_RPC_METHOD_FORBIDDEN');
        (new EvmExplorerClient())->proxy('bsc', 'eth_sendRawTransaction', ['forbidden']);
    }

    public function test_shared_explorer_key_handles_complete_rpc_outage_on_all_three_chains(): void
    {
        config(['deposits.explorer.proxy_fallback' => true, 'deposits.explorer.key' => 'test-fixture']);
        foreach (['bsc' => 56, 'ethereum' => 1, 'polygon' => 137] as $chain => $chainId) {
            config(['deposits.evm.' . $chain . '.rpc' => 'https://offline.invalid', 'deposits.evm.' . $chain . '.rpc_fallbacks' => []]);
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(function ($r) use ($chainId) {
                if (!str_contains($r->url(), 'api.etherscan.io')) return Http::response(['error' => ['code' => -32001]]);
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);
                $this->assertSame((string) $chainId, $query['chainid']);
                $this->assertSame('test-fixture', $query['apikey']);
                return Http::response(['jsonrpc' => '2.0', 'result' => $query['action'] === 'eth_blockNumber' ? '0x96' : ['number' => $query['tag'], 'transactions' => []]]);
            });
            $client = new EvmDepositClient(); $client->beginRound(20);
            $this->assertSame(150, $client->height($chain));
            $this->assertCount(2, $client->blocks($chain, 100, 101));
            $client->endRound();
        }
    }
    public function test_explorer_cannot_hide_incorrect_chain_mapping(): void
    {
        config(['deposits.explorer.key' => 'test-fixture', 'deposits.evm.bsc.chain_id' => 1]);
        $this->expectExceptionMessage('DEPOSIT_WRONG_CHAIN');
        (new EvmExplorerClient())->height('bsc');
    }

    public function test_recipient_index_combines_assets_and_ignores_unlisted_nft_events(): void
    {
        config(['deposits.explorer.key'=>'test-fixture','deposits.explorer.recipient_filter'=>true,'deposits.evm.bsc.logs_provider'=>'etherscan']);
        $calls=0;
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function($r) use (&$calls) {
            $calls++; parse_str(parse_url($r->url(),PHP_URL_QUERY),$q);
            $this->assertArrayNotHasKey('address',$q);
            $this->assertSame('56',$q['chainid']);
            $rows=array_values(array_filter($this->logs,fn($l)=>$l['topics'][2]===$q['topic2']));
            $nft=$rows[0];$nft['address']='0x'.str_repeat('e',40);$nft['topics'][]='0x'.str_repeat('0',64);$nft['data']='0x';$rows[]=$nft;
            return Http::response(['status'=>'1','message'=>'OK','result'=>$rows]);
        });
        $rows=(new EvmLogDiscovery(new EvmDepositClient(),new EvmExplorerClient()))->logs('bsc',array_map(fn($c)=>$c->contract,$this->channels),array_map(fn($a)=>$a->address,$this->addresses),100,100);
        $this->assertCount(4,$rows); $this->assertSame(count($this->addresses),$calls);
    }


    public function test_round_budget_does_not_shrink_backfill_range_or_skip_gap(): void
    {
        config(['deposits.scan.live_window'=>10, 'deposits.scan.backfill_pages'=>1]);
        $scanner=app(ScanEvmDeposits::class);$scanner->runChannels($this->channels,'live',true);
        DB::table('chain_deposit_scan_states')->whereIn('scope',array_map(fn($c)=>$c->scanScope(),$this->channels))->update(['backfill_range_cap'=>16]);
        config(['deposits.scan.backfill_requests'=>2]);
        $this->assertTrue($scanner->runChannels($this->channels,'backfill'));
        $this->assertNull($this->state()->scanned_through);
        $this->assertEquals(16,$this->state()->backfill_range_cap);
        config(['deposits.scan.backfill_requests'=>100]);
        $scanner->runChannels($this->channels,'backfill',true);
        $this->assertSame(115,$this->state()->scanned_through);
        $this->assertEquals(32,$this->state()->backfill_range_cap);
    }
    public function test_historical_indexed_discovery_does_not_change_live_provider(): void
    {
        config(['deposits.evm.bsc.logs_provider'=>'rpc','deposits.explorer.key'=>'test-fixture','deposits.explorer.recipient_filter'=>true]);
        $this->fake(function($r){
            if(str_contains($r->url(),'etherscan.io'))return Http::response(['status'=>'0','message'=>'No records found','result'=>[]]);
            return null;
        });
        $client=new EvmDepositClient();$client->beginRound(100);
        $contracts=[$this->channels[0]->contract];$addresses=[$this->addresses[0]->address];
        (new EvmLogDiscovery($client,new EvmExplorerClient(),true))->logs('bsc',$contracts,$addresses,100,1000000);
        $this->assertSame(0,$this->countCalls('eth_getLogs'));
        (new EvmLogDiscovery($client,new EvmExplorerClient()))->logs('bsc',$contracts,$addresses,100,150);
        $this->assertSame(1,$this->countCalls('eth_getLogs'));
    }

    public function test_idle_backfill_makes_no_network_requests_or_cursor_changes(): void
    {
        $scanner=app(ScanEvmDeposits::class);
        $this->assertTrue($scanner->runChannels($this->channels,'backfill',true));
        Http::assertNothingSent();
        $scanner->runChannels($this->channels,'live',true);
        $before=$this->state();$this->calls=[];Http::swap(new \Illuminate\Http\Client\Factory());Http::preventStrayRequests();
        $this->assertTrue($scanner->runChannels($this->channels,'backfill',true));
        Http::assertNothingSent();$this->assertEquals($before,$this->state());
    }

    public function test_idle_backfill_resumes_when_live_lane_creates_a_new_gap(): void
    {
        $scanner=app(ScanEvmDeposits::class);$scanner->runChannels($this->channels,'backfill',true);
        config(['deposits.scan.live_window'=>10]);$scanner->runChannels($this->channels,'live',true);
        $this->assertNotNull($this->state()->realtime_from);
        $scanner->runChannels($this->channels,'backfill',true);
        $this->assertNull($this->state()->realtime_from);
        $this->assertSame(4,DB::table('chain_deposit_receipts')->where('txn',$this->hash)->count());
    }
}
