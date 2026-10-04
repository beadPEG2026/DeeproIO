<?php

namespace Database\Seeders;

use App\Models\Network\Network;
use Illuminate\Database\Seeder;

class DerivNetworkSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $network = new Network();
        $network->id = 11;
        $network->name = "DERIV";
        $network->type = 'fiat';
        $network->slug = 'deriv';
        $network->save();
    }
}
