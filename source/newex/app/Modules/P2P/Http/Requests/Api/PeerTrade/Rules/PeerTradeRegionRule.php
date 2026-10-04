<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Country\Country;
use App\Models\Currency\Currency;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeRegionRule implements Rule
{
    public $error = 'Invalid region';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $regions)
    {
        if(!$regions || empty($regions)) return true;

        if(is_array($regions) && count($regions) > 0) {
            foreach ($regions as $region) {

                if($region == 0 || $region == '') continue;

                $region = Country::where('id', $region)->first();

                if(!$region) return false;
            }
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __($this->error);
    }
}
