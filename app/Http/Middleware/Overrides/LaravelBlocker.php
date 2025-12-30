<?php

namespace jeremykenedy\LaravelBlocker\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use jeremykenedy\LaravelBlocker\App\Traits\LaravelCheckBlockedTrait;

class LaravelBlocker
{
    use LaravelCheckBlockedTrait;

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (config('laravelblocker.laravelBlockerEnabled')) {
            // MODIFIED: Changed from LaravelCheckBlockedTrait::checkBlocked() to self::checkBlocked()
            // VENDOR VERSION: Calls trait method by trait name (deprecated in PHP 8.1+)
            // WHY: PHP 8.1+ deprecation - static trait methods should be called through class using the trait
            self::checkBlocked();
        }

        return $next($request);
    }
}
