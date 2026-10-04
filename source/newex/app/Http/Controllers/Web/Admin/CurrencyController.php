<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Currency\CurrencyFormRequest;
use App\Models\Currency\Currency;
use App\Models\Currency\CurrencyAdmin;
use App\Models\Market\Market;
use App\Repositories\BankAccount\BankAccountRepository;
use App\Repositories\Network\NetworkRepository;
use App\Services\Currency\CurrencyService;
use App\Services\PaymentGateways\Coin\Coinpayments\Services\CoinpaymentsService;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class CurrencyController extends Controller
{
    /**
     * @var CurrencyService
     */
    protected $currencyService;

    /**
     * CurrencyController Constructor
     *
     * @param CurrencyService $currencyService
     *
     */
    public function __construct(CurrencyService $currencyService)
    {
        $this->currencyService = $currencyService;
    }


    public function index()
    {
        $isJson = request()->get('json');

        $currencies = $this->currencyService->getCurrencies(!$isJson, true);

        $networks = (new NetworkRepository())->get(true);

        if($isJson) {
            return response()->json($currencies);
        }

        return Inertia::render('Admin/Currencies/Index', [
            'networks' => $networks,
            'filters' => request()->all(['search', 'trashed', 'type']),
            'currencies' => $currencies,
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $networks = (new NetworkRepository())->get(true);
        $bankAccounts = (new BankAccountRepository())->all(true);

        $fiats = (new \App\Repositories\Currency\CurrencyRepository())->all(false, true, ['file'], 'fiat');

        return Inertia::render('Admin/Currencies/Form', [
            'networks' => $networks,
            'settings' => Setting::get('currencies'),
            'bankAccounts' => $bankAccounts,
            'fiats' => $fiats,
        ]);
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(CurrencyFormRequest $request)
    {
        $this->currencyService->storeCurrency();

        $this->currencyService->updateCurrenciesInfoCache();

        return Redirect::route('admin.currencies');
    }

    /**
     * Edit resource.
     *
     * @param Currency $currency
     * @return \Inertia\Response
     */
    public function edit(CurrencyAdmin $currency)
    {
        $networks = (new NetworkRepository())->get(true);

        $currency = $this->currencyService->getCurrency($currency->id, true, true);

        $networkIds = $currency->networks->pluck('id')->toArray();

        $bankAccounts = (new BankAccountRepository())->all(true);

        $isMarkets = Market::where('base_currency_id', $currency->id)->orWhere('quote_currency_id', $currency->id)->exists();

        $fiats = (new \App\Repositories\Currency\CurrencyRepository())->all(false, true, ['file'], 'fiat');

        return Inertia::render('Admin/Currencies/Form', [
            'isEdit' => true,
            'isMarkets' => $isMarkets,
            'networks' => $networks,
            'networkIds' => $networkIds,
            'currency' => $currency,
            'bankAccounts' => $bankAccounts,
            'fiats' => $fiats,
        ]);
    }

    /**
     * Update resource.
     *
     * @param Currency $currency
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(CurrencyFormRequest $request, CurrencyAdmin $currency)
    {
        $this->currencyService->updateCurrency($currency->id);

        $this->currencyService->updateCurrenciesInfoCache();

        return Redirect::route('admin.currencies');
    }

    /**
     * Destroy resource.
     *
     * @param Currency $currency
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(CurrencyAdmin $currency)
    {
        $this->currencyService->deleteCurrency($currency->id);

        return Redirect::route('admin.currencies');
    }

    /**
     * Restore resource.
     *
     * @param Currency $currency
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restore(CurrencyAdmin $currency)
    {
        $this->currencyService->restoreCurrency($currency->id);

        return Redirect::route('admin.currencies');
    }

    /**
     * Get Coinpayment coins.
     *
     * @param Currency $currency
     * @return \Illuminate\Http\RedirectResponse
     */
    public function getCoinpaymentsCoins() {

        $coins = (new CoinpaymentsService())->getCoins();

        return response()->json($coins);
    }

    /**
     * Sync Coinpayment coins.
     *
     * @param Currency $currency
     * @return \Illuminate\Http\RedirectResponse
     */
    public function syncCoinpaymentsCoins() {

        (new CoinpaymentsService())->syncCoins();

        return response()->json(['success' => true]);
    }
}
