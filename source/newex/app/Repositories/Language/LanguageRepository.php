<?php

namespace App\Repositories\Language;

use App\Models\Language\Language;
use Illuminate\Support\Facades\Storage;

class LanguageRepository
{
    /**
     * @var Language
     */
    protected $language;

    /**
     * LanguageRepository constructor.
     *
     */
    public function __construct()
    {
        $this->language = new Language();
    }

    /**
     * @param $paginate
     * @param false $dashboard
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function get() {

        $language = Language::query();

        return $language
            ->paginate(10)
            ->withQueryString();
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function all($active = true) {

        $language = Language::query();

        if($active) {
            $language->active();
        }

        return $language->pluck('slug', 'name');
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function list($active = true) {

        $language = Language::query();

        if($active) {
            $language->active();
        }

        return $language->pluck('name', 'id');
    }

    public function getById($id) {
        return Language::find($id);
    }

    public function getFirst($id) {
        return Language::where('id', '!=', $id)->first();
    }

    public function getBySlug($slug, $active = false) {
        $language = Language::query();

        $language->whereSlug($slug);

        if($active) {
            $language->active();
        }

        return $language->first();
    }

    public function count() {
        return Language::count();
    }

    public function getByStatus($status, $id) {
        return Language::whereStatus($status)->where('id', '!=', $id)->first();
    }

    public function getByDefault($id = false) {
        return Language::where('is_default', true)->where('id', '!=', $id)->first();
    }

    /**
     * @param $data
     * @return Language
     */
    public function store($data) {

        $language = $this->language->create($data);

        return $language;
    }

    /**
     * @param $id
     * @param $data
     * @return Language
     */
    public function update($id, $data) {

        $language = Language::find($id);

        $language->update($data);

        return $language;
    }

    /**
     * @param $id
     * @return bool
     */
    public function delete($id) {

        $language = Language::find($id);
        $language->delete();

        return true;
    }

    /**
     * @return void
     */
    public function setDefaultLanguage(Language $language) {

        $defaultLanguage = $this->getByDefault($language->id);

        $status = request()->get('is_default');

        if($defaultLanguage && $status) {
            $defaultLanguage->is_default = false;
            $defaultLanguage->update();
        } elseif(!$defaultLanguage && !$status) {
            $firstLanguage = $this->getFirst($language->id);
            $firstLanguage->is_default = true;
            $firstLanguage->update();
        }
    }

    /*
     * Sync language file
     */
    public function sync(Language $language) {

        $translations = (new LanguageTranslationRepository())->all($language);

        if(count($translations) > 0) {
            Storage::disk('language_storage')->put($language->slug . '.json', json_encode($translations, JSON_PRETTY_PRINT));
        }
    }
}
