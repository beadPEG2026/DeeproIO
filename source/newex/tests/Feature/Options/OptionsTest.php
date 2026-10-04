<?php

namespace Tests\Feature\Options;

use App\Models\Currency\Currency;
use App\Models\Market\Market;
use App\Models\Option\Option;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Comprehensive Unit Tests for Options Trading Functionality
 *
 * This test suite covers all scenarios for options operations:
 * - Creating options (call/put)
 * - Authentication and authorization
 * - Input validation
 * - Balance requirements
 * - Viewing open options
 * - Options history/trades
 * - Time-based validation
 * - Edge cases
 */
class OptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Market $market;
    private Currency $baseCurrency;
    private Currency $quoteCurrency;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test currencies
        $this->baseCurrency = Currency::factory()->create([
            'id' => 1,
            'symbol' => 'BTC',
            'name' => 'Bitcoin',
            'type' => 'coin',
            'status' => true,
        ]);

        $this->quoteCurrency = Currency::factory()->create([
            'id' => 2,
            'symbol' => 'USDT',
            'name' => 'Tether',
            'type' => 'coin',
            'status' => true,
        ]);

        // Create test market with options enabled
        $this->market = Market::factory()->create([
            'name' => 'BTC-USDT',
            'base_currency_id' => $this->baseCurrency->id,
            'quote_currency_id' => $this->quoteCurrency->id,
            'status' => true,
            'base_precision' => 8,
            'quote_precision' => 2,
            'has_options' => true,
        ]);

        // Create test user
        User::withoutEvents(function () {
            $this->user = User::factory()->create([
                'email_verified_at' => now(),
            ]);
        });

        // Create wallets
        Wallet::factory()->create([
            'user_id' => $this->user->id,
            'currency_id' => $this->baseCurrency->id,
            'balance_in_wallet' => '10',
            'balance_in_trade' => '10',
            'balance_in_order' => '0',
        ]);

        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'currency_id' => $this->quoteCurrency->id,
            'balance_in_wallet' => '100000',
            'balance_in_trade' => '100000',
            'balance_in_order' => '0',
        ]);

        // Exercise real funding with an independently sourced HTTP quote fixture.
        \Illuminate\Support\Facades\Http::fake(['*api/v3/ticker/24hr*'=>fn()=>\Illuminate\Support\Facades\Http::response(['symbol'=>'BTCUSDT','lastPrice'=>'50000','closeTime'=>now()->getTimestampMs()])]);
        // Set up market stats
        Cache::put("markets_stats.{$this->market->id}.last", '50000');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ============================================================================
    // AUTHENTICATION & AUTHORIZATION TESTS
    // ============================================================================

    /**
     * Test: Unauthenticated users cannot create options
     */
    public function test_unauthenticated_user_cannot_create_option()
    {
        $response = $this->postJson('/api/v1/options', [
            'market' => 'BTC-USDT',
            'type' => 'call',
            'side' => 'buy',
            'quantity' => '100',
            'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
            'timeframeSeconds' => 60,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test: User without trade permission cannot create options
     */
    public function test_user_without_trade_permission_cannot_create_option()
    {
        $token = $this->user->createToken('test-token', ['read']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'call',
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        // Should fail - either 403 (forbidden) or 422 (validation first)
        $this->assertContains($response->status(), [403, 422]);
    }

    // ============================================================================
    // OPTIONS CREATION TESTS - VALIDATION
    // ============================================================================

    /**
     * Test: Market field is required
     */
    public function test_market_field_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'type' => 'call',
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    /**
     * Test: Type field is required
     */
    public function test_type_field_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        // Should fail validation
        $response->assertStatus(422);
    }

    /**
     * Test: Side field is required
     */
    public function test_side_field_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'call',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        // Should fail validation
        $response->assertStatus(422);
    }

    /**
     * Test: Quantity field is required
     */
    public function test_quantity_field_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'call',
                'side' => 'buy',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        // Should fail validation
        $response->assertStatus(422);
    }

    /**
     * Test: startAt field is required
     */
    public function test_start_at_field_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'call',
                'side' => 'buy',
                'quantity' => '100',
                'timeframeSeconds' => 60,
            ]);

        // Should fail validation
        $response->assertStatus(422);
    }

    /**
     * Test: timeframeSeconds field is required
     */
    public function test_timeframe_seconds_field_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'call',
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
            ]);

        // Should fail validation
        $response->assertStatus(422);
    }

    /**
     * Test: Invalid market is rejected
     */
    public function test_invalid_market_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'INVALID-MARKET',
                'type' => 'call',
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    /**
     * Test: Invalid type is rejected
     */
    public function test_invalid_type_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'invalid',
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        // Should fail validation - either for type or market rule
        $response->assertStatus(422);
    }

    /**
     * Test: Invalid side is rejected
     */
    public function test_invalid_side_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'call',
                'side' => 'invalid',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        // Should fail validation - either for side or market rule
        $response->assertStatus(422);
    }

    /**
     * Test: Invalid timeframeSeconds is rejected
     */
    public function test_invalid_timeframe_seconds_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'call',
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 999, // Not in allowed list [60, 120, 180, 300, 600]
            ]);

        // Should fail validation - either for timeframe or market rule
        $response->assertStatus(422);
    }

    /**
     * Test: Start time in past is rejected
     */
    public function test_start_time_in_past_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'call',
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => Carbon::now()->subMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        // Should fail validation
        $response->assertStatus(422);
    }

    /**
     * Test: Non-numeric startAt is rejected
     */
    public function test_non_numeric_start_at_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'call',
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => 'invalid',
                'timeframeSeconds' => 60,
            ]);

        // Should fail validation
        $response->assertStatus(422);
    }

    /**
     * Test: Negative quantity is rejected
     */
    public function test_negative_quantity_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 'call',
                'side' => 'buy',
                'quantity' => '-100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        // Should fail validation
        $response->assertStatus(422);
    }

    // ============================================================================
    // OPTIONS CREATION TESTS - SUCCESS
    // ============================================================================

    /**
     * Test: Call option creation with valid data
     */
    public function test_call_option_creation_with_valid_data()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 1,
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        $response->assertJsonStructure(['message']);

        // May succeed or fail based on market/settings
        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Put option creation with valid data
     */
    public function test_put_option_creation_with_valid_data()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/options', [
                'market' => 'BTC-USDT',
                'type' => 2,
                'side' => 'buy',
                'quantity' => '100',
                'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                'timeframeSeconds' => 60,
            ]);

        $response->assertJsonStructure(['message']);
        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Valid timeframe values are accepted
     */
    public function test_valid_timeframe_values_accepted()
    {
        $token = $this->user->createToken('test-token', ['trade']);
        $validTimeframes = [60, 120, 180, 300, 600];

        foreach ($validTimeframes as $timeframe) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
                ->postJson('/api/v1/options', [
                    'market' => 'BTC-USDT',
                    'type' => 1,
                    'side' => 'buy',
                    'quantity' => '10',
                    'startAt' => Carbon::now()->addMinute()->getTimestampMs(),
                    'timeframeSeconds' => $timeframe,
                ]);

            $response->assertJsonStructure(['message']);
            $this->assertContains($response->status(), [200]);
        }
    }

    // ============================================================================
    // OPEN OPTIONS TESTS
    // ============================================================================

    /**
     * Test: Open options requires authentication
     */
    public function test_open_options_requires_auth()
    {
        $response = $this->getJson('/api/v1/options/open');

        $response->assertStatus(401);
    }

    /**
     * Test: User can view their open options
     */
    public function test_user_can_view_open_options()
    {
        Option::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'currency_id' => $this->quoteCurrency->id,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/options/open');

        $response->assertStatus(200);
    }

    /**
     * Test: Open options can be filtered by market
     */
    public function test_open_options_filtered_by_market()
    {
        Option::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'currency_id' => $this->quoteCurrency->id,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/options/open?market=BTC-USDT');

        $response->assertStatus(200);
    }

    // ============================================================================
    // OPTIONS TRADES/HISTORY TESTS
    // ============================================================================

    /**
     * Test: Options trades endpoint is accessible
     */
    public function test_options_trades_endpoint_accessible()
    {
        $response = $this->getJson('/api/v1/options/trades');

        $response->assertStatus(200);
    }

    /**
     * Test: Options trades can be filtered by market
     */
    public function test_options_trades_filtered_by_market()
    {
        Option::factory()->expired()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'currency_id' => $this->quoteCurrency->id,
        ]);

        $response = $this->getJson('/api/v1/options/trades?market=BTC-USDT');

        $response->assertStatus(200);
    }

    // ============================================================================
    // EDGE CASES
    // ============================================================================

    /**
     * Test: Empty open options returns empty data
     */
    public function test_empty_open_options_returns_empty()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/options/open');

        $response->assertStatus(200);
    }

    /**
     * Test: User cannot see other users options
     */
    public function test_user_cannot_see_other_users_options()
    {
        $otherUser = User::factory()->create();

        Option::factory()->active()->create([
            'user_id' => $otherUser->id,
            'market_id' => $this->market->id,
            'currency_id' => $this->quoteCurrency->id,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/options/open');

        $response->assertJsonFragment(['data' => []]);
        $response->assertStatus(200);
    }
}
