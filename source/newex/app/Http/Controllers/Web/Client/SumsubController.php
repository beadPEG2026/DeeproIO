<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Models\User\User;
use App\Repositories\Market\MarketRepository;
use App\Services\Market\MarketService;
use App\Services\Sumsub\SumsubClient;
use App\Services\Sumsub\SumsubService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SumsubController extends Controller
{
    public function index($userId = null)
    {
        $user = User::where('referral_code', $userId)->first();

        if(!$user) {
            return response()->json([
                'status' => 'error'
            ])->setStatusCode('422');
        }

        // Security: Verify the authenticated user matches the requested user
        $authUser = auth()->user();
        if (!$authUser || $authUser->id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ])->setStatusCode('403');
        }

        $service = new SumsubService();

        try {

            $res = $service->getToken($userId);
            $token = $res['token'];

        } catch (\Exception $e) {
            Log::error($e);

            return view('sumsub.index', [
                'token' => null,
            ]);
        }

        return view('sumsub.index', [
            'token' => $token,
        ]);
    }
}
