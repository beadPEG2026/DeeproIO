<?php

namespace Tests\Feature\Deepro;

use App\Http\Controllers\Api\v1\EmailVerificationCodeController;
use App\Models\User\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\{Auth, Cache, DB, Event, Http, Mail, Notification, Queue, Route};
use Tests\TestCase;

final class EmailOnlyAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['app.readonly'=>false, 'cache.default'=>'array', 'session.driver'=>'array', 'broadcasting.default'=>'log']);
        DB::beginTransaction();
        Event::fake(); Mail::fake(); Queue::fake(); Notification::fake(); Http::fake(['*'=>Http::response([],503)]);
        if (!Route::has('admin.login')) Route::middleware('web')->group(base_path('routes/admin.php'));
        if (!Route::has('admin.peerOrdersAppeals.getChat')) Route::middleware('web')->group(base_path('app/Modules/P2P/Routes/admin.php'));
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    private function account(?string $identifier = null, string $role = 'user'): User
    {
        $user = User::withoutEvents(fn()=>User::factory()->create([
            'email'=>$identifier ?? 'email-only-'.uniqid().'@example.com',
            'deleted'=>false, 'deactivated'=>false, 'is_xn'=>false,
        ]));
        $user->assignRole($role);
        return $user;
    }

    public function test_email_login_still_normalizes_case_and_whitespace_and_remembers_account(): void
    {
        $user = $this->account();
        $this->post('/login', ['email'=>'  '.strtoupper($user->email).'  ', 'password'=>'password', 'remember'=>'on'])
            ->assertRedirect('/')->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty($user->fresh()->remember_token);
    }

    public function test_login_preserves_an_intended_wallet_action(): void
    {
        $user = $this->account();
        $this->withSession(['url.intended'=>'/wallets/deposit/crypto/USDT'])
            ->post('/login', ['email'=>$user->email,'password'=>'password'])
            ->assertRedirect('/wallets/deposit/crypto/USDT');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_an_external_intended_destination(): void
    {
        $user = $this->account();
        $this->withSession(['url.intended'=>'https://example.net/untrusted'])
            ->post('/login', ['email'=>$user->email,'password'=>'password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_phone_account_cannot_log_in_through_email_or_phone_fields(): void
    {
        $phone = '+85212345678'; $user = $this->account($phone);
        foreach ([['email'=>$phone], ['phone'=>$phone], ['email'=>'+852 1234-5678'], ['email'=>['invalid']]] as $identifier) {
            $this->postJson('/login', $identifier+['password'=>'password'])->assertStatus(422)->assertJsonValidationErrors('email');
            $this->assertGuest();
        }
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_phone_account_cannot_bypass_public_email_requirement_with_admin_flag(): void
    {
        $user = $this->account('+85222345678');
        $this->postJson('/login', ['email'=>$user->email,'password'=>'password','dashboard'=>true])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_separate_legacy_administrator_login_is_preserved(): void
    {
        $admin = $this->account('+85232345678', 'superadmin');
        $this->post('/login', ['email'=>$admin->email,'password'=>'password','dashboard'=>true])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_email_login_preserves_two_factor_challenge_and_recovery(): void
    {
        $user = $this->account();
        $provider = app(\Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider::class);
        $user->forceFill(['two_factor_secret'=>encrypt($provider->generateSecretKey()),
            'two_factor_recovery_codes'=>encrypt(json_encode(['email-only-local-recovery'])),
            'two_factor_confirmed_at'=>now()])->save();
        $this->post('/login',['email'=>$user->email,'password'=>'password'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->post('/two-factor-challenge',['code'=>'invalid'])->assertSessionHasErrors('code');
        $this->post('/two-factor-challenge',['recovery_code'=>'email-only-local-recovery'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_phone_and_still_requires_email_code(): void
    {
        $base = ['password'=>'LocalEmailOnly!2026','password_confirmation'=>'LocalEmailOnly!2026','terms'=>true];
        foreach ([['email'=>'+85242345678'], ['phone'=>'+85242345678'] ] as $identity) {
            $this->postJson('/register', $base+$identity+['email_code'=>'314159'])
                ->assertStatus(422)->assertJsonValidationErrors('email');
        }
        $email = 'email-only-registration-'.uniqid().'@example.com';
        $this->postJson('/register', $base+['email'=>$email,'email_code'=>'314159'])
            ->assertStatus(422)->assertJsonValidationErrors('email_code');
        $this->assertDatabaseMissing('users',['email'=>$email]);
        Cache::put('register_email_code:'.md5($email), ['code'=>'314159','type'=>'email','email'=>$email,
            'expires_at'=>now()->addMinutes(5)->toIso8601String(),'purpose'=>'identity_verification','attempts'=>0],600);
        $this->postJson('/register', $base+['email'=>$email,'email_code'=>'314159'])->assertStatus(201);
        $user = User::where('email',$email)->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(['user'], $user->getRoleNames()->all());
        $this->assertFalse(EmailVerificationCodeController::verifyEmailCode($email,'314159'));
    }

    public function test_guest_phone_code_requests_are_rejected_without_sending_sms(): void
    {
        foreach ([['type'=>'phone','phone'=>'+85252345678'], ['phone'=>'+85252345678']] as $payload) {
            $this->postJson('/api/v1/register/email/code/send',$payload)->assertStatus(422);
        }
        Http::assertNothingSent(); Mail::assertNothingSent();
        $this->assertNull(Cache::get('register_sms_code:'.md5('85252345678')));
    }

    public function test_authenticated_kyc_can_still_reach_sms_verification(): void
    {
        $this->partialMock(EmailVerificationCodeController::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('sendSmsCode')->once()
                ->with('+85262345678')->andReturn(response()->json(['type'=>'phone']));
        });
        $this->actingAs($this->account(),'web')->postJson('/api/v1/register/email/code/send',[
            'type'=>'phone','phone'=>'+85262345678',
        ])->assertOk()->assertJsonPath('type','phone');
        Http::assertNothingSent();
    }

    public function test_existing_api_password_login_also_rejects_phone(): void
    {
        $user = $this->account('+85272345678');
        $this->postJson('/api/v1/auth/token', ['email'=>$user->email,'password'=>'password','device_name'=>'local-regression'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(0,$user->tokens()->count());
    }

    public function test_existing_email_password_reset_still_completes(): void
    {
        $user = $this->account();
        $this->post('/forgot-password',['email'=>$user->email])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password',['token'=>$notification->token,'email'=>$user->email,
                'password'=>'LocalEmailOnly!2026','password_confirmation'=>'LocalEmailOnly!2026'])->assertSessionHasNoErrors();
            return true;
        });
        $this->post('/login',['email'=>$user->email,'password'=>'LocalEmailOnly!2026'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }
}
