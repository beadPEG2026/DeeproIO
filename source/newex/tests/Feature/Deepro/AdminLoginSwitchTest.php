<?php

namespace Tests\Feature\Deepro;

use App\Models\User\User;
use App\Support\AdminAccess;
use Illuminate\Support\Facades\{Auth, DB, Event, Http, Mail, Queue, Route};
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminLoginSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['app.readonly'=>false, 'cache.default'=>'array', 'session.driver'=>'array', 'broadcasting.default'=>'log']);
        DB::beginTransaction();
        Event::fake(); Mail::fake(); Queue::fake(); Http::fake(['*'=>Http::response([],503)]);
        if (!Route::has('admin.login')) Route::middleware('web')->group(base_path('routes/admin.php'));
        if (!Route::has('admin.peerOrdersAppeals.getChat')) Route::middleware('web')->group(base_path('app/Modules/P2P/Routes/admin.php'));
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    private function account(string $role = 'user'): User
    {
        $user = User::withoutEvents(fn()=>User::factory()->create([
            'email'=>'admin-switch-'.uniqid().'@example.invalid',
            'deleted'=>false, 'deactivated'=>false, 'is_xn'=>false,
        ]));
        $user->assignRole($role);
        return $user;
    }

    public function test_authentication_uses_lean_entry_and_crossing_from_site_forces_fresh_document(): void
    {
        foreach (['/login','/register','/exchange-control-panel/admin-login'] as $path) {
            $this->get($path)->assertOk()->assertSee('/auth/js/auth.js', false)
                ->assertDontSee('/frontend/js/app.js', false)->assertDontSee('/alternative/js/', false);
            $this->get($path, ['X-Inertia'=>'true','X-Inertia-Version'=>'old-site-bundle'])
                ->assertStatus(409)->assertHeader('X-Inertia-Location', url($path));
        }
    }

    public function test_guest_sees_admin_credentials_form(): void
    {
        $this->get('/exchange-control-panel/admin-login')->assertOk()
            ->assertInertia(fn(Assert $p)=>$p->component('Auth/LoginAdmin')->where('currentAccount',null));
    }

    public function test_existing_regular_account_sees_identity_without_losing_its_session(): void
    {
        $user=$this->account();
        $this->actingAs($user)->get('/exchange-control-panel/admin-login')->assertOk()
            ->assertInertia(fn(Assert $p)=>$p->component('Auth/LoginAdmin')->where('currentAccount.email',$user->email));
        $this->assertAuthenticatedAs($user);
        $this->get('/exchange-control-panel')->assertRedirect(route('admin.login'));
        $this->assertSame(['user'],$user->fresh()->getRoleNames()->all());
    }

    public function test_custody_deep_link_guides_guest_and_regular_browser_sessions_to_admin_login(): void
    {
        $this->get('/exchange-control-panel/custody')->assertRedirect(route('admin.login'));
        $regular = $this->account();
        $this->actingAs($regular)->get('/exchange-control-panel/custody', ['X-Inertia'=>'true', 'X-Inertia-Version'=>app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create('/exchange-control-panel/custody'))])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('admin.login'));
        $this->assertAuthenticatedAs($regular);
        $this->getJson('/exchange-control-panel/custody')->assertForbidden();
        $this->post('/exchange-control-panel/custody/permissions', ['user_id'=>$regular->id])->assertForbidden();
        $this->assertSame(['user'], $regular->fresh()->getRoleNames()->all());
        // An administrator lacking this module's role still receives a denial.
        $this->actingAs($this->account('admin'))->get('/exchange-control-panel/custody')->assertForbidden();
    }

    public function test_administrator_entry_redirect_uses_existing_route_permissions(): void
    {
        $this->actingAs($this->account('superadmin'))->get('/exchange-control-panel/admin-login')
            ->assertRedirect(route('admin.dashboard'));
        $gate=collect(Route::getRoutes()->getByName('admin.dashboard')->gatherMiddleware())->first(fn($m)=>str_starts_with($m,'role:'));
        $this->assertSame('role:'.implode('|',AdminAccess::ROLES),$gate);
    }

    public function test_stale_login_submission_does_not_silently_return_to_markets_or_change_user(): void
    {
        $user=$this->account();
        $this->actingAs($user)->post('/login',[
            'dashboard'=>true,'email'=>'another-admin@example.invalid','password'=>'invalid',
        ],['X-Inertia'=>'true'])->assertStatus(409)->assertHeader('X-Inertia-Location',route('admin.login'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_explicit_switch_logs_out_then_allows_fresh_admin_login(): void
    {
        $regular=$this->account(); $admin=$this->account('superadmin');
        $this->actingAs($regular)->withSession(['sentinel'=>'old-session','auth.admin_login'=>true]);
        $this->post('/logout',['admin_login'=>true])->assertRedirect(route('admin.login'))
            ->assertSessionMissing('sentinel')->assertSessionMissing('auth.admin_login');
        $this->assertGuest();
        Auth::forgetGuards();
        $this->post('/login',['email'=>$admin->email,'password'=>'password','dashboard'=>true,'remember'=>'on'])
            ->assertRedirect(route('admin.dashboard'))->assertSessionMissing('auth.admin_login')
            ->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($admin);
        $this->assertNotEmpty($admin->fresh()->remember_token);
    }

    public function test_wrong_credentials_and_regular_user_cannot_gain_admin_access(): void
    {
        $user=$this->account();
        $this->from(route('admin.login'))->post('/login',['email'=>$user->email,'password'=>'invalid','dashboard'=>true])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login',['email'=>$user->email,'password'=>'password','dashboard'=>true])
            ->assertRedirect(route('admin.login'));
        $this->assertAuthenticatedAs($user);
        $this->get('/exchange-control-panel')->assertRedirect(route('admin.login'));
    }

    public function test_normal_login_and_logout_destinations_are_preserved(): void
    {
        $user=$this->account();
        $this->withSession(['auth.admin_login'=>true])->post('/login',['email'=>$user->email,'password'=>'password'])
            ->assertRedirect('/')->assertSessionMissing('auth.admin_login');
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_admin_destination_survives_two_factor_and_failed_challenge(): void
    {
        $admin=$this->account('superadmin');
        $provider=app(\Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider::class);
        $secret=$provider->generateSecretKey();
        $admin->forceFill(['two_factor_secret'=>encrypt($secret),
            'two_factor_recovery_codes'=>encrypt(json_encode(['local-recovery-code'])),
            'two_factor_confirmed_at'=>now()])->save();
        $this->post('/login',['email'=>$admin->email,'password'=>'password','dashboard'=>true,'remember'=>'on'])
            ->assertRedirect(route('two-factor.login'))->assertSessionHas('auth.admin_login',true);
        $this->assertGuest();
        $this->post('/two-factor-challenge',['code'=>'invalid'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/two-factor-challenge',['recovery_code'=>'local-recovery-code'])
            ->assertRedirect(route('admin.dashboard'))->assertSessionMissing('auth.admin_login');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_switch_endpoint_is_post_only_and_never_accepts_external_destination(): void
    {
        $user = $this->account();
        $this->actingAs($user);
        // The site's GET catch-all resolves this path as 404 instead of 405.
        $this->get('/logout?admin_login=1')->assertNotFound();
        $this->assertAuthenticatedAs($user);
        $this->post('/logout',['admin_login'=>true,'redirect'=>'https://example.invalid'])
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }
}
