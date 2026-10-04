<?php

namespace Database\Seeders\Currency;

use App\Models\Network\Network;
use Illuminate\Database\Seeder;

class CoinTypeSeeder extends Seeder
{
    const ALLOWED_COIN_TYPES = [
        'internal' => [
            'id' => 0,
            'type' => 'coin',
            'name' => 'Internal'
        ],
        'eth' => [
            'id' => 2,
            'type' => 'coin',
            'name' => 'ETH'
        ],
        'erc20' => [
            'id' => 3,
            'type' => 'coin',
            'name' => 'ERC-20'
        ],
        'bank_credit_card' => [
            'id' => 4,
            'type' => 'fiat',
            'name' => 'Bank Transfer & Credit Card'
        ],
        'bnb' => [
            'id' => 5,
            'type' => 'coin',
            'name' => 'BNB'
        ],
        'bep20' => [
            'id' => 6,
            'type' => 'coin',
            'name' => 'BEP-20'
        ],
        'trx' => [
            'id' => 7,
            'type' => 'coin',
            'name' => 'TRX'
        ],
        'trc20' => [
            'id' => 8,
            'type' => 'coin',
            'name' => 'TRC-20'
        ],
        'btc' => [
            'id' => 9,
            'type' => 'coin',
            'name' => 'BTC'
        ],
        'matic' => [
            'id' => 15,
            'type' => 'coin',
            'name' => 'Polygon'
        ],
        'matic20' => [
            'id' => 16,
            'type' => 'coin',
            'name' => 'MATIC-20'
        ],
        /*'brc20' => [
            'id' => 17,
            'type' => 'coin',
            'name' => 'BRC-20'
        ],*/
        'sol' => [
            'id' => 20,
            'type' => 'coin',
            'name' => 'Solana'
        ],
        'solspl' => [
            'id' => 21,
            'type' => 'coin',
            'name' => 'Solana SPL'
        ],
        'xrp' => [
            'id' => 22,
            'type' => 'coin',
            'name' => 'XRP Ledger'
        ],
        'ton' => [
            'id' => 23,
            'type' => 'coin',
            'name' => 'TON Network'
        ],
    ];

    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        foreach (self::ALLOWED_COIN_TYPES as $slug=>$data) {

            if(Network::whereSlug($slug)->first()) continue;

            $network = new Network();
            $network->id = $data['id'];
            $network->name = $data['name'];
            $network->type = $data['type'];
            $network->slug = $slug;
            $network->save();
        }
    }
}
