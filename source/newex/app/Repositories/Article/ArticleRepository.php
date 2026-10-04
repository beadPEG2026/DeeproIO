<?php

namespace App\Repositories\Article;

use App\Interfaces\Article\ArticleRepositoryInterface;
use App\Models\Article\Article;
use App\Services\Security\IpCountryResolver;
use App\Services\Referral\ReferralTreeService;

class ArticleRepository implements ArticleRepositoryInterface
{
    /**
     * @var Article
     */
    protected $article;

    /**
     * Resolved once per repository instance so the homepage can build both
     * the announcement list and popup with the same visitor context.
     *
     * @var array|null
     */
    protected $audienceContext;

    /**
     * ArticleRepository constructor.
     */
    public function __construct()
    {
        $this->article = new Article();
    }

    public function get($id, $trashed = false, $dashboard = false, $relations = null)
    {
        $article = Article::whereId($id);

        if ($relations === null) {
            $article->with(['file']);
        } else {
            $article->with($relations);
        }

        if (!$dashboard) {
            $article->active();
            $this->applyPublicVisibility($article);
        }

        if ($trashed) {
            $article->withTrashed();
        }

        return $article->first();
    }

    public function all($paginate, $dashboard = false, $relations = ['file'], $type = false)
    {
        $articles = Article::filter(request()->only(['search', 'trashed']))->orderByLatest();

        if (!$dashboard) {
            $articles->active();
            $this->applyPublicVisibility($articles);
        }

        if ($type) {
            $articles->type($type);
        }

        $articles->with($relations);

        if ($paginate) {
            return $articles->paginate($dashboard ? 10 : 24)->withQueryString();
        }

        return $articles->get();
    }

    public function featured()
    {
        $articles = Article::orderByLatest();

        $articles->active();
        $articles->featured();
        $this->applyPublicVisibility($articles);

        return $articles->get();
    }

    /**
     * Return the newest enabled homepage popup visible to this visitor.
     *
     * Country exclusions are evaluated in PHP after the public audience
     * scope has been applied. This keeps the stored comma-separated country
     * list portable across PostgreSQL, MySQL and SQLite while ensuring that
     * excluded article content is never sent to the browser.
     */
    public function homepagePopup()
    {
        $context = $this->publicAudienceContext();

        $articles = Article::query()
            ->where('homepage_popup_enabled', true)
            ->active()
            ->orderByLatest()
            ->with(['file']);

        $articles->visibleToAudience(
            $context['country_code'],
            $context['referral_root_ids']
        );

        $countryCode = $context['country_code'];

        return $articles->get()->first(function (Article $article) use ($countryCode) {
            return !$this->isPopupCountryExcluded(
                $article->homepage_popup_excluded_countries,
                $countryCode
            );
        });
    }

    public function count()
    {
        $article = Article::query();

        return $article->count();
    }

    public function store($data)
    {
        $data = $this->filterFillableData($data);

        $article = $this->article->create($data);

        return $article->fresh();
    }

    public function update($id, $data)
    {
        $data = $this->filterFillableData($data);

        $article = Article::withTrashed()->find($id);

        if (!$article) {
            return null;
        }

        $article->update($data);

        return $article->fresh();
    }

    public function delete($id)
    {
        $article = Article::find($id);

        if (!$article) {
            return false;
        }

        $article->delete();

        return true;
    }

    public function restore($id)
    {
        $article = Article::withTrashed()->find($id);

        if (!$article) {
            return false;
        }

        $article->restore();

        return true;
    }

    public function getReport($filters = [], $pagination = true)
    {
        $article = Article::query();

        $article->filter($filters)->orderBy('title', 'asc');

        if (!$pagination) {
            return $article->get();
        }

        return $article->paginate(10)->withQueryString();
    }

    protected function filterFillableData(array $data): array
    {
        $fillable = $this->article->getFillable();

        return collect($data)
            ->only($fillable)
            ->toArray();
    }

    /**
     * Apply country and invitation-chain targeting to every public article
     * query. Unknown visitors only receive fully global articles.
     */
    protected function applyPublicVisibility($query): void
    {
        $context = $this->publicAudienceContext();

        $query->visibleToAudience(
            $context['country_code'],
            $context['referral_root_ids']
        );
    }

    /**
     * Resolve the current request's country and invitation-chain ancestors.
     */
    protected function publicAudienceContext(): array
    {
        if ($this->audienceContext !== null) {
            return $this->audienceContext;
        }

        $request = request();
        $user = $request->user() ?: auth('sanctum')->user();

        $this->audienceContext = [
            'country_code' => app(IpCountryResolver::class)->resolveCountryCode(
                $request,
                $user
            ),
            'referral_root_ids' => app(ReferralTreeService::class)->ancestorIds($user),
        ];

        return $this->audienceContext;
    }

    /**
     * Determine whether an article's popup should be hidden for a country.
     * An unknown country is treated conservatively when exclusions exist.
     */
    protected function isPopupCountryExcluded($excludedCountries, ?string $countryCode): bool
    {
        $excludedCountries = $this->normalizePopupCountries($excludedCountries);

        if ($excludedCountries === []) {
            return false;
        }

        if ($countryCode === null || trim($countryCode) === '') {
            return true;
        }

        return in_array(strtoupper(trim($countryCode)), $excludedCountries, true);
    }

    /**
     * Normalize the administrator's comma-separated ISO country list.
     */
    protected function normalizePopupCountries($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $value = trim((string) $value);

            if ($value === '') {
                return [];
            }

            $decoded = json_decode($value, true);

            $parts = is_array($decoded)
                ? $decoded
                : preg_split('/[,\s;；，、]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        }

        return collect($parts ?: [])
            ->map(function ($countryCode) {
                return strtoupper(trim((string) $countryCode));
            })
            ->filter(function ($countryCode) {
                return preg_match('/^[A-Z]{2}$/', $countryCode) === 1;
            })
            ->unique()
            ->values()
            ->all();
    }
}
