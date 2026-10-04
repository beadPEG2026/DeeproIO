<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Actions\Fortify\CreateNewUser;
use App\Models\User\User;
use App\Services\User\FreshUserIdentity;
use Illuminate\Support\Facades\{DB, Cache, Mail, Http};

class FreshUserIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['app.readonly'=>false, 'cache.default'=>'array', 'queue.default'=>'sync', 'broadcasting.default'=>'log']);
        Mail::fake(); Http::fake(['*'=>Http::response([],503)]);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    private function register(string $email): User
    {
        Cache::put('register_email_code:'.md5($email), ['code'=>'314159','email'=>$email,'type'=>'email',
            'expires_at'=>now()->addMinutes(5)->toIso8601String(),'purpose'=>'identity_verification','attempts'=>0], 600);
        return app(CreateNewUser::class)->create(['email'=>$email, 'email_code'=>'314159',
            'password'=>'LocalRegression!2026','password_confirmation'=>'LocalRegression!2026','terms'=>true]);
    }

    public function test_registration_skips_orphan_permissions_and_financial_records_after_sequence_reset(): void
    {
        $id = app(FreshUserIdentity::class)->highWaterMark() + 10;
        $role = DB::table('roles')->where('name','superadmin')->value('id');
        DB::table('model_has_roles')->insert(['role_id'=>$role,'model_type'=>User::class,'model_id'=>$id]);
        $order = (array) DB::table('auto_invest_orders')->first();
        unset($order['id']); $order['order_no']=(string) \Illuminate\Support\Str::uuid();
        $order['user_id']=$id; $order['amount']='40868.646527888813579921';
        DB::table('auto_invest_orders')->insert($order);
        DB::select("SELECT setval('users_id_seq', ?, false)", [$id]);
        $user = $this->register('identity-isolation@example.com');
        $this->assertGreaterThan($id, $user->id);
        $this->assertSame(['user'], $user->getRoleNames()->all());
        $this->assertSame(0, DB::table('auto_invest_orders')->where('user_id',$user->id)->count());
        $wallets=DB::table('wallets')->where('user_id',$user->id)->get();
        $this->assertGreaterThan(0,$wallets->count());
        foreach($wallets as $wallet) foreach((array)$wallet as $key=>$value) {
            if(str_starts_with($key,'balance_')) $this->assertSame(0,bccomp($value??'0','0',18));
        }
        $this->actingAs($user)->getJson('/exchange-control-panel')->assertForbidden();
        $this->assertDatabaseHas('model_has_roles',['role_id'=>$role,'model_id'=>$id]);
    }

    public function test_sequence_repair_uses_legacy_references_instead_of_only_users(): void
    {
        $id=app(FreshUserIdentity::class)->highWaterMark()+10;
        DB::table('model_has_roles')->insert(['role_id'=>DB::table('roles')->where('name','user')->value('id'),'model_type'=>User::class,'model_id'=>$id]);
        DB::select("SELECT setval('users_id_seq', 1, false)");
        $this->artisan('deepro:database-sequences',['--apply'=>true,'--json'=>true])->assertExitCode(0);
        $this->assertGreaterThan($id, (int)DB::selectOne("SELECT nextval('users_id_seq') AS id")->id);
    }

    public function test_failed_role_assignment_rolls_back_user_and_wallets(): void
    {
        $before=DB::table('wallets')->count();
        // Leave historical role links intact, but make the public role unavailable.
        DB::table('roles')->where('name','user')->update(['name'=>'user-unavailable-for-test']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        try { $this->register('identity-rollback@example.com'); $this->fail('Missing role accepted'); }
        catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) { $this->assertDatabaseMissing('users',['email'=>'identity-rollback@example.com']); }
        $this->assertSame($before, DB::table('wallets')->count());
    }

    public function test_explicit_creation_cannot_reuse_a_legacy_identity(): void
    {
        $this->expectException(\RuntimeException::class);
        app(FreshUserIdentity::class)->allocate(66);
    }
}
