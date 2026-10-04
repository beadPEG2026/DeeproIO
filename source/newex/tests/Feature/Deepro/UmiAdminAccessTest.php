<?php
namespace Tests\Feature\Deepro;

use App\Models\User\User;
use App\Support\{UmiAdminAccess,AdminAccess};
use App\Http\Controllers\Web\Admin\UmiV2FundedAdminController;
use Illuminate\Http\Request;
use Tests\TestCase;

final class UmiAdminAccessTest extends TestCase
{
    private function operator(array $roles):User
    {
        $u=\Mockery::mock(User::class)->makePartial();$u->deleted=false;$u->deactivated=false;
        $u->shouldReceive('hasRole')->andReturnUsing(fn($role)=>in_array($role,$roles,true));
        $u->shouldReceive('hasAnyRole')->andReturnUsing(fn($set)=>(bool)array_intersect($set,$roles));return $u;
    }
    public function test_every_action_requires_its_exact_grant():void
    {
        foreach(UmiAdminAccess::ROLES as $role) {
            $u=$this->operator([$role]);self::assertTrue(AdminAccess::allows($u));self::assertTrue(UmiAdminAccess::allows($u,'read'));
            foreach(UmiAdminAccess::ACTIONS as $action=>$grant) {
                if($role==='perm_umi_'.$grant){UmiAdminAccess::authorizeAction($u,$action);$this->addToAssertionCount(1);}
                else {try{UmiAdminAccess::authorizeAction($u,$action);self::fail('Permission escalation: '.$role.' '.$action);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(403,$e->getStatusCode());}}
            }
            self::assertSame($role==='perm_umi_export',UmiAdminAccess::allows($u,'export'));
        }
    }
    public function test_exchange_roles_do_not_grant_umi_access_and_superadmin_retains_all():void
    {
        foreach(['user','admin','finance_manager','salesman','perm_finances'] as $role)self::assertFalse(UmiAdminAccess::allows($this->operator([$role]),'read'));
        self::assertNotContains(false,UmiAdminAccess::capabilities($this->operator(['superadmin'])));
        $u=$this->operator(['superadmin']);$u->deactivated=true;self::assertFalse(UmiAdminAccess::allows($u,'read'));
    }
    public function test_direct_post_denies_before_running_any_financial_operation():void
    {
        foreach(array_keys(UmiAdminAccess::ACTIONS) as $action) {
            $r=Request::create('/exchange-control-panel/umi/operations','POST',['action'=>$action]);$r->setUserResolver(fn()=>$this->operator(['perm_umi_read']));
            try {app(UmiV2FundedAdminController::class)->submit($r);self::fail('Read-only account wrote an action');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(403,$e->getStatusCode());}
        }
    }
    public function test_record_export_has_independent_server_gate():void
    {
        $r=Request::create('/exchange-control-panel/umi/operations/records','GET',['dataset'=>'members','format'=>'csv']);$r->setUserResolver(fn()=>$this->operator(['perm_umi_read']));
        try{app(\App\Http\Controllers\Web\UmiRecordController::class)->admin($r);self::fail('Read-only account exported');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(403,$e->getStatusCode());}
    }
    public function test_umi_only_grants_do_not_inherit_exchange_reports_or_uploads():void
    {
        $allowed=AdminAccess::routes($this->operator(['perm_umi_read']));
        self::assertContains('admin.umi.operations',$allowed);
        foreach($allowed as $name) self::assertTrue(in_array($name,['admin.dashboard','admin.login'],true)||str_starts_with($name,'admin.umi.'),'Unrelated route: '.$name);
        foreach(['file-upload','file-delete'] as $name) {
            $route=app('router')->getRoutes()->getByName($name);
            self::assertContains('role:'.implode('|',AdminAccess::EXCHANGE_ROLES),$route->gatherMiddleware());
        }
    }
    public function test_route_and_assignment_catalog_include_new_grants():void
    {
        foreach(['admin.umi.operations','admin.umi.operations.submit','admin.umi.records','admin.umi.team','admin.umi.member','admin.umi.v2.funded','admin.umi.v2.funded.submit'] as $name) {
            $route=app('router')->getRoutes()->getByName($name);self::assertNotNull($route);self::assertContains('role:'.UmiAdminAccess::roleGate(),$route->gatherMiddleware());
        }
        $m=new \ReflectionMethod(\App\Http\Controllers\Web\Admin\UserController::class,'functionPermissionRoles');
        $roles=$m->invoke(app(\App\Http\Controllers\Web\Admin\UserController::class));foreach(UmiAdminAccess::ROLES as $role)self::assertContains($role,$roles);
    }
}
