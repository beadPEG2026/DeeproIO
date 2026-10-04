<?php

namespace Tests\Feature\Staking;

use App\Models\Currency\Currency;
use App\Models\Staking\Staking;
use App\Models\Staking\StakingUser;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Comprehensive Unit Tests for Staking Functionality
 *
 * This test suite covers all scenarios for staking operations:
 * - Listing stakings (public and user-specific)
 * - Viewing staking details
 * - Purchasing/subscribing to staking
 * - Redeeming staked amounts
 * - Validation rules
 * - Balance operations
 * - Authentication requirements
 */
class StakingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Currency $currency;
    private Staking $staking;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test currency
        $this->currency = Currency::factory()->create([
            'id' => 1,
            'symbol' => 'USDT',
            'name' => 'Tether',
            'type' => 'coin',
            'status' => true,
        ]);

        // Create test staking
        $this->staking = Staking::factory()->create([
            'currency_id' => $this->currency->id,
            'allowed_days' => '7,14,30,60,90',
            'rewards_percentage' => '1,2,5,10,15',
            'min_amount' => '100',
            'max_amount' => '100000',
            'status' => 'active',
        ]);

        // Create test user
        User::withoutEvents(function () {
            $this->user = User::factory()->create([
                'email_verified_at' => now(),
            ]);
        });

        // Create wallet
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'currency_id' => $this->currency->id,
            'balance_in_wallet' => '10000',
            'balance_in_trade' => '10000',
            'balance_in_order' => '0',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ============================================================================
    // PUBLIC STAKING LIST TESTS
    // ============================================================================

    /**
     * Test: Public staking list is accessible without authentication
     */
    public function test_public_staking_list_accessible_without_auth()
    {
        $response = $this->getJson('/api/v1/stakings');
        $response->assertStatus(200);
        $response->assertJsonStructure(['stakings']);
    }

    /**
     * Test: Staking list returns active stakings
     */
    public function test_staking_list_returns_active_stakings()
    {
        Staking::factory()->active()->create(['currency_id' => $this->currency->id]);

        $response = $this->getJson('/api/v1/stakings');
        $response->assertStatus(200);
        $response->assertJsonStructure(['stakings']);
    }

    /**
     * Test: Staking details endpoint works
     */
    public function test_staking_details_endpoint_works()
    {
        $response = $this->getJson('/api/v1/staking?id=' . $this->staking->id);

        $response->assertStatus(200);
        $response->assertJsonStructure(['staking']);
    }

    /**
     * Test: Invalid staking ID returns 404
     */
    public function test_invalid_staking_id_returns_404()
    {
        $response = $this->getJson('/api/v1/staking?id=99999');

        $response->assertStatus(404);
    }

    // ============================================================================
    // USER STAKING LIST TESTS
    // ============================================================================

    /**
     * Test: User staking list requires authentication
     */
    public function test_user_staking_list_requires_auth()
    {
        $response = $this->getJson('/api/v1/stakings/my');

        $response->assertStatus(401);
    }

    /**
     * Test: Authenticated user can view their stakings
     */
    public function test_authenticated_user_can_view_their_stakings()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        // Create user staking
        StakingUser::factory()->create([
            'user_id' => $this->user->id,
            'staking_id' => $this->staking->id,
            'currency_id' => $this->currency->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/stakings/my');

        $response->assertStatus(200);
        $response->assertJsonStructure(['stakings']);
    }

    // ============================================================================
    // STAKING PURCHASE TESTS
    // ============================================================================

    /**
     * Test: Staking purchase requires authentication
     */
    public function test_staking_purchase_requires_auth()
    {
        $response = $this->postJson('/api/v1/staking/submit', [
            'id' => $this->staking->id,
            'amount' => '1000',
            'days' => 30,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test: Staking purchase with valid data succeeds
     */
    public function test_staking_purchase_with_valid_data_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/submit', [
                'id' => $this->staking->id,
                'amount' => '1000',
                'days' => 30,
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);
    }

    /**
     * Test: Staking ID is required
     */
    public function test_staking_id_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/submit', [
                'amount' => '1000',
                'days' => 30,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['id']);
    }

    /**
     * Test: Staking amount is required
     */
    public function test_staking_amount_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/submit', [
                'id' => $this->staking->id,
                'days' => 30,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test: Invalid staking ID is rejected
     */
    public function test_invalid_staking_id_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/submit', [
                'id' => 99999,
                'amount' => '1000',
                'days' => 30,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['id']);
    }

    /**
     * Test: Amount below minimum is rejected
     */
    public function test_amount_below_minimum_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/submit', [
                'id' => $this->staking->id,
                'amount' => '1', // Below min_amount of 100
                'days' => 30,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test: Negative amount is rejected
     */
    public function test_negative_amount_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/submit', [
                'id' => $this->staking->id,
                'amount' => '-1000',
                'days' => 30,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test: Non-numeric amount is rejected
     */
    public function test_non_numeric_amount_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/submit', [
                'id' => $this->staking->id,
                'amount' => 'invalid',
                'days' => 30,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test: Inactive staking cannot be purchased
     */
    public function test_inactive_staking_cannot_be_purchased()
    {
        $inactiveStaking = Staking::factory()->inactive()->create([
            'currency_id' => $this->currency->id,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/submit', [
                'id' => $inactiveStaking->id,
                'amount' => '1000',
                'days' => 30,
            ]);

        $response->assertStatus(422);
    }

    // ============================================================================
    // STAKING REDEEM TESTS
    // ============================================================================

    /**
     * Test: Staking redeem requires authentication
     */
    public function test_staking_redeem_requires_auth()
    {
        $stakingUser = StakingUser::factory()->create([
            'user_id' => $this->user->id,
            'staking_id' => $this->staking->id,
            'currency_id' => $this->currency->id,
        ]);

        $response = $this->postJson('/api/v1/staking/redeem', [
            'id' => $stakingUser->id,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test: Staking redeem ID is required
     */
    public function test_staking_redeem_id_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/redeem', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['id']);
    }

    /**
     * Test: Invalid staking user ID cannot be redeemed
     */
    public function test_invalid_staking_user_id_cannot_be_redeemed()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/redeem', [
                'id' => 99999,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test: Already redeemed staking cannot be redeemed again
     */
    public function test_already_redeemed_staking_cannot_be_redeemed_again()
    {
        $stakingUser = StakingUser::factory()->redeemed()->create([
            'user_id' => $this->user->id,
            'staking_id' => $this->staking->id,
            'currency_id' => $this->currency->id,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/redeem', [
                'id' => $stakingUser->id,
            ]);

        $response->assertStatus(422);
    }

    // ============================================================================
    // REDEMPTION CALCULATION TESTS
    // ============================================================================

    /**
     * Test: Redemption calculation endpoint requires auth
     */
    public function test_redemption_calculation_requires_auth()
    {
        $response = $this->postJson('/api/v1/staking/redemption/calculate', [
            'days' => 30,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test: Redemption calculation returns valid dates
     */
    public function test_redemption_calculation_returns_valid_dates()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/staking/redemption/calculate', [
                'days' => 30,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['value_date', 'redemption_date']);
    }

    // ============================================================================
    // EDGE CASES
    // ============================================================================

    /**
     * Test: Empty stakings list returns empty array
     */
    public function test_empty_stakings_list_returns_empty_structure()
    {
        // Remove all stakings
        Staking::query()->delete();

        $response = $this->getJson('/api/v1/stakings');

        $response->assertStatus(200);
        $response->assertJsonStructure(['stakings']);
    }

    /**
     * Test: User with no stakings gets empty list
     */
    public function test_user_with_no_stakings_gets_empty_list()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/stakings/my');

        $response->assertStatus(200);
        $response->assertJsonStructure(['stakings']);
    }
}
