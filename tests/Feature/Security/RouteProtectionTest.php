<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Concerns\CreatesSyntheticUsers;
use Tests\Concerns\FillsRouteParameters;
use Tests\TestCase;

/**
 * Every route is either deliberately public (PUBLIC_URIS) or refuses guests, so a new route that forgets its
 * middleware fails here. Covers this app's routes and the packages' (myfinance2's are gated by
 * can:myfinance2.access). Also proves the CSRF check is on (the other security tests switch it off). The activity log
 * rows the requests leave, and the throwaway user, are rolled back.
 */
class RouteProtectionTest extends TestCase
{
    use CreatesSyntheticUsers;
    use DatabaseTransactions;
    use FillsRouteParameters;

    /**
     * Routes anyone may open: the landing pages, sign-in / sign-up / password reset / activation flows.
     */
    private const PUBLIC_URIS = [
        '/', 'terms', 'php',
        'login', 'logout', 'register',
        'password/reset', 'password/email', 'password/reset/{token}', 'reset-password/{token}', 'reset-password',
        'activate', 'activate/{token}', 'activation', 'exceeded', 're-activate/{token}',
        'social/redirect/{provider}', 'social/handle/{provider}',
        'sanctum/csrf-cookie', 'up', 'storage/{path}',
    ];

    /**
     * Prefixes of package tooling that guards itself (only active in debug) or is public by design.
     */
    private const PUBLIC_PREFIXES = ['_debugbar', '_ignition'];

    /**
     * Middleware that turns a guest away (redirect to login, 401 or 403).
     */
    private const GUARDING_MIDDLEWARE = ['auth', 'currentUser'];
    private const GUARDING_MIDDLEWARE_PREFIXES = ['auth:', 'role:', 'can:', 'level:', 'permission:'];

    public function test_every_route_is_either_deliberately_public_or_refuses_guests(): void
    {
        $unguarded = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (!$this->_isPublic($route) && !$this->_refusesGuests($route)) {
                $unguarded[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $unguarded,
            "Routes open to guests but not listed as public:\n" . implode("\n", $unguarded));
    }

    public function test_guests_cannot_open_any_protected_page(): void
    {
        $opened = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if ($this->_isPublic($route) || !in_array('GET', $route->methods(), true)) {
                continue;
            }
            $status = $this->get(self::filledUri($route))->getStatusCode();
            if ($status < 300 || ($status >= 500)) {
                $opened[] = "{$status} GET {$route->uri()}";
            }
        }

        $this->assertSame([], $opened,
            "Protected pages a guest could open (or that crashed):\n" . implode("\n", $opened));
    }

    public function test_forms_without_the_csrf_token_are_refused(): void
    {
        $this->actingAs($this->syntheticUser(['user']))->post('/logout')->assertStatus(419);
    }

    private function _isPublic(Route $route): bool
    {
        $uri = $route->uri();
        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return in_array($uri, self::PUBLIC_URIS, true);
    }

    private function _refusesGuests(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (!is_string($middleware)) {
                continue;
            }
            if (in_array($middleware, self::GUARDING_MIDDLEWARE, true)) {
                return true;
            }
            foreach (self::GUARDING_MIDDLEWARE_PREFIXES as $prefix) {
                if (str_starts_with($middleware, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
