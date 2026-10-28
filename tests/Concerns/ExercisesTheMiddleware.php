<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Http\Middleware\VerifyCsrfToken;
use Spatie\Html\Html;

/**
 * For the security tests: their requests go through every middleware (the feature tests skip them all with
 * withoutMiddleware()), except the CSRF check, since they post no token (RouteProtectionTest proves the check is on;
 * it is only skipped by Laravel when APP_ENV=testing, and phpunit.xml sets local).
 */
trait ExercisesTheMiddleware
{
    protected function exerciseTheMiddleware(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        // spatie's html() is a singleton holding the request it was first built with; in a test, the forms of every
        // later request would read that first request, which has no session ("Session store not set on request").
        $this->app->bind(Html::class, fn ($app) => new Html($app['request']));
    }
}
