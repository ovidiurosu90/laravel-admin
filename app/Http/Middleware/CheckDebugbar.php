<?php

namespace App\Http\Middleware;

use Auth;
use Closure;
use Debugbar;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckDebugbar
{
    public function handle(Request $request, Closure $next): Response
    {
        $currentUser = Auth::user();
        if(Auth::check() && Auth::user()->hasRole('admin')) {
            Debugbar::enable();
        } else {
            Debugbar::disable();
        }
        return $next($request);
    }
}

