<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Api\v1\EmailVerificationCodeController;
use App\Http\Controllers\Controller;
use App\Models\User\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VerificationCodeController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->hasRole('superadmin'),403);
        return Inertia::render('Admin/VerificationCodes/Index');
    }

    public function query(Request $request)
    {
        abort_unless($request->user()?->hasRole('superadmin'),403);
        $account = trim((string) $request->input('account', ''));

        if ($account === '') {
            return response()->json([
                'success' => false,
                'message' => 'Please enter email or phone number.',
            ], 422);
        }

        $request->validate(['account'=>'required|string|max:255']);
        $result = EmailVerificationCodeController::lookupCachedCode($account);
        $result = array_intersect_key($result, array_flip(['found','type','account','expires_at','created_at','remaining_seconds']));
        $result['status'] = $result['found'] ? 'active' : 'not_found';

        if (!$result['found']) {
            return response()->json([
                'success' => false,
                'message' => 'No valid verification code was found.',
                'data' => [
                    'type' => $result['type'],
                    'account' => $result['account'],
                    'status' => 'not_found',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    protected function findUser(string $rawAccount, ?string $normalizedAccount): ?array
    {
        $rawAccount = trim($rawAccount);
        $normalizedAccount = trim((string) $normalizedAccount);

        $user = User::query()
            ->select(['id', 'name', 'email', 'phone', 'referral_code'])
            ->where(function ($query) use ($rawAccount, $normalizedAccount) {
                $query->where('email', strtolower($rawAccount))
                    ->orWhere('phone', $rawAccount);

                if ($normalizedAccount !== '' && $normalizedAccount !== $rawAccount) {
                    $query->orWhere('phone', $normalizedAccount)
                        ->orWhere('phone', '+' . ltrim($normalizedAccount, '+'));
                }
            })
            ->first();

        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'referral_code' => $user->referral_code,
        ];
    }
}
