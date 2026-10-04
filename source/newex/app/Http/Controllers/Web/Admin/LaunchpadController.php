<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Launchpad\LaunchpadFormRequest;
use App\Models\Launchpad\Launchpad;
use App\Repositories\Launchpad\LaunchpadRepository;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class LaunchpadController extends Controller
{
    /**
     * @var LaunchpadRepository
     */
    protected $launchpadRepository;

    /**
     * BankAccountController Constructor
     *
     * @param LaunchpadRepository $launchpadRepository
     *
     */
    public function __construct(LaunchpadRepository $launchpadRepository)
    {
        $this->launchpadRepository = $launchpadRepository;
    }


    public function index()
    {
        $launchpadRepositories = $this->launchpadRepository->get();

        return Inertia::render('Admin/Launchpads/Index', [
            'launchpads' => $launchpadRepositories,
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $currencies = (new CurrencyService())->getCurrencies(false, true);

        $networks = [
          ['id' => NETWORK_ETH, 'name' => 'Ethereum'],
          ['id' => NETWORK_BNB, 'name' => 'Binance Smart Chain']
        ];

        return Inertia::render('Admin/Launchpads/Form', [
            'currencies' => $currencies,
            'networks' => $networks
        ]);
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(LaunchpadFormRequest $request)
    {
        \App\Services\Content\ProductPublication::save($request, function ($publication) use ($request) {
        return $this->launchpadRepository->store(array_merge($request->only([
            'name',
            'description',
            'currency_id',
            'network_id',
            'rate',
            'min_buy',
            'max_buy',
            'soft_cap',
            'hard_cap',
            'start_time',
            'end_time',
            'dy_am',
            'kt_sl',
            'status'
        ]), $publication));
        });

        return Redirect::route('admin.launchpads');
    }

    /**
     * Edit resource.
     *
     * @param Launchpad $launchpad
     * @return \Inertia\Response
     */
    public function edit(Launchpad $launchpad)
    {
        $launchpadModel = $this->launchpadRepository->getLaunchpadById($launchpad->id);

        $currencies = (new CurrencyService())->getCurrencies(false, true);

        $networks = [
            ['id' => NETWORK_ETH, 'name' => 'Ethereum'],
            ['id' => NETWORK_BNB, 'name' => 'Binance Smart Chain']
        ];

        return Inertia::render('Admin/Launchpads/Form', [
            'isEdit' => true,
            'launchpad' => $launchpadModel,
            'currencies' => $currencies,
            'networks' => $networks
        ]);
    }

    /**
     * Update resource.
     *
     * @param Launchpad $launchpad
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(LaunchpadFormRequest $request, Launchpad $launchpad)
    {
        \App\Services\Content\ProductPublication::save($request, function ($publication) use ($request, $launchpad) {
        return $this->launchpadRepository->update($launchpad->id, array_merge($request->only([
            'name',
            'description',
            'currency_id',
            'network_id',
            'rate',
            'min_buy',
            'dy_am',
            'kt_sl',
            'max_buy',
            'soft_cap',
            'hard_cap',
            'start_time',
            'end_time',
            'status'
        ]), $publication));
        }, $launchpad);

        return Redirect::route('admin.launchpads');
    }

    /**
     * Destroy resource.
     *
     * @param Launchpad $launchpad
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Launchpad $launchpad)
    {
        $this->launchpadRepository->delete($launchpad->id);

        return Redirect::route('admin.launchpads');
    }
}
