<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Country\CountryCollection;
use App\Mail\Users\AdminUserRegistered;
use App\Models\User\User;
use App\Repositories\Country\CountryRepository;
use App\Services\Settings\SettingsService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Agent;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Setting;
use Web3\Utils;
use Agustind\EthSignature;

class SettingController extends Controller
{

    #[ExcludeRouteFromDocs]
    public function time()
    {
        return response()->json(['time' => Carbon::now()]);
    }

    #[ExcludeRouteFromDocs]
    public function siteStatus() {
        return response()->json(['maintenance' => Setting::get('general.maintenance_status', false)]);
    }

    #[ExcludeRouteFromDocs]
    public function auth(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('The provided credentials are incorrect.')],
            ]);
        }

        // 2FA Auth
        if($user->two_factor_secret) {
            $request->validate([
                'twofa' => 'required',
            ]);

            $google2fa = app(Google2FA::class);

            $valid = $google2fa->verifyKey(decrypt($user->two_factor_secret), (string)$request->get('twofa'), 1);

            if(!$valid) {
                throw ValidationException::withMessages([
                    'twofa' => [__('2FA Code is invalid or expired.')],
                ]);
            }
        }

        return response()->json([
            'status' => true,
            'user' => [
                'verified' => (bool)$user->kyc_verified_at,
                'id' => $user->referral_code,
                'hash' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'registered_date' => Carbon::parse($user->created_at)->format('Y-m-d'),
                'referral_code' => $user->referral_code,
                'email_verified' => Carbon::parse($user->email_verified_at)->format('Y-m-d'),
                'kyc_verified' => Carbon::parse($user->kyc_verified_at)->format('Y-m-d'),

            ],
            'token' => $user->createToken($request->device_name)->plainTextToken
        ]);
    }

    #[ExcludeRouteFromDocs]
    public function user(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'registered_date' => Carbon::parse($user->created_at)->format('Y-m-d'),
            'referral_code' => $user->referral_code,
            'verified' => (bool)$user,
            'id' => $user->referral_code,
            'hash' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'email_verified' => Carbon::parse($user->email_verified_at)->format('Y-m-d'),
            'kyc_verified' => Carbon::parse($user->kyc_verified_at)->format('Y-m-d'),
        ]);
    }

    #[ExcludeRouteFromDocs]
    public function languageFile(Request $request) {

        $slug = $request->get('lang', app()->getLocale());

        $file = resource_path('/lang/' . $slug . '.json');

        if(file_exists($file)) {
            return response()->json(json_decode(file_get_contents($file)));
        }

        $default = resource_path('/lang/en.json');

        return response()->json(json_decode(file_get_contents($default)));
    }

    #[ExcludeRouteFromDocs]
    public function countries() {
        $countries = new CountryCollection((new CountryRepository())->get());
        return response()->json($countries);
    }

    #[ExcludeRouteFromDocs]
    public function checkAuth(Request $request) {

        try {



        $token = $request->get('token');

        if(!$token) {
            return response()->json(['success' => false]);
        }

        $settingService = new SettingsService();

        $decoded = base64_decode($token);

        if(!$decoded) {
            return response()->json(['success' => false, 'expired' => false]);
        }

        $parsedToken = explode('---', $decoded);

        if(!isset($parsedToken[0]) || !isset($parsedToken[1])) {
            return response()->json(['success' => false, 'expired' => false]);
        }

        $checkToken = $settingService->getQrCodeTokenCache($parsedToken[0]);

        if(!$checkToken) {
            return response()->json(['success' => false, 'expired' => true]);
        }

        $settingService->setQrCodeTokenCache($parsedToken[1], $parsedToken[0]);

        return response()->json(['success' => true, 'expired' => false]);

        } catch (\Exception $e) {
            return response()->json(['success' => true, 'expired' => false]);
        }
    }

    #[ExcludeRouteFromDocs]
    public function getQrCodeToken(Request $request) {

        $agent = new Agent();
        $browser = $agent->browser();
        $version = $agent->version($browser);
        $platform = $agent->platform();

        $userAgent = $browser . ' V' . $version . ' (' . $platform . ')';

        $prevToken = $request->get('prevtoken', null);
        $newToken = $prevToken;
        $isLogged = false;

        $settingService = new SettingsService();

        if(!$prevToken) {
            $newToken = $settingService->setQrCodeTokenCache('guest', null, $request->ip(), $userAgent);
        }

        $prevTokenCache = $settingService->getQrCodeTokenCache($prevToken);

        if(!$prevTokenCache) {

            $newToken = $settingService->setQrCodeTokenCache('guest', null, $request->ip(), $userAgent);

        } elseif($prevTokenCache !== "guest") {

            $model = PersonalAccessToken::findToken($prevTokenCache);

            if($model) {
                $user = User::where('id', $model->tokenable_id)->first();

                if($user) {

                    Auth::login($user, true);
                    $isLogged = true;
                }
            }
        }

        return response()->json(['token' => $newToken, 'isLogged' => $isLogged]);
    }

    #[ExcludeRouteFromDocs]
    public function walletconnect(Request $request) {

        $address = strtolower($request->input('address'));
        $signature = $request->input('signature');
        $message = $request->input('message');

        if (!$this->isValidSignature($message, $signature, $address)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $existingUser = User::where('wallet_id', $address)->first();

        if ($existingUser) {
            auth()->login($existingUser, true);
        } else {

            $requireReferral = Setting::get('general.registration_referral_required', false);
            $referral_code = request()->get('referral', null);
            $referrer = null;

            if ($requireReferral) {

                $validationRules = [
                    'referral' => ['required', 'exists:users,referral_code'],
                ];

                $validator = Validator::make(['referral' => $referral_code], $validationRules);

                if($validator->fails()) {
                    return redirect(route('register', ['ref' => 'required']));
                }

            }

            if ($referral_code) {
                $referrer = app(\App\Services\Referral\ExchangeInvitations::class)->resolve($referral_code, (bool)$requireReferral);
            }

            $refCode = $this->getUniqueReferralCode();
            $email = $refCode . "@temp-email.loc";

            // Create a new user.
            $newUser = new User();
            $newUser->name = $refCode;
            $newUser->email = $email;
            $newUser->email_verified_at = Carbon::now();
            $newUser->wallet_id = $address;
            $newUser->referral_code = $refCode;
            $newUser->referral_id = $referrer ? $referrer->id : null;
            $newUser->password = bcrypt(request(Str::random('30')));
            $newUser->save();

            $newUser->assignRole('user');

            // Admin Email Notification
            $adminEmail = Setting::get('notification.admin_email', false);
            $notificationAllowed = Setting::get('notification.new_user_registered', false);

            if($adminEmail && $notificationAllowed) {
                $route = route('admin.users') . "?search=" . $email;
                Mail::to($adminEmail)->queue(new AdminUserRegistered($email, $route));
            }

            // Log in the new user.
            auth()->login($newUser, true);
        }

        return response()->json(['success' => true]);
    }

    protected function isValidSignature($message, $signature, $address)
    {
        return (new Ethsignature())->verify($message, $signature, $address);
    }

    protected function getUniqueReferralCode() {

        $str = generate_string();

        if(User::where('referral_code', $str)->exists()) {
            return $this->getUniqueReferralCode();
        }

        return $str;
    }
}
