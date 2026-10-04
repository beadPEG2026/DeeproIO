<?php

namespace App\Modules\P2P\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Modules\P2P\Http\Requests\Web\PeerTrade\PeerPaymentFieldFormRequest;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentField;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;
use App\Modules\P2P\Repositories\PeerTrade\PeerPaymentFieldRepository;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class PeerPaymentFieldController extends Controller
{
    /**
     * @var PeerPaymentFieldRepository
     */
    protected $peerPaymentFieldRepository;

    /**
     * PeerPaymentFieldController Constructor
     *
     * @param PeerPaymentFieldRepository $peerPaymentFieldRepository
     *
     */
    public function __construct(PeerPaymentFieldRepository $peerPaymentFieldRepository)
    {
        $this->peerPaymentFieldRepository = $peerPaymentFieldRepository;
    }


    public function index(PeerPaymentMethod $paymentMethod)
    {
        $peerPaymentFields = $this->peerPaymentFieldRepository->all(true, true, $paymentMethod->id);

        return Inertia::render('Admin/PeerTrades/PaymentFields', [
            'paymentFields' => $peerPaymentFields,
            'paymentMethod' => $paymentMethod
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(PeerPaymentMethod $paymentMethod)
    {
        return Inertia::render('Admin/PeerTrades/PaymentFieldForm', [
            'paymentMethod' => $paymentMethod,
        ]);
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(PeerPaymentFieldFormRequest $request)
    {
        $this->peerPaymentFieldRepository->store($request->only([
            'title',
            'required',
            'payment_method'
        ]));

        return Redirect::route('admin.peerPaymentFields', $request->get('payment_method'));
    }

    /**
     * Edit resource.
     *
     * @param PeerPaymentField $paymentMethod
     * @return \Inertia\Response
     */
    public function edit(PeerPaymentMethod $paymentMethod, PeerPaymentField $paymentField)
    {
        $paymentField = $this->peerPaymentFieldRepository->getPaymentFieldById($paymentField->id, true);

        return Inertia::render('Admin/PeerTrades/PaymentFieldForm', [
            'isEdit' => true,
            'paymentField' => $paymentField,
            'paymentMethod' => $paymentMethod,
        ]);
    }

    /**
     * Update resource.
     *
     * @param PeerPaymentField $paymentMethod
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(PeerPaymentFieldFormRequest $request, PeerPaymentField $paymentField)
    {
        $this->peerPaymentFieldRepository->update($paymentField->id, $request->only([
            'title',
            'required',
            'payment_method'
        ]));

        return Redirect::route('admin.peerPaymentFields', $paymentField->payment_method);
    }

    /**
     * Destroy resource.
     *
     * @param PeerPaymentField $paymentField
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(PeerPaymentField $paymentField)
    {
        $this->peerPaymentFieldRepository->delete($paymentField->id);

        return Redirect::route('admin.peerPaymentFields', $paymentField->payment_method);
    }
}
