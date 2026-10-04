<?php
namespace App\Http\Controllers\Api\v1;
use App\Http\Controllers\Controller;
class GuessController extends Controller
{
    public function submit() { return response()->json(['message'=>__('This product is not currently available.')],410); }
}
