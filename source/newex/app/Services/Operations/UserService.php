<?php
namespace App\Services\Operations;

use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UserService
{
    public function show(User $user):array {
        Access::user($user);
        $modules=[];
        foreach(['deposit'=>['admin.reports.deposits','Deposits'],'withdrawal'=>['admin.reports.withdrawals','Withdrawals'],'ticket'=>['admin.support.tickets','Support tickets']] as $type=>[$route,$label]) {
            $allowed=Access::route($route);$rows=[];$counts=[];
            if($allowed) {
                $q=$type==='ticket'?DB::table('support_messages'):($type==='deposit'?Records::deposits()->toBase():Records::withdrawals()->toBase());
                $q->where('user_id',$user->id);
                if($type!=='ticket')$q->where('type','coin');
                $counts=(clone $q)->reorder()->select('status')->selectRaw('COUNT(*) as count')->groupBy('status')->get();
                $ref=$type==='ticket'?'ticket_id':$type.'_id';
                $fields=['id',$ref,'status','created_at','updated_at'];if($type==='ticket')$fields[]='title';else{$fields[]='currency_id';$fields[]='amount';}
                $rows=$q->reorder()->orderByDesc('created_at')->limit(10)->get($fields)->map(function($row)use($type,$ref){
                    if(isset($row->currency_id))$row->symbol=DB::table('currencies')->where('id',$row->currency_id)->value('symbol');
                    $row->reference=$row->$ref;$row->url=route('admin.operations.trace',['type'=>$type,'reference'=>$row->$ref]);return $row;
                });
            }
            $modules[]=['type'=>$type,'label'=>$label,'allowed'=>$allowed,'counts'=>$counts,'rows'=>$rows,'url'=>$allowed?route($route,$type==='ticket'?['search'=>(string)$user->id]:['user_id'=>$user->id]):null];
        }
        $orders=auth()->user()->hasRole('superadmin')?DB::table('order_histories')->where('user_id',$user->id)->orderByDesc('created_at')->limit(10)->get(['id','market_id','side','type','status','price','initial_quantity','created_at']):null;
        $umi=auth()->user()->hasRole('superadmin')?DB::table('umi_business_accounts')->where('user_id',$user->id)->get(['id','code','parent_id','fixture']):null;
        $case=DB::table('operations_service_cases')->where('user_id',$user->id)->first();
        $assignee=$case?->assigned_to?User::whereKey($case->assigned_to)->first(['id','name','deleted','deactivated']):null;
        return ['subject'=>['id'=>$user->id,'name'=>$user->name,'deactivated'=>(bool)$user->deactivated,'deleted'=>(bool)$user->deleted,'withdrawal_disabled'=>(bool)$user->withdrawal_disabled,'created_at'=>$user->created_at?->toISOString()],
            'case'=>$case??['revision'=>0,'assigned_to'=>null,'status'=>'open','priority'=>'normal','due_at'=>null],
            'teams'=>Teams::choices($user),'assignee'=>$assignee,'history'=>History::for('service',$user->id),'modules'=>$modules,'orders'=>$orders,
            'exchange_referral'=>['parent_id'=>$user->referral_id,'direct_count'=>User::where('referral_id',$user->id)->where('deleted',false)->count()],
            'umi_referral'=>$umi,'referral_url'=>Access::route('admin.referral-center')?route('admin.referral-center',['user'=>$user->id]):null,
            'profile_url'=>route('admin.users.edit',['user'=>$user->id]),'checked_at'=>now()->toISOString()];
    }
    public function update(User $user,array $data,int $actor):void {
        Access::user($user);
        Teams::validateAssignment($data);
        if(!empty($data['assigned_to'])) {
            $operator=User::find($data['assigned_to']);
            if(!$operator || !Access::canAssign($operator,$user))throw ValidationException::withMessages(['assigned_to'=>__('The operator must already have access to this user. Assignment does not grant permissions.')]);
        }
        DB::transaction(function()use($user,$data,$actor){
            // Lock the existing user row, so two first assignments cannot create competing cases.
            DB::table('users')->where('id',$user->id)->lockForUpdate()->first();
            if(History::replay($data['request_key'],'service',$user->id,$actor))return;
            $before=DB::table('operations_service_cases')->where('user_id',$user->id)->first();
            if((int)($before->revision??0)!==(int)$data['revision'])throw ValidationException::withMessages(['revision'=>__('Record changed. Refresh before saving.')]);
            $fields=['team_id'=>$data['team_id']??null,'assigned_to'=>$data['assigned_to']??null,'status'=>$data['status'],'priority'=>$data['priority'],'due_at'=>$data['due_at']??null,
                'revision'=>($before->revision??0)+1,'updated_at'=>now()];
            if($before)DB::table('operations_service_cases')->where('id',$before->id)->update($fields);
            else DB::table('operations_service_cases')->insert($fields+['user_id'=>$user->id,'created_at'=>now()]);
            History::append('service',$user->id,'service.updated',['before'=>$before?array_intersect_key((array)$before,$fields):null,'after'=>$fields],$actor,$data['reason'],$data['request_key']);
        });
    }
}
