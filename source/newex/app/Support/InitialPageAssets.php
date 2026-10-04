<?php

namespace App\Support;

/** A preload hint only: invalid or rolling-build metadata falls back to normal loading. */
final class InitialPageAssets
{
    public static function scripts(string $component, string $publicDirectory): array
    {
        try {
            return self::readScripts($component, $publicDirectory);
        } catch (\Throwable $error) {
            // A hint must never turn a readable page into a 500 during a deploy
            // or an I/O failure. The original script tags still load normally.
            return [];
        }
    }

    private static function readScripts(string $component, string $publicDirectory): array
    {
        $root = rtrim($publicDirectory, '/');
        $manifest = $root . '/frontend/page-preloads.json';
        $entry = $root . '/frontend/js/app.js';
        if (!is_file($manifest) || is_link($manifest) || !is_file($entry) || is_link($entry) || filesize($manifest) > 65536) {
            return [];
        }
        $data = json_decode(file_get_contents($manifest), true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1
            || !is_string($data['entry_sha256'] ?? null)
            || !is_string($entryHash = hash_file('sha256', $entry))
            || !hash_equals($entryHash, $data['entry_sha256'])) {
            return [];
        }
        $files = $data['pages'][$component] ?? [];
        if (!is_array($files) || !array_is_list($files) || count($files) > 6) {
            return [];
        }
        foreach ($files as $file) {
            if (!is_string($file) || !preg_match('#^/frontend/js/chunks/[A-Za-z0-9_.~-]+\.[a-f0-9]{12}\.js$#D', $file)
                || !is_file($root . $file) || is_link($root . $file)) {
                return [];
            }
        }
        return array_values(array_unique($files));
    }
}
