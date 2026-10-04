<?php

namespace App\Repositories\Voucher;

use App\Models\Voucher\Voucher;
use Auth;

class VoucherRepository
{
    /**
     * @var Voucher
     */
    protected $voucher;

    /**
     * VoucherRepository constructor.
     *
     */
    public function __construct()
    {
        $this->voucher = new Voucher();
    }

    public function get() {

        $vouchers = Voucher::query();

        $vouchers->with('currency');

        $vouchers->has('currency');

        $vouchers->orderBy('id', 'desc');

        return $vouchers->paginate(50)->withQueryString();
    }

    public function all($onlyActive = false) {

        $vouchers = Voucher::query();

        if($onlyActive) {
            $vouchers->active();
        }

        return $vouchers->get();

    }

    public function getVoucherById($id) {
        return Voucher::find($id);
    }

    public function getVoucherByCode($code) {

        $voucher = Voucher::query();

        $voucher->whereCode($code);

        $voucher->active();

        return $voucher->first();
    }

    public function store($data) {

        $voucher = $this->voucher->create($data);

        return $voucher->fresh();
    }

    public function update($id, $data) {

        $voucher = Voucher::find($id);
        $voucher->update($data);
        return $voucher->fresh();
    }

    public function delete($id) {

        $voucher = Voucher::find($id);
        $voucher->delete();

        return true;
    }
}
