<?php

namespace Tests\Feature\Common;

use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_authentication_can_be_enabled()
    {
        $this->actingAs($user = User::factory()->create());

        $this->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('/user/pending-two-factor-authentication')
            ->assertJson(['pending' => true]);

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_recovery_codes);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());

        $this->post('/user/pending-two-factor-authentication/confirm', [
            'code' => $this->currentPendingTwoFactorCode(),
        ])->assertJson(['confirmed' => true]);

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertCount(8, $user->fresh()->recoveryCodes());
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_two_factor_authentication_is_not_enabled_with_invalid_confirmation_code()
    {
        $this->actingAs($user = User::factory()->create());

        $this->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('/user/pending-two-factor-authentication')
            ->assertJson(['pending' => true]);

        $this->post('/user/pending-two-factor-authentication/confirm', [
            'code' => '000000',
        ])->assertSessionHasErrors('code', null, 'confirmTwoFactorAuthentication');

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_recovery_codes_can_be_regenerated()
    {
        $this->actingAs($user = User::factory()->create());

        $this->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('/user/pending-two-factor-authentication')
            ->assertJson(['pending' => true]);
        $this->post('/user/pending-two-factor-authentication/confirm', [
            'code' => $this->currentPendingTwoFactorCode(),
        ])->assertJson(['confirmed' => true]);
        $this->post('/user/two-factor-recovery-codes');

        $user = $user->fresh();

        $this->post('/user/two-factor-recovery-codes');

        $this->assertCount(8, $user->recoveryCodes());
        $this->assertCount(8, array_diff($user->recoveryCodes(), $user->fresh()->recoveryCodes()));
    }

    public function test_two_factor_authentication_can_be_disabled()
    {
        $this->actingAs($user = User::factory()->create());

        $this->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('/user/pending-two-factor-authentication')
            ->assertJson(['pending' => true]);
        $this->post('/user/pending-two-factor-authentication/confirm', [
            'code' => $this->currentPendingTwoFactorCode(),
        ])->assertJson(['confirmed' => true]);

        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());

        $this->delete('/user/pending-two-factor-authentication');

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    protected function currentPendingTwoFactorCode(): string
    {
        $pending = session('two_factor_pending');

        return app(Google2FA::class)->getCurrentOtp(
            Fortify::currentEncrypter()->decrypt($pending['secret'])
        );
    }
}
