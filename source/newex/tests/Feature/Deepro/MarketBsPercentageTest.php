<?php

namespace Tests\Feature\Deepro;

use App\Models\Market\Market;
use App\Models\User\User;
use Illuminate\Support\Facades\{DB, Event, Http, Mail, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

final class MarketBsPercentageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['app.readonly' => false, 'cache.default' => 'array', 'session.driver' => 'array',
            'broadcasting.default' => 'log', 'admin-controls.simulation_controls' => false]);
        DB::beginTransaction();
        Event::fake(); Mail::fake(); Queue::fake(); Http::preventStrayRequests();
        $admin = User::withoutEvents(fn () => User::factory()->create([
            'email' => 'bs-test-'.Str::uuid().'@example.invalid', 'deleted' => false, 'deactivated' => false,
        ]));
        $admin->assignRole('superadmin');
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    private function payload(Market $market): array
    {
        return [
            'id' => $market->id, 'name' => $market->name,
            'base_currency_id' => $market->base_currency_id, 'quote_currency_id' => $market->quote_currency_id,
            'base_precision' => 8, 'quote_precision' => 8, 'base_ticker_size' => '0.00000001', 'quote_ticker_size' => '0.00000001',
            'min_trade_size' => '0.00000001', 'max_trade_size' => '1000', 'min_trade_value' => '0.00000001', 'max_trade_value' => '1000000',
            'status' => true, 'trade_status' => true, 'buy_order_status' => true, 'sell_order_status' => true, 'cancel_order_status' => true,
            'discount' => 0, 'discount_bid' => 0,
        ];
    }

    public function test_existing_api_persists_ratios_without_changing_price_or_other_chart_settings(): void
    {
        $market = Market::where('name', 'BTC-USDT')->firstOrFail();
        $before = $market->only(['last', 'chart_source', 'chart_symbol', 'chart_default_resolution', 'bot_price_floor', 'bot_price_ceiling']);
        foreach (['0.3', '0.3', '0.305', '0', '2.5', null] as $ratio) {
            $this->putJson(route('admin.markets.update', $market->id), $this->payload($market) + ['bs' => $ratio])->assertRedirect();
            $stored = $market->fresh();
            $this->assertSame($ratio, $stored->getRawOriginal('bs'));
            $this->assertEquals($before, $stored->only(array_keys($before)));
        }
        $this->putJson(route('admin.markets.update', $market->id), $this->payload($market) + ['bs' => '30%'])->assertUnprocessable()->assertJsonValidationErrors('bs');
        $this->assertNull($market->fresh()->bs);
    }

    public function test_create_keeps_the_submitted_bs_and_omitting_bs_does_not_reset_it(): void
    {
        $base = Market::where('name', 'BTC-USDT')->firstOrFail();
        // Removing the fixture inside this transaction makes its pair available to the create route.
        $base->delete();
        $payload = $this->payload($base);
        unset($payload['id']);
        $payload['name'] = 'BS-TEST';
        $payload['last'] = '100';
        $payload['bs'] = '0.3';
        $this->postJson(route('admin.markets.store'), $payload)->assertRedirect();
        $created = Market::where('name', 'BS-TEST')->firstOrFail();
        $this->assertSame('0.3', $created->getRawOriginal('bs'));
        $this->putJson(route('admin.markets.update', $created->id), $this->payload($created))->assertRedirect();
        $this->assertSame('0.3', $created->fresh()->getRawOriginal('bs'));
    }

    public function test_bs_only_save_works_with_legacy_zero_limits_and_cannot_update_other_fields(): void
    {
        $market = Market::where('name', 'BTC-USDT')->firstOrFail();
        $limits = array_fill_keys(['min_trade_size', 'max_trade_size', 'min_trade_value', 'max_trade_value'], '0');
        $market->forceFill($limits)->save();
        $before = $market->fresh()->getRawOriginal();
        unset($before['bs'], $before['updated_at']);

        $this->putJson(route('admin.markets.update', $market->id),
            array_merge($this->payload($market), $limits, ['bs' => '0.5']))
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($limits));

        foreach (['0.5', '0.5', '0.3', '0', null] as $ratio) {
            $this->putJson(route('admin.markets.bs.update', $market->id), [
                'bs' => $ratio, 'last' => 999999, 'status' => false, 'min_trade_size' => 500,
                'chart_symbol' => 'DO-NOT-SAVE',
            ])->assertOk()->assertExactJson(['bs' => $ratio]);
            $after = $market->fresh()->getRawOriginal();
            $this->assertSame($ratio, $after['bs']);
            unset($after['bs'], $after['updated_at']);
            $this->assertSame($before, $after);
        }

        foreach ([[], ['bs' => '30%'], ['bs' => ['0.3']], ['bs' => 'NaN']] as $invalid) {
            $this->putJson(route('admin.markets.bs.update', $market->id), $invalid)
                ->assertUnprocessable()->assertJsonValidationErrors('bs');
            $this->assertNull($market->fresh()->bs);
        }
    }

    public function test_bs_only_route_preserves_role_and_read_only_protection(): void
    {
        $market = Market::where('name', 'BTC-USDT')->firstOrFail();
        $before = $market->getRawOriginal('bs');
        config(['app.readonly' => true]);
        $this->putJson(route('admin.markets.bs.update', $market->id), ['bs' => '0.5'])->assertStatus(423);
        config(['app.readonly' => false]);
        $user = User::withoutEvents(fn () => User::factory()->create([
            'email' => 'bs-user-'.Str::uuid().'@example.invalid', 'deleted' => false, 'deactivated' => false,
        ]));
        $user->assignRole('user');
        $this->actingAs($user)->putJson(route('admin.markets.bs.update', $market->id), ['bs' => '0.5'])->assertForbidden();
        $this->assertSame($before, $market->fresh()->getRawOriginal('bs'));
    }
}
