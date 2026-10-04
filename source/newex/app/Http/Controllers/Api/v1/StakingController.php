<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Staking\StakingPurchaseRequest;
use App\Http\Requests\Web\Staking\StakingRedeemRequest;
use App\Http\Resources\Staking\Staking as StakingResource;
use App\Http\Resources\Staking\StakingCollection;
use App\Http\Resources\Staking\StakingUserCollection;
use App\Models\Staking\Staking;
use App\Models\Staking\StakingUser;
use App\Repositories\Staking\StakingRepository;
use App\Repositories\Staking\StakingUserRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * @tags Staking
 */
class StakingController extends Controller
{

    /**
     * List Available Staking Plans
     *
     * Retrieves all available staking plans/products that users can subscribe to.
     *
     * @operationId listStakingPlans
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('sort', description: 'Optional sorting parameter', type: 'string', example: 'apy_desc')]
    public function index(Request $request)
    {
        $sort = $request->get('sort');
        
        $stakings = new StakingCollection((new StakingRepository())->get(false));
        
        return response()->json([
            'stakings' => $stakings,
            'sort' => $sort
        ]);
    }

    /**
     * Get My Staking Positions
     *
     * Retrieves all staking positions for the authenticated user.
     *
     * @operationId getMyStakingPositions
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('sort', description: 'Optional sorting parameter', type: 'string', example: 'created_at_desc')]
    public function my(Request $request)
    {
        $sort = $request->get('sort');

        $stakings = new StakingUserCollection((new StakingUserRepository())->get(false, auth()->user()));

        return response()->json([
            'stakings' => $stakings,
            'sort' => $sort
        ]);
    }

    /**
     * Get Staking Plan Details
     *
     * Retrieves detailed information about a specific staking plan.
     *
     * @operationId getStakingPlanDetails
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('id', description: 'The staking plan ID', required: true, type: 'integer', example: 1)]
    public function show(Request $request)
    {
        $staking = (new StakingRepository())->getStakingById($request->get('id'));

        if(!$staking) {
            return response()->json(['success' => false])->setStatusCode(STATUS_NOT_FOUND);
        }

        return response()->json([
            'staking' => new StakingResource($staking),
        ]);
    }
}
