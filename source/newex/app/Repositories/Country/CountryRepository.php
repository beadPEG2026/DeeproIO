<?php

namespace App\Repositories\Country;

use App\Models\Country\Country;

class CountryRepository
{
    public function get() {
        return Country::all();
    }

}
