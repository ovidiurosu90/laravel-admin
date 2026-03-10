<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2;

/**
 * Returns endpoint regression tests for year 2024
 *
 * See ReturnsEndpointRegressionTestBase for detailed documentation.
 */
class ReturnsEndpointRegression2024Test extends ReturnsEndpointRegressionTestBase
{
    // Redeclare static properties for per-class isolation (PHP static property caveat)
    protected static ?array $returnsData = null;
    protected static ?float $testStartTime = null;
    protected static ?string $cachedTestDataProviderClass = null;
    protected static bool $classLoaded = false;
    protected static bool $cacheCleared = false;

    protected static function _getTestYear(): int
    {
        return 2024;
    }
}
