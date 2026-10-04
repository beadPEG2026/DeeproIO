<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Lending\LendingCurrencyFormRequest;
use App\Http\Requests\Web\Lending\LendingFormRequest;
use App\Models\Lending\Lending;
use App\Models\Lending\LendingCurrencies;
use App\Repositories\Lending\LendingRepository;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class LendingController extends Controller
{
    /**
     * @var LendingRepository
     */
    protected $lendingRepository;

    /**
     * LendingController Constructor
     *
     * @param LendingRepository $lendingRepository
     *
     */
    public function __construct(LendingRepository $lendingRepository)
    {
        $this->lendingRepository = $lendingRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $lendings = $this->lendingRepository->get();

        return Inertia::render('Admin/Lendings/Index', [
            'lendings' => $lendings,
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

        return Inertia::render('Admin/Lendings/Form', [
            'currencies' => $currencies,
        ]);
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(LendingFormRequest $request)
    {
        $this->lendingRepository->store($request->only([
            'currency_id',
            'min_amount',
            'max_amount',
            'status',
            'is_flexible',
            'is_weekly',
            'is_monthly',
            'annual_rate_flexible',
            'annual_rate_weekly',
            'annual_rate_monthly'
        ]));

        return Redirect::route('admin.lendings');
    }

    /**
     * Edit resource.
     *
     * @param Lending $lending
     * @return \Inertia\Response
     */
    public function edit(Lending $lending)
    {
        $currencies = (new CurrencyService())->getCurrencies(false, true);

        $lending = $this->lendingRepository->getLendingById($lending->id);

        return Inertia::render('Admin/Lendings/Form', [
            'isEdit' => true,
            'lending' => $lending,
            'currencies' => $currencies,
        ]);
    }

    /**
     * Update resource.
     *
     * @param Lending $lending
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(LendingFormRequest $request, Lending $lending)
    {
        $this->lendingRepository->update($lending->id, $request->only([
            'currency_id',
            'min_amount',
            'max_amount',
            'status',
            'is_flexible',
            'is_weekly',
            'is_monthly',
            'annual_rate_flexible',
            'annual_rate_weekly',
            'annual_rate_monthly'
        ]));

        return Redirect::route('admin.lendings');
    }

    /**
     * Destroy resource.
     *
     * @param Lending $lending
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Lending $lending)
    {
        $this->lendingRepository->delete($lending->id);

        return Redirect::route('admin.lendings');
    }

    public function collaterals(Lending $lending) {

        $currencies = $this->lendingRepository->collaterals($lending->id);

        return Inertia::render('Admin/Lendings/Currencies', [
            'lending' => $lending,
            'currencies' => $currencies,
        ]);
    }

    public function collateralCreate(Lending $lending) {

        $currencies = (new CurrencyService())->getCurrencies(false, true);

        return Inertia::render('Admin/Lendings/CollateralForm', [
            'lending' => $lending,
            'currencies' => $currencies,
        ]);
    }

    /**
     * Edit resource.
     *
     * @param Lending $lending
     * @return \Inertia\Response
     */
    public function collateralEdit(Lending $lending, LendingCurrencies $currency)
    {
        $currencies = (new CurrencyService())->getCurrencies(false, true);

        $currency = $this->lendingRepository->getCollateralById($currency->id);

        return Inertia::render('Admin/Lendings/CollateralForm', [
            'isEdit' => true,
            'lending' => $lending,
            'currency' => $currency,
            'currencies' => $currencies,
        ]);
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function collateralStore(LendingCurrencyFormRequest $request)
    {
        $data = $request->only([
            'lending_id',
            'currency_id',

            'flex_initial_ltv',
            'flex_margin_call',
            'flex_liquidation_ltv',

            'weekly_initial_ltv',
            'weekly_margin_call',
            'weekly_liquidation_ltv',

            'monthly_initial_ltv',
            'monthly_margin_call',
            'monthly_liquidation_ltv',
        ]);

        $this->lendingRepository->storeCollateral($data);

        return Redirect::route('admin.lendings.collaterals', $request->get('lending_id'));
    }

    /**
     * Update resource.
     *
     * @param Lending $lending
     * @return \Illuminate\Http\RedirectResponse
     */
    public function collateralUpdate(LendingCurrencyFormRequest $request, Lending $lending, LendingCurrencies $currency)
    {
        $data = $request->only([
            'lending_id',
            'currency_id',
            'flex_initial_ltv',
            'flex_margin_call',
            'flex_liquidation_ltv',

            'weekly_initial_ltv',
            'weekly_margin_call',
            'weekly_liquidation_ltv',

            'monthly_initial_ltv',
            'monthly_margin_call',
            'monthly_liquidation_ltv',
        ]);


        $this->lendingRepository->collateralUpdate($currency->id, $data);

        return Redirect::route('admin.lendings.collaterals', $lending->id);
    }

    /**
     * Destroy resource.
     *
     * @param LendingCurrencies $currency
     * @return \Illuminate\Http\RedirectResponse
     */
    public function collateralDestroy(LendingCurrencies $currency)
    {
        $this->lendingRepository->collateralDelete($currency->id);

        return Redirect::route('admin.lendings.collaterals', $currency->lending_id);
    }
}
