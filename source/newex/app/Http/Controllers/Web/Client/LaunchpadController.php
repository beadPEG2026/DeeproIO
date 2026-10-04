<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Launchpad\LaunchpadPurchaseRequest;
use App\Http\Resources\Launchpad\Launchpad as LaunchpadResource;
use App\Http\Resources\Launchpad\LaunchpadCollection;
use App\Models\Launchpad\Launchpad;
use App\Models\Launchpad\LaunchpadTransaction;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Launchpad\LaunchpadRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class LaunchpadController extends Controller
{

    public function index($sort = "all")
    {
        $launchpads = new LaunchpadCollection((new LaunchpadRepository())->get(false, $sort, true));

        return Inertia::render('Launchpad/Launchpads', [
            'launchpads' => $launchpads,
            'sort' => $sort
        ]);
    }


    public function show(Launchpad $launchpad)
    {
        $launchpad = (new LaunchpadRepository())->getLaunchpadById($launchpad->id);

        if(!$launchpad || !$launchpad->status || !$launchpad->isPublished()) {
            throw new ModelNotFoundException();
        }

        return Inertia::render('Launchpad/Launchpad', [
            'launchpad' => new LaunchpadResource($launchpad),
        ]);
    }

    public function submit(LaunchpadPurchaseRequest $request) {
        
        try {
            return DB::transaction(function() use ($request) {
                
                $launchpad = Launchpad::where('id', $request->get('id'))->lockForUpdate()->first();
                
                if (!$launchpad) {
                    return response()->json(['success' => false, 'message' => 'Launchpad not found'], 404);
                }

                // Check if launchpad is still purchasable
                if (!$launchpad->status || !$launchpad->purchasable || !$launchpad->isPublished()) {
                    return response()->json(['success' => false, 'message' => 'Launchpad is not available for purchase'], 422);
                }

                $amount = $request->get('amount');

                // Check hard cap - ensure purchase doesn't exceed hard cap
                $newRaisedAmount = math_sum($launchpad->raised_amount, $amount);
                if (math_compare($newRaisedAmount, $launchpad->hard_cap) > 0) {
                    // Calculate remaining amount available
                    $remaining = math_sub($launchpad->hard_cap, $launchpad->raised_amount);
                    return response()->json([
                        'success' => false, 
                        'message' => "Purchase would exceed hard cap. Maximum available: {$remaining}"
                    ], 422);
                }

                $symbol = $launchpad->network_id == NETWORK_BNB ? 'BNB' : 'ETH';

                $user = auth()->user();

                $currency = (new CurrencyRepository())->getCurrencyBySymbol($symbol);
                
                if (!$currency) {
                    return response()->json(['success' => false, 'message' => 'Payment currency not found'], 422);
                }

                $wallet = (new WalletRepository())->getWalletByCurrency($user->id, $currency->id, false);
                
                if (!$wallet) {
                    return response()->json(['success' => false, 'message' => 'Wallet not found'], 422);
                }

                // Check sufficient balance
                if (math_compare($wallet->balance_in_wallet, $amount) < 0) {
                    return response()->json(['success' => false, 'message' => 'Insufficient balance'], 422);
                }

                // Decrease wallet balance
                (new WalletService())->decrease($wallet, $amount, 'wallet');

                // Update raised amount using math_sum for precision
                $launchpad->raised_amount = $newRaisedAmount;
                
                // Auto-close if hard cap reached
                if (math_compare($launchpad->raised_amount, $launchpad->hard_cap) >= 0) {
                    $launchpad->purchasable = false;
                    $launchpad->progress = 'closed';
                }
                
                $launchpad->save();

                // Create transaction record
                $transaction = new LaunchpadTransaction();
                $transaction->user_id = $user->id;
                $transaction->launchpad_id = $launchpad->id;
                $transaction->amount = $amount;
                $transaction->save();

                return response()->json(['success' => true]);
                
            }, 5); // 5 retries on deadlock
            
        } catch (\Throwable $e) {
            Log::error("Launchpad purchase error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Purchase failed. Please try again.'], 500);
        }
    }
}
