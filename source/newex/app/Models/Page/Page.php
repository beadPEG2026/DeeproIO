<?php

namespace App\Models\Page;

use App\Models\Page\Traits\Scopes\PageScope;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use PageScope;

    public $fillable = [
        'title',
        'slug',
        'content',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'status',
        'is_html',
        'html_content',

        'title_zh-cn', 'content_zh-cn', 'html_content_zh-cn',
        'title_zh-tw',
        'content_zh-tw',
        'html_content_zh-tw',

        'title_ja',
        'content_ja',
        'html_content_ja',

        'title_cs',
        'content_cs',
        'html_content_cs',

        'title_de',
        'content_de',
        'html_content_de',

        'title_es',
        'content_es',
        'html_content_es',

        'title_fr',
        'content_fr',
        'html_content_fr',

        'title_nl',
        'content_nl',
        'html_content_nl',

        'title_pt',
        'content_pt',
        'html_content_pt',

        'title_ro',
        'content_ro',
        'html_content_ro',

        'title_it',
        'content_it',
        'html_content_it',
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_html' => 'boolean',
    ];
}