<?php

namespace App\Console\Commands\Market;

use App\Services\Market\CryptoNews;
use Illuminate\Console\Command;

final class RefreshCryptoNews extends Command
{
    protected $signature = 'market:refresh-crypto-news';
    protected $description = 'Refresh cached crypto headline links from official localized RSS feeds';

    public function handle(CryptoNews $news): int
    {
        $snapshots = [];
        foreach (CryptoNews::LOCALES as $locale) $snapshots[] = $news->refresh($locale);
        $fresh = collect($snapshots)->every(fn ($snapshot) => $snapshot['state'] === 'fresh');
        $count = collect($snapshots)->sum(fn ($snapshot) => count($snapshot['items']));
        $fetched = collect($snapshots)->pluck('fetchedAt')->filter()->sort()->first();
        $this->line($count.' headlines; '.($fresh ? 'fresh' : 'stale').'; last successful fetch '.($fetched ?? 'none'));
        return $fresh ? self::SUCCESS : self::FAILURE;
    }
}
