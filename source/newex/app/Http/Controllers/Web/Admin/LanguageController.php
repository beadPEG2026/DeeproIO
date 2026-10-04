<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Language\LanguageDestroyRequest;
use App\Http\Requests\Web\Language\LanguageFormRequest;
use App\Http\Requests\Web\Language\LanguageTranslationFormRequest;
use App\Models\Language\Language;
use App\Models\Language\LanguageTranslation;
use App\Repositories\Language\LanguageRepository;
use App\Repositories\Language\LanguageTranslationRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;

class LanguageController extends Controller
{
    /**
     * @var LanguageRepository
     */
    protected $languageRepository;

    /**
     * LanguageController Constructor
     *
     * @param LanguageRepository $languageRepository
     *
     */
    public function __construct(LanguageRepository $languageRepository)
    {
        $this->languageRepository = $languageRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        $languages = $this->languageRepository->get();

        return Inertia::render('Admin/Languages/Index', [
            'languages' => $languages,
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return Inertia::render('Admin/Languages/Form');
    }

    /**
     * Store new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(LanguageFormRequest $request)
    {
        $language = $this->languageRepository->store($request->only([
            'name',
            'slug',
            'status',
            'is_default',
        ]));

        $this->languageRepository->setDefaultLanguage($language);

        copy(resource_path('/lang/default.json'), resource_path('/lang/'  . $request->get('slug') . '.json'));
        chmod(resource_path('/lang/'  . $request->get('slug') . '.json'),0777);

        File::copyDirectory( resource_path('/lang/default'), resource_path('/lang/'  . $request->get('slug')));

        chmod(resource_path('/lang/'  . $request->get('slug') . '/auth.php'),0777);
        chmod(resource_path('/lang/'  . $request->get('slug') . '/pagination.php'),0777);
        chmod(resource_path('/lang/'  . $request->get('slug') . '/passwords.php'),0777);
        chmod(resource_path('/lang/'  . $request->get('slug') . '/validation.php'),0777);

        //Artisan::queue('language:translation-seeder', [
        //    'language' => $language->id
        //]);

        return Redirect::route('admin.languages');
    }

    /**
     * Edit resource.
     *
     * @param Language $language
     * @return \Inertia\Response
     */
    public function edit(Language $language)
    {
        $language = $this->languageRepository->getById($language->id, false);

        return Inertia::render('Admin/Languages/Form', [
            'isEdit' => true,
            'language' => $language,
        ]);
    }

    /**
     * Update resource.
     *
     * @param Language $language
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(LanguageFormRequest $request, Language $language)
    {
        $this->languageRepository->update($language->id, request()->only('slug', 'name', 'status','is_default'));

        $this->languageRepository->setDefaultLanguage($language);

        return Redirect::route('admin.languages');
    }

    /**
     * Destroy resource.
     *
     * @param Language $language
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(LanguageDestroyRequest $request, Language $language)
    {
        $this->languageRepository->delete($language->id);

        return Redirect::route('admin.languages');
    }

    /**
     * Destroy resource.
     *
     * @param Language $language
     * @return \Inertia\Response
     */
    public function translations(Language $language)
    {
        $languageTranslationRepository = new LanguageTranslationRepository();

        $translations = $languageTranslationRepository->get($language->id);

        return Inertia::render('Admin/Languages/Translations', [
            'filters' => Request::all(['search']),
            'language' => $language,
            'translations' => $translations,
        ]);
    }

    /**
     * Store new translation resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function translationsStore(LanguageTranslationFormRequest $request, Language $language)
    {
        $languageTranslationRepository = new LanguageTranslationRepository();

        $languageTranslationRepository->store($language->id, $request->only([
            'key',
            'content',
        ]));

        return Redirect::route('admin.language.translations', $language->id);
    }

    /**
     * Store new translation resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function translationsUpdate(LanguageTranslationFormRequest $request, Language $language)
    {
        $languageTranslationRepository = new LanguageTranslationRepository();

        $languageTranslationRepository->update($request->get('id'), $request->only([
            'key',
            'content',
        ]));

        return Redirect::route('admin.language.translations', $language->id);
    }

    /**
     * Destroy resource.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function translationsDestroy(Language $language, LanguageTranslation $translation)
    {
        $languageTranslationRepository = new LanguageTranslationRepository();

        $languageTranslationRepository->delete($translation->id);

        $query = [
            'language' => $language->id,
            'page' => request()->get('page'),
            'search' => request()->get('search'),
        ];

        return Redirect::route('admin.language.translations', $query);
    }

    /**
     * Sync language file
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function sync(Language $language)
    {
        $this->languageRepository->sync($language);

        return response()->json(['success' => true]);
    }


}
