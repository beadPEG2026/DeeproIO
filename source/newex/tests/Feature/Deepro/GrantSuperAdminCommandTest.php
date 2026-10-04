<?php

namespace Tests\Feature\Deepro;

use App\Models\User\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\{Artisan, Auth, DB, Event, Hash, Http, Mail, Notification, Password, Queue, Route};
use Illuminate\Support\Str;
use Tests\TestCase;

class GrantSuperAdminCommandTest extends TestCase
{
    private User $actor;
    private string $email;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['app.readonly' => false, 'cache.default' => 'array', 'session.driver' => 'array', 'broadcasting.default' => 'log']);
        DB::beginTransaction();
        Event::fake(); Mail::fake(); Queue::fake(); Notification::fake(); Http::fake(['*' => Http::response([], 503)]);
        if (!Route::has('admin.login')) Route::middleware('web')->group(base_path('routes/admin.php'));
        $this->actor = $this->account('superadmin');
        $this->email = 'grant-'.Str::uuid().'@example.invalid';
        $this->key = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    private function account(string $role = 'user'): User
    {
        $account = User::withoutEvents(fn () => User::factory()->create([
            'email' => 'existing-grant-'.Str::uuid().'@example.invalid',
            'deleted' => false, 'deactivated' => false,
        ]));
        $account->assignRole($role);
        return $account;
    }

    private function apply(array $overrides = []): int
    {
        return Artisan::call('deepro:grant-superadmin', array_replace([
            'email' => $this->email, '--actor' => $this->actor->id,
            '--reason' => 'Explicitly authorized administrator provisioning test',
            '--request-key' => $this->key, '--expect-new' => true, '--apply' => true,
        ], $overrides));
    }

    public function test_preview_is_read_only_and_emits_no_credentials_or_messages(): void
    {
        $users = DB::table('users')->count(); $events = DB::table('operations_events')->count();
        $this->assertSame(0, Artisan::call('deepro:grant-superadmin', ['email' => $this->email]));
        $result = json_decode(Artisan::output(), true);
        $this->assertSame('preview', $result['status']);
        $this->assertNull($result['user_id']);
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($events, DB::table('operations_events')->count());
        Notification::assertNothingSent(); Mail::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function test_new_account_grants_only_superadmin_and_preserves_every_other_account_and_wallet(): void
    {
        $otherUsers = DB::table('users')->orderBy('id')->get()->toJson();
        $roles = DB::table('model_has_roles')->orderBy('model_id')->orderBy('role_id')->get()->toJson();
        $wallets = DB::table('wallets')->orderBy('id')->get()->toJson();
        $this->assertSame(0, $this->apply());
        $created = User::where('email', $this->email)->firstOrFail();
        $this->assertSame(['superadmin'], $created->getRoleNames()->all());
        $this->assertNull($created->email_verified_at);
        $this->assertFalse(Hash::check('password', $created->password));
        $this->assertNull($created->referral_id);
        $this->assertFalse(DB::table('password_resets')->where('email', $this->email)->exists());
        $this->assertSame($otherUsers, DB::table('users')->where('id', '<>', $created->id)->orderBy('id')->get()->toJson());
        $this->assertSame($roles, DB::table('model_has_roles')->where('model_id', '<>', $created->id)->orderBy('model_id')->orderBy('role_id')->get()->toJson());
        $this->assertSame($wallets, DB::table('wallets')->orderBy('id')->get()->toJson());
        $audit = DB::table('operations_events')->where('request_key', $this->key)->first();
        $this->assertSame($this->actor->id, $audit->actor_id);
        $this->assertSame('superadmin.granted', $audit->action);
        $this->assertStringNotContainsString($created->password, $audit->changes.Artisan::output());
        Notification::assertNothingSent(); Mail::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function test_replay_is_idempotent_and_request_key_cannot_be_reused_for_another_identity(): void
    {
        $this->assertSame(0, $this->apply());
        $id = User::where('email', $this->email)->value('id');
        $this->assertSame(0, $this->apply());
        $this->assertSame('already_applied', json_decode(Artisan::output(), true)['status']);
        $this->assertSame(1, DB::table('operations_events')->where('request_key', $this->key)->count());
        $this->assertSame($id, User::where('email', $this->email)->value('id'));
        $this->assertSame(1, $this->apply(['email' => 'other-'.$this->email]));
        $this->assertFalse(User::where('email', 'other-'.$this->email)->exists());
    }

    public function test_existing_account_keeps_password_two_factor_and_other_roles(): void
    {
        $target = $this->account('content_editor');
        $target->forceFill(['two_factor_secret' => encrypt('existing-secret'), 'two_factor_confirmed_at' => now()])->save();
        $before = DB::table('users')->where('id', $target->id)->first();
        $this->assertSame(0, $this->apply(['email' => $target->email, '--expect-new' => false, '--expect-user-id' => $target->id]));
        $this->assertEquals($before, DB::table('users')->where('id', $target->id)->first());
        $this->assertSame(['content_editor', 'superadmin'], $target->fresh()->getRoleNames()->sort()->values()->all());
        $this->assertSame('existing_credentials_preserved', json_decode(Artisan::output(), true)['credential_action']);
    }

    public function test_missing_actor_invalid_role_or_disabled_target_cannot_be_granted(): void
    {
        foreach ([null, $this->account()->id] as $actor) {
            $this->assertSame(1, $this->apply(['--actor' => $actor]));
            $this->assertFalse(User::where('email', $this->email)->exists());
        }
        $target = $this->account();
        $target->forceFill(['deactivated' => true])->save();
        $this->assertSame(1, $this->apply(['email' => $target->email, '--expect-new' => false, '--expect-user-id' => $target->id]));
        $this->assertFalse($target->fresh()->hasRole('superadmin'));
        $this->assertTrue($target->fresh()->deactivated);
    }

    public function test_stale_preview_and_ambiguous_expectations_are_rejected_without_elevation(): void
    {
        $target = $this->account();
        foreach ([['email' => $target->email], ['--expect-new' => false], ['--expect-user-id' => $target->id],
            ['email' => $target->email, '--expect-new' => false, '--expect-user-id' => $target->id + 1]] as $options) {
            $this->assertSame(1, $this->apply($options));
        }
        $this->assertSame(['user'], $target->fresh()->getRoleNames()->all());
        $this->assertFalse(User::where('email', $this->email)->exists());
    }

    public function test_existing_email_password_reset_flow_activates_without_bypassing_token_validation(): void
    {
        $this->assertSame(0, $this->apply());
        $target = User::where('email', $this->email)->firstOrFail();
        $before = $target->password;
        $this->post('/reset-password', ['email' => $this->email, 'token' => 'invalid', 'password' => 'New-test-only-pass42!', 'password_confirmation' => 'New-test-only-pass42!'])
            ->assertSessionHasErrors('email');
        $this->assertSame($before, $target->fresh()->password);
        $this->assertSame(Password::RESET_LINK_SENT, Password::sendResetLink(['email' => $this->email]));
        $token = null;
        Notification::assertSentTo($target, ResetPassword::class, function ($notification) use (&$token) { $token = $notification->token; return true; });
        $this->post('/reset-password', ['email' => $this->email, 'token' => $token, 'password' => 'New-test-only-pass42!', 'password_confirmation' => 'New-test-only-pass42!'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('New-test-only-pass42!', $target->fresh()->password));
        $this->assertFalse(Password::tokenExists($target, $token));
        Auth::forgetGuards();
        $this->post('/login', ['email' => $this->email, 'password' => 'New-test-only-pass42!', 'dashboard' => true])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($target);
        $this->get('/exchange-control-panel')->assertOk();
    }
}
