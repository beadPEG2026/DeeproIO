<?php
namespace Tests\Feature\Deepro;

use App\Models\User\User;
use Illuminate\Support\Facades\{DB,Event,Http,Mail,Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminIpLocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        DB::beginTransaction(); Event::fake(); Mail::fake(); Queue::fake(); Http::preventStrayRequests();
        config(['app.readonly'=>false,'cache.default'=>'array','session.driver'=>'array','broadcasting.default'=>'log']);
        $migration=require base_path('database/migrations/2026_09_29_160000_add_user_ip_location.php');
        $migration->up(); $migration->up();
    }
    protected function tearDown(): void
    {
        while (DB::transactionLevel()>0) DB::rollBack();
        parent::tearDown();
    }
    public function test_location_filter_has_a_column_and_keeps_team_scope(): void
    {
        $leader=User::factory()->create(['email'=>'location-admin-'.Str::uuid().'@example.invalid']);
        $leader->assignRole('admin');
        $child=User::factory()->create(['email'=>'location-child-'.Str::uuid().'@example.invalid','referral_id'=>$leader->id,'ip_location'=>'上海']);
        $outsider=User::factory()->create(['email'=>'location-other-'.Str::uuid().'@example.invalid','ip_location'=>'上海']);
        // ip_location is admin-maintained metadata, deliberately not mass assignable.
        DB::table('users')->whereIn('id',[$child->id,$outsider->id])->update(['ip_location'=>'上海']);
        $this->actingAs($leader,'web'); request()->merge(['ip_location'=>'上海']);
        $rows=(new \App\Repositories\User\UserRepository)->get();
        $this->assertTrue(collect($rows->items())->contains('id',$child->id));
        $this->assertFalse(collect($rows->items())->contains('id',$outsider->id));
        request()->merge(['ip_location'=>'不存在的地区']);
        $this->assertSame(0,(new \App\Repositories\User\UserRepository)->get()->total());
        Http::assertNothingSent();
    }
}
