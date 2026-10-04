<?php

namespace App\Http\Middleware;

use App\Repositories\Language\LanguageRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Setting;

class LanguageDetector
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  ...$guards
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$guards)
    {
        /*
         * Set App Locale
         */

        $user = $request->user();

        if($user && $user->language && $user->language->status) {
            $lang = $user->language->slug;

        } else {

            $langFromCookie = $request->cookie('user_language');

            $repository = new LanguageRepository();
            $defaultLanguage = $langFromCookie ? $repository->getBySlug($langFromCookie, true) : null;
            if (!$defaultLanguage) {
                $available = $repository->all(true)->values()->all();
                foreach ($request->getLanguages() as $preferred) {
                    $preferred = strtolower(str_replace('_', '-', $preferred));
                    $match = collect($available)->first(fn ($slug) => strtolower($slug) === $preferred);
                    if (!$match && (str_starts_with($preferred, 'zh-') || $preferred === 'zh')) {
                        $chinese = preg_match('/(?:hant|tw|hk|mo)/', $preferred) ? 'zh-tw' : 'zh-cn';
                        $match = in_array($chinese, $available, true) ? $chinese : null;
                    }
                    if (!$match) {
                        $base = explode('-', $preferred)[0];
                        $match = collect($available)->first(fn ($slug) => explode('-', strtolower($slug))[0] === $base);
                    }
                    if ($match) {
                        $defaultLanguage = $repository->getBySlug($match, true);
                        break;
                    }
                }
                $defaultLanguage ??= $repository->getByDefault();
            }

            if ($defaultLanguage) {
                $lang = $defaultLanguage->slug;
            } else {
                $lang = config('app.fallback_locale');
            }
        }

        App::setLocale($lang);
        // Keep the middleware's authoritative choice available to resources.
        // This prevents a stale guest cookie from overriding an authenticated
        // user's language preference when serializing homepage popup articles.
        $request->attributes->set('current_language', $lang);

        return $next($request);
    }
}
