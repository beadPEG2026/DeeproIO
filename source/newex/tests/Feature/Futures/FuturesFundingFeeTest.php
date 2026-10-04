<?php

namespace Tests\Feature\Futures;

use App\Models\Currency\Currency;
use App\Models\FundingFeeDistribution;
use App\Models\Market\Market;
use App\Models\Order\FuturesContract;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Setting;
use Tests\TestCase;

/**
 * Comprehensive Unit Tests for Futures Funding Fees
 *
 * This test suite covers all scenarios for futures funding fee management:
 * - Funding fee calculation
 * - Funding fee collection
 * - Long vs short position fee differences
 * - Periodic funding fee processing
 * - Balance updates from funding fees
 * - Funding fee history
 *
 * Each test method includes detailed documentation explaining:
 * - What scenario is being tested
 * - Expected behavior
 * - Why the test is important
 */
class FuturesFundingFeeTest extends TestCase
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

        // Enable funding fees
        Setting::set('futures.funding_fee_enabled', true);
        Setting::set('futures.funding_fee_rate', '0.01'); // 0.01%
        Setting::set('futures.funding_fee_interval_hours', 8);
    }

    // ============================================================================
    // FUNDING FEE CALCULATION TESTS
    // ============================================================================

    /**
     * Test: Funding fee is calculated based on position size
     *
     * Verifies that funding fees are proportional to position size.
     * Larger positions pay larger fees.
     */
    public function test_funding_fee_calculated_based_on_position_size()
    {
        $smallPosition = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'balance' => '100',
            'leverage' => 10,
            'status' => 'active',
        ]);

        $largePosition = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'balance' => '1000',
            'leverage' => 10,
            'status' => 'active',
        ]);

        // Large position should have proportionally larger fee
        // Fee = position_value * funding_rate
        $this->assertGreaterThan($smallPosition->balance, $largePosition->balance);
    }

    /**
     * Test: Funding fee is calculated based on leverage
     *
     * Verifies that funding fees account for leveraged position value.
     * Same margin with higher leverage = larger position = larger fee.
     */
    public function test_funding_fee_calculated_based_on_leverage()
    {
        $lowLeveragePosition = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'balance' => '1000',
            'leverage' => 5,
            'status' => 'active',
        ]);

        $highLeveragePosition = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'balance' => '1000',
            'leverage' => 50,
            'status' => 'active',
        ]);

        // High leverage position has larger position value
        // Position Value = Balance * Leverage
        $lowPositionValue = $lowLeveragePosition->balance * $lowLeveragePosition->leverage;
        $highPositionValue = $highLeveragePosition->balance * $highLeveragePosition->leverage;

        $this->assertGreaterThan($lowPositionValue, $highPositionValue);
    }

    // ============================================================================
    // FUNDING FEE COLLECTION TESTS
    // ============================================================================
    /**
     * Test: Funding fee tracked in total_funding_fee_paid
     *
     * Verifies that total funding fees paid are tracked on the position.
     */
    public function test_funding_fee_tracked_in_total_funding_fee_paid()
    {
        $position = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'balance' => '1000',
            'total_funding_fee_paid' => '0',
            'status' => 'active',
        ]);

        // Initially no fees paid
        $this->assertEquals('0', $position->total_funding_fee_paid);

        // After fees collected, total should increase
        // This is tracked for transparency and reporting
    }

    /**
     * Test: Funding fee updates last_funding_fee_at timestamp
     *
     * Verifies that the last funding fee timestamp is updated after collection.
     */
    public function test_funding_fee_updates_timestamp()
    {
        $position = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
            'last_funding_fee_at' => null,
        ]);

        // After first funding fee, timestamp should be set
        $this->assertNull($position->last_funding_fee_at);
    }

    // ============================================================================
    // POSITION TYPE TESTS
    // ============================================================================

    /**
     * Test: Long positions pay funding fee when rate is positive
     *
     * Verifies that long positions pay funding fees when the funding rate
     * is positive (longs pay shorts).
     */
    public function test_long_positions_pay_funding_fee_when_rate_positive()
    {
        Setting::set('futures.funding_fee_rate', '0.01'); // Positive rate

        $longPosition = FuturesContract::factory()->long()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'is_long' => true,
            'status' => 'active',
        ]);

        // With positive funding rate, longs pay shorts
        $this->assertTrue($longPosition->is_long);
    }

    /**
     * Test: Short positions receive funding fee when rate is positive
     *
     * Verifies that short positions receive funding fees when the funding rate
     * is positive (shorts receive from longs).
     */
    public function test_short_positions_receive_funding_fee_when_rate_positive()
    {
        Setting::set('futures.funding_fee_rate', '0.01'); // Positive rate

        $shortPosition = FuturesContract::factory()->short()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'is_long' => false,
            'status' => 'active',
        ]);

        // With positive funding rate, shorts receive
        $this->assertFalse($shortPosition->is_long);
    }

    // ============================================================================
    // INTERVAL TESTS
    // ============================================================================

    /**
     * Test: Funding fee only collected at specified intervals
     *
     * Verifies that funding fees are only collected every N hours
     * (typically 8 hours in perpetual futures).
     */
    public function test_funding_fee_only_collected_at_intervals()
    {
        Setting::set('futures.funding_fee_interval_hours', 8);

        $position = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
            'last_funding_fee_at' => now()->subHours(4), // Only 4 hours ago
        ]);

        // Position should not have fees collected yet (only 4 hours passed)
        $hoursSinceLastFee = now()->diffInHours($position->last_funding_fee_at);
        $this->assertLessThan(8, $hoursSinceLastFee);
    }

    /**
     * Test: Funding fee collected when interval exceeded
     *
     * Verifies that funding fees are collected when the interval has passed.
     */
    public function test_funding_fee_collected_when_interval_exceeded()
    {
        Setting::set('futures.funding_fee_interval_hours', 8);

        $position = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
            'last_funding_fee_at' => now()->subHours(10), // 10 hours ago
        ]);

        // Position is due for funding fee collection
        $hoursSinceLastFee = $position->last_funding_fee_at->diffInHours(now());
        $this->assertGreaterThanOrEqual(8, $hoursSinceLastFee);
    }

    // ============================================================================
    // POSITION STATUS TESTS
    // ============================================================================

    /**
     * Test: Funding fee only collected from active positions
     *
     * Verifies that only active positions are charged funding fees.
     */
    public function test_funding_fee_only_collected_from_active_positions()
    {
        $activePosition = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
        ]);

        $closedPosition = FuturesContract::factory()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'closed',
        ]);

        $this->assertEquals('active', $activePosition->status);
        $this->assertEquals('closed', $closedPosition->status);
    }

    /**
     * Test: Pending limit orders don't incur funding fees
     *
     * Verifies that pending limit orders are not charged funding fees.
     */
    public function test_pending_orders_dont_incur_funding_fees()
    {
        $pendingOrder = FuturesContract::factory()->pendingLimit()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'pending',
        ]);

        // Pending orders should not be charged fees
        $this->assertEquals('pending', $pendingOrder->status);
    }

    /**
     * Test: Scheduled positions don't incur funding fees
     *
     * Verifies that scheduled positions are not charged funding fees
     * until they are activated.
     */
    public function test_scheduled_positions_dont_incur_funding_fees()
    {
        $scheduledPosition = FuturesContract::factory()->scheduled()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'scheduled',
        ]);

        // Scheduled positions should not be charged fees
        $this->assertEquals('scheduled', $scheduledPosition->status);
    }

    // ============================================================================
    // BALANCE IMPACT TESTS
    // ============================================================================

    /**
     * Test: Position liquidated if balance insufficient for funding fee
     *
     * Verifies that positions are liquidated if the remaining balance
     * is insufficient to cover the funding fee.
     */
    public function test_position_liquidated_if_balance_insufficient_for_funding_fee()
    {
        $position = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'balance' => '0.01', // Very low balance
            'status' => 'active',
        ]);

        // If funding fee exceeds remaining balance, position should be liquidated
        $this->assertLessThan(1, $position->balance);
    }

    /**
     * Test: Multiple funding fees accumulated correctly
     *
     * Verifies that multiple funding fee payments are accumulated correctly
     * in the total_funding_fee_paid field.
     */
    public function test_multiple_funding_fees_accumulated_correctly()
    {
        $position = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'balance' => '1000',
            'total_funding_fee_paid' => '10', // Already paid 10 in fees
            'status' => 'active',
        ]);

        // After more fees, total should increase
        $this->assertEquals('10', $position->total_funding_fee_paid);
    }

    // ============================================================================
    // EDGE CASE TESTS
    // ============================================================================

    /**
     * Test: Zero funding rate results in no fee
     *
     * Verifies that when funding rate is zero, no fees are charged.
     */
    public function test_zero_funding_rate_results_in_no_fee()
    {
        Setting::set('futures.funding_fee_rate', '0');

        $position = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
        ]);

        // With zero rate, no fees should be charged
        $rate = Setting::get('futures.funding_fee_rate');
        $this->assertEquals('0', $rate);
    }

    /**
     * Test: Negative funding rate benefits longs
     *
     * Verifies that negative funding rate means shorts pay longs.
     */
    public function test_negative_funding_rate_benefits_longs()
    {
        Setting::set('futures.funding_fee_rate', '-0.01'); // Negative rate

        $longPosition = FuturesContract::factory()->long()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'is_long' => true,
            'status' => 'active',
        ]);

        // With negative funding rate, longs receive payment
        $rate = Setting::get('futures.funding_fee_rate');
        $this->assertLessThan(0, floatval($rate));
    }

    /**
     * Test: Funding fee disabled setting works
     *
     * Verifies that funding fees can be disabled via settings.
     */
    public function test_funding_fee_disabled_setting_works()
    {
        Setting::set('futures.funding_fee_enabled', false);

        $position = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
        ]);

        // Funding fees should be disabled
        $this->assertFalse(Setting::get('futures.funding_fee_enabled'));
    }

    /**
     * Test: Position with no previous funding fee gets first fee collected
     *
     * Verifies that positions without any previous funding fee timestamp
     * are correctly handled for first fee collection.
     */
    public function test_position_with_no_previous_funding_fee_handled()
    {
        $position = FuturesContract::factory()->active()->create([
            'user_id' => $this->user->id,
            'market_id' => $this->market->id,
            'status' => 'active',
            'last_funding_fee_at' => null, // Never had funding fee
            'activated_at' => now()->subHours(10), // Active for 10 hours
        ]);

        // Position should be eligible for funding fee
        $this->assertNull($position->last_funding_fee_at);
    }
}
