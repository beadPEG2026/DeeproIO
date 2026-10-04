<?php
namespace App\Services\Operations;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A service directory, never an authorization or referral tree. */
final class Teams {
    public static function listing() {
        return DB::table('operations_service_teams')->orderBy('name')->get()->map(function($r){$r->member_ids=json_decode($r->member_ids,true);return $r;});
    }
    public static function choices(?User $target=null) {
        $actor=auth()->user();return self::listing()->filter(fn($r)=>$r->enabled && ($actor->hasRole('superadmin')||in_array($actor->id,$r->member_ids,true)))->values();
    }
    public static function save(array $data,int $actor):void {
        abort_unless(auth()->user()->hasRole('superadmin'),403);
        $members=array_values(array_unique(array_map('intval',$data['member_ids'])));sort($members);
        foreach($members as $id){$u=User::find($id);if(!$u||!\App\Support\AdminAccess::allows($u)||!$u->hasAnyRole(['superadmin','admin','user_editor','user_leader','salesman','perm_users']))throw ValidationException::withMessages(['member_ids'=>__('Invalid operator.')]);}
        DB::transaction(function()use($data,$members,$actor){
            DB::select('SELECT pg_advisory_xact_lock(?)',[926201001]);
            $prior=DB::table('operations_events')->where('request_key',$data['request_key'])->first();
            if($prior){abort_unless($prior->object_type==='team'&&(int)$prior->actor_id===$actor&&(empty($data['id'])||(string)$prior->object_id===(string)$data['id']),409);return;}
            $before=!empty($data['id'])?DB::table('operations_service_teams')->where('id',$data['id'])->lockForUpdate()->first():null;
            if(!empty($data['id']))abort_unless($before,404);
            if((int)($before->revision??0)!==(int)$data['revision'])throw ValidationException::withMessages(['revision'=>__('Record changed. Refresh before saving.')]);
            if(DB::table('operations_service_teams')->where('name',trim($data['name']))->when($before,fn($q)=>$q->where('id','!=',$before->id))->exists())throw ValidationException::withMessages(['name'=>__('Team name already exists.')]);
            $fields=['name'=>trim($data['name']),'enabled'=>$data['enabled'],'member_ids'=>json_encode($members),'revision'=>($before->revision??0)+1,'updated_at'=>now()];
            $id=$before?->id??DB::table('operations_service_teams')->insertGetId($fields+['created_at'=>now()]);
            if($before)DB::table('operations_service_teams')->where('id',$id)->update($fields);
            History::append('team',$id,'team.updated',['before'=>$before?(array)$before:null,'after'=>$fields],$actor,$data['reason'],$data['request_key']);
        });
    }
    public static function validateAssignment(array $data):void {
        if(empty($data['team_id']))return;
        $team=self::choices()->firstWhere('id',(int)$data['team_id']);
        if(!$team || (!empty($data['assigned_to'])&&!in_array((int)$data['assigned_to'],$team->member_ids,true)))throw ValidationException::withMessages(['team_id'=>__('Choose an enabled service team and a member of that team.')]);
    }
}
