<?php

namespace App\Http\Requests\Web\Language\Rules;

use App\Repositories\Language\LanguageTranslationRepository;
use Illuminate\Contracts\Validation\Rule;

class LanguageKeyUniqueRule implements Rule
{
    /**
     * @var LanguageTranslationRepository
     */
    private $languageTranslationRepository;

    public function __construct()
    {
        $this->languageTranslationRepository = new LanguageTranslationRepository();
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  string $uuid
     * @return bool
     */
    public function passes($attribute, $key)
    {
        $translationByKey = $this->languageTranslationRepository->getByKey($key, request()->language, request()->get('id'));

        if($translationByKey) {
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
        return 'Key field must be unique';
    }
}
