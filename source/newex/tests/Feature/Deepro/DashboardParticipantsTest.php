<?php
namespace Tests\Feature\Deepro;

use App\Models\User\User;
use App\Repositories\{Report\ReportRepository,User\UserRepository};
use Illuminate\Support\Facades\{DB,Event,Http,Mail,Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

final class DashboardParticipantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();Event::fake();Http::preventStrayRequests();Mail::fake();Queue::fake();
        config(['app.readonly'=>false,'cache.default'=>'array','session.driver'=>'array','broadcasting.default'=>'log']);
    }
    protected function tearDown(): void {while(DB::transactionLevel()>0) DB::rollBack();parent::tearDown();}
    public function test_drilldown_uses_activation_date_deduplicates_and_preserves_scope(): void
    {
        $leader=User::factory()->create(['email'=>'participants-leader-'.Str::uuid().'@example.invalid','is_xn'=>false,'created_at'=>'2025-01-01']);$leader->assignRole('admin');
        $make=fn($parent,$virtual=false)=>User::factory()->create(['email'=>'participants-'.Str::uuid().'@example.invalid','referral_id'=>$parent,'is_xn'=>$virtual,'created_at'=>'2025-01-01']);
        $child=$make($leader->id);$outsider=$make(null);$virtual=$make($leader->id,true);$scheduled=$make($leader->id);$outsideDate=$make($leader->id);
        $insert=function($user,$status,$activation){DB::table('futures_contract')->insert(['id'=>(string)Str::uuid(),'user_id'=>$user->id,'status'=>$status,'created_at'=>'2026-09-28 10:00:00','activated_at'=>$activation,'price'=>1,'quantity'=>1,'leverage'=>1,'balance'=>1,'liquidation_price'=>0,'released_amount'=>0,'pnl'=>0,'is_long'=>true,'timeframe_seconds'=>0,'type'=>'market','entry_fee'=>0,'exit_fee'=>0,'fee_rate'=>0,'total_funding_fee_paid'=>0,'close_price'=>0,'trade_margin_amount'=>1,'auto_invest_margin_amount'=>0,'total_margin_amount'=>1]);};
        foreach([[$child,'active'],[$child,'closed'],[$outsider,'closed'],[$virtual,'active'],[$scheduled,'scheduled']] as [$u,$s])$insert($u,$s,'2026-09-29 00:00:00');
        $insert($outsideDate,'closed','2026-09-28 23:59:59');
        $this->actingAs($leader,'web');$period=['2026-09-29','2026-09-29'];
        request()->merge(['period'=>$period,'dashboard_participants'=>1]);
        $ids=(new ReportRepository)->dashboardParticipantIds(null,$period)->pluck('futures_contract.user_id')->all();
        $this->assertSame([$child->id],$ids);
        $rows=(new UserRepository)->get();$this->assertSame(1,$rows->total());$this->assertSame($child->id,$rows->items()[0]->id);
        request()->merge(['dashboard_participants'=>0]);$this->assertSame(0,(new UserRepository)->get()->total());
        Http::assertNothingSent();
    }
}
