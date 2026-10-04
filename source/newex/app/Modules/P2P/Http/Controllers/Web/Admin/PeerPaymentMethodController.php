<?php

namespace App\Modules\P2P\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Modules\P2P\Http\Requests\Web\PeerTrade\PeerPaymentMethodFormRequest;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;
use App\Modules\P2P\Repositories\PeerTrade\PeerPaymentMethodRepository;
use App\Repositories\Currency\CurrencyRepository;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class PeerPaymentMethodController extends Controller
{
    /**
     * @var PeerPaymentMethodRepository
     */
    protected $peerPaymentMethodRepository;

    /**
     * PeerPaymentMethodController Constructor
     *
     * @param PeerPaymentMethodRepository $peerPaymentMethodRepository
     *
     */
    public function __construct(PeerPaymentMethodRepository $peerPaymentMethodRepository)
    {
        $this->peerPaymentMethodRepository = $peerPaymentMethodRepository;
    }


    public function index()
    {
        $peerPaymentMethods = $this->peerPaymentMethodRepository->all(true, true);

        return Inertia::render('Admin/PeerTrades/PaymentMethods', [
            'paymentMethods' => $peerPaymentMethods,
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $currencies = (new CurrencyRepository())->all(false, false, [], 'fiat');

        return Inertia::render('Admin/PeerTrades/PaymentMethodForm',
            [
                'currencies' => $currencies
            ]
        );
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(PeerPaymentMethodFormRequest $request)
    {
        $this->peerPaymentMethodRepository->store($request->only([
            'title',
            'status',
            'currencies',
            'color'
        ]));

        return Redirect::route('admin.peerPaymentMethods');
    }

    /**
     * Edit resource.
     *
     * @param PeerPaymentMethod $paymentMethod
     * @return \Inertia\Response
     */
    public function edit(PeerPaymentMethod $paymentMethod)
    {
        $paymentMethod = $this->peerPaymentMethodRepository->getPaymentMethodById($paymentMethod->id, true, ['currencies']);
        $currencies = (new CurrencyRepository())->all(false, false, [], 'fiat');

        $currenciesIds = $paymentMethod->currencies->pluck('id')->toArray();


        return Inertia::render('Admin/PeerTrades/PaymentMethodForm', [
            'isEdit' => true,
            'paymentMethod' => $paymentMethod,
            'currencies' => $currencies,
            'currenciesIds' => $currenciesIds
        ]);
    }

    /**
     * Update resource.
     *
     * @param PeerPaymentMethod $paymentMethod
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(PeerPaymentMethodFormRequest $request, PeerPaymentMethod $paymentMethod)
    {
        $this->peerPaymentMethodRepository->update($paymentMethod->id, $request->only([
            'title',
            'status',
            'currencies',
            'color'
        ]));

        return Redirect::route('admin.peerPaymentMethods');
    }

    /**
     * Destroy resource.
     *
     * @param PeerPaymentMethod $paymentMethod
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(PeerPaymentMethod $paymentMethod)
    {
        $this->peerPaymentMethodRepository->delete($paymentMethod->id);

        return Redirect::route('admin.peerPaymentMethods');
    }
}
