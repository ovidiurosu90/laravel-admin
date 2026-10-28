<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Concerns\CreatesSyntheticUsers;
use Tests\Concerns\ExercisesTheMiddleware;
use Tests\Concerns\FillsRouteParameters;
use Tests\TestCase;

/**
 * The admin area (every route behind role:admin: users, themes, logs, routes, roles, blocker, activity, phpinfo, ...)
 * opens for admins only. Throwaway users (CreatesSyntheticUsers), rolled back; two-step verification is switched off
 * except where its own guard is tested.
 */
class AdminAreaAccessTest extends TestCase
{
    use CreatesSyntheticUsers;
    use DatabaseTransactions;
    use ExercisesTheMiddleware;
    use FillsRouteParameters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exerciseTheMiddleware();

        config(['laravel2step.laravel2stepEnabled' => false]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_the_admin_area_is_a_real_set_of_routes(): void
    {
        $uris = array_map(fn (Route $route) => $route->uri(), $this->_adminRoutes());

        foreach (['users', 'users/create', 'themes', 'logs', 'routes'] as $expected) {
            $this->assertContains($expected, $uris, "'{$expected}' is no longer behind role:admin");
        }
    }

    public function test_users_who_are_not_admins_are_refused_everywhere_in_the_admin_area(): void
    {
        foreach ([['user'], ['financeadmin'], ['unverified']] as $roles) {
            $this->actingAs($this->syntheticUser($roles));

            $opened = [];
            foreach ($this->_adminRoutes() as $route) {
                $method = self::firstMethod($route);
                $status = $this->call($method, self::filledUri($route))->getStatusCode();
                // A route with a parameter may answer 404 (model binding) before the role check; still refused.
                $refused = $status === 403 || ($status === 404 && str_contains($route->uri(), '{'));
                if (!$refused) {
                    $opened[] = "{$status} {$method} {$route->uri()}";
                }
            }

            $this->assertSame([], $opened, 'Admin routes a ' . implode('+', $roles) . " user was not refused:\n"
                . implode("\n", $opened));
        }
    }

    public function test_admins_open_every_admin_page(): void
    {
        $this->actingAs($this->syntheticUser(['admin']));

        $failed = [];
        foreach ($this->_adminRoutes() as $route) {
            if (self::firstMethod($route) !== 'GET' || str_contains($route->uri(), '{')) {
                continue;
            }
            $status = $this->get('/' . $route->uri())->getStatusCode();
            if ($status !== 200) {
                $failed[] = "{$status} GET {$route->uri()}";
            }
        }

        $this->assertSame([], $failed, "Admin pages an admin could not open:\n" . implode("\n", $failed));
    }

    public function test_admin_pages_still_ask_for_the_second_step_when_it_is_enabled(): void
    {
        config(['laravel2step.laravel2stepEnabled' => true]);

        $this->actingAs($this->syntheticUser(['admin']))
            ->get('/users')
            ->assertRedirect('verification/needed');
    }

    /**
     * @return list<Route>
     */
    private function _adminRoutes(): array
    {
        return array_values(array_filter(RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route) => in_array('role:admin', $route->gatherMiddleware(), true)));
    }
}
