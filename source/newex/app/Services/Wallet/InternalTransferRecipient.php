<?php
namespace App\Services\Wallet;

use App\Models\User\User;
use Illuminate\Validation\ValidationException;

final class InternalTransferRecipient
{
    public static function uid(int $id): string { return str_pad((string)$id,6,'0',STR_PAD_LEFT); }

    public function resolve(User $sender,string $value,string $type='legacy_code'): User
    {
        $value=trim($value);
        // Old API clients keep their explicit code semantics. Never guess which
        // account a numeric legacy code means when a new client submits a UID.
        if ($type==='uid') {
            if (!preg_match('/^0*[1-9][0-9]{0,17}$/D',$value) || strlen($value)>20) $this->invalid();
            $recipient=User::query()->where('id',ltrim($value,'0'))->first();
        } elseif ($type==='legacy_code') {
            $recipient=User::query()->where('referral_code',$value)->first();
        } else { $this->invalid(); }
        if (!$recipient || $recipient->deleted || $recipient->deactivated || $recipient->is_xn || $recipient->is_xm) $this->invalid();
        if ((int)$recipient->id===(int)$sender->id) throw ValidationException::withMessages(['internal_uid'=>__('You cannot withdraw to yourself')]);
        return $recipient;
    }
    private function invalid(): never
    {
        throw ValidationException::withMessages(['internal_uid'=>__('Recipient user not found')]);
    }
}
