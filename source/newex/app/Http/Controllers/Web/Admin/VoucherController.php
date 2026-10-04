<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Voucher\VoucherFormRequest;
use App\Models\Voucher\Voucher;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Voucher\VoucherRepository;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class VoucherController extends Controller
{
    /**
     * @var VoucherRepository
     */
    protected $voucherRepository;

    /**
     * VoucherController Constructor
     *
     * @param VoucherRepository $voucherRepository
     *
     */
    public function __construct(VoucherRepository $voucherRepository)
    {
        $this->voucherRepository = $voucherRepository;
    }


    public function index()
    {
        $vouchers = $this->voucherRepository->get();

        return Inertia::render('Admin/Vouchers/Index', [
            'vouchers' => $vouchers,
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $currencies = (new CurrencyRepository())->all(false);

        return Inertia::render('Admin/Vouchers/Form', [
            'currencies' => $currencies,
        ]);
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(VoucherFormRequest $request)
    {
        $this->voucherRepository->store($request->only([
            'currency_id',
            'code',
            'amount',
            'is_redeemed',
        ]));

        return Redirect::route('admin.vouchers');
    }

    /**
     * Edit resource.
     *
     * @param Voucher $voucher
     * @return \Inertia\Response
     */
    public function edit(Voucher $voucher)
    {
        $currencies = (new CurrencyRepository())->all(false);
        $voucher = $this->voucherRepository->getVoucherById($voucher->id);

        return Inertia::render('Admin/Vouchers/Form', [
            'isEdit' => true,
            'voucher' => $voucher,
            'currencies' => $currencies,
        ]);
    }

    /**
     * Update resource.
     *
     * @param Voucher $voucher
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(VoucherFormRequest $request, Voucher $voucher)
    {
        $this->voucherRepository->update($voucher->id, $request->only([
            'currency_id',
            'code',
            'amount',
            'is_redeemed',
        ]));

        return Redirect::route('admin.vouchers');
    }

    /**
     * Destroy resource.
     *
     * @param Voucher $voucher
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Voucher $voucher)
    {
        $this->voucherRepository->delete($voucher->id);

        return Redirect::route('admin.vouchers');
    }
}
