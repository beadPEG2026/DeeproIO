<?php

namespace Database\Seeders;

use Database\Seeders\Countries\CountriesSeeder;
use Database\Seeders\Currency\CoinTypeSeeder;
use Database\Seeders\Demo\StakingLaunchpadSeeder;
use Database\Seeders\Language\LanguageSeeder;
use Database\Seeders\Pages\PagesSeeder;
use Database\Seeders\Roles\RolesSeeder;
use Database\Seeders\Settings\DefaultSettingSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call(CoinTypeSeeder::class);
        $this->call(RolesSeeder::class);
        $this->call(CountriesSeeder::class);
        $this->call(LanguageSeeder::class);
        $this->call(DefaultSettingSeeder::class);
        $this->call(PagesSeeder::class);
        $this->call(DerivNetworkSeeder::class);
        //$this->call(PerfectMoneyNetworkSeeder::class);
        $this->call(PayeerNetworkSeeder::class);
        $this->call(StakingLaunchpadSeeder::class);
    }
}
