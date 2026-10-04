<?php
namespace App\Services\Referral;
use App\Models\User\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ExchangeInvitations
{
    public function resolve(mixed $code, bool $required = false): ?User
    {
        Validator::make(['referral'=>$code], ['referral'=>[$required?'required':'nullable','string','max:64']])->validate();
        $code = trim((string)$code);
        if ($code === '') return null;
        $user = User::where('referral_code',$code)->where('deleted',false)->where('deactivated',false)->first();
        if (!$user) throw ValidationException::withMessages(['referral'=>__('交易所邀请码无效或邀请人已停用。')]);
        return $user;
    }
}
