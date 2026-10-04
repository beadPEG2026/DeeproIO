<?php

namespace App\Models\Article;

use App\Models\Article\Traits\Relations\ArticleRelation;
use App\Models\Article\Traits\Scopes\ArticleScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    public $table = 'articles';

    use HasFactory, SoftDeletes, ArticleRelation, ArticleScope;

    public $fillable = [
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

    protected $casts = [
        'status' => 'boolean',
        'featured' => 'boolean',
        'visibility_referral_user_id' => 'integer',
        'homepage_popup_enabled' => 'boolean',
    ];
}
