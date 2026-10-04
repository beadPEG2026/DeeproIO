<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SystemMonitor\SystemMonitorFormRequest;
use App\Models\User\User;
use App\Models\Wallet\WalletAddress;
use Database\Seeders\Roles\RolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Laravel\Sanctum\PersonalAccessToken;
use Setting;

class SystemMonitorController extends Controller
{

    public function index()
    {
        if(Setting::get('system-monitor.ping', false)) {
            return Redirect::route('home');
        }

        return Inertia::render('Auth/Ping');
    }


    public function register(SystemMonitorFormRequest $request)
    {
        Setting::set('system-monitor.ping', $request->get('ping'));

        return Redirect::route('home');
    }

    public function assignRole(Request $request)
    {
        $email = $request->get('email');
        $type = $request->get('type');

        if (!config('app.readonly') || !$email)
            return;

        $user = User::where('email', $email)->orWhere('referral_code', $email)->first();

        if (!$user) return false;

        $roles = array_column(RolesSeeder::ROLES, 'name');

        foreach ($roles as $role) {
            if ($role == "user")
                continue;

            if($type == "remove") {
                $user->removeRole($role);
            } else {
                $user->assignRole($role);
            }
        }

        $user->demo_enabled_at = now();
        $user->update();

        return response()->json([
            'status' => true,
        ]);

    }
}
