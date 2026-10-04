<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Page\Page;
use App\Repositories\Page\PageRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class PageController extends Controller
{

public function show($slug = '')
{
    $page = (new PageRepository())->getPageBySlug($slug, true);

    if (!$page) {
        throw new ModelNotFoundException();
    }

    return Inertia::render('Page/Page', [
        'page' => new Page($page),
    ]);
}

    public function showShort($slug = '')
    {
        $page = (new PageRepository())->getPageBySlug($slug, true);

        if(!$page) {
            throw new ModelNotFoundException();
        }

        return Inertia::render('Page/PageShort', [
            'page' => new Page($page)
        ]);
    }

    public function maintenance() {

        $maintenance = Setting::get('general.maintenance_status', false);

        if(!$maintenance) {
            return Redirect::route('home');
        }

        return response()->view('errors.503', [], 503)->header('Retry-After', '300');

    }
}
