<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Services\Language\LanguageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class LanguageController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        return response()->json((new LanguageService())->getTranslations(app()->getLocale()));
    }

    /**
     * Set language
     *
     * @return \Inertia\Response
     */
    public function set(Request $request)
    {
        $cookie = (new LanguageService())->setLanguage(request()->get('language'), $request->user());

        if(!$cookie) {
            return response()->json([]);
        }

        return response()->json([])->withCookie($cookie);
    }

}
