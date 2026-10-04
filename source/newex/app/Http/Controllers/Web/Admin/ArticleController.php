<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Article\ArticleFormRequest;
use App\Models\Article\Article;
use App\Models\Article\ArticleAdmin;
use App\Repositories\Language\LanguageRepository;
use App\Services\Article\ArticleService;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class ArticleController extends Controller
{
    /**
     * @var ArticleService
     */
    protected $articleService;

    /**
     * ArticleController Constructor
     *
     * @param ArticleService $articleService
     */
    public function __construct(ArticleService $articleService)
    {
        $this->articleService = $articleService;
    }

    public function index()
    {
        $isJson = request()->get('json');

        $articles = $this->articleService->getArticles(!$isJson, true);

        if ($isJson) {
            return response()->json($articles);
        }

        return Inertia::render('Admin/Articles/Index', [
            'filters' => request()->all(['search', 'trashed']),
            'articles' => $articles,
        ]);
    }

    /**
     * Create new resource.
     *
     * @return \Inertia\Response
     */
    public function create()
    {
        $categories = config('articles.categories');
        $languages = (new LanguageRepository())->list()->toArray();

        return Inertia::render('Admin/Articles/Form', [
            'categories' => $categories,
            'languages' => $languages,
        ]);
    }

    /**
     * Store new resource.
     *
     * @param ArticleFormRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(ArticleFormRequest $request)
    {
        $this->articleService->storeArticle();

        return Redirect::route('admin.articles');
    }

    /**
     * Edit resource.
     *
     * @param ArticleAdmin $article
     * @return \Inertia\Response
     */
    public function edit(ArticleAdmin $article)
    {
        $article = $this->articleService->getArticle($article->id, true, true);

        $categories = config('articles.categories');
        $languages = (new LanguageRepository())->list()->toArray();

        return Inertia::render('Admin/Articles/Form', [
            'isEdit' => true,
            'article' => $article,
            'categories' => $categories,
            'languages' => $languages,
        ]);
    }

    /**
     * Update resource.
     *
     * @param ArticleFormRequest $request
     * @param ArticleAdmin $article
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(ArticleFormRequest $request, ArticleAdmin $article)
    {
        $this->articleService->updateArticle($article->id);

        return Redirect::route('admin.articles');
    }

    /**
     * Destroy resource.
     *
     * @param ArticleAdmin $article
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(ArticleAdmin $article)
    {
        $this->articleService->deleteArticle($article->id);

        return Redirect::route('admin.articles');
    }

    /**
     * Restore resource.
     *
     * @param ArticleAdmin $article
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restore(ArticleAdmin $article)
    {
        $this->articleService->restoreArticle($article->id);

        return Redirect::route('admin.articles');
    }
}