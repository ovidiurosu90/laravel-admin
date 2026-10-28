<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * The laravel-roles permission each MyFinance2 area's create / edit forms need (myfinance2.manage).
     *
     * @var array<string, string>
     */
    private const MYFINANCE2_PERMISSIONS = [
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

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->_defineMyFinance2Gates();
    }

    /**
     * The ovidiuro/myfinance2 gates (the package denies everyone until the app defines them): its pages for the
     * admin and financeadmin roles, each area's forms for that area's permission. Harmless without the package.
     */
    private function _defineMyFinance2Gates(): void
    {
        Gate::define('myfinance2.access', fn (User $user) => $user->hasRole('admin|financeadmin'));
        Gate::define('myfinance2.manage', fn (User $user, string $area) => isset(self::MYFINANCE2_PERMISSIONS[$area])
            && $user->hasPermission(self::MYFINANCE2_PERMISSIONS[$area]));
    }
}
