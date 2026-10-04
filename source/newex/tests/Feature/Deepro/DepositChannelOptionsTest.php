<?php

namespace Tests\Feature\Deepro;

use App\Models\Deposit\DepositChannel;
use App\Services\Deposit\DepositChannelConfiguration;
use Illuminate\Support\Facades\{DB, Event, Http, Mail, Queue, Schema};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DepositChannelOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        if (!Schema::hasColumn('deposit_channels','pilot_digest')) (require database_path('migrations/2026_09_23_110000_deposit_channel_pilots.php'))->up();
        config(['app.readonly'=>false, 'cache.default'=>'array']);
        Event::fake(); Mail::fake(); Queue::fake(); Http::preventStrayRequests();
        DB::table('currencies')->where('id', 2)->update(['contract'=>'0x'.str_repeat('a',40)]);
        foreach ([2,3] as $network) DB::table('currency_networks')->updateOrInsert(['currency_id'=>2,'network_id'=>$network]);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    private function draft(int $network): array
    {
        return ['currency_id'=>2,'network_id'=>$network,'contract'=>'0x'.str_repeat('a',40),'decimals'=>6,'confirmations'=>20,'minimum'=>'2','fee_fixed'=>'0','fee_percent'=>'0','start_block'=>null,'state'=>'draft'];
    }

    public function test_forged_native_route_is_rejected_even_when_legacy_association_exists(): void
    {
        $before = DepositChannel::where('currency_id',2)->get()->toJson();
        try {
            app(DepositChannelConfiguration::class)->save($this->draft(2), null, 'isolated-options-test');
            $this->fail('A token cannot select the native ETH route');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('network_id', $e->errors());
        }
        $this->assertSame($before, DepositChannel::where('currency_id',2)->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_valid_token_configuration_keeps_existing_network_id(): void
    {
        $channel = app(DepositChannelConfiguration::class)->save($this->draft(3), null, 'isolated-options-test');
        $this->assertSame(3, (int) $channel->network_id);
        $this->assertSame('ethereum', $channel->chain);
        $this->assertSame('token', $channel->kind);
        $this->assertSame('draft', $channel->state);
        Http::assertNothingSent();
    }

    public function test_withdrawal_policy_and_available_networks_reject_only_wrong_native_identity(): void
    {
        DB::table('currencies')->where('id',2)->update(['status'=>true,'withdraw_status'=>true,'disabled_withdrawal_networks'=>'']);
        DB::table('networks')->whereIn('id',[2,3])->update(['status'=>true,'withdraw_status'=>true]);
        $policy = app(\App\Services\Wallet\WithdrawalNetworkPolicy::class);
        $this->assertSame('This network is not supported for the selected currency', $policy->error(2,2));
        $this->assertNull($policy->error(2,3));
        $currency = \App\Models\Currency\Currency::with('networks')->findOrFail(2);
        $rows = app(\App\Services\Wallet\NetworkAvailability::class)->forCurrency($currency,'withdraw',null);
        $this->assertNotContains(2,array_column($rows,'id'));
        $this->assertContains(3,array_column($rows,'id'));
        $expected = $currency->networks->reject(fn($network)=>\App\Services\Wallet\AssetNetworkOptions::nativeMismatch($currency,$network->slug))->pluck('id')->all();
        $this->assertSame($expected,array_column($rows,'id'));
        Http::assertNothingSent();
    }
}
