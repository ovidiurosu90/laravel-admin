<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\Packages\MyFinance2\MyFinance2TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use ovidiuro\myfinance2\MyFinance2ServiceProvider;

/**
 * This app maps MyFinance2's two gates (myfinance2.access, myfinance2.manage) in AuthServiceProvider to the admin and
 * financeadmin roles and to each area's permission; the package's own default (everyone denied until an app defines
 * them) is tested in the package. Read-only: real users are only read, the role-less user is never saved.
 */
class AuthorizationGatesTest extends MyFinance2TestCase
{
    use DatabaseTransactions;

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_the_app_lets_the_finance_roles_in_and_nobody_else(): void
    {
        $this->assertTrue(Gate::forUser($this->_financeUser())->allows('myfinance2.access'));
        $this->assertTrue(Gate::forUser(User::factory()->make())->denies('myfinance2.access'));
    }

    public function test_the_app_maps_every_area_to_its_permission(): void
    {
        $admin = User::whereHas('roles', fn ($query) => $query->where('slug', 'admin'))->first();
        if ($admin === null) {
            $this->markTestSkipped('Requires a user with the admin role');
        }

        foreach (MyFinance2ServiceProvider::MANAGED_AREAS as $area) {
            $this->assertTrue(Gate::forUser($admin)->allows('myfinance2.manage', $area),
                "The admin (every permission) cannot manage '{$area}': is it mapped in AuthServiceProvider?");
            $this->assertTrue(Gate::forUser(User::factory()->make())->denies('myfinance2.manage', $area));
        }
        $this->assertTrue(Gate::forUser($admin)->denies('myfinance2.manage', 'unknown-area'));
    }

    private function _financeUser(): User
    {
        $user = User::whereHas('roles', fn ($query) => $query->whereIn('slug', ['admin', 'financeadmin']))->first();
        if ($user === null) {
            $this->markTestSkipped('Requires a user with the admin or financeadmin role');
        }

        return $user;
    }
}
