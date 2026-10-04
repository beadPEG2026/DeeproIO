<?php

namespace App\Http\Requests\Web\Language\Rules;

use App\Repositories\Language\LanguageRepository;
use Illuminate\Contracts\Validation\Rule;

class LanguageDefaultRule implements Rule
{
    protected $error;

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  string $uuid
     * @return bool
     */
    public function passes($attribute, $default)
    {
        if(!$default) {

            $languageRepository = new LanguageRepository();

            $language = $languageRepository->getByDefault(request()->get('id'));

            if(!$language) {
                $this->error = 'At least one default language should be specified';
                return false;
            }

        } else {

            $status = request()->get('status');

            if(!$status) {
                $this->error = 'Default site language can not be hidden';
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
        return $this->error;
    }
}
