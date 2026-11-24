<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ServeLandingPage
{
    /**
     * Show a static landing page on the site root instead of redirecting
     * straight to the CRM login screen.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->is('/') && $request->isMethod('get')) {
            return response()->view('landing');
        }

        return $next($request);
    }
}
