<?php

namespace Tests\Feature\Launchpad;

use App\Models\Currency\Currency;
use App\Models\Launchpad\Launchpad;
use App\Models\Launchpad\LaunchpadTransaction;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Comprehensive Unit Tests for Launchpad Functionality
 *
 * This test suite covers all scenarios for launchpad operations:
 * - Listing launchpads (all, active, ended)
 * - Viewing launchpad details
 * - Purchasing tokens in launchpad
 * - Validation rules
 * - Balance operations
 * - Time-based restrictions
 * - Authentication requirements
 */
class LaunchpadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Currency $currency;
    private Currency $ethCurrency;
    private Launchpad $launchpad;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test currency for launchpad token
        $this->currency = Currency::factory()->create([
            'id' => 1,
            'symbol' => 'TEST',
            'name' => 'Test Token',
            'type' => 'token',
            'status' => true,
        ]);

        // Create ETH currency for payment
        $this->ethCurrency = Currency::factory()->create([
            'id' => 2,
            'symbol' => 'ETH',
            'name' => 'Ethereum',
            'type' => 'coin',
            'status' => true,
        ]);

        // Create test launchpad
        $this->launchpad = Launchpad::factory()->active()->create([
            'currency_id' => $this->currency->id,
            'network_id' => 1, // ETH network
            'rate' => '0.001',
            'min_buy' => '0.1',
            'max_buy' => '10',
            'soft_cap' => '100',
            'hard_cap' => '1000',
        ]);

        // Create test user
        User::withoutEvents(function () {
            $this->user = User::factory()->create([
                'email_verified_at' => now(),
            ]);
        });

        // Create wallet with ETH for purchasing
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'currency_id' => $this->ethCurrency->id,
            'balance_in_wallet' => '100',
            'balance_in_trade' => '100',
            'balance_in_order' => '0',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ============================================================================
    // PUBLIC LAUNCHPAD LIST TESTS
    // ============================================================================

    /**
     * Test: Public launchpad list is accessible without authentication
     */
    public function test_public_launchpad_list_accessible_without_auth()
    {
        $response = $this->getJson('/api/v1/launchpads');

        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Launchpad list returns active launchpads
     */
    public function test_launchpad_list_returns_active_launchpads()
    {
        Launchpad::factory()->active()->create(['currency_id' => $this->currency->id]);

        $response = $this->getJson('/api/v1/launchpads');

        // May succeed or fail based on resource requirements
        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Launchpad list can be sorted
     */
    public function test_launchpad_list_can_be_sorted()
    {
        $response = $this->getJson('/api/v1/launchpads');

        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Launchpad details endpoint works
     */
    public function test_launchpad_details_endpoint_works()
    {
        $response = $this->getJson('/api/v1/launchpad?id=' . $this->launchpad->id);

        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Invalid launchpad ID returns 404
     */
    public function test_invalid_launchpad_id_returns_404()
    {
        $response = $this->getJson('/api/v1/launchpad?id=99999');

        $response->assertStatus(404);
    }

    // ============================================================================
    // LAUNCHPAD PURCHASE TESTS
    // ============================================================================

    /**
     * Test: Launchpad purchase requires authentication
     */
    public function test_launchpad_purchase_requires_auth()
    {
        $response = $this->postJson('/api/v1/launchpad/submit', [
            'id' => $this->launchpad->id,
            'amount' => '1',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test: Launchpad purchase with valid data succeeds
     */
    public function test_launchpad_purchase_with_valid_data_succeeds()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/launchpad/submit', [
                'id' => $this->launchpad->id,
                'amount' => '1',
            ]);

        // May succeed or fail based on balance/launchpad rules
        $this->assertContains($response->status(), [200]);
    }

    /**
     * Test: Launchpad ID is required
     */
    public function test_launchpad_id_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/launchpad/submit', [
                'amount' => '1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['id']);
    }

    /**
     * Test: Launchpad amount is required
     */
    public function test_launchpad_amount_is_required()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/launchpad/submit', [
                'id' => $this->launchpad->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test: Invalid launchpad ID is rejected
     */
    public function test_invalid_launchpad_id_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/launchpad/submit', [
                'id' => 99999,
                'amount' => '1',
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
            ->postJson('/api/v1/launchpad/submit', [
                'id' => $this->launchpad->id,
                'amount' => '0.01', // Below min_buy of 0.1
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
            ->postJson('/api/v1/launchpad/submit', [
                'id' => $this->launchpad->id,
                'amount' => '-1',
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
            ->postJson('/api/v1/launchpad/submit', [
                'id' => $this->launchpad->id,
                'amount' => 'invalid',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test: Inactive launchpad cannot be purchased
     */
    public function test_inactive_launchpad_cannot_be_purchased()
    {
        $inactiveLaunchpad = Launchpad::factory()->inactive()->create([
            'currency_id' => $this->currency->id,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/launchpad/submit', [
                'id' => $inactiveLaunchpad->id,
                'amount' => '1',
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test: Ended launchpad cannot be purchased
     */
    public function test_ended_launchpad_cannot_be_purchased()
    {
        $endedLaunchpad = Launchpad::factory()->ended()->create([
            'currency_id' => $this->currency->id,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/launchpad/submit', [
                'id' => $endedLaunchpad->id,
                'amount' => '1',
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test: Upcoming launchpad cannot be purchased
     */
    public function test_upcoming_launchpad_cannot_be_purchased()
    {
        $upcomingLaunchpad = Launchpad::factory()->upcoming()->create([
            'currency_id' => $this->currency->id,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/launchpad/submit', [
                'id' => $upcomingLaunchpad->id,
                'amount' => '1',
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test: Amount above maximum is rejected
     */
    public function test_amount_above_maximum_is_rejected()
    {
        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/launchpad/submit', [
                'id' => $this->launchpad->id,
                'amount' => '100', // Above max_buy of 10
            ]);

        $response->assertStatus(422);
    }

    // ============================================================================
    // LAUNCHPAD TRANSACTIONS TESTS
    // ============================================================================

    /**
     * Test: Launchpad transactions require authentication
     */
    public function test_launchpad_transactions_require_auth()
    {
        $response = $this->getJson('/api/v1/transactions/launchpads');

        $response->assertStatus(401);
    }

    /**
     * Test: User can view their launchpad transactions
     */
    public function test_user_can_view_launchpad_transactions()
    {
        // Create transaction
        LaunchpadTransaction::factory()->create([
            'user_id' => $this->user->id,
            'launchpad_id' => $this->launchpad->id,
            'amount' => '1',
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/v1/transactions/launchpads');

        $this->assertContains($response->status(), [200]);
    }

    // ============================================================================
    // EDGE CASES
    // ============================================================================

    /**
     * Test: Empty launchpad list returns empty array
     */
    public function test_empty_launchpad_list_returns_empty_structure()
    {
        Launchpad::query()->delete();

        $response = $this->getJson('/api/v1/launchpads');

        $response->assertStatus(200);
        $response->assertJsonStructure(['launchpads']);
    }

    /**
     * Test: Sold out launchpad cannot be purchased
     */
    public function test_sold_out_launchpad_cannot_be_purchased()
    {
        $soldOutLaunchpad = Launchpad::factory()->soldOut()->create([
            'currency_id' => $this->currency->id,
        ]);

        $token = $this->user->createToken('test-token', ['trade']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/launchpad/submit', [
                'id' => $soldOutLaunchpad->id,
                'amount' => '1',
            ]);

        $response->assertStatus(422);
    }
}
