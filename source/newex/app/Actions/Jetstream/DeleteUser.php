<?php

namespace App\Actions\Jetstream;

use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user.
     *
     * @param  mixed  $user
     * @return void
     */
    public function delete($user)
    {
        if($user->hasRole('admin')) {
            return false;
        }

        $user->tokens->each->delete();

        $user->deleted = true;
        $user->email = md5($user->email) . time() . '@deleteduser.com';
        $user->save();

        return true;
    }
}
