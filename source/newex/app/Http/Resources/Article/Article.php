<?php

namespace App\Http\Resources\Article;

use Illuminate\Http\Resources\Json\JsonResource;

class Article extends JsonResource
{
    public static $wrap = null;

    /**
     * Article translations are stored alongside the default title/body.
     * Keeping the selection in the resource makes every public consumer
     * (including the homepage popup) follow the locale selected by the
     * LanguageDetector middleware.
     */
    protected const LANGUAGE_MAP = [
        // English articles use the default title/body columns.
        'en' => [
            'title' => 'title',
            'body' => 'body',
        ],
        'zh-tw' => [
            'title' => 'title_zh-tw',
            'body' => 'body_zh-tw',
        ],
        'ja' => [
            'title' => 'title_ja',
            'body' => 'body_ja',
        ],
        'cs' => [
            'title' => 'title_cs',
            'body' => 'body_cs',
        ],
        'de' => [
            'title' => 'title_de',
            'body' => 'body_de',
        ],
        'es' => [
            'title' => 'title_es',
            'body' => 'body_es',
        ],
        'fr' => [
            'title' => 'title_fr',
            'body' => 'body_fr',
        ],
        'nl' => [
            'title' => 'title_nl',
            'body' => 'body_nl',
        ],
        'pt' => [
            'title' => 'title_pt',
            'body' => 'body_pt',
        ],
        'ro' => [
            'title' => 'title_ro',
            'body' => 'body_ro',
        ],
        'it' => [
            'title' => 'title_it',
            'body' => 'body_it',
        ],
    ];

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // ArticleController may have resolved an explicit request language
        // (for example, a `lang` query parameter) before this resource is
        // serialized. Preserve that value; otherwise use the same request /
        // cookie / session precedence and locale fallback for every consumer.
        $language = $this->normalizeLanguage(
            $this->resource->getAttribute('current_language') ?: $this->resolveCurrentLanguage($request)
        );
        $localizedFields = self::LANGUAGE_MAP[$language] ?? null;

        $title = $this->resource->getAttribute('title');
        $body = $this->resource->getAttribute('body');

        if ($localizedFields) {
            $localizedTitle = $this->resource->getAttribute($localizedFields['title']);
            $localizedBody = $this->resource->getAttribute($localizedFields['body']);

            // An untranslated field falls back to the default content.
            if ($this->hasValue($localizedTitle)) {
                $title = $localizedTitle;
            }

            if ($this->hasValue($localizedBody)) {
                $body = $localizedBody;
            }
        }

        return [
            'id' => $this->id,
            'title' => $title,
            'body' => \App\Services\Content\SafeHtml::render($body),
            'current_language' => $language,
            'thumbnail' => $this->file ? url($this->file->path) : null,
            'created_at' => $this->created_at->format('d-m-Y H:i'),
            'updated_at' => $this->updated_at->format('d-m-Y H:i'),
            'link' => route('article', [ 'article' => $this->id ]),
            'link_short' => route('article.short', [ 'article' => $this->id ])
        ];
    }

    protected function normalizeLanguage($language): string
    {
        $language = strtolower(str_replace('_', '-', trim((string) $language)));

        if (in_array($language, ['zh', 'zh-tw', 'zh-hk', 'zh-mo', 'tw'], true)) {
            return 'zh-tw';
        }

        if (str_contains($language, '-')) {
            $short = explode('-', $language)[0] ?? $language;

            return $short === 'zh' ? 'zh-tw' : $short;
        }

        return $language;
    }

    protected function resolveCurrentLanguage($request): string
    {
        $language = null;

        if ($request) {
            $language = $request->get('lang')
                ?: $request->get('language')
                ?: $request->attributes->get('current_language')
                ?: $request->cookie('user_language')
                ?: $request->cookie('lang')
                ?: $request->cookie('language')
                ?: $request->cookie('locale');

            if (!$language) {
                try {
                    if ($request->hasSession()) {
                        $language = $request->session()->get('lang')
                            ?: $request->session()->get('language')
                            ?: $request->session()->get('locale');
                    }
                } catch (\Throwable $e) {
                    // API/console requests may not have a session store.
                }
            }
        }

        return $language ?: app()->getLocale();
    }

    protected function hasValue($value): bool
    {
        return $value !== null && (!is_string($value) || trim($value) !== '');
    }
}
