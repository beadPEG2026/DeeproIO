<?php
namespace App\Http\Controllers\Web\Client;
use App\Http\Controllers\Controller;
class DownloadController extends Controller
{
    public function show(?string $platform = null)
    {
        abort_unless(in_array($platform, [null, 'android', 'ios'], true), 404);
        if (request()->header('X-Inertia')) return \Inertia\Inertia::location(url()->current());
        $release = null;
        if ($platform === 'android') {
            $release = json_decode(file_get_contents(public_path('downloads/android-release.json')), true, 512, JSON_THROW_ON_ERROR);
            abort_unless(preg_match('~^/downloads/deepro-android-[0-9.]+\.apk$~D', $release['url'] ?? ''), 503);
            abort_unless(is_file(public_path(ltrim($release['url'], '/'))), 503);
        }
        return response()->view('downloads.'.($platform ?? 'index'), compact('release'));
    }
}
