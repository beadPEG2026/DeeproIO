<?php

namespace App\Services\Article;

use App\Repositories\Article\ArticleRepository;

class ArticleService
{
    private $articleRepository;

    public function __construct()
    {
        $this->articleRepository = new ArticleRepository();
    }

    public function getArticles($paginate = true, $dashboard = false)
    {
        return $this->articleRepository->all($paginate, $dashboard);
    }

    public function getArticle($id, $trashed, $dashboard = false)
    {
        return $this->articleRepository->get($id, $trashed, $dashboard);
    }

    public function storeArticle()
    {
        return $this->articleRepository->store($this->getArticlePayload());
    }

    public function updateArticle($id)
    {
        return $this->articleRepository->update(
            $id,
            $this->getArticlePayload()
        );
    }

    public function deleteArticle($id)
    {
        return $this->articleRepository->delete($id);
    }

    public function restoreArticle($id)
    {
        return $this->articleRepository->restore($id);
    }

    protected function getArticlePayload(): array
    {
        return request()->only($this->getArticleFields());
    }

    protected function getArticleFields(): array
    {
        return [
            'title',
            'slug',
            'body',
            'status',
            'featured',
            'language',
            'file_id',
            'category_id',
            'visibility_country',
            'visibility_referral_user_id',
            'homepage_popup_enabled',
            'homepage_popup_excluded_countries',

            'title_zh-tw',
            'body_zh-tw',

            'title_ja',
            'body_ja',

            'title_cs',
            'body_cs',

            'title_de',
            'body_de',

            'title_es',
            'body_es',

            'title_fr',
            'body_fr',

            'title_nl',
            'body_nl',

            'title_pt',
            'body_pt',

            'title_ro',
            'body_ro',

            'title_it',
            'body_it',
        ];
    }
}
