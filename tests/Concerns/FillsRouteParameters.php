<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Routing\Route;

/**
 * For the route sweeps of the security tests: a route's URI with its parameters filled with values no record has
 * (optional ones dropped), and the method to call it with.
 */
trait FillsRouteParameters
{
    protected static function filledUri(Route $route): string
    {
        $uri = preg_replace('#/\{\w+\?\}#', '', $route->uri());

        return '/' . ltrim(preg_replace_callback('#\{(\w+)\}#', fn (array $match) => match ($match[1]) {
            'symbol'   => 'TST.NONE',
            'provider' => 'github',
            default    => '0',
        }, $uri), '/');
    }

    protected static function firstMethod(Route $route): string
    {
        return current(array_diff($route->methods(), ['HEAD']));
    }
}
