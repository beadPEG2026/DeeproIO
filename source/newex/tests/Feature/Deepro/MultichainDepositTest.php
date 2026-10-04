<?php

namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Wallet\{Wallet, WalletAddress};
use App\Models\Deposit\DepositChannel;
use App\Services\Deposit\{TronGridClient, VerifiedChainDeposit, DepositCreditService, DepositChannelPolicy, EvmDepositClient};
use App\Console\Commands\Tron\MonitorTrcDepositsCommand;
use App\Http\Controllers\Api\v1\WalletController;
use Illuminate\Support\Facades\{DB, Event, Http, Mail, Queue, Schema};
use Illuminate\Support\Str;
use Illuminate\Http\Request;
final class MultichainDepositTest extends TestCase
{
    private Wallet $wallet;
    private WalletAddress $address;
    private DepositChannel $channel;
    private array $receipt;
    private string $hash;
    protected function setUp(): void
    {
        parent::setUp();
        // Receipt/ledger fixtures use fake HTTP. Cross-runtime pacing has its own real-DB suite.
        $budget=\Mockery::mock(\App\Services\Deposit\TronRpcBudget::class);
        $budget->shouldReceive('acquire','outcome')->andReturnNull();
        $this->app->instance(\App\Services\Deposit\TronRpcBudget::class,$budget);

        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        if (!Schema::hasTable('deposit_channels')) {
            (require database_path('migrations/2026_09_21_030000_deposit_channels.php'))->up();
        }
        if (!Schema::hasColumn('deposit_channels', 'pilot_digest')) (require database_path('migrations/2026_09_23_110000_deposit_channel_pilots.php'))->up();
        if (!Schema::hasColumn('custody_networks', 'auto_sweep_scope')) (require database_path('migrations/2026_09_23_120000_custody_sweep_scope.php'))->up();
        if (!Schema::hasColumn('chain_deposit_scan_states', 'realtime_from')) (require database_path('migrations/2026_09_24_100000_evm_scan_lanes.php'))->up();
        if (!Schema::hasColumn('chain_deposit_scan_states', 'realtime_last_success_at')) (require database_path('migrations/2026_09_24_120000_evm_live_health.php'))->up();
        config(['deposits.explorer.logs_fallback' => false, 'deposits.explorer.native_backfill' => false, 'deposits.evm.bsc.rpc_fallbacks' => [], 'deposits.evm.ethereum.rpc_fallbacks' => [], 'deposits.evm.polygon.rpc_fallbacks' => []]);
        DB::table('custody_networks')->update(['auto_sweep_scope'=>'new_live']);
        config(['app.readonly' => false, 'services.trongrid.key' => 'fixture-only', 'cache.default' => 'array']);
        Event::fake();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        DB::table('currencies')->where('id', 2)->update(['status' => true, 'deposit_status' => true, 'disabled_deposit_networks' => '']);
        DB::table('networks')->where('id', 8)->update(['status' => true, 'deposit_status' => true]);
        $user = User::withoutEvents(fn() => User::factory()->create(['email' => 'trc-' . Str::uuid() . '@example.invalid', 'deleted' => false, 'deactivated' => false, 'is_xn' => false]));
        $this->actingAs($user);
        $this->wallet = Wallet::create(['user_id' => $user->id, 'currency_id' => 2, 'balance_in_wallet' => 0, 'balance_in_trade' => 0, 'balance_in_order' => 0, 'balance_in_withdraw' => 0, 'balance_in_virtual_wallet' => 0]);
        $this->address = new WalletAddress();
        $this->address->forceFill(['user_id' => $user->id, 'wallet_id' => $this->wallet->id, 'network_id' => 8, 'address' => TronGridClient::base58Address('41' . substr(hash('sha256', (string) Str::uuid()), 0, 40)), 'created_at' => now()->subHour()]);
        $this->address->save();
        DepositChannel::where('currency_id', 2)->where('network_id', 8)->delete();
        $this->channel = DepositChannel::create(['currency_id' => 2, 'network_id' => 8, 'chain' => 'tron', 'kind' => 'token', 'contract' => TronGridClient::base58Address('41' . str_repeat('a', 40)), 'decimals' => 6, 'confirmations' => 20, 'minimum' => '30', 'fee_fixed' => 0, 'fee_percent' => 0, 'state' => 'active', 'acceptance_reference' => 'ISOLATED FIXTURE, NOT MAINNET']);
        $this->approve();
        $this->hash = hash('sha256', (string) Str::uuid());
        $this->receipt = ['id' => $this->hash, 'blockNumber' => 100, 'blockTimeStamp' => now()->subMinutes(2)->getTimestamp() * 1000, 'receipt' => ['result' => 'SUCCESS'], 'log' => [$this->log(30000000)]];
        $this->fake();
    }
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $this->travelBack();
        parent::tearDown();
    }
    private function approve(): void
    {
        $field=[3=>'contract',6=>'bep_contract',8=>'trc_contract',16=>'matic_contract'][(int)$this->channel->network_id]??null;
        if($field)DB::table('currencies')->where('id',$this->channel->currency_id)->update([$field=>$this->channel->contract]);
        $this->channel->refresh();
        $this->channel->config_digest = $this->channel->digest();
        $this->channel->save();
    }
    private function log(int $value): array
    {
        return ['address' => str_repeat('a', 40), 'topics' => [\App\Services\Deposit\ChainAmount::TRANSFER, str_repeat('0', 24) . str_repeat('b', 40), str_repeat('0', 24) . substr(TronGridClient::hexAddress($this->address->address), 2)], 'data' => str_pad(dechex($value), 64, '0', STR_PAD_LEFT)];
    }
    private function fake(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function ($r) {
            if (str_contains($r->url(), '/v1/accounts/')) {
                return Http::response(['success' => true, 'data' => [['transaction_id' => $this->hash, 'type' => 'Transfer', 'to' => $this->address->address]], 'meta' => []]);
            }
            if (str_contains($r->url(), 'getnowblock')) {
                return Http::response(['block_header' => ['raw_data' => ['number' => 125]]]);
            }
            if (str_contains($r->url(), 'gettransactioninfobyid')) {
                return Http::response($this->receipt);
            }
            return Http::response(['txID' => $this->hash, 'ret' => [['contractRet' => 'SUCCESS']]]);
        });
    }
    private function scan(): void
    {
        app(MonitorTrcDepositsCommand::class)->check($this->address);
    }
    private function rejected(string $code): void
    {
        try {
            $this->scan();
            $this->fail('Accepted invalid receipt');
        } catch (\RuntimeException $e) {
            $this->assertSame($code, $e->getMessage());
        }
        $this->assertEquals(0, $this->wallet->fresh()->balance_in_wallet);
        $this->assertSame(0, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
    }
    public function test_discovery_receipt_credit_history_and_duplicate_retry(): void
    {
        $this->scan();
        $this->scan();
        $this->assertEquals(30, $this->wallet->fresh()->balance_in_wallet);
        $this->assertSame(1, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
        $d = DB::table('deposits')->where('txn', $this->hash)->first();
        $this->assertSame('confirmed', $d->status);
        $this->assertSame('review', $d->wallet_transfer_status);
        $this->assertSame(8, $d->network_id);
    }
    public function test_two_identical_transfer_logs_in_one_transaction_each_credit_once(): void
    {
        $this->receipt['log'][] = $this->log(30000000);
        $this->scan();
        $this->scan();
        $this->assertEquals(60, $this->wallet->fresh()->balance_in_wallet);
        $this->assertSame(2, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
    }
    public function test_unknown_contract_is_observed_without_credit(): void
    {
        $this->receipt['log'][0]['address'] = str_repeat('c', 40);
        $this->scan();
        $this->scan();
        $this->assertEquals(0, $this->wallet->fresh()->balance_in_wallet);
        $this->assertSame(1, DB::table('unrecognized_deposit_events')->where('txn', $this->hash)->count());
    }
    public function test_wrong_recipient_does_not_credit(): void
    {
        $this->receipt['log'][0]['topics'][2] = str_repeat('0', 24) . str_repeat('c', 40);
        $this->scan();
        $this->assertEquals(0, $this->wallet->fresh()->balance_in_wallet);
    }
    public function test_nft_shaped_event_is_not_treated_as_fungible_transfer(): void
    {
        $this->receipt['log'][0]['topics'][] = str_repeat('0', 64);
        $this->scan();
        $this->assertEquals(0, $this->wallet->fresh()->balance_in_wallet);
    }
    public function test_failed_receipt_rejected(): void
    {
        $this->receipt['receipt']['result'] = 'REVERT';
        $this->rejected('TRON_NOT_SUCCESSFUL_SOLID_TRANSFER');
    }
    public function test_mismatched_receipt_rejected(): void
    {
        $this->receipt['id'] = str_repeat('0', 64);
        $this->rejected('TRON_NOT_SUCCESSFUL_SOLID_TRANSFER');
    }
    public function test_confirmation_requirement_is_enforced(): void
    {
        $this->channel->update(['confirmations' => 30]);
        $this->approve();
        $this->rejected('DEPOSIT_PROOF_MISMATCH');
    }
    public function test_transfer_before_address_assignment_is_rejected(): void
    {
        $this->receipt['blockTimeStamp'] = now()->subDays(2)->getTimestamp() * 1000;
        $this->rejected('DEPOSIT_PREDATES_ADDRESS');
    }
    public function test_below_minimum_has_record_without_credit(): void
    {
        $this->receipt['log'][0] = $this->log(29999999);
        $this->scan();
        $this->assertEquals(0, $this->wallet->fresh()->balance_in_wallet);
        $this->assertSame('ignored', DB::table('deposits')->where('txn', $this->hash)->value('status'));
        $rows = app(\App\Repositories\Deposit\DepositRepository::class)->getReportUser($this->wallet->user, false);
        $this->assertCount(1, $rows);
    }
    public function test_fee_and_precision_are_exact(): void
    {
        $this->receipt['log'][0] = $this->log(30000001);
        $this->channel->update(['fee_fixed' => '0.000001']);
        $this->approve();
        $this->scan();
        $this->assertSame(0, bccomp('30', $this->wallet->fresh()->balance_in_wallet, 18));
    }
    public function test_failed_wallet_credit_rolls_back_receipt_deposit_and_cursor(): void
    {
        $mock = \Mockery::mock(DepositCreditService::class);
        $mock->shouldReceive('credit')->once()->andThrow(new \RuntimeException('DEPOSIT_FIXTURE_WRITE_FAILED'));
        $this->app->instance(DepositCreditService::class, $mock);
        $this->rejected('DEPOSIT_FIXTURE_WRITE_FAILED');
        $this->assertSame(0, DB::table('deposits')->where('txn', $this->hash)->count());
        $this->assertNull(DB::table('chain_deposit_scan_states')->where('scope', 'address:' . $this->address->address)->value('scanned_through'));
    }
    public function test_configuration_change_invalidates_acceptance(): void
    {
        $this->channel->update(['minimum' => '29']);
        $this->rejected('DEPOSIT_CHANNEL_UNAVAILABLE');
    }
    public function test_legacy_deposit_requires_reconciliation(): void
    {
        DB::table('deposits')->insert(['deposit_id' => (string) Str::uuid(), 'txn' => $this->hash, 'currency_id' => 2, 'network_id' => 8, 'user_id' => $this->wallet->user_id, 'amount' => 30, 'type' => 'coin', 'status' => 'confirmed', 'address' => $this->address->address]);
        $this->rejected('DEPOSIT_LEGACY_RECONCILIATION_REQUIRED');
    }
    public function test_duplicate_address_owner_rejected(): void
    {
        $copy = $this->address->replicate();
        $copy->user_id = 1;
        $copy->save();
        $this->rejected('DEPOSIT_ADDRESS_OWNERSHIP_CONFLICT');
    }
    public function test_pagination_retries_same_cursor_and_window(): void
    {
        $calls = [];
        $fail = true;
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function ($r) use (&$calls, &$fail) {
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
            $calls[] = $q;
            if (isset($q['fingerprint']) && $fail) {
                return Http::response([], 429, ['Retry-After'=>'1']);
            }
            return Http::response(['data' => [], 'meta' => isset($q['fingerprint']) ? [] : ['fingerprint' => 'page2']]);
        });
        try {
            $this->scan();
            $this->fail('Ignored rate limit');
        } catch (\RuntimeException $e) {
            $this->assertSame('TRONGRID_HTTP_429', $e->getMessage());
        }
        $fail = false;
        $this->scan();
        $this->assertSame($calls[1], $calls[2]);
        $this->assertSame($calls[0]['max_timestamp'], $calls[2]['max_timestamp']);
    }
    public function test_trongrid_defers_throttled_read_until_next_scheduled_attempt(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fakeSequence()->push([], 429, ['Retry-After'=>'1'])->push(['block_header'=>['raw_data'=>['number'=>123]]]);
        try {app(TronGridClient::class)->request('walletsolidity/getnowblock', []);$this->fail('Retried immediately');}catch(\RuntimeException $e){$this->assertSame('TRONGRID_HTTP_429',$e->getMessage());}
        Http::assertSentCount(1);
        $result = app(TronGridClient::class)->request('walletsolidity/getnowblock', []);
        $this->assertSame(123, $result['block_header']['raw_data']['number']);
        Http::assertSentCount(2);
        $this->assertSame(0, DB::table('chain_deposit_receipts')->where('channel_id',$this->channel->id)->count());
    }
    public function test_display_names_are_chain_specific_and_preserve_ids(): void
    {
        $this->assertSame('BNB Smart Chain (BSC)', \App\Models\Network\Network::find(5)->name);
        $this->assertSame('TRON (TRC20)', \App\Models\Network\Network::find(8)->name);
    }
    public function test_history_currency_and_network_filters_preserve_account_scope(): void
    {
        $this->scan();
        $request = Request::create('/history', 'GET', ['currency' => 7, 'network' => 5]);
        app()->instance('request', $request);
        $repo = app(\App\Repositories\Deposit\DepositRepository::class);
        $this->assertCount(0, $repo->getReportUser($this->wallet->user, false));
        $request->replace(['currency' => 2, 'network' => 8]);
        $this->assertCount(1, $repo->getReportUser($this->wallet->user, false));
    }
    public function test_only_accepted_and_current_channels_are_offered(): void
    {
        $policy = app(DepositChannelPolicy::class);
        $this->assertSame('Deposit scanner needs attention', $policy->error(2, 8));
        DB::table('chain_deposit_scan_states')->insert(['chain' => 'tron', 'scope' => 'channel:' . $this->channel->id, 'last_success_at' => now()]);
        $this->assertNull($policy->error(2, 8));
        $this->channel->update(['state' => 'draft']);
        $this->assertSame('Deposit channel is awaiting acceptance', $policy->error(2, 8));
    }
    public function test_same_symbol_cannot_override_receipt_contract(): void
    {
        $this->receipt['log'][0]['address'] = str_repeat('d', 40);
        $this->scan();
        $this->assertSame(0, DB::table('chain_deposit_receipts')->where('txn', $this->hash)->count());
    }
    private function evm(): void
    {
        $this->channel->update(['chain' => 'bsc', 'network_id' => 6, 'contract' => '0x' . str_repeat('a', 40), 'decimals' => 6, 'start_block' => 100]);
        $this->approve();
        DB::table('networks')->where('id', 6)->update(['status' => true, 'deposit_status' => true]);
        $this->address->forceFill(['network_id' => 6, 'address' => '0x' . substr(hash('sha256', (string) Str::uuid()), 0, 40)])->save();
        config(['deposits.evm.bsc.rpc' => 'https://fixture.invalid/rpc']);
        $this->hash = '0x' . $this->hash;
        $blockHash = '0x' . str_repeat('f', 64);
        $this->receipt = ['transactionHash' => $this->hash, 'blockNumber' => '0x64', 'blockHash' => $blockHash, 'status' => '0x1', 'logs' => [['transactionHash' => $this->hash, 'blockHash' => $blockHash, 'logIndex' => '0x2', 'address' => $this->channel->contract, 'topics' => ['0x' . \App\Services\Deposit\ChainAmount::TRANSFER, '0x' . str_repeat('0', 24) . str_repeat('b', 40), '0x' . str_repeat('0', 24) . substr($this->address->address, 2)], 'data' => '0x' . str_pad(dechex(30000000), 64, '0', STR_PAD_LEFT)]]];
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function ($r) use ($blockHash) {
            $v = match ($r['method']) {
                'eth_chainId' => '0x38',
                'eth_blockNumber' => '0x7d',
                'eth_getTransactionReceipt' => $this->receipt,
                'eth_getBlockByNumber' => ['hash' => $blockHash, 'timestamp' => '0x' . dechex(now()->subMinutes(2)->getTimestamp())],
                'eth_getLogs' => array_map(fn($log) => $log + ['blockNumber' => '0x64'], $this->receipt['logs']),
                'eth_getCode' => '0x1234',
                'eth_call' => '0x06',
                default => null,
            };
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $v]);
        });
    }
    private function evmProofs(): array
    {
        return app(EvmDepositClient::class)->verify($this->channel, $this->hash, $this->address->address, 125);
    }
    public function test_evm_bsc_receipt_credits_once_with_log_index(): void
    {
        $this->evm();
        $proof = $this->evmProofs()[0];
        $this->assertSame('2', $proof['event_index']);
        $service = app(VerifiedChainDeposit::class);
        $this->assertSame('credited', $service->process($this->channel, $this->address, $proof)['result']);
        $this->assertSame('already_processed', $service->process($this->channel, $this->address, $proof)['result']);
        $this->assertEquals(30, $this->wallet->fresh()->balance_in_wallet);
    }
    public function test_evm_wrong_chain_rejected(): void
    {
        $this->evm();
        config(['deposits.evm.bsc.chain_id' => 1]);
        $this->expectExceptionMessage('DEPOSIT_WRONG_CHAIN');
        app(EvmDepositClient::class)->height('bsc');
    }
    public function test_evm_reverted_transaction_rejected(): void
    {
        $this->evm();
        $this->receipt['status'] = '0x0';
        $this->expectExceptionMessage('DEPOSIT_RECEIPT_NOT_SUCCESSFUL');
        $this->evmProofs();
    }
    public function test_evm_reorg_receipt_rejected(): void
    {
        $this->evm();
        $this->receipt['blockHash'] = '0x' . str_repeat('0', 64);
        $this->expectExceptionMessage('DEPOSIT_NONCANONICAL_BLOCK');
        $this->evmProofs();
    }
    public function test_evm_insufficient_confirmations_rejected(): void
    {
        $this->evm();
        $this->channel->confirmations = 30;
        $this->expectExceptionMessage('DEPOSIT_AWAITING_CONFIRMATIONS');
        $this->evmProofs();
    }
    public function test_evm_removed_log_rejected(): void
    {
        $this->evm();
        $this->receipt['logs'][0]['removed'] = true;
        $this->expectExceptionMessage('DEPOSIT_INVALID_TRANSFER_LOG');
        $this->evmProofs();
    }
    public function test_evm_wrong_contract_does_not_credit(): void
    {
        $this->evm();
        $this->receipt['logs'][0]['address'] = '0x' . str_repeat('d', 40);
        $this->assertSame([], $this->evmProofs());
    }
    public function test_evm_duplicate_log_index_rejected(): void
    {
        $this->evm();
        $this->receipt['logs'][] = $this->receipt['logs'][0];
        $this->expectExceptionMessage('DEPOSIT_DUPLICATE_LOG_INDEX');
        $this->evmProofs();
    }
    public function test_evm_signing_methods_are_forbidden(): void
    {
        $this->evm();
        $this->expectExceptionMessage('DEPOSIT_RPC_METHOD_FORBIDDEN');
        app(EvmDepositClient::class)->rpc('bsc', 'eth_sendTransaction');
    }
    public function test_evm_token_precision_checked_on_chain(): void
    {
        $this->evm();
        app(EvmDepositClient::class)->validateToken($this->channel);
        $this->channel->decimals = 18;
        $this->expectExceptionMessage('DEPOSIT_DECIMALS_MISMATCH');
        app(EvmDepositClient::class)->validateToken($this->channel);
    }
    public function test_evm_scanner_credit_and_checkpoint_retries_idempotently(): void
    {
        $this->evm();
        $scanner = app(\App\Console\Commands\Deepro\ScanEvmDeposits::class);
        $scanner->check($this->channel);
        $scanner->check($this->channel);
        $this->assertEquals(30, $this->wallet->fresh()->balance_in_wallet);
        $state = DB::table('chain_deposit_scan_states')->where('scope', 'channel:' . $this->channel->id)->first();
        $this->assertSame(106, $state->scanned_through);
        $this->assertNull($state->last_error);
    }
    public function test_evm_failed_page_does_not_advance_checkpoint(): void
    {
        $this->evm();
        $mock = \Mockery::mock(DepositCreditService::class);
        $mock->shouldReceive('credit')->andThrow(new \RuntimeException('DEPOSIT_FIXTURE_WRITE_FAILED'));
        $this->app->instance(DepositCreditService::class, $mock);
        try {
            app(\App\Console\Commands\Deepro\ScanEvmDeposits::class)->check($this->channel);
            $this->fail('Skipped failed credit');
        } catch (\RuntimeException $e) {
            $this->assertSame('DEPOSIT_FIXTURE_WRITE_FAILED', $e->getMessage());
        }
        $this->assertNull(DB::table('chain_deposit_scan_states')->where('scope', 'channel:' . $this->channel->id)->value('scanned_through'));
        $this->assertEquals(0, $this->wallet->fresh()->balance_in_wallet);
    }
    public function test_unaccepted_direct_address_request_is_denied(): void
    {
        $this->channel->update(['state' => 'draft']);
        $request = \App\Http\Requests\Api\Wallet\GetAddressRequest::create('/address', 'GET', ['symbol' => 'USDT', 'network' => 8]);
        $response = app(WalletController::class)->getAddress($request);
        $this->assertSame(403, $response->getStatusCode());
    }
    public function test_regular_user_cannot_manage_channels(): void
    {
        $this->get(route('admin.deposit-channels'))->assertRedirect(route('admin.login'));
        $this->getJson(route('admin.deposit-channels.preflight',['depositChannel'=>$this->channel->id]))->assertForbidden();
    }
    private function native(string $chain = 'bsc'): void
    {
        $this->evm();
        $id = $chain === 'ethereum' ? 1 : 7;
        $network = $chain === 'ethereum' ? 2 : 5;
        if ($chain === 'polygon') {
            $currency = \App\Models\Currency\Currency::findOrFail(7)->replicate();
            $currency->symbol = 'POL';
            $currency->save();
            $id = $currency->id;
            $network = 15;
            DB::table('currency_networks')->insert(['currency_id' => $id, 'network_id' => $network]);
        }
        $this->wallet->update(['currency_id' => $id]);
        $this->address->forceFill(['network_id' => $network])->save();
        DB::table('currencies')->where('id', $id)->update(['status' => true, 'deposit_status' => true, 'disabled_deposit_networks' => '']);
        DB::table('networks')->where('id', $network)->update(['status' => true, 'deposit_status' => true]);
        $this->channel->update(['currency_id' => $id, 'network_id' => $network, 'chain' => $chain, 'kind' => 'native', 'contract' => null, 'decimals' => 18, 'minimum' => '1']);
        $this->approve();
        config(['deposits.evm.' . $chain . '.rpc' => 'https://fixture.invalid']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function ($r) use ($chain) {
            $tx = ['hash' => $this->hash, 'blockHash' => $this->receipt['blockHash'], 'to' => $this->address->address, 'from' => '0x' . str_repeat('b', 40), 'value' => '0xde0b6b3a7640000'];
            if (isset($r[0])) {
                return Http::response(array_map(fn($call) => ['id' => $call['id'], 'result' => ['number' => '0x' . dechex($call['id']), 'transactions' => $call['id'] === 100 ? [$tx] : []]], $r->data()));
            }
            return Http::response(['result' => match ($r['method']) {
                'eth_chainId' => match ($chain) { 'ethereum' => '0x1', 'polygon' => '0x89', default => '0x38' },
                'eth_blockNumber' => '0x7d',
                'eth_getTransactionReceipt' => $this->receipt,
                'eth_getTransactionByHash' => $tx,
                'eth_getBlockByNumber' => ['hash' => $this->receipt['blockHash'], 'timestamp' => '0x' . dechex(now()->subMinutes(2)->getTimestamp()), 'transactions' => $r['params'][0] === '0x64' ? [$tx] : []],
                default => null,
            }]);
        });
    }
    public function test_native_bnb_discovery_credits_one_bnb_once(): void
    {
        $this->native();
        $scanner = app(\App\Console\Commands\Deepro\ScanEvmDeposits::class);
        $scanner->check($this->channel);
        $scanner->check($this->channel);
        $this->assertEquals(1, $this->wallet->fresh()->balance_in_wallet);
        $this->assertSame('native', DB::table('chain_deposit_receipts')->where('txn', $this->hash)->value('event_index'));
    }
    public function test_native_eth_exact_wei_conversion(): void
    {
        $this->native('ethereum');
        $proof = app(EvmDepositClient::class)->verify($this->channel, $this->hash, $this->address->address, 125)[0];
        $this->assertSame('1000000000000000000', $proof['raw_amount']);
        app(VerifiedChainDeposit::class)->process($this->channel, $this->address, $proof);
        $this->assertEquals(1, $this->wallet->fresh()->balance_in_wallet);
    }
    public function test_native_polygon_credits_pol_without_relabeling_another_asset(): void
    {
        $this->native('polygon');
        $scanner = app(\App\Console\Commands\Deepro\ScanEvmDeposits::class);
        $scanner->check($this->channel);
        $scanner->check($this->channel);
        $this->assertSame('POL', $this->channel->fresh()->currency->symbol);
        $this->assertEquals(1, $this->wallet->fresh()->balance_in_wallet);
    }
    public function test_channel_contract_must_match_the_currency_network_mapping(): void
    {
        DB::table('currencies')->where('id', 2)->update(['trc_contract' => TronGridClient::base58Address('41' . str_repeat('c', 40))]);
        $this->channel->refresh();
        $this->assertSame('Invalid token contract', app(DepositChannelPolicy::class)->configurationError($this->channel));
        $this->assertEquals(0, $this->wallet->fresh()->balance_in_wallet);
    }
    public function test_native_asset_cannot_credit_another_currency(): void
    {
        $this->native();
        $this->channel->update(['currency_id' => 2]);
        $this->approve();
        $this->assertSame('Invalid native asset configuration', app(DepositChannelPolicy::class)->configurationError($this->channel));
    }
    public function test_rpc_failure_keeps_previous_checkpoint(): void
    {
        $this->evm();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response(['error' => ['message' => 'unavailable']], 503)]);
        try {
            app(\App\Console\Commands\Deepro\ScanEvmDeposits::class)->check($this->channel);
            $this->fail('Accepted provider failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('DEPOSIT_RPC_FAILED', $e->getMessage());
        }
        $this->assertEquals(0, $this->wallet->fresh()->balance_in_wallet);
    }
    public function test_admin_cannot_activate_when_node_verification_fails(): void
    {
        $this->channel->update(['state' => 'draft']);
        $this->approve();
        $data = $this->channel->only(['currency_id', 'network_id', 'contract', 'decimals', 'confirmations', 'minimum', 'fee_fixed', 'fee_percent', 'start_block', 'acceptance_reference']);
        $data['state'] = 'active';
        $request = Request::create('/admin/channels', 'POST', $data);
        $request->setUserResolver(fn() => $this->wallet->user);
        try {
            app(\App\Http\Controllers\Web\Admin\DepositChannelController::class)->store($request);
            $this->fail('Skipped validation');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('state', $e->errors());
        }
        $this->assertSame('draft', $this->channel->fresh()->state);
    }
    public function test_admin_configuration_stage_sequence_and_audit(): void
    {
        $this->evm();
        $this->channel->update(['state' => 'draft']);
        $this->approve();
        foreach (['validated', 'pilot', 'tested', 'active'] as $state) {
            $data = $this->channel->fresh()->only(['currency_id', 'network_id', 'contract', 'decimals', 'confirmations', 'minimum', 'fee_fixed', 'fee_percent', 'start_block', 'acceptance_reference']);
            $data += ['pilot_user_ids' => [$this->wallet->user_id], 'pilot_minimum' => '1', 'pilot_limit' => '50'];
            $data['state'] = $state;
            $request = Request::create('/admin/channels', 'POST', $data);
            $request->setUserResolver(fn() => $this->wallet->user);
            app(\App\Http\Controllers\Web\Admin\DepositChannelController::class)->store($request);
            $this->assertSame($state, $this->channel->fresh()->state);
            if ($state === 'pilot') {
                $this->travel(3)->minutes();
                app(VerifiedChainDeposit::class)->process($this->channel->fresh(), $this->address, $this->evmProofs()[0]);
            }
        }
        $this->assertSame(4, DB::table('deposit_channel_audits')->where('channel_id', $this->channel->id)->count());
        $data['minimum'] = '31';
        $request = Request::create('/admin/channels', 'POST', $data);
        $request->setUserResolver(fn() => $this->wallet->user);
        app(\App\Http\Controllers\Web\Admin\DepositChannelController::class)->store($request);
        $this->assertSame('active', $this->channel->fresh()->state);
        $this->assertFalse($this->channel->fresh()->hasPilotEvidence());
        $this->assertStringContainsString('funded test is optional', $this->channel->fresh()->acceptance_reference);
    }
    public function test_direct_activation_requires_technical_checks_but_not_a_funded_receipt(): void
    {
        $this->evm(); $this->channel->update(['state'=>'draft', 'config_digest'=>null, 'acceptance_reference'=>null]);
        $data = $this->channel->fresh()->only(['currency_id','network_id','contract','decimals','confirmations','minimum','fee_fixed','fee_percent','start_block']);
        $data['state'] = 'active';
        $saved = app(\App\Services\Deposit\DepositChannelConfiguration::class)->save($data, $this->wallet->user_id);
        $this->assertSame('active', $saved->state);
        $this->assertSame($saved->digest(), $saved->config_digest);
        $this->assertFalse($saved->hasPilotEvidence());
        $this->assertSame(0, DB::table('chain_deposit_receipts')->where('channel_id',$saved->id)->count());
        $this->assertNull(app(DepositChannelPolicy::class)->error($saved->currency_id,$saved->network_id,false));
        $this->assertSame('Deposit scanner needs attention',app(DepositChannelPolicy::class)->error($saved->currency_id,$saved->network_id));
    }
    public function test_bulk_open_dry_run_rolls_back_switches_and_apply_preserves_withdrawal_settings(): void
    {
        $this->evm(); $this->channel->update(['state'=>'draft','config_digest'=>null]);
        $asset=\App\Models\Currency\Currency::find($this->channel->currency_id);
        $asset->update(['deposit_status'=>false,'withdraw_status'=>false,'disabled_deposit_networks'=>[6],'disabled_withdrawal_networks'=>[6]]);
        $args=['--channel'=>[$this->channel->id]];
        $this->artisan('deepro:open-deposit-channels',$args)->assertExitCode(0);
        $this->assertFalse($asset->fresh()->deposit_status); $this->assertSame('draft',$this->channel->fresh()->state);
        $this->artisan('deepro:open-deposit-channels',$args+['--apply'=>true])->assertExitCode(0);
        $this->assertTrue($asset->fresh()->deposit_status); $this->assertSame('active',$this->channel->fresh()->state);
        $this->assertFalse($asset->fresh()->withdraw_status); $this->assertSame([6],$asset->fresh()->disabled_withdrawal_networks);
        $this->assertSame([], $asset->fresh()->disabled_deposit_networks);
    }
    public function test_recent_scan_health_is_independent_of_historical_backfill_and_expires(): void
    {
        $this->evm();
        DB::table('chain_deposit_scan_states')->insert(['chain'=>'bsc','scope'=>$this->channel->scanScope(),'scanned_through'=>101,'realtime_from'=>1000,'realtime_through'=>1200,'window_end'=>1250,'last_error'=>'DEPOSIT_SCAN_BUDGET','last_success_at'=>now(),'realtime_last_success_at'=>now(),'realtime_last_error'=>null,'updated_at'=>now()]);
        $this->assertNull(app(DepositChannelPolicy::class)->error(2,6));
        DB::table('chain_deposit_scan_states')->where('chain','bsc')->update(['realtime_last_error'=>'DEPOSIT_RPC_FAILED']);
        $this->assertSame('Deposit scanner needs attention',app(DepositChannelPolicy::class)->error(2,6));
        DB::table('chain_deposit_scan_states')->where('chain','bsc')->update(['realtime_last_error'=>null,'realtime_last_success_at'=>now()->subMinutes(16)]);
        $this->assertSame('Deposit scanner needs attention',app(DepositChannelPolicy::class)->error(2,6));
    }

    public function test_uint256_too_large_for_ledger_is_rejected(): void
    {
        $this->expectExceptionMessage('DEPOSIT_AMOUNT_OUT_OF_RANGE');
        \App\Services\Deposit\ChainAmount::decimal(str_repeat('9', 77), 18);
    }
    private function pilot(string $limit = '2'): void
    {
        $this->channel->update(['state'=>'pilot', 'pilot_user_ids'=>[$this->wallet->user_id], 'pilot_minimum'=>'1', 'pilot_limit'=>$limit, 'pilot_started_at'=>now()->subMinutes(30)->startOfSecond(), 'pilot_expires_at'=>now()->addDay()->startOfSecond(), 'pilot_start_block'=>$this->channel->chain === 'tron' ? null : 100]);
        $this->channel->refresh();
        $this->channel->update(['pilot_digest'=>$this->channel->pilotDigest()]);
        $this->receipt['log'][0] = $this->log(1000000);
    }
    public function test_pilot_receipt_cap_duplicates_and_manual_custody(): void
    {
        $this->pilot();
        $this->address->forceFill(['private_key'=>'isolated-fixture-not-a-key'])->save();
        DB::table('custody_networks')->where('chain','tron')->update(['enabled'=>true,'auto_sweep'=>true,'max_fee'=>'100']);
        \Setting::set('tron.wallet', TronGridClient::base58Address('41'.str_repeat('d',40)));
        $command=app(MonitorTrcDepositsCommand::class);
        $command->check($this->address,$this->channel); $command->check($this->address,$this->channel);
        $first=DB::table('deposits')->where('txn',$this->hash)->first();
        $this->assertEquals(1,$this->wallet->fresh()->balance_in_wallet);
        $this->assertSame('pilot',json_decode($first->initial_raw,true)['deposit_mode']);
        $task=app(\App\Services\Custody\CustodyService::class)->sweep($first->id);
        $this->assertSame('awaiting_approval',$task->status);
        for($i=0;$i<2;$i++) {
            $this->hash=hash('sha256',(string)Str::uuid()); $this->receipt['id']=$this->hash;
            $command->check($this->address,$this->channel);
        }
        $this->assertEquals(2,$this->wallet->fresh()->balance_in_wallet);
        $last=DB::table('deposits')->where('txn',$this->hash)->first();
        $this->assertSame('ignored',$last->status);
        $this->assertSame('DEPOSIT_PILOT_LIMIT_EXCEEDED',json_decode($last->initial_raw,true)['pilot_reason']);
        $this->assertTrue($this->channel->hasPilotEvidence());
        $this->assertEquals(30,$this->channel->minimum);
        $this->assertNull(DB::table('chain_deposit_scan_states')->where('scope','address:'.$this->address->address)->first());
    }
    private function pendingPilotSweep(): object
    {
        $this->pilot();
        $this->address->forceFill(['private_key'=>'isolated-fixture-not-a-key'])->save();
        DB::table('custody_networks')->where('chain','tron')->update(['enabled'=>true,'auto_sweep'=>true,'auto_sweep_scope'=>'new_live','max_fee'=>'100','confirmations'=>20]);
        \Setting::set('tron.wallet', TronGridClient::base58Address('41'.str_repeat('d',40)));
        app(MonitorTrcDepositsCommand::class)->check($this->address,$this->channel);
        $id=DB::table('deposits')->where('txn',$this->hash)->value('id');
        return app(\App\Services\Custody\CustodyService::class)->sweep($id);
    }

    public function test_scope_enables_pending_verified_pilot_once_without_crediting_or_signing(): void
    {
        $task=$this->pendingPilotSweep();
        $automation=app(\App\Services\Custody\SweepAutomation::class);
        $bridge=\Mockery::mock(\App\Services\Custody\CustodyBridge::class);$bridge->shouldNotReceive('call');$this->app->instance(\App\Services\Custody\CustodyBridge::class,$bridge);
        $this->assertFalse($automation->approve($task->id));
        DB::table('custody_networks')->where('chain','tron')->update(['auto_sweep_scope'=>'all_verified','max_fee'=>'50','confirmations'=>30]);
        $this->assertTrue($automation->approve($task->id));$this->assertFalse($automation->approve($task->id));
        $row=DB::table('custody_transfers')->find($task->id);
        $this->assertSame('approved',$row->status);$this->assertSame(0,bccomp('50',$row->max_fee,18));$this->assertSame(30,$row->confirmations);
        $this->assertNull($row->approved_by);$this->assertNull($row->signed_payload);$this->assertNull($row->txn);
        $this->assertEquals('1',$this->wallet->fresh()->balance_in_wallet);
        $this->assertSame(1,DB::table('custody_audits')->where('transfer_id',$task->id)->where('action','transfer.auto_approved')->count());
        $this->assertNull(DB::table('custody_audits')->where('transfer_id',$task->id)->where('action','transfer.auto_approved')->value('actor_id'));
        DB::table('custody_networks')->where('chain','tron')->update(['auto_sweep'=>false]);
        app(\App\Services\Custody\CustodyService::class)->run($task->id);
        $this->assertSame('CUSTODY_AUTO_SWEEP_PAUSED',DB::table('custody_transfers')->find($task->id)->last_error);
    }

    public function test_all_verified_scope_automates_new_pilot_and_live_deposits(): void
    {
        $task=$this->pendingPilotSweep();
        DB::table('custody_networks')->where('chain','tron')->update(['auto_sweep_scope'=>'all_verified']);
        $this->hash=hash('sha256',(string)Str::uuid());$this->receipt['id']=$this->hash;
        app(MonitorTrcDepositsCommand::class)->check($this->address,$this->channel);
        $next=app(\App\Services\Custody\CustodyService::class)->sweep(DB::table('deposits')->where('txn',$this->hash)->value('id'));
        $this->assertSame('approved',$next->status);$this->assertNull($next->txn);
        // Production receipts still auto-approve under the compatible default scope.
        DB::table('custody_networks')->where('chain','tron')->update(['auto_sweep_scope'=>'new_live']);
        $this->channel->update(['state'=>'active','pilot_digest'=>null]);$this->approve();
        $this->hash=hash('sha256',(string)Str::uuid());$this->receipt['id']=$this->hash;$this->receipt['log'][0]=$this->log(30000000);
        app(MonitorTrcDepositsCommand::class)->check($this->address);
        $next=app(\App\Services\Custody\CustodyService::class)->sweep(DB::table('deposits')->where('txn',$this->hash)->value('id'));
        $this->assertSame('approved',$next->status);
    }

    public function test_scheduled_cycle_picks_up_eligible_pending_sweeps(): void
    {
        $task=$this->pendingPilotSweep();
        DB::table('custody_networks')->where('chain','tron')->update(['auto_sweep_scope'=>'all_verified']);
        $bridge=\Mockery::mock(\App\Services\Custody\CustodyBridge::class);$bridge->shouldNotReceive('call');
        $service=\Mockery::mock(\App\Services\Custody\CustodyService::class,[$bridge])->makePartial();
        $service->shouldReceive('cold')->andReturn(null);
        $checked=[];$service->shouldReceive('run')->andReturnUsing(function($id)use(&$checked){$checked[]=$id;});
        $this->app->instance(\App\Services\Custody\CustodyService::class,$service);
        $this->artisan('wallets:custody-run')->assertExitCode(0);
        $this->assertSame('approved',DB::table('custody_transfers')->find($task->id)->status);
        $this->assertContains($task->id,$checked);
        $this->assertNull(DB::table('custody_transfers')->find($task->id)->signed_payload);
    }

    /** @dataProvider unsafeSweepCases */
    public function test_pending_automation_rechecks_identity_and_respects_manual_stops(string $case): void
    {
        $task=$this->pendingPilotSweep();
        DB::table('custody_networks')->where('chain','tron')->update(['auto_sweep_scope'=>'all_verified']);
        switch ($case) {
            case 'amount': DB::table('custody_transfers')->where('id',$task->id)->update(['amount'=>'1.1']);break;
            case 'destination': DB::table('custody_transfers')->where('id',$task->id)->update(['destination'=>TronGridClient::base58Address('41'.str_repeat('e',40))]);break;
            case 'receipt': DB::table('chain_deposit_receipts')->where('deposit_id',$task->deposit_id)->delete();break;
            case 'credit': DB::table('chain_deposit_receipts')->where('deposit_id',$task->deposit_id)->update(['credited_amount'=>'0']);break;
            case 'owner': DB::table('deposits')->where('id',$task->deposit_id)->update(['user_id'=>144]);break;
            case 'manual_retry': DB::table('custody_audits')->insert(['action'=>'transfer.resumed','actor_id'=>144,'transfer_id'=>$task->id,'detail'=>'{}','created_at'=>now()]);break;
            case 'signature': DB::table('custody_transfers')->where('id',$task->id)->update(['txn'=>'signed-fixture']);break;
            case 'cancelled': DB::table('custody_transfers')->where('id',$task->id)->update(['status'=>'cancelled']);break;
            case 'disabled': DB::table('custody_networks')->where('chain','tron')->update(['auto_sweep'=>false]);break;
            case 'wrong_purpose': DB::table('custody_transfers')->where('id',$task->id)->update(['purpose'=>'cold']);break;
        }
        $this->assertFalse(app(\App\Services\Custody\SweepAutomation::class)->approve($task->id));
        $this->assertNotSame('approved',DB::table('custody_transfers')->find($task->id)->status);
        $this->assertEquals('1',$this->wallet->fresh()->balance_in_wallet);
        $this->assertFalse(DB::table('custody_audits')->where('transfer_id',$task->id)->where('action','transfer.auto_approved')->exists());
    }
    public static function unsafeSweepCases(): array
    {
        return array_map(fn($v)=>[$v],['amount','destination','receipt','credit','owner','manual_retry','signature','cancelled','disabled','wrong_purpose']);
    }

    public function test_pilot_whitelist_and_changed_limits_fail_closed(): void
    {
        $this->pilot();$policy=app(DepositChannelPolicy::class);
        $this->assertSame('Deposits are unavailable',$policy->error(2,8,false,$this->wallet->user_id+100000));
        $this->assertNull($policy->error(2,8,false,$this->wallet->user_id));
        $this->channel->update(['pilot_limit'=>'200']);
        $this->assertSame('Deposit channel is awaiting acceptance',$policy->error(2,8,false,$this->wallet->user_id));
        $this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);
    }
    public function test_expired_pilot_allows_delayed_confirmations_but_not_new_transfers(): void
    {
        $this->pilot(); $this->channel->update(['pilot_expires_at'=>now()->subMinute()->startOfSecond()]);
        $this->channel->refresh();$this->channel->update(['pilot_digest'=>$this->channel->pilotDigest()]);
        $this->assertSame('Deposits are unavailable',app(DepositChannelPolicy::class)->error(2,8,true,$this->wallet->user_id));
        app(MonitorTrcDepositsCommand::class)->check($this->address,$this->channel);
        $this->assertEquals(1,$this->wallet->fresh()->balance_in_wallet);
        $proof=json_decode(DB::table('deposits')->where('txn',$this->hash)->value('initial_raw'),true);
        $proof['txn']=hash('sha256',(string)Str::uuid());$proof['timestamp']=now()->getTimestamp()*1000;
        $result=app(VerifiedChainDeposit::class)->process($this->channel,$this->address,$proof);
        $this->assertSame('DEPOSIT_OUTSIDE_PILOT_WINDOW',$result['result']);
        $this->assertEquals(1,$this->wallet->fresh()->balance_in_wallet);
    }
    public function test_tested_state_requires_a_real_verified_receipt(): void
    {
        $this->pilot();
        $data=$this->channel->toArray();$data['state']='tested';
        $r=Request::create('/admin/channels','POST',$data);$r->setUserResolver(fn()=>$this->wallet->user);
        try {app(\App\Http\Controllers\Web\Admin\DepositChannelController::class)->store($r);$this->fail('Unverified channel promoted');}
        catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('state',$e->errors());}
        $this->assertSame('pilot',$this->channel->fresh()->state);
    }
    public function test_evm_historical_start_precedes_first_assigned_address_without_state_change(): void
    {
        $this->evm();$this->channel->update(['state'=>'draft','start_block'=>null]);
        $earliest=WalletAddress::whereIn('network_id',config('deposits.evm.bsc.networks'))->has('user')->min('created_at');
        $epoch=\Carbon\Carbon::parse($earliest)->getTimestamp()-300;
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function($r)use($epoch){
            $v=match($r['method']){
                'eth_chainId'=>'0x38','eth_blockNumber'=>'0x7d','eth_getCode'=>'0x1234','eth_call'=>'0x06',
                'eth_getBlockByNumber'=>['number'=>$r['params'][0],'hash'=>'0x'.str_repeat('f',64),'timestamp'=>'0x'.dechex($epoch+3*hexdec($r['params'][0]))],default=>null};
            return Http::response(['jsonrpc'=>'2.0','id'=>1,'result'=>$v]);
        });
        $plan=app(\App\Services\Deposit\DepositChannelReadiness::class)->inspect($this->channel->fresh());
        $this->assertSame(99,$plan['suggested_start_block']);$this->assertTrue($plan['precision_matches']);
        $this->assertNull($this->channel->fresh()->start_block);
        $this->artisan('deepro:plan-deposit-channels',['--channel'=>[$this->channel->id],'--apply-drafts'=>true])->assertExitCode(0);
        $this->assertSame(99,$this->channel->fresh()->start_block);
        $this->assertSame('draft',$this->channel->fresh()->state);
        $this->assertNull(DB::table('deposit_channel_audits')->where('channel_id',$this->channel->id)->value('actor_id'));
        $this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);
    }

    public function test_initialization_rejects_precision_mismatch_without_mutating_draft(): void
    {
        $this->evm();$this->channel->update(['state'=>'draft','start_block'=>null]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn($r)=>Http::response(['jsonrpc'=>'2.0','id'=>1,'result'=>match($r['method']){
            'eth_chainId'=>'0x38','eth_blockNumber'=>'0x7d','eth_getCode'=>'0x1234','eth_call'=>'0x12',
            'eth_getBlockByNumber'=>['number'=>$r['params'][0],'hash'=>'0x'.str_repeat('f',64),'timestamp'=>'0x0'],default=>null}]));
        $this->artisan('deepro:plan-deposit-channels',['--channel'=>[$this->channel->id],'--apply-drafts'=>true])->assertExitCode(1);
        $this->assertNull($this->channel->fresh()->start_block);
        $this->assertSame(0,DB::table('deposit_channel_audits')->where('channel_id',$this->channel->id)->count());
    }
    public function test_initialization_does_not_change_active_channels(): void
    {
        $this->evm();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        $this->artisan('deepro:plan-deposit-channels',['--channel'=>[$this->channel->id],'--apply-drafts'=>true])->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertSame(100,$this->channel->fresh()->start_block);
        $this->assertSame('active',$this->channel->fresh()->state);
    }

    public function test_delayed_pilot_receipt_retains_limits_after_public_activation(): void
    {
        $this->pilot();
        app(MonitorTrcDepositsCommand::class)->check($this->address,$this->channel);
        $proof=json_decode(DB::table('deposits')->where('txn',$this->hash)->value('initial_raw'),true);
        $this->channel->update(['state'=>'active']);
        $proof['txn']=hash('sha256',(string)Str::uuid());
        $result=app(VerifiedChainDeposit::class)->process($this->channel,$this->address,$proof);
        $this->assertSame('credited',$result['result']);
        $this->assertSame('pilot',json_decode(DB::table('deposits')->where('id',$result['deposit_id'])->value('initial_raw'),true)['deposit_mode']);
        $proof['txn']=hash('sha256',(string)Str::uuid());
        $result=app(VerifiedChainDeposit::class)->process($this->channel,$this->address,$proof);
        $this->assertSame('DEPOSIT_PILOT_LIMIT_EXCEEDED',$result['result']);
        $this->assertEquals(2,$this->wallet->fresh()->balance_in_wallet);
    }

    public function test_recent_address_start_does_not_require_pruned_ancient_blocks(): void
    {
        $this->evm();$this->channel->update(['state'=>'draft','start_block'=>null]);
        $earliest=WalletAddress::whereIn('network_id',config('deposits.evm.bsc.networks'))->has('user')->min('created_at');
        $epoch=\Carbon\Carbon::parse($earliest)->getTimestamp()-3*9950;
        Http::swap(new \Illuminate\Http\Client\Factory());
        $retained=9940;
        Http::fake(function($r)use($epoch,&$retained){
            $n=($r['method']==='eth_getBlockByNumber')?hexdec($r['params'][0]):null;
            $v=match($r['method']){
                'eth_chainId'=>'0x38','eth_blockNumber'=>'0x2710','eth_getCode'=>'0x1234','eth_call'=>'0x06',
                'eth_getBlockByNumber'=>$n<$retained?null:['number'=>$r['params'][0],'hash'=>'0x'.str_repeat('f',64),'timestamp'=>'0x'.dechex($epoch+3*$n)],default=>null};
            return Http::response(['jsonrpc'=>'2.0','id'=>1,'result'=>$v]);
        });
        $plan=app(\App\Services\Deposit\DepositChannelReadiness::class)->inspect($this->channel->fresh());
        $this->assertSame(9949,$plan['suggested_start_block']);
        $this->assertNull($this->channel->fresh()->start_block);
        $retained=9990;
        $this->expectExceptionMessage('DEPOSIT_HISTORICAL_BLOCK_UNAVAILABLE');
        (new \App\Services\Deposit\DepositChannelReadiness())->inspect($this->channel->fresh());
    }

}
