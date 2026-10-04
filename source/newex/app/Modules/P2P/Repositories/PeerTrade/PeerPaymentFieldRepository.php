<?php

namespace App\Modules\P2P\Repositories\PeerTrade;


use App\Modules\P2P\Models\PeerTrade\PeerPaymentField;
use Auth;

class PeerPaymentFieldRepository
{
    /**
     * @var PeerPaymentField
     */
    protected $paymentField;

    /**
     * PeerPaymentFieldRepository constructor.
     *
     */
    public function __construct()
    {
        $this->paymentField = new PeerPaymentField();
    }

    public function getPaymentFieldById($id, $dashboard = false, $relations = null) {

        $paymentField = PeerPaymentField::whereId($id);

        if($relations !== null) {
            $paymentField->with($relations);
        }

        if(!$dashboard) {
            $paymentField->active();
        }

        return $paymentField->first();
    }

    public function all($paginate, $dashboard, $paymentMethod, $asc = false) {

        $paymentFields = PeerPaymentField::filter(request()->only(['search']));

        if(!$asc) {
            $paymentFields->orderByLatest();
        } else {
            $paymentFields->orderByAsc();
        }

        $paymentFields->where('payment_method', $paymentMethod);

        if($paginate) {
            return $paymentFields->paginate(24)->withQueryString();
        } else {
            return $paymentFields->get();
        }
    }

    public function count() {
        $paymentField = PeerPaymentField::query();
        return $paymentField->count();
    }

    public function store($data) {

        $paymentField = $this->paymentField->create($data);

        return $paymentField->fresh();
    }

    public function update($id, $data) {

        $paymentField = PeerPaymentField::find($id);
        $paymentField->update($data);

        return $paymentField->fresh();
    }

    public function delete($id) {

        $paymentField = PeerPaymentField::find($id);
        $paymentField->delete();

        return true;
    }

    public function restore($id) {

        $paymentField = PeerPaymentField::find($id);
        $paymentField->restore();

        return true;
    }

    public function getReport($filters = [], $pagination = true) {

        $paymentField = PeerPaymentField::query();

        $paymentField->filter($filters)->orderBy('title', 'asc');

        if(!$pagination) {
            return $paymentField->get();
        }

        return $paymentField->paginate(150)->withQueryString();
    }
}
