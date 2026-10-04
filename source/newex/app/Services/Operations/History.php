<?php
namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class History
{
    public static function append(string $type, $id, string $action, array $changes, ?int $actor=null, ?string $reason=null, ?string $key=null): void {
        DB::table('operations_events')->insert(['request_key'=>$key ?? (string)Str::uuid(),'actor_id'=>$actor,
            'object_type'=>$type,'object_id'=>(string)$id,'action'=>$action,'reason'=>$reason,
            'changes'=>json_encode($changes,JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }
    public static function replay(string $key,string $type,$id,int $actor): bool {
        $prior=DB::table('operations_events')->where('request_key',$key)->first();
        if (!$prior) return false;
        abort_unless($prior->object_type===$type && $prior->object_id===(string)$id && (int)$prior->actor_id===$actor,409);
        return true;
    }
    public static function for(string $type,$id,int $limit=50) {
        return DB::table('operations_events')->where('object_type',$type)->where('object_id',(string)$id)
            ->orderByDesc('id')->limit($limit)->get(['id','request_key','actor_id','action','reason','changes','created_at'])
            ->map(function($row){$row->changes=json_decode($row->changes,true);return $row;});
    }
}
