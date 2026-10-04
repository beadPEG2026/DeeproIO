<?php

namespace App\Http\Controllers\Api\v1;

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
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * @tags Launchpad
 */
class LaunchpadController extends Controller
{

    /**
     * List Launchpad Projects
     *
     * Retrieves all launchpad/IEO (Initial Exchange Offering) projects.
     * Status options: all, upcoming, active, ended
     *
     * @operationId listLaunchpadProjects
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[PathParameter('sort', description: 'Filter by status: all, upcoming, active, ended', type: 'string', example: 'active')]
    public function index($sort = "all")
    {
        $launchpads = new LaunchpadCollection((new LaunchpadRepository())->get(false, $sort, true));

        return response()->json([
            'launchpads' => $launchpads,
            'sort' => $sort
        ]);
    }

    /**
     * Get Launchpad Project Details
     *
     * Retrieves detailed information about a specific launchpad project.
     *
     * @operationId getLaunchpadProjectDetails
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('id', description: 'The launchpad project ID', required: true, type: 'integer', example: 1)]
    public function show(Request $request)
    {
        $launchpad = (new LaunchpadRepository())->getLaunchpadById($request->get('id'));

        if(!$launchpad || !$launchpad->status || !$launchpad->isPublished()) {
            return response()->json(['success' => false])->setStatusCode(STATUS_NOT_FOUND);
        }

        return response()->json([
            'launchpad' => new LaunchpadResource($launchpad)
        ]);
    }
}
