<?php

namespace App\Modules\P2P\Repositories\PeerTrade;

use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;
use Auth;

class PeerPaymentMethodRepository
{
    /**
     * @var PeerPaymentMethod
     */
    protected $paymentMethod;

    /**
     * PeerPaymentMethodRepository constructor.
     *
     */
    public function __construct()
    {
        $this->paymentMethod = new PeerPaymentMethod();
    }

    public function getPaymentMethodById($id, $dashboard = false, $relations = null) {

        $paymentMethod = PeerPaymentMethod::whereId($id);

        if($relations !== null) {
            $paymentMethod->with($relations);
        }

        if(!$dashboard) {
            $paymentMethod->active();
        }

        return $paymentMethod->first();
    }

    public function all($paginate, $dashboard = false, $relations = [], $type = false, $currency = false) {

        $paymentMethods = PeerPaymentMethod::filter(request()->only(['search']))->orderByLatest();

        if(!$dashboard) {
            $paymentMethods->active();
        }

        if($type) {
            $paymentMethods->type($type);
        }

        if($currency) {
            $paymentMethods->whereHas('currencies',function($query) use($currency){
                $query->where("symbol", $currency);
            });
        }

        $paymentMethods->with($relations);

        if($paginate) {
            return $paymentMethods->paginate(24)->withQueryString();
        } else {
            return $paymentMethods->get();
        }
    }

    public function count() {
        $paymentMethod = PeerPaymentMethod::query();
        return $paymentMethod->count();
    }

    public function store($data) {

        $paymentMethod = $this->paymentMethod->create($data);
        $paymentMethod->currencies()->sync($data['currencies']);

        return $paymentMethod->fresh();
    }

    public function update($id, $data) {

        $paymentMethod = PeerPaymentMethod::find($id);
        $paymentMethod->update($data);
        $paymentMethod->currencies()->sync($data['currencies']);

        return $paymentMethod->fresh();
    }

    public function delete($id) {

        $paymentMethod = PeerPaymentMethod::find($id);
        $paymentMethod->delete();

        return true;
    }

    public function restore($id) {

        $paymentMethod = PeerPaymentMethod::find($id);
        $paymentMethod->restore();

        return true;
    }

    public function getReport($filters = [], $pagination = true) {

        $paymentMethod = PeerPaymentMethod::query();

        $paymentMethod->filter($filters)->orderBy('title', 'asc');

        if(!$pagination) {
            return $paymentMethod->get();
        }

        return $paymentMethod->paginate(150)->withQueryString();
    }
}
