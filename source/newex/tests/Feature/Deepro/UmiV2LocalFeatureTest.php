<?php

namespace Tests\Feature\Deepro;

use App\Models\User\User;
use App\Domain\Umi\V2\Decimal;
use App\Services\Umi\V2\LocalEngine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Cache, Event, Http, Schema};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class UmiV2LocalFeatureTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // This adapter explicitly forbids real PostgreSQL wallets. Build its entire
        // sandbox fixture in memory rather than weakening enrollSandbox's guard.
        config(['database.default'=>'umi_local_feature_memory',
            'database.connections.umi_local_feature_memory'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true],
            'app.key'=>'base64:'.base64_encode(str_repeat('l',32)), 'app.readonly'=>false,
            'cache.default'=>'array', 'setting.auto_save'=>false,
            'umi-v2.local_acceptance'=>true, 'umi-v2.funded_enabled'=>false,
            'umi-v2.umi_usd_quote'=>'1', 'umi-v2.share_usd_quote'=>'0.1',
            'umi-v2.timezone'=>'Asia/Shanghai']);
        DB::purge('umi_local_feature_memory');
        Event::fake(); Http::preventStrayRequests(); Cache::flush();
        foreach (['2014_10_12_000000_create_users_table.php'=>'CreateUsersTable',
            '2021_07_21_183330_create_permission_tables.php'=>'CreatePermissionTables',
            '2017_08_24_000000_create_settings_table.php'=>'CreateSettingsTable'] as $file=>$class) {
            require_once database_path('migrations/'.$file); (new $class)->up();
        }
        Schema::table('users', fn(Blueprint $t)=>$t->timestamp('last_seen_at')->nullable());
        foreach (['user','superadmin'] as $role) \Spatie\Permission\Models\Role::create(['name'=>$role,'guard_name'=>'web']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('languages')->insert(['name'=>'English','slug'=>'en','status'=>true,'is_default'=>true]);
        DB::table('settings')->insert(['key'=>'system-monitor.ping','value'=>'1']);
        foreach (['2026_09_30_210000_umi_v2_schema.php',
            '2026_10_01_010000_umi_v2_local_acceptance.php',
            '2026_10_01_020000_umi_v2_chain_settings.php',
            '2026_10_01_030000_umi_v2_dynamic_rank.php'] as $file)
            (require database_path('migrations/deepro/'.$file))->up();
        // Global shared navigation props are outside this sandbox fixture. Keep
        // session, CSRF, authentication, verified-email and all role middleware real.
        $this->withoutMiddleware(\App\Http\Middleware\HandleInertiaRequests::class);
        $this->assertSame('sqlite',DB::getDriverName());
        $this->assertSame(':memory:',DB::connection()->getDatabaseName());
        if (!Route::has('admin.umi.v2')) {
            Route::middleware('web')->group(base_path('routes/admin.php'));
        }
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
        $this->withSession(['_token' => 'umi-v2-local'])
            ->withHeader('X-CSRF-TOKEN', 'umi-v2-local');
    }

    protected function tearDown(): void
    {
        DB::disconnect('umi_local_feature_memory');
        parent::tearDown();
    }

    private function page(string $url)
    {
        return $this->get($url, ['X-Inertia'=>'true']);
    }

    public function test_authenticated_user_and_superadmin_have_separate_local_v2_flows(): void
    {
        $this->get('/umi-ecosystem/v2')->assertRedirect();
        $user = User::factory()->create();
        $user->assignRole('user');
        $this->actingAs($user);
        $this->page('/umi-ecosystem/v2')->assertOk()
            ->assertJsonPath('component','Umi/FundedHome')->assertJsonPath('props.state.mode','funded')
            ->assertJsonPath('props.state.ready',false);
        // The old user URL is a funded alias now; it must not reopen sandbox writes.
        $this->postJson('/umi-ecosystem/v2', [
            'action' => 'enroll', 'request_key' => 'feature-enroll',
        ])->assertStatus(422);
        $this->assertSame(0,DB::table('umi_v2_members')->count());
        $engine = app(LocalEngine::class);
        $member = $engine->enroll($user->id,null,'feature-enroll');
        $this->postJson('/exchange-control-panel/umi/v2', [
            'action' => 'inventory', 'amount' => '100', 'request_key' => 'forbidden-inventory',
        ])->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->actingAs($admin);
        $this->page('/exchange-control-panel/umi/v2')->assertOk()
            ->assertJsonPath('component','Umi/V2Console')->assertJsonPath('props.state.mode','sandbox');
        $this->postJson('/exchange-control-panel/umi/v2', [
            'action' => 'fund', 'member_id' => $member->id, 'amount' => '100',
            'request_key' => 'feature-fund',
        ])->assertOk();
        $this->postJson('/exchange-control-panel/umi/v2', [
            'action' => 'inventory', 'amount' => '1000',
            'request_key' => 'feature-inventory',
        ])->assertOk();
        $this->actingAs($user);
        $this->postJson('/umi-ecosystem/v2/preview', [
            'action' => 'activate', 'amount' => '100',
        ])->assertStatus(422);
        $preview=$engine->preview($user->id,'activate','100');
        $this->assertSame(3,$preview['multiplier']);
        $this->assertSame('300',$preview['cap_umi']);
        $this->postJson('/umi-ecosystem/v2', [
            'action' => 'activate', 'amount' => '100', 'request_key' => 'feature-activate',
        ])->assertStatus(422);
        $this->assertSame('pending_burn',$engine->activate($user->id,'100','feature-activate')->status);
        $this->actingAs($admin);
        $this->postJson('/exchange-control-panel/umi/v2', [
            'action' => 'batch', 'request_key' => 'feature-batch',
        ])->assertOk()->assertJsonPath('result.amount_umi', '100');
        $this->assertTrue(app(LocalEngine::class)->reconcile()['ok']);
        $this->assertSame('active', DB::table('umi_v2_cycles')->where('member_id', $member->id)->value('status'));
    }

    public function test_disabled_mode_does_not_expose_local_business(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $this->actingAs($user);
        config(['umi-v2.local_acceptance' => false]);
        $this->page('/umi-ecosystem/v2')->assertOk()->assertJsonPath('component','Umi/FundedHome')
            ->assertJsonPath('props.state.ready',false)->assertJsonPath('props.state.enrollment_enabled',false);
        $this->postJson('/umi-ecosystem/v2', [
            'action' => 'enroll', 'request_key' => 'disabled-enroll',
        ])->assertStatus(422);
        $admin=User::factory()->create(); $admin->assignRole('superadmin'); $this->actingAs($admin);
        $this->postJson('/exchange-control-panel/umi/v2',['action'=>'inventory','amount'=>'100','request_key'=>'disabled-admin'])->assertStatus(503);
        $this->assertSame(0,DB::table('umi_v2_members')->count());
        $this->assertSame(0,DB::table('umi_v2_sandbox_entries')->count());
        config(['umi-v2.funded_enabled'=>true]);
        $this->get('/exchange-control-panel/umi/v2')->assertRedirect(route('admin.umi.operations'));
    }

    public function test_only_superadmin_can_save_bsc_address_draft(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $this->actingAs($user);
        $input = ['action' => 'chain_settings', 'request_key' => 'feature-settings-1',
            'token_contract' => (string) config('umi.asset.contract'),
            'burn_address' => LocalEngine::BSC_DEAD_ADDRESS,
            'dedicated_address' => null];
        $this->postJson('/exchange-control-panel/umi/v2', $input)->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->actingAs($admin);
        $this->postJson('/exchange-control-panel/umi/v2', $input)->assertOk()
            ->assertJsonPath('state.chain_settings.chain_id', 56)
            ->assertJsonPath('state.chain_settings.configured', false)
            ->assertJsonPath('state.chain_settings.real_transfer_enabled', false);
        $this->assertSame(1, DB::table('umi_v2_chain_settings_audit')->count());
    }

    public function test_v1_v9_grade_difference_uses_paid_static_and_shared_cycle_cap(): void
    {
        $engine = app(LocalEngine::class);
        $users = [];
        $members = [];
        foreach (['A', 'B', 'C'] as $name) {
            $users[$name] = User::factory()->create();
            $sponsor = match ($name) {
                'A' => null,
                'B' => $members['A']->member_code,
                'C' => $members['B']->member_code,
            };
            $members[$name] = $engine->enroll($users[$name]->id, $sponsor, 'grade-enroll-' . $name);
            $engine->fundTestWallet($members[$name]->id, '1000', 'grade-fund-' . $name, $users[$name]->id);
            $engine->activate($users[$name]->id, '1000', 'grade-cycle-' . $name);
        }
        $engine->fundTestRewardInventory('1000', 'grade-reward', $users['A']->id);
        $engine->confirmSandboxBatch('grade-batch', $users['A']->id);
        $engine->setTestLevel($members['A']->id, 3);
        $engine->setTestLevel($members['B']->id, 2);
        $day = now('Asia/Shanghai')->toDateString();
        $summary = $engine->runDay($day, '0.01', 'grade-day', $users['A']->id);

        $this->assertSame(3, $summary['grade_rewards']);
        $this->assertSame('36', $summary['released_umi']);
        $this->assertSame(0, Decimal::cmp('4', (string) DB::table('umi_v2_income_accounts')
            ->where('member_id', $members['A']->id)->value('team_pending')));
        $this->assertSame(0, Decimal::cmp('2', (string) DB::table('umi_v2_income_accounts')
            ->where('member_id', $members['B']->id)->value('team_pending')));
        $this->assertCount(3, DB::table('umi_v2_release_events')->where('kind', 'team')->get());
        $this->assertTrue($engine->reconcile()['ok']);
    }

    public function test_sandbox_exchange_and_chain_inbounds_open_one_pending_cycle_per_source(): void
    {
        $engine = app(LocalEngine::class);
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $exchangeUser = User::factory()->create();
        $chainUser = User::factory()->create();
        $exchangeMember = $engine->enroll($exchangeUser->id, null, 'inbound-exchange-member');
        $chainMember = $engine->enroll($chainUser->id, $exchangeMember->member_code, 'inbound-chain-member');
        $engine->fundTestWallet($exchangeMember->id, '100', 'inbound-wallet', $admin->id);
        // A second new member requires an invitation, so its first activation
        // also needs actual sandbox inventory for the existing direct reward.
        $engine->fundTestRewardInventory('100', 'inbound-rewards', $admin->id);

        $this->actingAs($admin);
        $exchange = ['action' => 'inbound', 'member_id' => $exchangeMember->id,
            'source' => 'exchange', 'source_ref' => 'exchange-event-1', 'amount' => '100'];
        $this->postJson('/exchange-control-panel/umi/v2', $exchange +
            ['request_key' => 'inbound-admin-1'])
            ->assertOk()->assertJsonPath('result.status', 'pending_burn')
            ->assertJsonPath('state.burn.pending_umi', '100');
        $this->postJson('/exchange-control-panel/umi/v2', $exchange +
            ['request_key' => 'inbound-admin-replay'])->assertOk();
        $this->postJson('/exchange-control-panel/umi/v2', array_merge($exchange,
            ['amount' => '101', 'request_key' => 'inbound-admin-conflict']))->assertStatus(422);
        $this->assertSame(1, DB::table('umi_v2_cycles')
            ->where('member_id', $exchangeMember->id)->count());

        $this->postJson('/exchange-control-panel/umi/v2', [
            'action' => 'inbound', 'member_id' => $chainMember->id,
            'source' => 'chain', 'source_ref' => 'chain-event-1',
            'amount' => '100', 'request_key' => 'inbound-chain-admin',
        ])->assertOk()->assertJsonPath('state.burn.pending_umi', '200');
        $this->postJson('/exchange-control-panel/umi/v2', [
            'action' => 'batch', 'request_key' => 'inbound-batch',
        ])->assertOk()->assertJsonPath('result.amount_umi', '200')
            ->assertJsonPath('state.burn.past_24h_simulated_umi', '200');
        $this->assertSame(2, DB::table('umi_v2_cycles')->where('status', 'active')->count());
        $this->assertSame(0,Decimal::cmp('10',(string)DB::table('umi_v2_income_accounts')
            ->where('member_id',$exchangeMember->id)->value('referral_pending')));
        $this->assertTrue($engine->reconcile()['ok']);
    }

    public function test_rolling_24_hour_burn_is_separate_from_cumulative_burn(): void
    {
        $engine = app(LocalEngine::class);
        $user = User::factory()->create();
        $member = $engine->enroll($user->id, null, 'rolling-member');
        $engine->recordSandboxInbound($member->id, 'chain', 'rolling-inbound', '100', $user->id);
        $engine->confirmSandboxBatch('rolling-batch', $user->id);
        $this->assertSame('100', $engine->dashboard($user->id)['burn']['past_24h_simulated_umi']);

        DB::table('umi_v2_burn_lots')->where('member_id', $member->id)
            ->update(['confirmed_at' => now()->subHours(25)->toDateTimeString()]);
        $burn = $engine->dashboard($user->id)['burn'];
        $this->assertSame('100', $burn['simulated_confirmed_umi']);
        $this->assertSame('0', $burn['past_24h_simulated_umi']);
        $this->assertTrue($engine->reconcile()['ok']);
    }

    public function test_all_v1_to_v9_thresholds_settle_from_simulated_two_branch_teams(): void
    {
        $engine = app(LocalEngine::class);
        $thresholds = [500, 3000, 10000, 100000, 300000,
            1000000, 3000000, 5000000, 10000000];
        $leaders = [];
        $rootUser=User::factory()->create();
        $root=$engine->enroll($rootUser->id,null,'matrix-root');
        foreach ($thresholds as $index => $threshold) {
            $leaderUser = User::factory()->create();
            $leader = $engine->enroll($leaderUser->id, $root->member_code, 'matrix-leader-' . $index);
            $leaders[$index + 1] = $leader;
            $engine->fundTestWallet($leader->id, '1000000', 'matrix-fund-' . $index, $leaderUser->id);
            $engine->recordSandboxInbound($leader->id, 'exchange',
                'matrix-leader-' . $index, '1000000', $leaderUser->id);
            foreach (['left', 'right'] as $side) {
                $child = User::factory()->create();
                $member = $engine->enroll($child->id, $leader->member_code,
                    'matrix-' . $index . '-' . $side);
                $engine->recordSandboxInbound($member->id, 'chain',
                    'matrix-' . $index . '-' . $side, (string) $threshold, $child->id);
            }
        }
        $actor = (int) DB::table('users')->where('id', $leaders[1]->user_id)->value('id');
        $engine->fundTestRewardInventory('20000000', 'matrix-inventory', $actor);
        $engine->confirmSandboxBatch('matrix-batch', $actor);

        foreach ($leaders as $level => $leader) {
            $this->assertSame($level, (int) DB::table('umi_v2_members')
                ->where('id', $leader->id)->value('level'));
        }
        $summary = $engine->runDay(now('Asia/Shanghai')->toDateString(),
            '0.01', 'matrix-day', $actor);
        $this->assertSame(18, $summary['grade_rewards']);
        $this->assertSame(0, Decimal::cmp('180000', (string) DB::table('umi_v2_income_accounts')
            ->where('member_id', $leaders[9]->id)->value('team_pending')));
        $this->assertTrue($engine->reconcile()['ok']);
    }
}
