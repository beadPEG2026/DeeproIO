<?php
namespace App\Services\Umi;

use App\Models\{Umi\LegacyAccount,User\User};
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Validation\ValidationException;

final class RecoveryWorkflow
{
    public function update(string $id,User $actor,int $version,string $state,string $note,?int $legacyId):void {
        abort_unless($actor->hasRole('superadmin') && !$actor->deactivated,403);
        if(!in_array($state,['in_review','needs_information','resolved','rejected'],true)||mb_strlen(trim($note))<10||mb_strlen($note)>2000)throw ValidationException::withMessages(['recovery'=>__('请选择处理状态并填写核查记录（10–2000 字）。')]);
        DB::transaction(function()use($id,$actor,$version,$state,$note,$legacyId){
            $case=DB::table('umi_recovery_requests')->where('id',$id)->lockForUpdate()->first();abort_unless($case,404);
            abort_unless((int)$case->version===$version,409,__('申请已被更新，请刷新后查看最新处理记录。'));
            if(in_array($case->state,['resolved','rejected'],true))throw ValidationException::withMessages(['recovery'=>__('该申请已结案，历史记录不能改写。')]);
            if($case->assigned_to && (int)$case->assigned_to!==$actor->id)throw ValidationException::withMessages(['recovery'=>__('该申请已由另一位管理员受理。')]);
            $legacyId=$legacyId?:$case->legacy_id;
            $account=$legacyId?LegacyAccount::findOrFail($legacyId):null;
            if($case->legacy_id && $legacyId!=$case->legacy_id)throw ValidationException::withMessages(['recovery'=>__('已经关联的原 UID 不能在处理过程中更换。')]);
            if($state==='resolved' && (!$account || !$account->approved_email || !DB::table('umi_identity_reviews')->where('legacy_id',$legacyId)->where('state','approved')->exists()))throw ValidationException::withMessages(['recovery'=>__('完成原账户邮箱的双人身份复核后才能结案。')]);
            DB::table('umi_recovery_requests')->where('id',$id)->update([
                'state'=>$state,'assigned_to'=>$actor->id,'legacy_id'=>$legacyId,'version'=>$version+1,
                'closed_at'=>in_array($state,['resolved','rejected'],true)?now()->toIso8601String():null,'updated_at'=>now(),
            ]);
            DB::table('umi_recovery_actions')->insert([
                'request_id'=>$id,'actor_id'=>$actor->id,'from_state'=>$case->state,'to_state'=>$state,
                'note'=>Crypt::encryptString(trim($note)),'version'=>$version+1,'created_at'=>now()->toIso8601String(),
            ]);
        });
    }
}
