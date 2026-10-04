<?php

namespace Database\Seeders;

use App\Models\Network\Network;
use Illuminate\Database\Seeder;

class PerfectMoneyNetworkSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $network = new Network();
        $network->id = 12;
        $network->name = "Perfect Money";
        $network->type = 'fiat';
        $network->slug = 'perfectmoney';
        $network->save();
    }
}
