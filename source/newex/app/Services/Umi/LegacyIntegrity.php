<?php
namespace App\Services\Umi;

use App\Models\Umi\LegacyAccount;

/** Check both the sealed payload and the columns used by live account queries. */
final class LegacyIntegrity
{
    public function account(LegacyAccount $account): array
    {
        $identity=$account->identity;$profile=$account->profile;$errors=[];
        if(!hash_equals($account->source_hash,hash('sha256',json_encode([$identity,$profile],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION))))$errors[]='source_hash';
        $expected=[
            'legacy_id'=>(int)($identity['id']??0),'legacy_uuid'=>$identity['uuid']??null,
            'parent_legacy_id'=>isset($profile['inviter']['user_id'])?(int)$profile['inviter']['user_id']:null,
            'level'=>$identity['level']??null,'legacy_status'=>(int)($identity['status']??-1),
        ];
        foreach($expected as $field=>$value){
            $actual=$account->$field;
            if(in_array($field,['legacy_id','parent_legacy_id','legacy_status'],true)&&$actual!==null)$actual=(int)$actual;
            if($actual!==$value)$errors[]=$field;
        }
        if((int)($profile['id']??0)!==(int)$account->legacy_id||($profile['uuid']??null)!==$account->legacy_uuid)$errors[]='profile_identity';
        return $errors;
    }
}
