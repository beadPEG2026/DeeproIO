<?php

namespace App\Repositories\Network;

use App\Interfaces\Network\NetworkRepositoryInterface;
use App\Models\Network\Network;
use App\Models\Wallet\WalletAddress;

class NetworkRepository implements NetworkRepositoryInterface
{
    /**
     * @var Network
     */
    protected $network;

    /**
     * NetworkRepository constructor.
     *
     */
    public function __construct()
    {
        $this->network = new Network();
    }

    public function get($onlyActive = false, $onlyCrypto = false) {

        $network = Network::query();

        if($onlyActive) {
            $network->active();
        }

        if($onlyCrypto) {
            $network->where('type', 'coin');
        }

        return $network->get();
    }

    public function getById($networkId, $onlyActive = true) {
        $network = Network::query();

        if($onlyActive) {
            $network->active();
        }

        return $network->whereId($networkId)->first();
    }

    public function getIdBySlug($slug) {
        return Network::whereSlug($slug)->first();
    }

    public function update($id, $data) {
        $network = Network::find($id);
        $network->update($data);

        return $network->fresh();
    }

    public function getInternalWallet($address) {
        return WalletAddress::where('address', $address)->first();
    }

}
