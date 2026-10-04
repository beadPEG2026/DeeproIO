<?php
namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User\User;
use App\Services\Operations\{Access,Assets,Trace,UserService,Incidents,History,Teams};
use Illuminate\Http\Request;
use Inertia\Inertia;

final class OperationsController extends Controller
{
    public function __construct() {
        $this->middleware(function($r,$next){abort_unless(\App\Support\AdminAccess::allows($r->user()),403);return $next($r);});
    }
    private function page(Request $r,string $name,array $props=[]) {
        $tabs=[];
        foreach(['admin.operations.assets'=>'Asset workbench','admin.operations.trace'=>'Trace history','admin.operations.users'=>'User service overview','admin.operations.incidents'=>'Operations incidents'] as $route=>$label)
            if(Access::route($route))$tabs[]=['label'=>$label,'url'=>route($route)];
        return Inertia::render('Admin/Operations/'.$name,\App\Services\Operations\TimestampPresentation::normalize($props)+['tabs'=>$tabs])->toResponse($r)->header('Cache-Control','private, no-store');
    }
    public function assets(Request $r) {
        Access::requireRoute('admin.deposit-channels');
        $f=$r->validate(['asset'=>'nullable|integer|min:1','page'=>'sometimes|integer|min:1|max:100000']);
        return $this->page($r,'Assets',app(Assets::class)->index($f));
    }
    public function trace(Request $r) {
        $f=$r->validate(['type'=>'nullable|in:'.implode(',',array_keys(Trace::TYPES)),'reference'=>'nullable|string|max:100|regex:/^[A-Za-z0-9_\-]+$/D']);
        $t=app(Trace::class);return $this->page($r,'Trace',['types'=>$t->types(),'filters'=>$f,'results'=>!empty($f['type'])&&!empty($f['reference'])?$t->find($f['type'],$f['reference']):[],'searched'=>!empty($f['reference'])]);
    }
    public function users(Request $r) {
        Access::requireRoute('admin.users');
        $f=$r->validate(['user_id'=>'nullable|integer|min:1']);
        if(!empty($f['user_id'])) { $u=User::findOrFail($f['user_id']);Access::user($u);return redirect()->route('admin.operations.user',['user'=>$u->id]); }
        $queue=\Illuminate\Support\Facades\DB::table('operations_service_cases')->where('status','!=','resolved');
        if(!$r->user()->hasRole('superadmin'))$queue->where('assigned_to',$r->user()->id);
        $rows=$queue->orderBy('due_at')->limit(100)->get(['id','user_id','assigned_to','status','priority','due_at'])->filter(function($case)use($r){
            $u=User::find($case->user_id);return $u && Access::canAssign($r->user(),$u);
        })->values();
        return $this->page($r,'User',['subject'=>null,'userListUrl'=>route('admin.users'),'queue'=>$rows,'teams'=>Teams::choices(),'teamDirectory'=>$r->user()->hasRole('superadmin')?Teams::listing():null]);
    }
    public function user(Request $r,User $user) { return $this->page($r,'User',app(UserService::class)->show($user)); }
    private function mutation(Request $r,array $extra):array {
        abort_if(config('app.readonly'),403);
        $data=$r->validate(array_merge(['request_key'=>'required|uuid','revision'=>'required|integer|min:0','assigned_to'=>'nullable|integer|min:1',
            'due_at'=>'nullable|date','reason'=>'required|string|min:5|max:2000'],$extra));
        $data['reason']=trim($data['reason']);
        if(mb_strlen($data['reason'])<5)throw \Illuminate\Validation\ValidationException::withMessages(['reason'=>__('Please describe the action and its evidence.')]);
        return $data;
    }
    public function updateUser(Request $r,User $user) {
        Access::user($user);
        $data=$this->mutation($r,['team_id'=>'nullable|integer|min:1','status'=>'required|in:open,following,resolved','priority'=>'required|in:normal,high,urgent']);
        app(UserService::class)->update($user,$data,$r->user()->id);return back();
    }
    public function teams(Request $r) {
        abort_unless($r->user()->hasRole('superadmin'),403);
        $data=$this->mutation($r,['id'=>'nullable|integer|min:1','name'=>'required|string|max:80','enabled'=>'required|boolean','member_ids'=>'required|array|min:1|max:100','member_ids.*'=>'integer|min:1']);
        if(trim($data['name'])==='')throw \Illuminate\Validation\ValidationException::withMessages(['name'=>__('Team name is required.')]);
        Teams::save($data,$r->user()->id);return back();
    }
    public function incidents(Request $r) {
        Access::requireRoute('admin.system.monitor');
        $f=$r->validate(['status'=>'nullable|in:open,acknowledged,investigating,resolved','assigned_to'=>'nullable|integer|min:1','overdue'=>'nullable|boolean','page'=>'sometimes|integer|min:1|max:100000']);
        return $this->page($r,'Incidents',app(Incidents::class)->list($f));
    }
    public function incident(Request $r,int $id) {
        Access::requireRoute('admin.system.monitor');
        $row=\Illuminate\Support\Facades\DB::table('operations_incidents')->where('id',$id)->first();abort_unless($row,404);
        $r->merge(['type'=>'incident','reference'=>(string)$id]);return $this->trace($r);
    }
    public function sync(Request $r) {
        Access::requireRoute('admin.system.monitor');abort_if(config('app.readonly'),403);
        app(Incidents::class)->sync();return back();
    }
    public function updateIncident(Request $r,int $id) {
        Access::requireRoute('admin.system.monitor');
        $data=$this->mutation($r,['status'=>'required|in:open,acknowledged,investigating,resolved','due_at'=>'required|date']);
        app(Incidents::class)->update($id,$data,$r->user()->id);return back();
    }
}
