<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Article\Article as ArticleResource;
use App\Http\Resources\Article\ArticleCollection;
use App\Http\Resources\Launchpad\LaunchpadCollection;
use App\Http\Resources\Staking\StakingCollection;
use App\Models\Launchpad\Launchpad;
use App\Models\Staking\Staking;
use App\Repositories\Article\ArticleRepository;
use App\Repositories\Market\MarketRepository;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        $articleRepository = new ArticleRepository();

        // Get featured articles
        $articles = new ArticleCollection($articleRepository->featured());

        // Get the newest homepage popup visible to this visitor.
        $popupArticle = $articleRepository->homepagePopup();

        $featuredIds=\Illuminate\Support\Facades\DB::table('settings')->whereIn('key',['staking.featured_term.BTC','staking.featured_term.ETH'])->pluck('value')->map(fn($v)=>(int)$v)->filter()->all();
        // Get active stakings
        $stakings = Staking::query()
            ->when($featuredIds,fn($q)=>$q->whereIn('id',$featuredIds))
            ->visible()
            ->where('staking_type', 0)
            ->whereHas('currency',fn($q)=>$q->whereIn('symbol',['BTC','ETH']))
            ->with('currency.file')
            ->orderBy('id')
            ->take(2)
            ->get();

        $quantifies = Staking::query()
            ->visible()
            ->where('staking_type', 1)
            ->has('currency')
            ->has('currencyd')
            ->with([
                'currency.file',
                'currencyd.file',
            ])
            ->orderBy('id', 'desc')
            ->take(4)
            ->get();

        // Get launchpads
        $launchpads = Launchpad::query()->published()->where('status', true)
            ->whereIn('progress', ['open', 'pending'])
            ->has('currency')
            ->with('currency.file')
            ->orderBy('id', 'desc')
            ->take(4)
            ->get();

        // 交易对数量
        $marketCount = (new MarketRepository())->count();

        return Inertia::render('Home/Home', [
            'articles' => $articles,
            'banners' => app(\App\Services\Content\HomeBanners::class)->published(),
            'popupArticle' => $popupArticle ? new ArticleResource($popupArticle) : null,
            'stakings' => new StakingCollection($stakings),
            'quantifies' => new StakingCollection($quantifies),
            'launchpads' => new LaunchpadCollection($launchpads),
            'marketCount' => $marketCount,

            // APP 下载链接
            'androidDownloadUrl' => $this->getSettingValue('general.android_download_url') ?: url('/download/android'),
            'iosDownloadUrl' => $this->getSettingValue('general.ios_download_url') ?: url('/download/ios'),
        ]);
    }

    private function getSettingValue(string $key, string $default = ''): string
    {
        $value = DB::table('settings')
            ->where('key', $key)
            ->value('value');

        if ($value === null) {
            return $default;
        }

        return (string) $value;
    }
}
