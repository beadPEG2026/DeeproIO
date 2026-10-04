<?php

namespace App\Http\Controllers\Fortify;

use App\Actions\Fortify\ConfirmTwoFactorAuthentication;
use App\Actions\Fortify\DisableTwoFactorAuthentication;
use App\Actions\Fortify\EnableTwoFactorAuthentication;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PendingTwoFactorAuthenticationController extends Controller
{
    public function store(Request $request, EnableTwoFactorAuthentication $enable)
    {
        $enable($request->user());

        return response()->json([
            'pending' => $request->session()->has('two_factor_pending'),
        ]);
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm)
    {
        $confirm($request->user(), $request->input('code'));

        return response()->json([
            'confirmed' => $request->user()->fresh()->hasEnabledTwoFactorAuthentication(),
        ]);
    }

    public function destroy(Request $request, DisableTwoFactorAuthentication $disable)
    {
        $disable($request->user());

        return response()->json([
            'disabled' => true,
        ]);
    }
}
