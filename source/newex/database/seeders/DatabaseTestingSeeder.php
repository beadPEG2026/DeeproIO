<?php

namespace Database\Seeders;

use Database\Seeders\Tests\CurrencySeeder;
use Database\Seeders\Tests\MarketSeeder;
use Illuminate\Database\Seeder;
use App\Models\User\User;

class DatabaseTestingSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        User::factory(2)->create();

        $this->call(CurrencySeeder::class);
        $this->call(MarketSeeder::class);
    }
}
