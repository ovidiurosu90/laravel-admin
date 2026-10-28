<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature\Security;

use Tests\Packages\MyFinance2\MyFinance2TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Concerns\CreatesSyntheticUsers;
use Tests\Concerns\ExercisesTheMiddleware;
use Tests\Concerns\FillsRouteParameters;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;

/**
 * This app's wiring of the MyFinance2 routes: its activity log and blocker run on them (config/myfinance2.php), and
 * the myfinance2.access gate opens them to the admin and financeadmin roles only (not to unverifiedfinanceadmin, user,
 * or no role), guests going to the login page. That every route is behind the gate, whatever the config, is tested in
 * the package. Throwaway users (CreatesSyntheticUsers), rolled back.
 */
class FinanceRouteAccessTest extends MyFinance2TestCase
{
    use CreatesSyntheticUsers;
    use DatabaseTransactions;
    use ExercisesTheMiddleware;
    use FillsRouteParameters;

    /**
     * Light pages a finance user with no data opens (no live quotes needed).
     */
    private const FINANCE_PAGES = [
        'myfinance2::accounts.index', 'myfinance2::currencies.index', 'myfinance2::cash-balances.index',
        'myfinance2::dividends.index', 'myfinance2::ledger-transactions.index', 'myfinance2::price-alerts.index',
        'myfinance2::stock-splits.index', 'myfinance2::price-alerts.history',
    ];

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->exerciseTheMiddleware();

        AssignedToUserScope::enable();
        config(['laravel2step.laravel2stepEnabled' => false]);
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_every_route_runs_the_apps_middleware_between_web_and_the_gate(): void
    {
        // This app's config/myfinance2.php adds the activity log and the blocker, like its own pages.
        $expected = ['web', 'activity', 'checkblocked', 'can:myfinance2.access'];

        $different = [];
        foreach ($this->_financeRoutes() as $route) {
            $middleware = array_values(array_filter($route->middleware(), 'is_string'));
            if ($middleware !== $expected) {
                $different[] = $route->uri() . ': ' . implode(', ', $middleware);
            }
        }

        $this->assertSame([], $different,
            "MyFinance2 routes with another middleware stack:\n" . implode("\n", $different));
    }

    public function test_guests_are_refused_everywhere(): void
    {
        $this->assertSame([], $this->_notRefused(true), 'MyFinance2 routes a guest was not refused');
    }

    public function test_users_without_finance_access_are_refused_everywhere(): void
    {
        foreach ([[], ['user'], ['unverifiedfinanceadmin']] as $roles) {
            $this->actingAs($this->syntheticUser($roles));

            $this->assertSame([], $this->_notRefused(),
                'MyFinance2 routes a user with roles [' . implode(', ', $roles) . '] was not refused');
            $this->get('/home')->assertOk()->assertDontSee('navbar-dropdown-myfinance2', false);
        }
    }

    public function test_the_finance_roles_open_the_finance_pages_and_see_the_menu(): void
    {
        foreach ([['financeadmin'], ['admin']] as $roles) {
            $this->actingAs($this->syntheticUser($roles));

            foreach (self::FINANCE_PAGES as $name) {
                $this->get(route($name))->assertOk();
            }
            $this->get('/home')->assertOk()->assertSee('navbar-dropdown-myfinance2', false);
        }
    }

    /**
     * Every MyFinance2 route (each with its first method) that did not answer 403 for the current user, or for a
     * guest, 403 or a redirect to the login page.
     *
     * @return list<string>
     */
    private function _notRefused(bool $guest = false): array
    {
        $notRefused = [];
        foreach ($this->_financeRoutes() as $route) {
            $method   = self::firstMethod($route);
            $response = $this->call($method, self::filledUri($route));
            $status   = $response->getStatusCode();
            $location = (string) $response->headers->get('Location');
            if ($status === 403 || ($guest && $status === 302 && $location === route('login'))) {
                continue;
            }
            $notRefused[] = "{$status} {$method} {$route->uri()}" . ($location !== '' ? " -> {$location}" : '');
        }

        return $notRefused;
    }

    /**
     * @return list<Route>
     */
    private function _financeRoutes(): array
    {
        return array_values(array_filter(RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with((string) $route->getActionName(), 'ovidiuro\\myfinance2\\')));
    }
}
