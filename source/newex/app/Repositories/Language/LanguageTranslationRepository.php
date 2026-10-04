<?php

namespace App\Repositories\Language;

use App\Models\Language\Language;
use App\Models\Language\LanguageTranslation;

class LanguageTranslationRepository
{
    /**
     * @var LanguageTranslation
     */
    protected $languageTranslation;

    /**
     * LanguageTranslationRepository constructor.
     *
     */
    public function __construct()
    {
        $this->languageTranslation = new LanguageTranslation();
    }

    /**
     * @param $paginate
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function get($language_id) {

        $languageTranslation = LanguageTranslation::query();

        $languageTranslation->filter(request()->only(['search']))->orderByAsc();

        $languageTranslation->where('language_id', $language_id);

        return $languageTranslation
            ->paginate(100)
            ->withQueryString();
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function all(Language $language) {

        $languageTranslation = LanguageTranslation::query();

        $languageTranslation->where('language_id', $language->id);

        return $languageTranslation->pluck('content', 'key');
    }

    public function getById($id) {
        return LanguageTranslation::find($id);
    }

    public function getByKey($key, $language, $id) {
        $translation = LanguageTranslation::query();

        $translation
            ->where('key', $key)
            ->where('language_id', $language->id)
            ->where('id', '!=', $id);

        return $translation->first();
    }

    /**
     * @param $data
     * @return LanguageTranslation
     */
    public function store($id, $data) {

        $data['language_id'] = $id;

        $this->languageTranslation->create($data);

        return $this->languageTranslation->fresh();
    }

    /**
     * @param $id
     * @param $data
     * @return LanguageTranslation
     */
    public function update($id, $data) {

        $languageTranslation = LanguageTranslation::find($id);

        if($languageTranslation) {
            $languageTranslation->update($data);
        }

        return $languageTranslation;
    }

    /**
     * @param $id
     * @return bool
     */
    public function delete($id) {

        $languageTranslation = LanguageTranslation::find($id);
        $languageTranslation->delete();

        return true;
    }
}
