<?php

namespace App\Http\Requests\Api\Market\Rules;

use App\Repositories\Market\MarketRepository;
use Illuminate\Contracts\Validation\Rule;

class MarketDataRule implements Rule
{
    public $error = 'Invalid market name';

    /**
     * @var MarketRepository
     */
    private $marketRepository;

    public function __construct()
    {
        $this->marketRepository = new MarketRepository();
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $market)
    {
        // Check if market is active and valid
        if(!$market = $this->marketRepository->get($market, false, false)) {
            return false;
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
        return $this->error;
    }
}
