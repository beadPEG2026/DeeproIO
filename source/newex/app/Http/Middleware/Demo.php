<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class Demo
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
        // If read only mode enabled
        if(config('app.readonly')) {

            $message = "In Demo Version we enabled READ ONLY mode to protect our demo content.";

            $allowed_methods = ['GET'];
            $allowed_actions = ['logout', ''];

            if(in_array($request->route()->getName(), $allowed_actions)) {
                return $next($request);
            }

            if(!in_array($request->method(), $allowed_methods)) {

                if($request->header('X-Inertia')){

                    return Redirect::back();

                } else {
                    return response()->json(['message' => $message], 423);
                }

            }

        }

        return $next($request);
    }
}
