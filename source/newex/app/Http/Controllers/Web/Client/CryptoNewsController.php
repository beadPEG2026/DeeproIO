<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Services\Market\CryptoNews;
use Illuminate\Http\JsonResponse;

final class CryptoNewsController extends Controller
{
    public function index(CryptoNews $news): JsonResponse
    {
        return response()->json($news->snapshot(app()->getLocale()))->header('Cache-Control', 'no-store');
    }
}
