<?php
namespace App\Services\SystemMonitor;
final class ReleaseInfo
{
    public function version(?string $path = null): string
    {
        $path ??= storage_path('app/releases/current.json');
        if (!is_file($path) || filesize($path) > 1048576) return 'Unknown';
        $data = json_decode(file_get_contents($path), true);
        $tag = $data['tag'] ?? $data['release'] ?? null;
        return is_string($tag) && preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $tag) ? $tag : 'Unknown';
    }
}
