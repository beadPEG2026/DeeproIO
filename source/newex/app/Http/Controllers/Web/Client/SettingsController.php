<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SettingsController extends Controller
{
    public function mode(Request $request){

        $response = new Response('');

        return $response->withCookie(cookie('theme', $request->get('mode') == "dark" ? 'dark' : '', 45000));
    }

}
