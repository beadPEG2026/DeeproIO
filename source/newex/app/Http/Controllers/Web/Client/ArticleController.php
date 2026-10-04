<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Article\Article as ArticleResource;
use App\Http\Resources\Article\ArticleCollection;
use App\Models\Article\Article;
use App\Repositories\Article\ArticleRepository;
use Dedoc\Scramble\Attributes\ExcludeAllRoutesFromDocs;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;

#[ExcludeAllRoutesFromDocs]
class ArticleController extends Controller
{
    /**
     * 多语言字段映射。
     *
     * 默认英文仍然使用：
     * title
     * body
     */
    protected array $languageMap = [
        // English articles use the default title/body columns.
        'en' => [
            'title' => 'title',
            'body'  => 'body',
        ],
        'zh-tw' => [
            'title' => 'title_zh-tw',
            'body'  => 'body_zh-tw',
        ],
        'ja' => [
            'title' => 'title_ja',
            'body'  => 'body_ja',
        ],
        'cs' => [
            'title' => 'title_cs',
            'body'  => 'body_cs',
        ],
        'de' => [
            'title' => 'title_de',
            'body'  => 'body_de',
        ],
        'es' => [
            'title' => 'title_es',
            'body'  => 'body_es',
        ],
        'fr' => [
            'title' => 'title_fr',
            'body'  => 'body_fr',
        ],
        'nl' => [
            'title' => 'title_nl',
            'body'  => 'body_nl',
        ],
        'pt' => [
            'title' => 'title_pt',
            'body'  => 'body_pt',
        ],
        'ro' => [
            'title' => 'title_ro',
            'body'  => 'body_ro',
        ],
        'it' => [
            'title' => 'title_it',
            'body'  => 'body_it',
        ],
    ];
    public function faq()
    {

        return Inertia::render('Article/Faq',['items'=>array_values(array_filter(app(\App\Services\Content\SitePresentation::class)->get('help')['items'],fn($i)=>$i['visible']))]);
    }
    public function index($sort = "all")
    {
        $articles = (new ArticleRepository())->all(true);

        $articles = $this->applyLocalizedArticles($articles);

        return Inertia::render('Article/Articles', [
            'articles' => new ArticleCollection($articles),
            'sort' => $sort,
        ]);
    }

    public function show(Article $article)
    {
        $article = (new ArticleRepository())->get($article->id);

        if (!$article) {
            throw new ModelNotFoundException();
        }

        $article = $this->applyLocalizedArticle($article);

        return Inertia::render('Article/Article', [
            'article' => new ArticleResource($article),
        ]);
    }

    public function showShort(Article $article)
    {
        $article = (new ArticleRepository())->get($article->id);

        if (!$article) {
            throw new ModelNotFoundException();
        }

        $article = $this->applyLocalizedArticle($article);

        return Inertia::render('Article/ArticleShort', [
            'article' => new ArticleResource($article),
        ]);
    }

    public function featured()
    {
        $articles = (new ArticleRepository())->featured();

        $articles = $this->applyLocalizedArticles($articles);

        return response()->json(new ArticleCollection($articles));
    }

    protected function applyLocalizedArticles($articles)
    {
        /**
         * paginate() 返回的是 paginator，需要改 collection。
         */
        if ($articles instanceof AbstractPaginator) {
            $articles->getCollection()->transform(function ($article) {
                return $this->applyLocalizedArticle($article);
            });

            return $articles;
        }

        /**
         * get() 返回的是 Collection。
         */
        if ($articles instanceof Collection) {
            return $articles->map(function ($article) {
                return $this->applyLocalizedArticle($article);
            });
        }

        return $articles;
    }

    protected function applyLocalizedArticle($article)
    {
        if (!$article) {
            return $article;
        }

        $language = $this->getCurrentLanguage();

        if (!isset($this->languageMap[$language])) {
            return $article;
        }

        $titleField = $this->languageMap[$language]['title'];
        $bodyField = $this->languageMap[$language]['body'];

        $localizedTitle = $article->getAttribute($titleField);
        $localizedBody = $article->getAttribute($bodyField);

        if ($this->hasValue($localizedTitle)) {
            $article->setAttribute('title', $localizedTitle);
        }

        if ($this->hasValue($localizedBody)) {
            $article->setAttribute('body', $localizedBody);
        }

        /**
         * 额外返回当前语言，方便前端排查。
         */
        $article->setAttribute('current_language', $language);

        return $article;
    }

    protected function getCurrentLanguage(): string
    {
        $language = request()->get('lang')
            ?: request()->get('language')
            ?: request()->attributes->get('current_language')
            ?: request()->cookie('user_language')
            ?: request()->cookie('lang')
            ?: request()->cookie('language')
            ?: request()->cookie('locale')
            ?: session('lang')
            ?: session('language')
            ?: session('locale')
            ?: app()->getLocale();

        return $this->normalizeLanguage($language);
    }

    protected function normalizeLanguage($language): string
    {
        $language = strtolower(trim((string) $language));

        $language = str_replace('_', '-', $language);

        /**
         * 兼容浏览器语言：
         * zh_TW / zh-TW / zh-HK 都走繁体。
         */
        if (in_array($language, ['zh', 'zh-tw', 'zh-hk', 'zh-mo', 'tw'], true)) {
            return 'zh-tw';
        }

        /**
         * 兼容 ja-JP、de-DE 这种格式。
         */
        if (str_contains($language, '-')) {
            $parts = explode('-', $language);
            $short = $parts[0] ?? $language;

            if ($short === 'zh') {
                return 'zh-tw';
            }

            return $short;
        }

        return $language;
    }

    protected function hasValue($value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return true;
    }
}
