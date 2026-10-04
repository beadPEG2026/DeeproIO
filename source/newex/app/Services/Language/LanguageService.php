<?php

namespace App\Services\Language;

use App\Repositories\Language\LanguageRepository;
use App\Repositories\Language\LanguageTranslationRepository;
use Illuminate\Support\Facades\Cookie;

class LanguageService {

    private $languageRepository, $languageTranslationRepository;

    public function __construct()
    {
        $this->languageRepository = new LanguageRepository();
        $this->languageTranslationRepository = new LanguageTranslationRepository();
    }

    public function getTranslations($slug = false) {

        if(!$slug) {
            $defaultLanguage = $this->languageRepository->getByDefault();
            $slug = $defaultLanguage->slug;
        }

        $language = $this->languageRepository->getBySlug($slug);

        return $this->languageTranslationRepository->all($language);
    }

    public function getLanguages() {
        return $this->languageRepository->all();
    }

    public function setLanguage($slug, $user) {

        $language = $this->languageRepository->getBySlug($slug, true);

        if(!$language) return;

        if($user) {
            $user->language_id = $language->id;
            $user->update();
        }

        // Also persist the explicit choice after logout and in a new session.
        return cookie('user_language', $slug, 60 * 24 * 365);
    }
}
