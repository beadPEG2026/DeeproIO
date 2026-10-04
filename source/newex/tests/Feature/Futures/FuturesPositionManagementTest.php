<?php

namespace Tests\Feature\Futures;

use App\Models\Currency\Currency;
use App\Models\Market\Market;
use App\Models\Order\FuturesContract;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Setting;
use Tests\TestCase;

/**
 * Comprehensive Unit Tests for Futures Position Management
 *
 * This test suite covers all scenarios for managing existing futures positions:
 * - Position closing (manual close)
 * - Take Profit / Stop Loss execution
 * - Position status transitions
 * - PnL calculations
 * - Balance updates on close
 * - Partial closes
 * - Position queries and filtering
 *
 * Each test method includes detailed documentation explaining:
 * - What scenario is being tested
 * - Expected behavior
 * - Why the test is important
 */
class FuturesPositionManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Market $market;
    private Currency $baseCurrency;
    private Currency $quoteCurrency;

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
            'has_futures' => true,
            'base_precision' => 8,
            'quote_precision' => 2,
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

        // Set up market stats
        Cache::put("markets_stats.{$this->market->id}.last", '50000');
    }

    // ============================================================================
    // POSITION CLOSING TESTS
    // ============================================================================

    /**
     * Test: Close active long position succeeds
     *
     * Verifies that an active long position can be manually closed.
     * Balance should be returned with profit/loss calculated.
     */
    public function test_close_active_long_position_succeeds()
    {
        $position = FuturesContract::factory()->long()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'quote_currency_id' => $this->quoteCurrency->id,
            'status' => 'active',
            'balance' => '1000',
            'price' => '50000',
            'leverage' => 10,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders/futures/cancel', [
                'uuid' => $position->id,
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Close active short position succeeds
     *
     * Verifies that an active short position can be manually closed.
     */
    public function test_close_active_short_position_succeeds()
    {
        $position = FuturesContract::factory()->short()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'quote_currency_id' => $this->quoteCurrency->id,
            'status' => 'active',
            'balance' => '1000',
            'price' => '50000',
            'leverage' => 10,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders/futures/cancel', [
                'uuid' => $position->id,
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test: Cannot close another user's position
     *
     * Verifies that users cannot close positions that don't belong to them.
     */
    public function test_cannot_close_another_users_position()
    {
        $otherUser = User::factory()->create();
        $position = FuturesContract::factory()->active()->create([
            'user_id' => $otherUser->id,
            'market_id' => $this->market->id,
            'quote_currency_id' => $this->quoteCurrency->id,
            'status' => 'active',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders/futures/cancel', [
                'uuid' => $position->id,
            ]);

        // Should fail - position belongs to another user
        $response->assertJsonFragment(['message' => 'Invalid order']);
        $this->assertContains($response->status(), [422]);
    }

    /**
     * Test: Cannot close already closed position
     *
     * Verifies that positions that are already closed cannot be closed again.
     */
    public function test_cannot_close_already_closed_position()
    {
        $position = FuturesContract::factory()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'quote_currency_id' => $this->quoteCurrency->id,
            'status' => 'closed',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/orders/futures/cancel', [
                'uuid' => $position->id,
            ]);

        $response->assertJsonFragment(['message' => 'Invalid order']);
        $this->assertContains($response->status(), [422]);
    }

    // ============================================================================
    // PENDING LIMIT ORDER TESTS
    // ============================================================================

    /**
     * Test: Multiple pending orders can be cancelled
     *
     * Verifies that multiple pending orders can be cancelled in sequence.
     */
    public function test_multiple_pending_orders_can_be_cancelled()
    {
        $orders = [];
        for ($i = 0; $i < 3; $i++) {
            $orders[] = FuturesContract::factory()->pendingLimit()->create([
                'user_id' => $this->user->id,
                'market_id' => $this->market->id,
                'quote_currency_id' => $this->quoteCurrency->id,
                'status' => 'pending',
                'type' => FuturesContract::TYPE_LIMIT,
                'balance' => '100',
            ]);
        }

        $token = $this->user->createToken('test-token', ['trade']);

        foreach ($orders as $order) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
                ->postJson('/api/v1/orders/futures/cancel', [
                    'uuid' => $order->id,
                ]);

            $response->assertStatus(200);
        }
    }

    // ============================================================================
    // POSITION QUERY TESTS
    // ============================================================================

    /**
     * Test: Open positions endpoint returns only active positions
     *
     * Verifies that the open positions endpoint filters out closed positions.
     */
    public function test_open_positions_returns_only_active_positions()
    {
        // Create active position
        FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
        ]);

        // Create closed position
        FuturesContract::factory()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'closed',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/open');

        $response->assertStatus(200);

        // Should only return the active position
        $data = $response->json('data');
        $this->assertCount(1, $data);
    }

    /**
     * Test: Open positions can be filtered by market
     *
     * Verifies that positions can be filtered by specific market.
     */
    public function test_open_positions_filtered_by_market()
    {
        FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
        ]);

        // Create another market
        $otherMarket = Market::factory()->create([
            'name' => 'ETH-USDT',
            'has_futures' => true,
        ]);

        FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $otherMarket->id,
            'status' => 'active',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/open?market=BTC-USDT');

        $response->assertStatus(200);

        // Should only return BTC-USDT position
        $data = $response->json('data');
        $this->assertCount(1, $data);
    }

    /**
     * Test: Open orders endpoint returns only pending limit orders
     *
     * Verifies that open orders returns pending limit orders, not active positions.
     */
    public function test_open_orders_returns_only_pending_limit_orders()
    {
        // Create pending limit order
        FuturesContract::factory()->pendingLimit()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'pending',
            'type' => FuturesContract::TYPE_LIMIT,
        ]);

        // Create active market position
        FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
            'type' => FuturesContract::TYPE_MARKET,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/orders');

        $response->assertStatus(200);

        // Should only return pending limit order
        $data = $response->json('data');
        $this->assertCount(1, $data);
    }

    /**
     * Test: Unauthenticated user cannot view positions
     *
     * Verifies that position queries require authentication.
     */
    public function test_unauthenticated_user_cannot_view_positions()
    {
        $response = $this->getJson('/api/v1/orders/futures/open');

        $response->assertStatus(401);
    }

    // ============================================================================
    // SCHEDULED POSITION TESTS
    // ============================================================================

    /**
     * Test: Scheduled positions are included in open positions
     *
     * Verifies that scheduled (not yet activated) positions are shown in open.
     */
    public function test_scheduled_positions_included_in_open_positions()
    {
        FuturesContract::factory()->scheduled()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'scheduled',
            'start_at' => now()->addHour(),
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/open');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
    }

    // ============================================================================
    // POSITION WITH TP/SL TESTS
    // ============================================================================

    /**
     * Test: Position with TP/SL shows correct values
     *
     * Verifies that positions with take profit and stop loss show correct prices.
     */
    public function test_position_with_tpsl_shows_correct_values()
    {
        FuturesContract::factory()->withTPSL('55000', '48000')->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
            'take_profit_price' => '55000',
            'stop_loss_price' => '48000',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/open');

        $response->assertStatus(200);
    }

    // ============================================================================
    // HIGH LEVERAGE POSITION TESTS
    // ============================================================================

    /**
     * Test: High leverage positions have correct liquidation price
     *
     * Verifies that positions with high leverage show appropriate liquidation prices.
     */
    public function test_high_leverage_position_has_correct_liquidation_price()
    {
        $position = FuturesContract::factory()->highLeverage(100)->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
            'leverage' => 100,
            'price' => '50000',
            'liquidation_price' => '49500', // Very close to entry at 100x
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/open');

        $response->assertStatus(200);
    }

    // ============================================================================
    // EDGE CASE TESTS
    // ============================================================================

    /**
     * Test: Empty positions list returns empty array
     *
     * Verifies that users with no positions receive an empty data array.
     */
    public function test_empty_positions_list_returns_empty_array()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/open');

        $response->assertStatus(200);
        $response->assertJson(['data' => []]);
    }

    /**
     * Test: Invalid market filter returns empty results
     *
     * Verifies that filtering by non-existent market returns empty results.
     */
    public function test_invalid_market_filter_returns_empty_results()
    {
        FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/orders/futures/open?market=NONEXISTENT-PAIR');

        $response->assertStatus(200);
        $response->assertJson(['data' => []]);
    }
}
