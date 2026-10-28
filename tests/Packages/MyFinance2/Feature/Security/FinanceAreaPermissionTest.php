<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature\Security;

use Tests\Packages\MyFinance2\MyFinance2TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CreatesSyntheticUsers;
use ovidiuro\myfinance2\MyFinance2ServiceProvider;

/**
 * This app maps myfinance2.manage, for each area, to that area's permission (AuthServiceProvider): a user with one
 * permission and no role may manage that area only, a user without any permission none. That each form asks the gate
 * for its own area is tested in the package. Throwaway users (CreatesSyntheticUsers), rolled back.
 */
class FinanceAreaPermissionTest extends MyFinance2TestCase
{
    use CreatesSyntheticUsers;
    use DatabaseTransactions;

    /**
     * The permission this app requires for each area (mirrors AuthServiceProvider::MYFINANCE2_PERMISSIONS).
     */
    private const PERMISSIONS = [
        'accounts'         => 'accounts.create',
        'alerts'           => 'alerts.create',
        'cashbalances'     => 'cashbalances.create',
        'currencies'       => 'currencies.create',
        'dividends'        => 'dividends.create',
        'ledger'           => 'ledger.create.transaction',
        'orders'           => 'orders.create',
        'splits'           => 'splits.create',
        'trades'           => 'trades.create',
        'watchlistsymbols' => 'watchlist.symbols.create',
    ];

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_every_managed_area_has_its_permission_listed(): void
    {
        $this->assertEqualsCanonicalizing(MyFinance2ServiceProvider::MANAGED_AREAS, array_keys(self::PERMISSIONS));
    }

    public function test_without_any_permission_no_area_can_be_managed(): void
    {
        $user = $this->syntheticUser();

        foreach (MyFinance2ServiceProvider::MANAGED_AREAS as $area) {
            $this->assertTrue(Gate::forUser($user)->denies('myfinance2.manage', $area), "'{$area}' without permission");
        }
    }

    public function test_each_permission_opens_its_own_area_only(): void
    {
        foreach (self::PERMISSIONS as $area => $permission) {
            $user = $this->syntheticUser([], [$permission]);

            foreach (MyFinance2ServiceProvider::MANAGED_AREAS as $other) {
                $this->assertSame($other === $area, Gate::forUser($user)->allows('myfinance2.manage', $other),
                    "'{$other}' with only the '{$permission}' permission");
            }
        }
    }
}
