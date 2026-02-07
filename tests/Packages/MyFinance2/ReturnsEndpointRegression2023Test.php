<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2;

/**
 * Returns endpoint regression tests for year 2023
 *
 * See ReturnsEndpointRegressionTestBase for detailed documentation.
 * Includes virtual accounts (e.g., transfer adjustments) which only have
 * actualReturn data - these are tested via the return value test only.
 */
class ReturnsEndpointRegression2023Test extends ReturnsEndpointRegressionTestBase
{
    // Redeclare static properties for per-class isolation (PHP static property caveat)
    protected static ?array $returnsData = null;
    protected static ?float $testStartTime = null;
    protected static ?string $cachedTestDataProviderClass = null;
    protected static bool $classLoaded = false;
    protected static bool $cacheCleared = false;

    protected static function _getTestYear(): int
    {
        return 2023;
    }
}
