<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use ovidiuro\adminmydata\AdminMyDataServiceProvider;
use ovidiuro\myfinance2\MyFinance2ServiceProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Packages\MyFinance2\MyFinance2TestCase;

/**
 * admin-mydata's private trades config (trades-private.php) overrides myfinance2's public defaults in
 * myfinance2.trades, whichever provider registers first. admin-mydata's logic, tested here as it has no test runner
 * of its own (myfinance2's config namespacing is tested in the package). Only this test's config changes.
 */
class AdminMyDataTradesConfigTest extends MyFinance2TestCase
{
    public function test_the_private_trades_config_wins_whichever_provider_registers_first(): void
    {
        if (!class_exists(AdminMyDataServiceProvider::class)) {
            $this->markTestSkipped('The ovidiuro/laravel-package-admin-mydata package is not installed.');
        }

        $public  = $this->_myFinance2Config('trades');
        $private = dirname((new ReflectionClass(AdminMyDataServiceProvider::class))->getFileName())
            . '/config/trades-private.php';
        $this->_assertPrivateWins($private, 'as registered');

        // myfinance2 first: its defaults are already set when admin-mydata registers
        config(['myfinance2.trades' => require $public]);
        $this->_overrideConfigFrom($private);
        $this->_assertPrivateWins($private, 'myfinance2 first');

        // admin-mydata first: myfinance2's merge keeps the values already set
        config(['myfinance2.trades' => []]);
        $this->_overrideConfigFrom($private);
        $this->_mergeConfigFrom($public, 'myfinance2.trades');
        $this->_assertPrivateWins($private, 'admin-mydata first');
    }

    private function _assertPrivateWins(string $private, string $order): void
    {
        foreach (require $private as $key => $value) {
            $this->assertSame($value, config("myfinance2.trades.{$key}"), "{$order}: myfinance2.trades.{$key}");
        }
    }

    private function _myFinance2Config(string $area): string
    {
        return dirname((new ReflectionClass(MyFinance2ServiceProvider::class))->getFileName())
            . "/config/{$area}.php";
    }

    private function _mergeConfigFrom(string $path, string $key): void
    {
        (new ReflectionMethod(MyFinance2ServiceProvider::class, 'mergeConfigFrom'))
            ->invoke(new MyFinance2ServiceProvider($this->app), $path, $key);
    }

    private function _overrideConfigFrom(string $path): void
    {
        (new ReflectionMethod(AdminMyDataServiceProvider::class, '_overrideConfigFrom'))
            ->invoke(new AdminMyDataServiceProvider($this->app), $path, 'myfinance2.trades');
    }
}
