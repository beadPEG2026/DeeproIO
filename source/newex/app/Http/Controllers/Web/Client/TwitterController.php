<?php

namespace App\Http\Controllers\Web\Client;

use App\Mail\Users\AdminUserRegistered;
use App\Models\User\User;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;
use Setting;

class TwitterController extends Controller
{
    /**
     * Redirect to Twitter
     *
     * @return RedirectResponse
     */
    public function redirectToTwitter(): RedirectResponse
    {
        return Socialite::driver('twitter')->redirect();
    }

    /**
     * Handle Twitter authentication callback
     *
     * @return RedirectResponse
     */
    public function handleTwitterCallback(): RedirectResponse
    {
        if(!config('app.twitter_auth_enabled', true)) {
            return redirect(route('login'))->with('error', 'Twitter authentication failed.');
        }

        try
        {
            $user = Socialite::driver('twitter')->user();
        }
        catch (Throwable $e) {
            return redirect(route('login'))->with('error', 'Twitter authentication failed.');
        }

        $existingUser = User::where('email', $user->email)->first();

        if ($existingUser){

            if(!$existingUser->twitter_id) {
                $existingUser->twitter_id = $user->id;
                $existingUser->update();
            }

            Auth::login($existingUser);
        } else {
            // Enforce referral if enabled
            $requireReferral = Setting::get('general.registration_referral_required', false);
            $referral_code = request()->get('referral', null);
            $referrer = null;

            if ($requireReferral) {

                $validationRules = [
                    'referral' => ['required', 'exists:users,referral_code'],
                ];
                Validator::make(['referral' => $referral_code], $validationRules)->validate();

            }

            if ($referral_code) {
                $referrer = app(\App\Services\Referral\ExchangeInvitations::class)->resolve($referral_code, (bool)$requireReferral);
            }

            // Create a new user.
            $newUser = new User();
            $newUser->name = $user->name;
            $newUser->email = $user->email;
            $newUser->email_verified_at = Carbon::now();
            $newUser->twitter_id = $user->id;
            $newUser->referral_code = $this->getUniqueReferralCode();
            $newUser->referral_id = $referrer ? $referrer->id : null;
            $newUser->password = bcrypt(request(Str::random('30')));
            $newUser->save();

            $newUser->assignRole('user');

            // Admin Email Notification
            $adminEmail = Setting::get('notification.admin_email', false);
            $notificationAllowed = Setting::get('notification.new_user_registered', false);

            if($adminEmail && $notificationAllowed) {
                $route = route('admin.users') . "?search=" . $user->email;
                Mail::to($adminEmail)->queue(new AdminUserRegistered($user->email, $route));
            }

            // Log in the new user.
            auth()->login($newUser, true);
        }
        return redirect()->intended('/');
    }

    public function getUniqueReferralCode() {

        $str = generate_string();

        if(User::where('referral_code', $str)->exists()) {
            return $this->getUniqueReferralCode();
        }

        return $str;
    }
}
