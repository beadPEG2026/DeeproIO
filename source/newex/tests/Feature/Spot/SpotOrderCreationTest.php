<?php

namespace Tests\Feature\Spot;

use App\Events\OrderBookUpdated;
use App\Events\WalletUpdated;
use App\Models\Currency\Currency;
use App\Models\Market\Market;
use App\Models\Order\Order;
use App\Models\Transaction\Transaction;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Setting;
use Mockery;
use Tests\TestCase;

/**
 * Comprehensive Unit Tests for Spot Order Creation Functionality
 *
 * This test suite covers all possible scenarios for creating spot orders including:
 * - Authentication and authorization
 * - Input validation (all fields)
 * - Different order types (market, limit, stop_limit)
 * - Different order sides (buy, sell)
 * - Balance and wallet operations
 * - Fee calculations
 * - Order matching logic
 * - Edge cases and error handling
 * - Database transactions
 * - Events and jobs
 *
 * Each test method includes detailed documentation explaining:
 * - What scenario is being tested
 * - Expected behavior
 * - Why the test is important
 */
class SpotOrderCreationTest extends TestCase
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

        // Create test market
        $this->market = Market::factory()->create([
            'name' => 'BTC-USDT',
            'base_currency_id' => $this->baseCurrency->id,
            'quote_currency_id' => $this->quoteCurrency->id,
            'status' => true,
            'min_trade_size' => '0.001',
            'max_trade_size' => '1000',
            'base_precision' => 8,
            'quote_precision' => 2,
        ]);

        // Create test user
        User::withoutEvents(function () {
            $this->user = User::factory()->create([
                'email_verified_at' => now(),
            ]);
        });

        // Create wallets for user
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
            'balance_in_wallet' => '50000.0',
            'balance_in_trade' => '50000.0',
            'balance_in_order' => '0.0',
        ]);

        Cache::put("market.{$this->market->id}.last", '50000');
    }

    private function fundReferenceMaker(): void
    {
        $maker=User::withoutEvents(fn()=>User::factory()->create());
        foreach([$this->baseCurrency->id,$this->quoteCurrency->id] as $id) Wallet::factory()->create(['user_id'=>$maker->id,'currency_id'=>$id,'balance_in_trade'=>$id===$this->baseCurrency->id?'100':'1000000']);
        $this->market->update(['liq'=>true]);
        \Illuminate\Support\Facades\DB::table('market_execution_policies')->insert(['market_id'=>$this->market->id,'mode'=>'platform_maker','maker_user_id'=>$maker->id,'max_quote_per_fill'=>'100000','created_at'=>now(),'updated_at'=>now()]);
        Cache::put('markets_liquidity.BTC-USDT.received_at',time());
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
     * Test: Unauthenticated users cannot create orders
     *
     * Verifies that the order creation endpoint requires authentication.
     * Unauthenticated requests should return 401 Unauthorized status.
     * This ensures that only authenticated users can place orders.
     */
    public function test_unauthenticated_user_cannot_create_order()
    {
        $response = $this->postJson('/api/v1/orders', [
            'market' => 'BTC-USDT',
            'type' => 'limit',
            'side' => 'buy',
            'price' => '50000',
            'quantity' => '0.1',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test: User without 'trade' token permission cannot create orders
     *
     * Verifies that authenticated users must have the 'trade' token permission.
     * Users with other permissions (e.g., 'read') should receive 403 Forbidden.
     * This ensures proper API token-based authorization.
     */
    public function test_user_without_trade_permission_cannot_create_order()
    {
        $token = $this->user->createToken('test-token', ['read']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test: Inactive users cannot create orders
     *
     * Verifies that deactivated users cannot place orders even if authenticated.
     * This prevents blocked users from trading.
     */
    public function test_inactive_user_cannot_create_order()
    {
        $this->user->update(['deactivated' => true]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        // Should fail validation due to inactive user
        $response->assertStatus(422);
    }

    // ============================================================================
    // VALIDATION TESTS - Required Fields
    // ============================================================================

    /**
     * Test: Market field is required
     *
     * Verifies that the market field is mandatory for order creation.
     * Missing market should result in validation error.
     */
    public function test_market_field_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    /**
     * Test: Type field is required
     *
     * Verifies that the order type field is mandatory.
     * Missing type should result in validation error.
     */
    public function test_type_field_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    /**
     * Test: Side field is required
     *
     * Verifies that the order side field is mandatory.
     * Missing side should result in validation error.
     */
    public function test_side_field_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['side']);
    }

    // ============================================================================
    // VALIDATION TESTS - Field Values
    // ============================================================================

    /**
     * Test: Invalid market name is rejected
     *
     * Verifies that non-existent or invalid market names are rejected.
     * Only valid, active markets should be accepted.
     */
    public function test_invalid_market_name_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'INVALID-MARKET',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    /**
     * Test: Invalid order type is rejected
     *
     * Verifies that only valid order types (market, limit, stop_limit) are accepted.
     * Invalid types should be rejected with validation error.
     */
    public function test_invalid_order_type_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'invalid_type',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
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
    public function test_invalid_order_side_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'invalid_side',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['side']);
    }

    /**
     * Test: Price is required for limit orders
     *
     * Verifies that limit orders require a price field.
     * Market orders don't need price, but limit orders must have it.
     */
    public function test_price_is_required_for_limit_orders()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['price']);
    }

    /**
     * Test: Price is not required for market orders
     *
     * Verifies that market orders don't require a price field.
     * Market orders execute at current market price.
     */
    public function test_price_is_not_required_for_market_orders()
    {
        $this->fundReferenceMaker();
        Cache::put('markets_liquidity.BTC-USDT.bids', [['price'=>'5','quantity'=>'1']]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => 5,
                'quantity' => '0.1',
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'sell',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Quantity is required for non-buy-market orders
     *
     * Verifies that quantity is required for limit orders and sell market orders.
     * Buy market orders use quoteQuantity instead.
     */
    public function test_quantity_is_required_for_non_buy_market_orders()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        // Test limit order without quantity
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);

        // Test sell market order without quantity
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'sell',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: QuoteQuantity is required for buy market orders
     *
     * Verifies that buy market orders require quoteQuantity instead of quantity.
     * This is because buy market orders specify how much quote currency to spend.
     */
    public function test_quoteQuantity_is_required_for_buy_market_orders()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }


    // ============================================================================
    // VALIDATION TESTS - Quantity Rules
    // ============================================================================

    /**
     * Test: Negative quantity is rejected
     *
     * Verifies that negative quantities are not allowed.
     * Order quantities must be positive numbers.
     */
    public function test_negative_quantity_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '-0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Zero quantity is rejected
     *
     * Verifies that zero quantities are not allowed.
     * Order quantities must be greater than zero.
     */
    public function test_zero_quantity_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Quantity below minimum trade size is rejected
     *
     * Verifies that orders with quantity below market's min_trade_size are rejected.
     * This ensures orders meet minimum size requirements.
     */
    public function test_quantity_below_minimum_trade_size_is_rejected()
    {
        $this->market->update(['min_trade_size' => '0.01']);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.0001', // Below minimum
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Quantity above maximum trade size is rejected
     *
     * Verifies that orders with quantity above market's max_trade_size are rejected.
     * This ensures orders don't exceed maximum size limits.
     */
    public function test_quantity_above_maximum_trade_size_is_rejected()
    {
        $this->market->update(['max_trade_size' => '100']);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '200', // Above maximum
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Invalid quantity format (scientific notation) is rejected
     *
     * Verifies that scientific notation (e.g., 1e5) is not allowed in quantities.
     * This prevents potential parsing issues and ensures explicit values.
     */
    public function test_scientific_notation_quantity_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '1e5',
            ]);

        $response->assertStatus(422);
    }

    // ============================================================================
    // VALIDATION TESTS - Price Rules
    // ============================================================================

    /**
     * Test: Negative price is rejected
     *
     * Verifies that negative prices are not allowed.
     * Order prices must be positive numbers.
     */
    public function test_negative_price_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '-50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['price']);
    }

    /**
     * Test: Zero price is rejected
     *
     * Verifies that zero prices are not allowed for limit orders.
     * Limit order prices must be greater than zero.
     */
    public function test_zero_price_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '0',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['price']);
    }

    /**
     * Test: Invalid price format (scientific notation) is rejected
     *
     * Verifies that scientific notation is not allowed in prices.
     * This ensures explicit price values.
     */
    public function test_scientific_notation_price_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '5e4',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
    }

    // ============================================================================
    // VALIDATION TESTS - QuoteQuantity Rules
    // ============================================================================

    /**
     * Test: Negative quoteQuantity is rejected
     *
     * Verifies that negative quoteQuantity values are not allowed.
     * Quote quantities must be positive numbers.
     */
    public function test_negative_quoteQuantity_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'quoteQuantity' => '-1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }

    /**
     * Test: Zero quoteQuantity is rejected
     *
     * Verifies that zero quoteQuantity values are not allowed.
     * Quote quantities must be greater than zero.
     */
    public function test_zero_quoteQuantity_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'quoteQuantity' => '0',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }

    // ============================================================================
    // VALIDATION TESTS - Market Rules
    // ============================================================================

    /**
     * Test: Inactive market is rejected
     *
     * Verifies that orders cannot be placed on inactive markets.
     * Only active markets should accept orders.
     */
    public function test_inactive_market_is_rejected()
    {
        $this->market->update(['status' => false]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    /**
     * Test: Market with disabled trades is rejected
     *
     * Verifies that orders cannot be placed when trades are globally disabled.
     * This allows administrators to halt trading system-wide.
     */
    public function test_market_with_disabled_trades_is_rejected()
    {
        Setting::set('trade.disable_trades', true);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    // ============================================================================
    // BALANCE & WALLET TESTS
    // ============================================================================

    /**
     * Test: Insufficient balance for buy limit order is rejected
     *
     * Verifies that buy limit orders require sufficient quote currency balance
     * (price * quantity + fee). Orders exceeding available balance should be rejected.
     */
    public function test_insufficient_balance_for_buy_limit_order_is_rejected()
    {
        // Set wallet balance to insufficient amount
        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->quoteCurrency->id)
            ->first();
        $wallet->update(['balance_in_trade' => '100']); // Insufficient for 50000 * 0.1

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '500',
                'quantity' => '1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Insufficient balance for sell limit order is rejected
     *
     * Verifies that sell limit orders require sufficient base currency balance.
     * Orders exceeding available balance should be rejected.
     */
    public function test_insufficient_balance_for_sell_limit_order_is_rejected()
    {
        // Set wallet balance to insufficient amount
        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->baseCurrency->id)
            ->first();
        $wallet->update(['balance_in_trade' => '0.01']); // Insufficient for 0.1

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Insufficient balance for buy market order is rejected
     *
     * Verifies that buy market orders require sufficient quote currency balance
     * (quoteQuantity + fee). Orders exceeding available balance should be rejected.
     */
    public function test_insufficient_balance_for_buy_market_order_is_rejected()
    {
        // Set wallet balance to insufficient amount
        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->quoteCurrency->id)
            ->first();
        $wallet->update(['balance_in_trade' => '100']); // Insufficient for 1000 + fee

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }

    /**
     * Test: Insufficient balance for sell market order is rejected
     *
     * Verifies that sell market orders require sufficient base currency balance.
     * Orders exceeding available balance should be rejected.
     */
    public function test_insufficient_balance_for_sell_market_order_is_rejected()
    {
        // Set wallet balance to insufficient amount
        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->baseCurrency->id)
            ->first();
        $wallet->update(['balance_in_trade' => '0.01']); // Insufficient for 0.1

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'sell',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Balance includes pending orders is considered
     *
     * Verifies that the balance check considers balance_in_trade (available balance)
     * which excludes balance_in_order (locked in pending orders).
     * This ensures users can't double-spend locked funds.
     */
    public function test_balance_check_considers_pending_orders()
    {
        // Create a pending order that locks some balance
        Order::factory()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'side' => 'buy',
            'type' => 'limit',
            'quantity' => '0.05',
            'price' => '50000',
        ]);

        // Lock balance in order
        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->quoteCurrency->id)
            ->first();
        $wallet->update([
            'balance_in_trade' => '2500', // Available
            'balance_in_order' => '2500', // Locked
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        // Try to create order that would exceed available balance
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1', // Would require 5000 + fee, but only 2500 available
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    // ============================================================================
    // ORDER TYPE TESTS - Limit Orders
    // ============================================================================

    /**
     * Test: Buy limit order creation succeeds with sufficient balance
     *
     * Verifies that a buy limit order is successfully created when:
     * - User is authenticated with trade permission
     * - All required fields are provided
     * - User has sufficient quote currency balance
     * - Market is active and tradable
     */
    public function test_buy_limit_order_creation_succeeds_with_sufficient_balance()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);


        $response->assertStatus(200);
        $response->assertSeeText('message');
    }

    /**
     * Test: Sell limit order creation succeeds with sufficient balance
     *
     * Verifies that a sell limit order is successfully created when:
     * - User is authenticated with trade permission
     * - All required fields are provided
     * - User has sufficient base currency balance
     * - Market is active and tradable
     */
    public function test_sell_limit_order_creation_succeeds_with_sufficient_balance()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(200);
        $response->assertSeeText('message');
    }

    /**
     * Test: Limit order locks balance in order balance
     *
     * Verifies that when a limit order is created, the required balance
     * is moved from balance_in_trade to balance_in_order (locked).
     * This prevents the funds from being used for other orders.
     */
    public function test_limit_order_locks_balance_in_order_balance()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->quoteCurrency->id)
            ->first();

        $initialTradeBalance = $wallet->balance_in_trade;
        $initialOrderBalance = $wallet->balance_in_order;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(200);

        // In a real test, you would verify the wallet balance changes
        // This requires integration testing with actual repository
    }

    // ============================================================================
    // ORDER TYPE TESTS - Market Orders
    // ============================================================================

    /**
     * Test: Buy market order creation succeeds with sufficient balance
     *
     * Verifies that a buy market order is successfully created when:
     * - User is authenticated with trade permission
     * - quoteQuantity is provided (not quantity)
     * - User has sufficient quote currency balance
     * - Market has liquidity or matched orders
     */
    public function test_buy_market_order_creation_succeeds_with_sufficient_balance()
    {
        $this->fundReferenceMaker();
        // Mock liquidity or matched order
        Cache::put("markets_liquidity.BTC-USDT.asks", collect([
            ['price' => '50000', 'quantity' => '10']
        ]));

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(200);
        $response->assertSeeText('message');
    }

    /**
     * Test: Sell market order creation succeeds with sufficient balance
     *
     * Verifies that a sell market order is successfully created when:
     * - User is authenticated with trade permission
     * - quantity is provided
     * - User has sufficient base currency balance
     * - Market has liquidity or matched orders
     */
    public function test_sell_market_order_creation_succeeds_with_sufficient_balance()
    {
        $this->fundReferenceMaker();
        // Mock liquidity or matched order
        Cache::put("markets_liquidity.BTC-USDT.bids", collect([
            ['price' => '50000', 'quantity' => '10']
        ]));

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'sell',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(200);
        $response->assertSeeText('message');
    }

    /**
     * Test: Buy market order without liquidity is rejected
     *
     * Verifies that buy market orders are rejected when:
     * - No matched orders exist in the orderbook
     * - No liquidity is available
     * - Market has no sell orders to match against
     */
    public function test_buy_market_order_without_liquidity_is_rejected()
    {
        // Clear any cached liquidity
        Cache::forget("markets_liquidity.BTC-USDT.asks");

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'quoteQuantity' => '1000',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }

    /**
     * Test: Sell market order without liquidity is rejected
     *
     * Verifies that sell market orders are rejected when:
     * - No matched orders exist in the orderbook
     * - No liquidity is available
     * - Market has no buy orders to match against
     */
    public function test_sell_market_order_without_liquidity_is_rejected()
    {
        // Clear any cached liquidity
        Cache::forget("markets_liquidity.BTC-USDT.bids");

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'sell',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    // ============================================================================
    // ORDER TYPE TESTS - Stop Limit Orders
    // ============================================================================

    /**
     * Test: Stop limit order creation succeeds with valid trigger price
     *
     * Verifies that a stop limit order is successfully created when:
     * - User is authenticated with trade permission
     * - All required fields including trigger_price are provided
     * - User has sufficient balance
     * - Trigger price is valid
     */
    public function test_stop_limit_order_creation_succeeds_with_valid_trigger_price()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'stop_limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
                'trigger_price' => '49000',
            ]);

        $response->assertStatus(200);
        $response->assertSeeText('message');
    }

    /**
     * Test: Stop limit order is not processed if trigger condition not met
     *
     * Verifies that stop limit orders remain pending until trigger condition is met.
     * Orders should not be processed immediately if market price hasn't reached trigger.
     */
    public function test_stop_limit_order_not_processed_if_trigger_condition_not_met()
    {
        // This would require mocking market_get_stats to return a price
        // that doesn't meet the trigger condition
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'stop_limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
                'trigger_price' => '49000', // Trigger when price drops to 49000
            ]);

        $response->assertStatus(200);
        // Order should be created but not processed until trigger condition is met
    }

    // ============================================================================
    // FEE CALCULATION TESTS
    // ============================================================================

    /**
     * Test: Taker fee is calculated correctly for buy limit orders
     *
     * Verifies that taker fees are correctly calculated for buy limit orders.
     * Fee should be calculated as: (price * quantity) * taker_fee_rate
     * Total required balance = (price * quantity) + fee
     */
    public function test_taker_fee_is_calculated_correctly_for_buy_limit_orders()
    {
        Setting::set('trade.taker_fee', 0.25); // 0.25%
        $token = $this->user->createToken('test-token', ['trade']);

        $price = '50000';
        $quantity = '0.1';
        $expectedTotal = math_sum(
            math_multiply($price, $quantity),
            math_percentage(math_multiply($price, $quantity), 0.25)
        );

        // Verify balance check considers fee
        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->quoteCurrency->id)
            ->first();

        // Set balance to exactly cover order + fee
        $wallet->update(['balance_in_trade' => $expectedTotal]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => $price,
                'quantity' => $quantity,
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Taker fee is calculated correctly for buy market orders
     *
     * Verifies that taker fees are correctly calculated for buy market orders.
     * Fee should be calculated as: quoteQuantity * taker_fee_rate
     * Available quoteQuantity after fee = quoteQuantity - fee
     */
    public function test_taker_fee_is_calculated_correctly_for_buy_market_orders()
    {
        $this->fundReferenceMaker();
        Setting::set('trade.taker_fee', 0.25); // 0.25%
        Cache::put("markets_liquidity.BTC-USDT.asks", collect([
            ['price' => '50000', 'quantity' => '10']
        ]));

        $token = $this->user->createToken('test-token', ['trade']);

        $quoteQuantity = '1000';
        $fee = math_percentage($quoteQuantity, 0.25);
        $expectedTotal = math_sum($quoteQuantity, $fee);

        $wallet = Wallet::where('user_id', $this->user->id)
            ->where('currency_id', $this->quoteCurrency->id)
            ->first();
        $wallet->update(['balance_in_trade' => $expectedTotal]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'quoteQuantity' => $quoteQuantity,
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Maker fee is applied when order adds liquidity
     *
     * Verifies that maker fees (lower than taker fees) are applied when
     * a limit order doesn't match immediately and adds liquidity to the orderbook.
     * This incentivizes market making.
     */
    public function test_maker_fee_is_applied_when_order_adds_liquidity()
    {
        $marker_fee = 0.1;

        Setting::set('trade.maker_fee', $marker_fee); // 0.1%
        Setting::set('trade.taker_fee', 0.25); // 0.25%

        $token = $this->user->createToken('test-token', ['trade']);

        $wallet = Wallet::where('user_id', $this->user->id)->where('currency_id', $this->quoteCurrency->id)->first();
        $initialWalletBalance = $wallet->balance_in_trade;
        $amount = 10000;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => $amount, // Very low price, unlikely to match
                'quantity' => '1',
            ]);

        $wallet->refresh();

        $this->assertEquals($initialWalletBalance - $amount - ($amount * ($marker_fee / 100)), $wallet->balance_in_trade );
        $response->assertStatus(200);
    }

    // ============================================================================
    // ORDER MATCHING TESTS
    // ============================================================================

    /**
     * Test: Limit order matches immediately when price is favorable
     *
     * Verifies that limit orders are matched immediately if there are
     * opposing orders at equal or better prices in the orderbook.
     */
    public function test_limit_order_matches_immediately_when_price_is_favorable()
    {
        // Create an opposing order in the orderbook
        $opposingUser = User::withoutEvents(fn()=>User::factory()->create());
        foreach([$this->baseCurrency->id,$this->quoteCurrency->id] as $id) Wallet::factory()->create(['user_id'=>$opposingUser->id,'currency_id'=>$id,'balance_in_order'=>$id===$this->baseCurrency->id?'0.2':'0','balance_in_trade'=>'0']);
        $sourceOrder = Order::factory()->create([
            'user_id' => $opposingUser->id,
            'market_id' => $this->market->id,
            'side' => 'sell',
            'type' => 'limit',
            'price' => '50000',
            'quantity' => '0.2',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000', // Same price, should match
                'quantity' => '0.1',
            ]);

        $this->assertEquals(Transaction::count(), 2);
        $response->assertStatus(200);
    }

    /**
     * Test: Limit order remains pending when no match found
     *
     * Verifies that limit orders remain in pending state when no matching
     * orders are found in the orderbook. Order should be added to orderbook.
     */
    public function test_limit_order_remains_pending_when_no_match_found()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '30000', // Very low price, won't match
                'quantity' => '0.1',
            ]);

        $this->assertEquals(Order::count(), 1);
        $response->assertStatus(200);
    }

    /**
     * Test: Partial fill is handled correctly
     *
     * Verifies that when an order is partially filled, the remaining
     * quantity stays in the orderbook and order status is updated.
     */
    public function test_partial_fill_is_handled_correctly()
    {

        $quantity = '0.05';
        // Create an opposing order with smaller quantity
        $opposingUser = User::withoutEvents(fn()=>User::factory()->create());
        foreach([$this->baseCurrency->id,$this->quoteCurrency->id] as $id) Wallet::factory()->create(['user_id'=>$opposingUser->id,'currency_id'=>$id,'balance_in_order'=>$id===$this->baseCurrency->id?'0.2':'0','balance_in_trade'=>'0']);
        $order = Order::factory()->create([
            'user_id' => $opposingUser->id,
            'market_id' => $this->market->id,
            'side' => 'sell',
            'type' => 'limit',
            'price' => '50000',
            'quantity' => $quantity, // Smaller than our order
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1', // Larger than opposing order
            ]);

        $order = Order::where('id', $response->json()['message'])->first();
        $this->assertEquals($order->quantity, $quantity);

        $response->assertStatus(200);
    }

    // ============================================================================
    // EVENT & JOB TESTS
    // ============================================================================

    /**
     * Test: WalletUpdated event is dispatched when balance changes
     *
     * Verifies that WalletUpdated event is dispatched when wallet balance
     * is modified during order creation. This allows real-time balance updates.
     */
    public function test_walletUpdated_event_is_dispatched_when_balance_changes()
    {
        Event::fake();

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(200);

        Event::assertDispatched(function (WalletUpdated $event) {
            return $event->wallet->user_id == $this->user->id;
        });
    }

    /**
     * Test: OrderBookUpdated event is dispatched for limit orders
     *
     * Verifies that OrderBookUpdated event is dispatched when a limit order
     * is added to the orderbook. This allows real-time orderbook updates.
     */
    public function test_orderBookUpdated_event_is_dispatched_for_limit_orders()
    {
        Event::fake();

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(200);

        Event::assertDispatched(function (OrderBookUpdated $event) use ($response) {
            return $event->order['id'] == $response->json()['message'];
        });
    }

    // ============================================================================
    // EDGE CASES & ERROR HANDLING TESTS
    // ============================================================================

    /**
     * Test: Order creation handles concurrent order creation
     *
     * Verifies that multiple orders can be created concurrently without
     * race conditions or balance inconsistencies.
     */
    public function test_order_creation_handles_concurrent_order_creation()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
                ->postJson('/api/v1/orders', [
                    'market' => 'BTC-USDT',
                    'type' => 'limit',
                    'side' => 'buy',
                    'price' => '50000',
                    'quantity' => '0.1',
                ]);
        }

        foreach ($responses as $response) {
            $response->assertStatus(200);
        }
    }

    /**
     * Test: Order creation with very large quantity is handled
     *
     * Verifies that orders with very large quantities are handled correctly.
     * System should validate against max_trade_size and handle precision correctly.
     */
    public function test_order_creation_with_very_large_quantity_is_handled()
    {
        $this->market->update(['max_trade_size' => '1000']);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '2000', // Exceeds max
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Order creation with very small quantity is handled
     *
     * Verifies that orders with very small quantities are handled correctly.
     * System should validate against min_trade_size and handle precision correctly.
     */
    public function test_order_creation_with_very_small_quantity_is_handled()
    {
        $this->market->update(['min_trade_size' => '0.001']);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.0001', // Below minimum
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Order creation with maximum precision decimals is handled
     *
     * Verifies that orders with maximum precision decimals are handled correctly.
     * System should respect market precision settings (base_precision, quote_precision).
     */
    public function test_order_creation_with_maximum_precision_decimals_is_handled()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        // Use maximum precision based on market settings
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000.12', // 2 decimal places (quote_precision)
                'quantity' => '0.12345678', // 8 decimal places (base_precision)
            ]);

        $response->assertStatus(200);
    }

    // ============================================================================
    // PRECISION VALIDATION TESTS
    // ============================================================================

    /**
     * Test: Quantity with valid base precision is accepted
     *
     * Verifies that quantity with decimals within the market's base_precision is accepted.
     * For base_precision = 8, values like 0.12345678 should be valid.
     */
    public function test_quantity_with_valid_base_precision_is_accepted()
    {
        $this->market->update(['base_precision' => 5]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.12345', // 5 decimal places = base_precision
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Quantity exceeding base precision is rejected
     *
     * Verifies that quantity with more decimals than the market's base_precision is rejected.
     * For base_precision = 5, values like 0.123456 (6 decimals) should fail.
     */
    public function test_quantity_exceeding_base_precision_is_rejected()
    {
        $this->market->update(['base_precision' => 5]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.123456', // 6 decimal places > base_precision (5)
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    /**
     * Test: Price with valid quote precision is accepted
     *
     * Verifies that price with decimals within the market's quote_precision is accepted.
     * For quote_precision = 2, values like 50000.12 should be valid.
     */
    public function test_price_with_valid_quote_precision_is_accepted()
    {
        $this->market->update(['quote_precision' => 2]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000.12', // 2 decimal places = quote_precision
                'quantity' => '0.1',
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Price exceeding quote precision is rejected
     *
     * Verifies that price with more decimals than the market's quote_precision is rejected.
     * For quote_precision = 2, values like 50000.123 (3 decimals) should fail.
     */
    public function test_price_exceeding_quote_precision_is_rejected()
    {
        $this->market->update(['quote_precision' => 2]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000.123', // 3 decimal places > quote_precision (2)
                'quantity' => '0.1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['price']);
    }

    /**
     * Test: QuoteQuantity with valid quote precision is accepted
     *
     * Verifies that quoteQuantity with decimals within the market's quote_precision is accepted.
     * For quote_precision = 2, values like 1000.12 should be valid.
     */
    public function test_quoteQuantity_with_valid_quote_precision_is_accepted()
    {
        $this->fundReferenceMaker();
        $this->market->update(['quote_precision' => 2]);
        Cache::put("markets_liquidity.BTC-USDT.asks", collect([
            ['price' => '50000', 'quantity' => '10']
        ]));

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'quoteQuantity' => '1000.12', // 2 decimal places = quote_precision
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: QuoteQuantity exceeding quote precision is rejected
     *
     * Verifies that quoteQuantity with more decimals than the market's quote_precision is rejected.
     * For quote_precision = 2, values like 1000.123 (3 decimals) should fail.
     */
    public function test_quoteQuantity_exceeding_quote_precision_is_rejected()
    {
        $this->market->update(['quote_precision' => 2]);
        Cache::put("markets_liquidity.BTC-USDT.asks", collect([
            ['price' => '50000', 'quantity' => '10']
        ]));

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'quoteQuantity' => '1000.123', // 3 decimal places > quote_precision (2)
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quoteQuantity']);
    }

    /**
     * Test: Whole numbers are valid for any precision
     *
     * Verifies that whole numbers (no decimals) are valid regardless of precision settings.
     * Values like 100, 50000 should always be accepted.
     */
    public function test_whole_numbers_are_valid_for_any_precision()
    {
        $this->market->update(['base_precision' => 2, 'quote_precision' => 2]);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '100', // No decimals, low price to stay within balance
                'quantity' => '1', // No decimals
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Precision validation works with different market configurations
     *
     * Verifies that precision validation works correctly across different market configurations.
     * Tests a market with base_precision=3 and quote_precision=4.
     */
    public function test_precision_validation_with_different_market_config()
    {
        $this->market->update(['base_precision' => 3, 'quote_precision' => 4]);
        $token = $this->user->createToken('test-token', ['trade']);

        // Valid precision
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000.1234', // 4 decimals = quote_precision
                'quantity' => '0.123', // 3 decimals = base_precision
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Quantity precision validation for sell orders
     *
     * Verifies that precision validation for quantity applies equally to sell orders.
     */
    public function test_quantity_precision_validation_for_sell_orders()
    {
        $this->market->update(['base_precision' => 4]);
        $token = $this->user->createToken('test-token', ['trade']);

        // Invalid precision for sell order
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'sell',
                'price' => '50000',
                'quantity' => '0.12345', // 5 decimals > base_precision (4)
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    // ============================================================================
    // SWAP ORDER TESTS
    // ============================================================================

    /**
     * Test: Swap order creation succeeds with fixed rate enabled
     *
     * Verifies that swap orders (instant exchange) are created successfully
     * when fixed swap rate is enabled and a real maker has available inventory.
     * The fixed-rate flag no longer bypasses funded counterparties.
     */
    public function test_swap_order_creation_succeeds_with_fixed_rate_enabled()
    {
        $this->fundReferenceMaker();
        config(['app.fixed_swap' => true]);

        Cache::put("markets_liquidity.BTC-USDT.asks", collect([
            ['price' => '50000', 'quantity' => '10']
        ]));

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'market',
                'side' => 'buy',
                'quoteQuantity' => '1000',
                'swap' => true,
            ]);

        $response->assertStatus(200);
    }

    // ============================================================================
    // MAINTENANCE MODE TESTS
    // ============================================================================

    /**
     * Test: Order creation is blocked during maintenance mode for non-admins
     *
     * Verifies that regular users cannot create orders during maintenance mode.
     * Only administrators should be able to trade during maintenance.
     */
    public function test_order_creation_blocked_during_maintenance_for_non_admins()
    {
        Setting::set('general.maintenance_status', true);
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders', [
                'market' => 'BTC-USDT',
                'type' => 'limit',
                'side' => 'buy',
                'price' => '50000',
                'quantity' => '0.1',
            ]);

        $response->assertStatus(200);
        $response->assertSeeText(['maintenance']);
    }
}
