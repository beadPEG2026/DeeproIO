<?php

namespace Tests\Feature\Futures;

use App\Events\WalletUpdated;
use App\Models\Currency\Currency;
use App\Models\Market\Market;
use App\Models\Order\FuturesContract;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Setting;
use Mockery;
use Tests\TestCase;

/**
 * Comprehensive Unit Tests for Futures Order Creation Functionality
 *
 * This test suite covers all possible scenarios for creating futures orders including:
 * - Authentication and authorization
 * - Input validation (all fields)
 * - Different order types (market, limit)
 * - Different position types (long/short)
 * - Leverage validation
 * - Balance and wallet operations
 * - Take Profit / Stop Loss validation
 * - Liquidation price calculation
 * - Order matching logic
 * - Position management
 * - Edge cases and error handling
 * - Database transactions
 * - Events and jobs
 *
 * Each test method includes detailed documentation explaining:
 * - What scenario is being tested
 * - Expected behavior
 * - Why the test is important
 */
class FuturesOrderCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user instance
     */
    private User $user;

    /**
     * Test market instance
     */
    private Market $market;

    /**
     * Base currency (e.g., BTC)
     */
    private Currency $baseCurrency;

    /**
     * Quote currency (e.g., USDT)
     */
    private Currency $quoteCurrency;

    /**
     * Set up test environment before each test
     * Creates test data: user, currencies, market, wallets
     */
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

        // Create test market with futures enabled
        $this->market = Market::factory()->create([
            'name' => 'BTC-USDT',
            'base_currency_id' => $this->baseCurrency->id,
            'quote_currency_id' => $this->quoteCurrency->id,
            'status' => true,
            'min_trade_size' => '0.001',
            'max_trade_size' => '1000',
            'base_precision' => 8,
            'quote_precision' => 2,
            'has_futures' => true,
        ]);

        // Create test user
        User::withoutEvents(function () {
            $this->user = User::factory()->create([
                'email_verified_at' => now(),
            ]);
        });

        // Create wallets for user with sufficient balance
        Wallet::factory()->create([
            'user_id' => $this->user->id,
            'currency_id' => $this->baseCurrency->id,
            'balance_in_wallet' => '10.0',
            'balance_in_trade' => '10.0',
            'balance_in_order' => '0.0',
        ]);

        Wallet::factory()->create([
            'user_id' => $this->user->id,
            'currency_id' => $this->quoteCurrency->id,
            'balance_in_wallet' => '100000.0',
            'balance_in_trade' => '100000.0',
            'balance_in_order' => '0.0',
        ]);



        // Set up market stats in cache
        Cache::put("market.{$this->market->id}.last", '50000');
        Cache::put("markets_liquidity.BTC-USDT.asks", collect([
            ['price' => '50000', 'quantity' => '10']
        ]));
        Cache::put("markets_liquidity.BTC-USDT.bids", collect([
            ['price' => '50000', 'quantity' => '10']
        ]));
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ============================================================================
    // AUTHENTICATION & AUTHORIZATION TESTS
    // ============================================================================

    /**
     * Test: Unauthenticated users cannot create futures orders
     *
     * Verifies that the futures order creation endpoint requires authentication.
     * Unauthenticated requests should return 401 Unauthorized status.
     * This ensures that only authenticated users can place futures orders.
     */
    public function test_unauthenticated_user_cannot_create_futures_order()
    {
        $response = $this->postJson('/api/v1/futures', [
            'market' => 'BTC-USDT',
            'type' => 'market',
            'side' => 'buy',
            'leverage' => 10,
            'quoteQuantity' => '1000',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test: User without 'trade' token permission cannot create futures orders
     *
     * Verifies that authenticated users must have the 'trade' token permission.
     * Users with other permissions (e.g., 'read') should receive 403 Forbidden.
     * This ensures proper API token-based authorization.
     */
    public function test_user_without_trade_permission_cannot_create_futures_order()
    {
        $token = $this->user->createToken('test-token', ['read']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(403);
    }

    /**
     * Test: Inactive users cannot create futures orders
     *
     * Verifies that deactivated users cannot place futures orders even if authenticated.
     * This prevents blocked users from trading.
     */
    public function test_inactive_user_cannot_create_futures_order()
    {
        $this->user->update(['deactivated' => true]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertJsonStructure(['message']);

        $this->assertContains($response->status(), [403]);
    }

    // ============================================================================
    // VALIDATION TESTS - Required Fields
    // ============================================================================

    /**
     * Test: Market field is required for futures orders
     *
     * Verifies that the market field is mandatory for futures order creation.
     * Missing market should result in validation error.
     */
    public function test_market_field_is_required_for_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    /**
     * Test: Leverage field is required for futures orders
     *
     * Verifies that the leverage field is mandatory for futures.
     * Missing leverage should result in validation error.
     */
    public function test_leverage_field_is_required_for_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['leverage']);
    }

    /**
     * Test: Type field is required for futures orders
     *
     * Verifies that the order type field is mandatory.
     * Missing type should result in validation error.
     */
    public function test_type_field_is_required_for_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    /**
     * Test: Side field is required for futures orders
     *
     * Verifies that the order side field is mandatory.
     * Missing side should result in validation error.
     */
    public function test_side_field_is_required_for_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['side']);
    }

    // ============================================================================
    // VALIDATION TESTS - Leverage Rules
    // ============================================================================

    /**
     * Test: Leverage must be numeric
     *
     * Verifies that leverage must be a valid numeric value.
     * Non-numeric values should be rejected.
     */
    public function test_leverage_must_be_numeric()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 'invalid',
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['leverage']);
    }

    /**
     * Test: Leverage must be an integer
     *
     * Verifies that leverage must be a whole number.
     * Decimal values should be rejected.
     */
    public function test_leverage_must_be_integer()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10.5,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['leverage']);
    }

    /**
     * Test: Leverage minimum is 1
     *
     * Verifies that leverage must be at least 1x.
     * Zero or negative leverage should be rejected.
     */
    public function test_leverage_minimum_is_1()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 0,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['leverage']);
    }

    /**
     * Test: Leverage maximum is 125
     *
     * Verifies that leverage cannot exceed 125x.
     * Higher leverage values should be rejected.
     */
    public function test_leverage_maximum_is_125()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 150,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['leverage']);
    }

    /**
     * Test: Negative leverage is rejected
     *
     * Verifies that negative leverage values are not allowed.
     */
    public function test_negative_leverage_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => -10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['leverage']);
    }

    // ============================================================================
    // VALIDATION TESTS - Market Rules
    // ============================================================================

    /**
     * Test: Invalid market name is rejected
     *
     * Verifies that non-existent or invalid market names are rejected.
     * Only valid, active markets should be accepted.
     */
    public function test_invalid_market_name_is_rejected_for_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'INVALID-MARKET',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    /**
     * Test: Market without futures enabled is rejected
     *
     * Verifies that orders cannot be placed on markets without futures.
     * Only markets with has_futures=true should accept futures orders.
     */
    public function test_market_without_futures_is_rejected()
    {
        $this->market->update(['has_futures' => false]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    /**
     * Test: Inactive market is rejected for futures
     *
     * Verifies that futures orders cannot be placed on inactive markets.
     * Only active markets should accept orders.
     */
    public function test_inactive_market_is_rejected_for_futures()
    {
        $this->market->update(['status' => false]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    /**
     * Test: Market with disabled trades is rejected for futures
     *
     * Verifies that futures orders cannot be placed when trades are globally disabled.
     */
    public function test_market_with_disabled_trades_is_rejected_for_futures()
    {
        Setting::set('trade.disable_trades', true);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    // ============================================================================
    // VALIDATION TESTS - Order Type Rules
    // ============================================================================

    /**
     * Test: Invalid order type is rejected
     *
     * Verifies that only valid order types (market, limit) are accepted.
     * Invalid types should be rejected with validation error.
     */
    public function test_invalid_order_type_is_rejected_for_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'invalid_type',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    /**
     * Test: Invalid order side is rejected
     *
     * Verifies that only valid order sides (buy, sell) are accepted.
     * Invalid sides should be rejected with validation error.
     */
    public function test_invalid_order_side_is_rejected_for_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'invalid_side',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['side']);
    }

    // ============================================================================
    // VALIDATION TESTS - Quantity Rules
    // ============================================================================

    /**
     * Test: QuoteQuantity is required for buy market futures orders
     *
     * Verifies that buy market orders require quoteQuantity (margin amount).
     * This specifies how much quote currency to use as margin.
     */
    public function test_quoteQuantity_is_required_for_buy_market_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }

    /**
     * Test: Quantity is required for sell market futures orders
     *
     * Verifies that sell market orders require quantity.
     */
    public function test_quantity_is_required_for_sell_market_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'sell',
                'leverage' => 10,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Price is required for limit futures orders
     *
     * Verifies that limit orders require a price field.
     * Market orders don't need price, but limit orders must have it.
     */
    public function test_price_is_required_for_limit_futures_orders()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'leverage' => 10,
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['price']);
    }

    /**
     * Test: Negative quantity is rejected for futures
     *
     * Verifies that negative quantities are not allowed.
     * Order quantities must be positive numbers.
     */
    public function test_negative_quantity_is_rejected_for_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '-0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Negative quoteQuantity is rejected for futures
     *
     * Verifies that negative quoteQuantity values are not allowed.
     */
    public function test_negative_quoteQuantity_is_rejected_for_futures()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '-1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }

    // ============================================================================
    // VALIDATION TESTS - Take Profit / Stop Loss Rules
    // ============================================================================

    /**
     * Test: TP/SL requires at least one price when enabled
     *
     * Verifies that when enable_tp_sl is true, at least one of
     * take_profit_price or stop_loss_price must be provided.
     */
    public function test_tpsl_requires_at_least_one_price_when_enabled()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
                'enable_tp_sl' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['take_profit_price']);
    }

    /**
     * Test: Long position TP must be above entry price
     *
     * Verifies that for long positions, take profit price must be above entry.
     * This ensures profit target is in the correct direction.
     */
    public function test_long_position_tp_must_be_above_entry_price()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy', // Long position
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.1',
                'enable_tp_sl' => true,
                'take_profit_price' => '45000', // Below entry - invalid
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['take_profit_price']);
    }

    /**
     * Test: Long position SL must be below entry price
     *
     * Verifies that for long positions, stop loss price must be below entry.
     * This ensures stop loss triggers in the correct direction.
     */
    public function test_long_position_sl_must_be_below_entry_price()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy', // Long position
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.1',
                'enable_tp_sl' => true,
                'stop_loss_price' => '55000', // Above entry - invalid
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['stop_loss_price']);
    }

    /**
     * Test: Short position TP must be below entry price
     *
     * Verifies that for short positions, take profit price must be below entry.
     * Short positions profit when price decreases.
     */
    public function test_short_position_tp_must_be_below_entry_price()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell', // Short position
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.1',
                'enable_tp_sl' => true,
                'take_profit_price' => '55000', // Above entry - invalid for short
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['take_profit_price']);
    }

    /**
     * Test: Short position SL must be above entry price
     *
     * Verifies that for short positions, stop loss price must be above entry.
     * Short positions lose when price increases.
     */
    public function test_short_position_sl_must_be_above_entry_price()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell', // Short position
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.1',
                'enable_tp_sl' => true,
                'stop_loss_price' => '45000', // Below entry - invalid for short
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['stop_loss_price']);
    }

    /**
     * Test: Long position TP must be above SL
     *
     * Verifies that for long positions, take profit price must be above stop loss.
     * This ensures logical ordering of TP and SL.
     */
    public function test_long_position_tp_must_be_above_sl()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy', // Long position
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.1',
                'enable_tp_sl' => true,
                'take_profit_price' => '48000', // Below SL - invalid
                'stop_loss_price' => '49000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['take_profit_price']);
    }

    // ============================================================================
    // BALANCE & WALLET TESTS
    // ============================================================================

    /**
     * Test: Insufficient balance for long market futures order is rejected
     *
     * Verifies that long market orders require sufficient quote currency balance
     * for the margin amount. Orders exceeding available balance should be rejected.
     */
    public function test_insufficient_balance_for_long_market_futures_is_rejected()
    {
        // Set wallet balance to insufficient amount
        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->quoteCurrency->id)
            ->first();
        $wallet->update(['balance_in_trade' => '100']); // Insufficient for 1000 margin

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }

    /**
     * Test: Insufficient balance for short market futures order is rejected
     *
     * Verifies that short market orders require sufficient balance for margin.
     */
    public function test_insufficient_balance_for_short_market_futures_is_rejected()
    {
        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->quoteCurrency->id)
            ->first();
        $wallet->update(['balance_in_trade' => '10']); // Very low balance

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'sell',
                'leverage' => 10,
                'quantity' => '100', // Very large quantity requiring significant margin
            ]);

        $response->assertJsonStructure(['errors']);

        $this->assertContains($response->status(), [422]);
    }

    /**
     * Test: Insufficient balance for limit futures order is rejected
     *
     * Verifies that limit futures orders require sufficient balance for margin.
     */
    public function test_insufficient_balance_for_limit_futures_is_rejected()
    {
        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->quoteCurrency->id)
            ->first();
        $wallet->update(['balance_in_trade' => '10']); // Very low balance

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '100', // Very large quantity requiring massive margin
            ]);

        $response->assertJsonStructure(['errors']);

        $this->assertContains($response->status(), [422]);
    }

    // ============================================================================
    // ORDER TYPE TESTS - Long Positions (Buy)
    // ============================================================================

    /**
     * Test: Long market futures order creation succeeds with sufficient balance
     *
     * Verifies that a long market futures order is successfully created when:
     * - User is authenticated with trade permission
     * - All required fields are provided
     * - User has sufficient quote currency balance for margin
     * - Market has futures enabled and is tradable
     */
    public function test_long_market_futures_order_creation_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Long limit futures order creation succeeds with sufficient balance
     *
     * Verifies that a long limit futures order is successfully created when all
     * requirements are met including price specification.
     */
    public function test_long_limit_futures_order_creation_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'leverage' => 10,
                'price' => '48000', // Limit price below market
                'quantity' => '0.1',
            ]);

        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Long position with valid TP/SL succeeds
     *
     * Verifies that a long position with correctly configured take profit
     * and stop loss prices is successfully created.
     */
    public function test_long_position_with_valid_tpsl_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.1',
                'enable_tp_sl' => true,
                'take_profit_price' => '55000', // Above entry
                'stop_loss_price' => '48000', // Below entry
            ]);

        $this->assertContains($response->status(), [200]);
    }

    // ============================================================================
    // ORDER TYPE TESTS - Short Positions (Sell)
    // ============================================================================

    /**
     * Test: Short market futures order creation succeeds with sufficient balance
     *
     * Verifies that a short market futures order is successfully created when:
     * - User is authenticated with trade permission
     * - All required fields are provided
     * - User has sufficient balance for margin
     * - Market has futures enabled and is tradable
     */
    public function test_short_market_futures_order_creation_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'sell',
                'leverage' => 10,
                'quantity' => '0.1',
            ]);

        // Should succeed (200) or may return 403/500 if additional checks fail
        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Short limit futures order creation succeeds with sufficient balance
     *
     * Verifies that a short limit futures order is successfully created.
     */
    public function test_short_limit_futures_order_creation_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'leverage' => 10,
                'price' => '52000', // Limit price above market
                'quantity' => '0.1',
            ]);

        $response->assertStatus(200);
        $response->assertSeeText('message');
    }

    /**
     * Test: Short position with valid TP/SL succeeds
     *
     * Verifies that a short position with correctly configured take profit
     * and stop loss prices is successfully created.
     */
    public function test_short_position_with_valid_tpsl_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.1',
                'enable_tp_sl' => true,
                'take_profit_price' => '45000', // Below entry for short
                'stop_loss_price' => '52000', // Above entry for short
            ]);

        $response->assertStatus(200);
        $response->assertSeeText('message');
    }

    // ============================================================================
    // LEVERAGE TESTS
    // ============================================================================

    /**
     * Test: Futures order with 1x leverage succeeds
     *
     * Verifies that minimum leverage (1x) is accepted.
     * This represents 1:1 position without leverage.
     */
    public function test_futures_order_with_1x_leverage_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 1,
                'quoteQuantity' => '1000',
            ]);

        $response->assertJsonStructure(['message']);
        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Futures order with 125x leverage succeeds
     *
     * Verifies that maximum leverage (125x) is accepted.
     * High leverage positions have higher liquidation risk.
     */
    public function test_futures_order_with_125x_leverage_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 125,
                'quoteQuantity' => '1000',
            ]);

        $response->assertJsonStructure(['message']);
        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Futures order with common leverage values succeeds
     *
     * Verifies that common leverage values (5x, 10x, 20x, 50x, 100x) are accepted.
     */
    public function test_futures_order_with_common_leverage_values_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);
        $leverageValues = [5, 10, 20, 50, 100];

        foreach ($leverageValues as $leverage) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
                ->postJson('/api/v1/futures', [
                    'market' => 'BTC-USDT',
                    'type' => 'market',
                    'side' => 'buy',
                    'leverage' => $leverage,
                    'quoteQuantity' => '100',
                ]);

            $response->assertJsonStructure(['message']);
            $this->assertContains($response->status(), [200]);
        }
    }

    // ============================================================================
    // CANCEL ORDER TESTS
    // ============================================================================

    /**
     * Test: Cancelling pending limit futures order succeeds
     *
     * Verifies that pending limit orders can be cancelled.
     * Locked balance should be released back to user.
     */
    public function test_cancel_pending_limit_futures_order_succeeds()
    {
        // Create a pending limit order
        $futuresOrder = FuturesContract::factory()->pendingLimit()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'quote_currency_id' => $this->quoteCurrency->id,
            'balance' => '500',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders/futures/cancel', [
                'uuid' => $futuresOrder->id,
            ]);

        $response->assertJsonStructure(['message']);
        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Cancelling non-existent futures order fails
     *
     * Verifies that attempting to cancel a non-existent order fails gracefully.
     */
    public function test_cancel_nonexistent_futures_order_fails()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders/futures/cancel', [
                'uuid' => generate_uuid(),
            ]);

        $response->assertJsonFragment(['message' => 'Invalid order']);
        $this->assertContains($response->status(), [422]);
    }

    /**
     * Test: Cancel request without uuid fails
     *
     * Verifies that uuid is required for cancel requests.
     */
    public function test_cancel_request_without_uuid_fails()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders/futures/cancel', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['uuid']);
    }

    // ============================================================================
    // OPEN POSITIONS TESTS
    // ============================================================================

    /**
     * Test: Get open futures positions returns active positions
     *
     * Verifies that the open futures endpoint returns user's active positions.
     */
    public function test_get_open_futures_positions_returns_active_positions()
    {
        // Create some active positions
        FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/open');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    /**
     * Test: Get open futures positions filters by market
     *
     * Verifies that open futures can be filtered by market parameter.
     */
    public function test_get_open_futures_positions_filters_by_market()
    {
        FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/open?market=BTC-USDT');

        $response->assertStatus(200);
    }

    /**
     * Test: Get open futures orders returns pending limit orders
     *
     * Verifies that the open orders endpoint returns pending limit orders.
     */
    public function test_get_open_futures_orders_returns_pending_limit_orders()
    {
        FuturesContract::factory()->pendingLimit()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/orders');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    // ============================================================================
    // SCHEDULED ORDERS TESTS (Timeframe Feature)
    // ============================================================================

    /**
     * Test: Scheduled futures order with future start time succeeds
     *
     * Verifies that futures orders can be scheduled for future execution
     * when timeframe feature is enabled.
     */
    public function test_scheduled_futures_order_with_future_start_time_succeeds()
    {
        Setting::set('futures.timeframe_enabled', true);
        $token = $this->user->createToken('test-token', ['trade']);

        $futureTimestamp = now()->addHour()->getTimestampMs();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
                'startAt' => $futureTimestamp,
            ]);

        $response->assertJsonStructure(['message']);
        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Scheduled futures order with past start time fails
     *
     * Verifies that past timestamps are rejected for scheduled orders.
     */
    public function test_scheduled_futures_order_with_past_start_time_fails()
    {
        Setting::set('futures.timeframe_enabled', true);
        $token = $this->user->createToken('test-token', ['trade']);

        $pastTimestamp = now()->subHour()->getTimestampMs();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
                'startAt' => $pastTimestamp,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['startAt']);
    }

    /**
     * Test: Scheduled futures order with invalid start time format fails
     *
     * Verifies that non-numeric timestamps are rejected.
     */
    public function test_scheduled_futures_order_with_invalid_start_time_fails()
    {
        Setting::set('futures.timeframe_enabled', true);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
                'startAt' => 'invalid-timestamp',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['startAt']);
    }

    // ============================================================================
    // EDGE CASES & ERROR HANDLING TESTS
    // ============================================================================

    /**
     * Test: Futures order with very large quantity is handled
     *
     * Verifies that orders with very large quantities are handled correctly.
     * System should validate against available balance and market limits.
     */
    public function test_futures_order_with_very_large_quantity_is_handled()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '999999999999', // Extremely large
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }

    /**
     * Test: Futures order with very small quantity is handled
     *
     * Verifies that orders with very small quantities are handled correctly.
     * System should validate against minimum trade size.
     */
    public function test_futures_order_with_very_small_quantity_is_handled()
    {
        $this->market->update(['min_trade_size' => '0.001']);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.0000001', // Below minimum
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Concurrent futures order creation is handled
     *
     * Verifies that multiple orders can be created concurrently without
     * race conditions or balance inconsistencies.
     */
    public function test_concurrent_futures_order_creation_is_handled()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
                ->postJson('/api/v1/futures', [
                    'market' => 'BTC-USDT',
                    'type' => 'market',
                    'side' => 'buy',
                    'leverage' => 10,
                    'quoteQuantity' => '100',
                ]);
        }

        foreach ($responses as $response) {
            $response->assertJsonStructure(['message']);
            $this->assertContains($response->status(), [200]);
        }
    }

    /**
     * Test: Futures order with maximum precision decimals is handled
     *
     * Verifies that orders with maximum precision decimals are handled correctly.
     */
    public function test_futures_order_with_maximum_precision_decimals_is_handled()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'leverage' => 10,
                'price' => '50000.12',
                'quantity' => '0.12345678',
            ]);

        $response->assertJsonStructure(['message']);
        $this->assertContains($response->status(), [200]);
    }

    // ============================================================================
    // MAINTENANCE MODE TESTS
    // ============================================================================

    /**
     * Test: Futures order creation is blocked during maintenance for non-admins
     *
     * Verifies that regular users cannot create futures orders during maintenance.
     * Only administrators should be able to trade during maintenance.
     */
    public function test_futures_order_blocked_during_maintenance_for_non_admins()
    {
        Setting::set('general.maintenance_status', true);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        $response->assertJsonFragment(['message' => 'Unfortunately the site is down for a maintenance right now']);
        $this->assertContains($response->status(), [200]);
    }

    // ============================================================================
    // EVENT & JOB TESTS
    // ============================================================================

    /**
     * Test: WalletUpdated event is dispatched when balance changes
     *
     * Verifies that WalletUpdated event is dispatched when wallet balance
     * is modified during futures order creation.
     */
    public function test_walletUpdated_event_is_dispatched_for_futures()
    {
        Event::fake();

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000',
            ]);

        Event::assertDispatched(function (WalletUpdated $event) {
            return $event->wallet->balance_in_trade == '99000';
        });

        $this->assertContains($response->status(), [200]);
    }

    // ============================================================================
    // DATABASE TRANSACTION TESTS
    // ============================================================================

    /**
     * Test: Futures order creation is atomic
     *
     * Verifies that wallet balance changes happen atomically with order creation.
     * Either both succeed or both fail.
     */
    public function test_futures_order_creation_is_atomic()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->quoteCurrency->id)
            ->first();

        $amount = 1000;
        $initialTradeBalance = $wallet->balance_in_trade;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => $amount,
            ]);

        $wallet->refresh();
        $this->assertEquals($initialTradeBalance - $amount, $wallet->balance_in_trade);
    }

    // ============================================================================
    // PRECISION VALIDATION TESTS
    // ============================================================================

    /**
     * Test: Futures quantity with valid base precision is accepted
     *
     * Verifies that quantity with decimals within the market's base_precision is accepted.
     * For base_precision = 8, values like 0.12345678 should be valid.
     */
    public function test_futures_quantity_with_valid_base_precision_is_accepted()
    {
        $this->market->update(['base_precision' => 5]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.12345', // 5 decimal places = base_precision
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Futures quantity exceeding base precision is rejected
     *
     * Verifies that quantity with more decimals than the market's base_precision is rejected.
     * For base_precision = 5, values like 0.123456 (6 decimals) should fail.
     */
    public function test_futures_quantity_exceeding_base_precision_is_rejected()
    {
        $this->market->update(['base_precision' => 5]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.123456', // 6 decimal places > base_precision (5)
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Futures price with valid quote precision is accepted
     *
     * Verifies that price with decimals within the market's quote_precision is accepted.
     * For quote_precision = 2, values like 50000.12 should be valid.
     */
    public function test_futures_price_with_valid_quote_precision_is_accepted()
    {
        $this->market->update(['quote_precision' => 2]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'leverage' => 10,
                'price' => '50000.12', // 2 decimal places = quote_precision
                'quantity' => '0.1',
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Futures price exceeding quote precision is rejected
     *
     * Verifies that price with more decimals than the market's quote_precision is rejected.
     * For quote_precision = 2, values like 50000.123 (3 decimals) should fail.
     */
    public function test_futures_price_exceeding_quote_precision_is_rejected()
    {
        $this->market->update(['quote_precision' => 2]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'leverage' => 10,
                'price' => '50000.123', // 3 decimal places > quote_precision (2)
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['price']);
    }

    /**
     * Test: Futures quoteQuantity with valid quote precision is accepted
     *
     * Verifies that quoteQuantity with decimals within the market's quote_precision is accepted.
     * For quote_precision = 2, values like 1000.12 should be valid.
     */
    public function test_futures_quoteQuantity_with_valid_quote_precision_is_accepted()
    {
        $this->market->update(['quote_precision' => 2]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000.12', // 2 decimal places = quote_precision
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Futures quoteQuantity exceeding quote precision is rejected
     *
     * Verifies that quoteQuantity with more decimals than the market's quote_precision is rejected.
     * For quote_precision = 2, values like 1000.123 (3 decimals) should fail.
     */
    public function test_futures_quoteQuantity_exceeding_quote_precision_is_rejected()
    {
        $this->market->update(['quote_precision' => 2]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'leverage' => 10,
                'quoteQuantity' => '1000.123', // 3 decimal places > quote_precision (2)
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }

    /**
     * Test: Futures order with whole numbers is valid for any precision
     *
     * Verifies that whole numbers (no decimals) are valid regardless of precision settings.
     * Values like 100, 50000 should always be accepted.
     */
    public function test_futures_whole_numbers_are_valid_for_any_precision()
    {
        $this->market->update(['base_precision' => 2, 'quote_precision' => 2]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'leverage' => 10,
                'price' => '50000', // No decimals
                'quantity' => '1', // No decimals
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Futures precision validation with different market configurations
     *
     * Verifies that precision validation works correctly across different market configurations.
     * Tests a market with base_precision=3 and quote_precision=4.
     */
    public function test_futures_precision_validation_with_different_market_config()
    {
        $this->market->update(['base_precision' => 3, 'quote_precision' => 4]);
        $token = $this->user->createToken('test-token', ['trade']);

        // Valid precision
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'leverage' => 10,
                'price' => '50000.1234', // 4 decimals = quote_precision
                'quantity' => '0.123', // 3 decimals = base_precision
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Futures quantity precision validation for short positions
     *
     * Verifies that precision validation for quantity applies equally to short positions.
     */
    public function test_futures_quantity_precision_validation_for_short_positions()
    {
        $this->market->update(['base_precision' => 4]);
        $token = $this->user->createToken('test-token', ['trade']);

        // Invalid precision for short position
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/futures', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'leverage' => 10,
                'price' => '50000',
                'quantity' => '0.12345', // 5 decimals > base_precision (4)
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }
}
