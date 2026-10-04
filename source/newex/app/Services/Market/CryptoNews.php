<?php

namespace App\Services\Market;

use Illuminate\Support\Facades\{Cache, Http};

/** Public headline links only; page requests never contact the publisher. */
final class CryptoNews
{
    public const CACHE_KEY = 'crypto-news.v1.cointelegraph';
    public const FEED_URL = 'https://cointelegraph.com/rss';
    public const REFRESH_SECONDS = 300;
    public const STALE_SECONDS = 900;
    public const RETENTION_SECONDS = 604800;

    public const LOCALES = ['en', 'zh-cn', 'zh-tw', 'ja'];

    public function source(string $locale): array
    {
        if ($locale === 'en') return ['name' => 'Cointelegraph', 'url' => self::FEED_URL];
        $language = ['zh-cn' => 'zh', 'zh-tw' => 'zh-hant', 'ja' => 'ja'][$locale] ?? null;
        return ['name' => 'PANews', 'url' => $language ? 'https://www.panewslab.com/rss.xml?lang='.$language.'&type=NEWS' : null];
    }

    private function cacheKey(string $locale): string
    {
        return $locale === 'en' ? self::CACHE_KEY : 'crypto-news.v2.panews.'.$locale;
    }

    public function snapshot(string $locale = 'en'): array
    {
        $saved = Cache::get($this->cacheKey($locale), []);
        $items = array_values(array_filter($saved['items'] ?? [],
            fn ($item) => strtotime($item['publishedAt'] ?? '') >= now()->timestamp - self::RETENTION_SECONDS));
        $lastSuccess = $saved['fetchedAt'] ?? null;
        $stale = !$lastSuccess || !empty($saved['lastError'])
            || now()->timestamp - strtotime($lastSuccess) >= self::STALE_SECONDS;

        return [
            'items' => $items,
            'source' => $this->source($locale), 'locale' => $locale,
            'fetchedAt' => $lastSuccess,
            'lastAttemptAt' => $saved['lastAttemptAt'] ?? null,
            'state' => !$items ? 'unavailable' : ($stale ? 'stale' : 'fresh'),
            'staleAfterSeconds' => self::STALE_SECONDS,
        ];
    }

    public function refresh(string $locale = 'en'): array
    {
        if (!in_array($locale, self::LOCALES, true)) return $this->snapshot($locale);
        $lock = Cache::lock($this->cacheKey($locale).'.lock', 30);
        if (!$lock->get()) return $this->snapshot($locale);

        try {
            $saved = Cache::get($this->cacheKey($locale), []);
            if (($saved['nextAttemptAt'] ?? 0) > now()->timestamp) return $this->snapshot($locale);
            $attempt = now()->toIso8601String();
            try {
                $xml = Http::connectTimeout(3)->timeout(5)->withoutRedirecting()
                    ->withHeaders(['Accept' => 'application/rss+xml, application/xml', 'User-Agent' => 'Deepro Headlines/1.0 (+https://deepro.io)'])
                    ->get($this->source($locale)['url'])->throw()->body();
                $items = $this->parse($xml, $locale);
                if (!$items) throw new \UnexpectedValueException('No valid headlines');
                $fetchedAt = now()->toIso8601String();
                $saved = ['items' => $items, 'fetchedAt' => $fetchedAt, 'lastAttemptAt' => $attempt,
                    'lastError' => null, 'failures' => 0, 'nextAttemptAt' => now()->timestamp + self::REFRESH_SECONDS];
            } catch (\Throwable $e) {
                // Preserve last successful contents and time. Never expose publisher responses/errors.
                $failures = min(5, ($saved['failures'] ?? 0) + 1);
                $saved = array_merge($saved, ['lastAttemptAt' => $attempt, 'lastError' => 'source_unavailable',
                    'failures' => $failures, 'nextAttemptAt' => now()->timestamp + min(3600, self::REFRESH_SECONDS * (2 ** ($failures - 1)))]);
            }
            Cache::put($this->cacheKey($locale), $saved, self::RETENTION_SECONDS);
            return $this->snapshot($locale);
        } finally {
            $lock->release();
        }
    }

    public function parse(string $xml, string $locale = 'en'): array
    {
        if (strlen($xml) > 2000000 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false)
            throw new \UnexpectedValueException('Invalid news response');
        $previous = libxml_use_internal_errors(true);
        try { $feed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$feed || !isset($feed->channel)) throw new \UnexpectedValueException('Invalid news feed');

        $items = [];
        foreach ($feed->channel->item as $item) {
            $title = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
            $title = preg_replace('/[\x00-\x1f\x7f]/u', '', $title);
            $url = $this->canonicalUrl(trim((string) $item->link), $locale);
            $date = trim((string) $item->pubDate);
            // A source timestamp must include a real date and zone; missing dates never become "now".
            $time = preg_match('/\d{1,2}\s+[A-Za-z]{3}\s+\d{4}\s+\d{2}:\d{2}(?::\d{2})?\s+(?:[+-]\d{4}|GMT|UTC)$/D', $date) ? strtotime($date) : false;
            if (!$title || !$url || !$time || $time > now()->timestamp + 300 || $time < now()->timestamp - self::RETENTION_SECONDS) continue;
            $id = hash('sha256', $url);
            $entry = ['id' => $id, 'title' => mb_substr($title, 0, 240), 'url' => $url,
                'publishedAt' => gmdate('c', $time), 'source' => $this->source($locale)['name'], 'locale' => $locale];
            if (!isset($items[$id]) || $entry['publishedAt'] > $items[$id]['publishedAt']) $items[$id] = $entry;
        }
        usort($items, fn ($a, $b) => strcmp($b['publishedAt'], $a['publishedAt']));
        return array_slice($items, 0, 12);
    }

    private function canonicalUrl(string $url, string $locale): ?string
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            ) return null;
        $host = $locale === 'en' ? 'cointelegraph.com' : 'www.panewslab.com';
        $language = ['zh-cn' => 'zh', 'zh-tw' => 'zh-hant', 'ja' => 'ja'][$locale] ?? null;
        if ($locale !== 'en' && !$language) return null;
        $pattern = $locale === 'en' ? '~^/news/[a-zA-Z0-9-]+/?$~D' : '~^/'.$language.'/articles/[a-zA-Z0-9-]+/?$~D';
        if (strtolower($parts['host'] ?? '') !== $host || !preg_match($pattern, $parts['path'] ?? '')) return null;
        return 'https://'.$host.rtrim($parts['path'], '/');
    }
}
