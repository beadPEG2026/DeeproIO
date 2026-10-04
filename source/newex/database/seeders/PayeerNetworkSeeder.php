<?php

namespace Database\Seeders;

use App\Models\Network\Network;
use Illuminate\Database\Seeder;

class PayeerNetworkSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $network = new Network();
        $network->id = 13;
        $network->name = "Payeer";
        $network->type = 'fiat';
        $network->slug = 'payeer';
        $network->save();
    }
}
