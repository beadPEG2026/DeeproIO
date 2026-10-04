<?php

namespace App\Http\Requests\Web\Language\Rules;

use App\Repositories\Language\LanguageRepository;
use Illuminate\Contracts\Validation\Rule;

class LanguageStatusRule implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  string $uuid
     * @return bool
     */
    public function passes($attribute, $status)
    {
        if(!$status) {

            $languageRepository = new LanguageRepository();

            $language = $languageRepository->getByStatus(true, request()->get('id'));

            if(!$language) {
                return false;
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
        return 'At least one active language should be specified';
    }
}
