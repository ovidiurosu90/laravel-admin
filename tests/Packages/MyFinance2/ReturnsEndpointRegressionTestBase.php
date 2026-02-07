<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use App\Models\User;

/**
 * Abstract base class for returns endpoint regression tests
 *
 * These are read-only regression tests that verify the returns endpoint
 * correctly calculates portfolio values, deposits, withdrawals, and returns
 * for multiple accounts and currencies.
 *
 * These tests serve to ensure:
 * 1. The data in the database is correct
 * 2. The endpoint logic correctly processes that data for all accounts
 * 3. All accounts are fetched in a single optimized request
 *
 * If any of these tests fail after making changes, it indicates either:
 * - The database data changed unexpectedly
 * - The calculation logic was inadvertently modified
 *
 * Test data and test year are configured in the private admin-mydata package.
 *
 * CACHE BEHAVIOR:
 * PHPUnit uses an isolated 'array' cache driver (configured in phpunit.xml),
 * separate from the production cache (file/redis). This means:
 * - Tests always start with an empty cache (deterministic behavior)
 * - The clear-cache call clears the test's array cache, not production cache
 * - Tests verify the full data flow without relying on cached data
 * - Tests use skip_overview=1 to skip the expensive all-years overview calculation
 * - Expected runtime: ~30 seconds (fetches fresh data from Yahoo Finance API for one year)
 *
 * This isolation is intentional for regression testing - tests should not
 * depend on or affect production cache state.
 *
 * Subclasses MUST redeclare all static properties to get per-class isolation
 * (PHP static properties are shared per-declaring-class, not per-subclass).
 */
abstract class ReturnsEndpointRegressionTestBase extends TestCase
{
    // Subclasses MUST redeclare these 5 static properties for isolation:
    protected static ?array $returnsData = null;
    protected static ?float $testStartTime = null;
    protected static ?string $cachedTestDataProviderClass = null;
    protected static bool $classLoaded = false;
    protected static bool $cacheCleared = false;

    /**
     * Return the year under test (e.g. 2022, 2023)
     */
    abstract protected static function _getTestYear(): int;

    /**
     * Get the test data provider class
     * Loads from configuration (no hardcoded namespaces in test file)
     * Caches result on first successful load
     *
     * The config can be:
     * - Set via config/test-data.php in the main project
     * - Set via environment variable TEST_DATA_RETURNS_CLASS
     * - Handled by the admin-mydata service provider (merges with published config)
     */
    protected static function _getTestDataProviderClass(): ?string
    {
        // Return cached value if already loaded
        if (static::$classLoaded) {
            return static::$cachedTestDataProviderClass;
        }

        // Try config helper first (works after Laravel bootstrap)
        try {
            $class = config('test-data.providers.returns');
            if (is_string($class) && class_exists($class)) {
                static::$cachedTestDataProviderClass = $class;
                static::$classLoaded = true;
                return $class;
            }
        } catch (\Throwable) {
            // Config helper might not be available during data provider evaluation
        }

        // Fallback: read config file directly (works during data provider evaluation)
        try {
            // __DIR__ is tests/Packages/MyFinance2, so we need to go up 3 levels to project root
            $configPath = __DIR__ . '/../../../config/test-data.php';
            if (file_exists($configPath)) {
                $config = require $configPath;
                $class = $config['providers']['returns'] ?? null;
                if (is_string($class) && class_exists($class)) {
                    static::$cachedTestDataProviderClass = $class;
                    static::$classLoaded = true;
                    return $class;
                }
            }
        } catch (\Throwable) {
            // Config file might not exist or be readable
        }

        // Fallback: check environment variable for test data class
        // This allows specifying test data provider without modifying code
        $envClass = getenv('TEST_DATA_RETURNS_CLASS');
        if (is_string($envClass) && class_exists($envClass)) {
            static::$cachedTestDataProviderClass = $envClass;
            static::$classLoaded = true;
            return $envClass;
        }

        static::$classLoaded = true; // Mark as "attempted" to avoid repeated failures
        return null;
    }

    /**
     * Check if private test data package is available
     */
    protected static function _hasPrivateTestData(): bool
    {
        return static::_getTestDataProviderClass() !== null;
    }

    /**
     * Get account test data from private package
     */
    protected static function _getAccountTestData(): array
    {
        $class = static::_getTestDataProviderClass();
        if ($class === null) {
            return [];
        }
        return $class::getAccountTestData(static::_getTestYear());
    }

    /**
     * Get float tolerance from private package
     */
    protected static function _getFloatTolerance(): float
    {
        $class = static::_getTestDataProviderClass();
        if ($class === null) {
            return 0.01;
        }
        return $class::getFloatTolerance();
    }

    /**
     * Get max duration from private package
     */
    protected static function _getMaxDurationSeconds(): int
    {
        $class = static::_getTestDataProviderClass();
        if ($class === null) {
            return 40;
        }
        return $class::getMaxDurationSeconds();
    }

    /**
     * Track start time before tests run
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$testStartTime = microtime(true);
    }

    /**
     * Verify all tests completed within the time limit
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (static::$testStartTime !== null) {
            $elapsed = microtime(true) - static::$testStartTime;
            $maxDuration = static::_getMaxDurationSeconds();
            $year = static::_getTestYear();
            $className = basename(str_replace('\\', '/', static::class));
            $message = sprintf(
                '%s (year %d) completed in %.2f seconds (limit: %d seconds)',
                $className,
                $year,
                $elapsed,
                $maxDuration
            );

            if ($elapsed > $maxDuration) {
                fwrite(STDERR, "\n⚠️  WARNING: $message - EXCEEDED TIME LIMIT!\n");
            } else {
                fwrite(STDERR, "\n✓ Performance check: $message\n");
            }
        }
    }

    /**
     * Fetch returns data once before any tests run
     *
     * NOTE: PHPUnit uses an isolated 'array' cache driver, so the clear-cache
     * call only affects the test's in-memory cache (which starts empty anyway).
     * This is intentional - tests should verify the full data flow without
     * relying on production cache. Expect ~30 seconds for fresh data fetch.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Check if private test data is available, skip all tests if not
        if (!static::_hasPrivateTestData()) {
            $this->markTestSkipped(
                'Private test data package (admin-mydata) is not installed. '
                . 'These regression tests require private account data. '
                . 'If you are a maintainer, install the private admin-mydata package.'
            );
        }

        $year = static::_getTestYear();

        // Fetch returns data only once (cached in static variable for all test methods)
        if (static::$returnsData === null) {
            $user = User::first();

            // Clear the test's isolated array cache (not production cache)
            // This ensures consistent test behavior regardless of test order
            // Note: PHPUnit uses CACHE_DRIVER=array, so this clears an empty in-memory cache
            if (!static::$cacheCleared) {
                $response = $this->actingAs($user)
                    ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
                    ->post(route('myfinance2::returns.clear-cache', ['year' => $year]));

                // Verify the endpoint works (even though it clears an empty array cache)
                $response->assertRedirect();
                $response->assertSessionHas('success');

                static::$cacheCleared = true;
            }

            // Single request to get data for all accounts
            // Use skip_overview=1 to avoid expensive all-years overview calculation (not tested here)
            $response = $this->actingAs($user)
                ->get(route('myfinance2::returns.index', [
                    'year' => $year,
                    'skip_overview' => 1,
                ]));

            $response->assertStatus(200);
            $response->assertViewIs('myfinance2::returns.dashboard');
            $response->assertViewHas('returnsData');
            $response->assertViewHas('selectedYear', $year);
            $response->assertViewHas('availableYears');

            static::$returnsData = $response->viewData('returnsData');
        }
    }

    // ========== Data Providers ==========

    /**
     * Provide only real (non-virtual) account keys for structural tests
     *
     * @return array<string, array<int, string>>
     */
    public static function realAccountDataProvider(): array
    {
        try {
            $accountData = static::_getAccountTestData();
            if (empty($accountData)) {
                return ['placeholder' => ['placeholder']];
            }
            $data = [];
            foreach ($accountData as $accountKey => $accountConfig) {
                if (!empty($accountConfig['virtual'])) {
                    continue;
                }
                $testName = strtolower($accountKey);
                $data[$testName] = [$accountKey];
            }
            return !empty($data) ? $data : ['placeholder' => ['placeholder']];
        } catch (\Exception $e) {
            return ['placeholder' => ['placeholder']];
        }
    }

    /**
     * Provide all account keys (including virtual) for return value tests
     *
     * @return array<string, array<int, string>>
     */
    public static function allAccountDataProvider(): array
    {
        try {
            $accountData = static::_getAccountTestData();
            if (empty($accountData)) {
                return ['placeholder' => ['placeholder']];
            }
            $data = [];
            foreach ($accountData as $accountKey => $accountConfig) {
                $testName = strtolower(str_replace('_', ' ', $accountKey));
                $data[$testName] = [$accountKey];
            }
            return $data;
        } catch (\Exception $e) {
            return ['placeholder' => ['placeholder']];
        }
    }

    // ========== Account Info Tests ==========

    #[DataProvider('realAccountDataProvider')]
    public function test_account_info(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = static::_getAccountTestData();
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $accountName = $accountConfig['name'];

        $this->assertArrayHasKey($accountId, static::$returnsData);
        $accountData = static::$returnsData[$accountId];
        $this->assertArrayHasKey('account', $accountData);
        $this->assertEquals($accountName, $accountData['account']->name);
    }

    // ========== Jan1 Values Tests ==========

    #[DataProvider('realAccountDataProvider')]
    public function test_jan1_values(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = static::_getAccountTestData();
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = static::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['jan1Value'],
                $accountData['jan1Value'][$currency]['value'],
                static::_getFloatTolerance(), "Jan1 value mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['jan1PositionsValue'],
                $accountData['jan1PositionsValue'][$currency]['value'], static::_getFloatTolerance(),
                "Jan1 positions value mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['jan1CashValue'],
                $accountData['jan1CashValue'][$currency]['value'],
                static::_getFloatTolerance(), "Jan1 cash value mismatch for $accountKey $currency");
            // Verify that positions + cash = total
            $this->assertEqualsWithDelta(
                $accountData['jan1Value'][$currency]['value'],
                $accountData['jan1PositionsValue'][$currency]['value']
                    + $accountData['jan1CashValue'][$currency]['value'],
                static::_getFloatTolerance(),
                "Jan1 total should equal positions + cash for $accountKey $currency"
            );
        }
    }

    // ========== Dec31 Values Tests ==========

    #[DataProvider('realAccountDataProvider')]
    public function test_dec31_values(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = static::_getAccountTestData();
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = static::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['dec31Value'],
                $accountData['dec31Value'][$currency]['value'],
                static::_getFloatTolerance(), "Dec31 value mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['dec31PositionsValue'],
                $accountData['dec31PositionsValue'][$currency]['value'], static::_getFloatTolerance(),
                "Dec31 positions value mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['dec31CashValue'],
                $accountData['dec31CashValue'][$currency]['value'],
                static::_getFloatTolerance(), "Dec31 cash value mismatch for $accountKey $currency");
            // Verify that positions + cash = total
            $this->assertEqualsWithDelta(
                $accountData['dec31Value'][$currency]['value'],
                $accountData['dec31PositionsValue'][$currency]['value']
                    + $accountData['dec31CashValue'][$currency]['value'],
                static::_getFloatTolerance(),
                "Dec31 total should equal positions + cash for $accountKey $currency"
            );
        }
    }

    // ========== Deposits and Withdrawals Tests ==========

    #[DataProvider('realAccountDataProvider')]
    public function test_deposits_and_withdrawals(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = static::_getAccountTestData();
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = static::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['totalDeposits'],
                $accountData['totalDeposits'][$currency]['value'],
                static::_getFloatTolerance(), "Deposits mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['totalWithdrawals'],
                $accountData['totalWithdrawals'][$currency]['value'], static::_getFloatTolerance(),
                "Withdrawals mismatch for $accountKey $currency");
        }
    }

    // ========== Purchases and Sales Tests ==========

    #[DataProvider('realAccountDataProvider')]
    public function test_purchases_and_sales(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = static::_getAccountTestData();
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = static::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['totalPurchases'],
                $accountData['totalPurchases'][$currency]['value'],
                static::_getFloatTolerance(), "Purchases mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['totalSales'],
                $accountData['totalSales'][$currency]['value'],
                static::_getFloatTolerance(), "Sales mismatch for $accountKey $currency");
        }
    }

    // ========== Purchases and Sales Net Tests ==========

    #[DataProvider('realAccountDataProvider')]
    public function test_purchases_and_sales_net(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = static::_getAccountTestData();
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = static::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['totalPurchasesNet'],
                $accountData['totalPurchasesNet'][$currency]['value'], static::_getFloatTolerance(),
                "Purchases net mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['totalSalesNet'],
                $accountData['totalSalesNet'][$currency]['value'],
                static::_getFloatTolerance(), "Sales net mismatch for $accountKey $currency");
        }
    }

    // ========== Gross Dividends Tests ==========

    #[DataProvider('realAccountDataProvider')]
    public function test_gross_dividends(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = static::_getAccountTestData();
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = static::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['totalGrossDividends'],
                $accountData['totalGrossDividends'][$currency]['value'], static::_getFloatTolerance(),
                "Gross dividends mismatch for $accountKey $currency");
        }
    }

    // ========== Return Value Tests ==========

    #[DataProvider('allAccountDataProvider')]
    public function test_return_value(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = static::_getAccountTestData();
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = static::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['actualReturn'],
                $accountData['actualReturn'][$currency]['value'],
                static::_getFloatTolerance(), "Return value mismatch for $accountKey $currency");
        }
    }

    // ========== Return Formula Tests ==========

    #[DataProvider('realAccountDataProvider')]
    public function test_return_formula(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = static::_getAccountTestData();
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = static::$returnsData[$accountId];

            // Use fee-adjusted deposits/withdrawals (matching the BE formula and FE display):
            // BE formula: deposits - depositFees, withdrawals + withdrawalFees
            // totalDeposits/totalWithdrawals include overrides; fees come from transaction totals
            $depositFees = $accountData['deposits']['totals'][$currency]['fees'] ?? 0;
            $withdrawalFees = $accountData['withdrawals']['totals'][$currency]['fees'] ?? 0;

            $calculatedReturn = $accountData['totalGrossDividends'][$currency]['value']
                + $accountData['dec31Value'][$currency]['value']
                - $accountData['jan1Value'][$currency]['value']
                - ($accountData['totalDeposits'][$currency]['value'] - $depositFees)
                + ($accountData['totalWithdrawals'][$currency]['value'] + $withdrawalFees)
                - ($accountData['totalPurchasesNet'][$currency]['value']
                    ?? $accountData['totalPurchases'][$currency]['value'])
                + ($accountData['totalSalesNet'][$currency]['value']
                    ?? $accountData['totalSales'][$currency]['value']);

            // For accounts with overrides (e.g., in-kind transfers), check against the expected
            // calculated return instead of the actual return (which has been overridden to 0)
            $expectedReturn = isset($currencyData['calculatedReturn'])
                ? $currencyData['calculatedReturn']
                : $accountData['actualReturn'][$currency]['value'];

            $this->assertEqualsWithDelta($expectedReturn, $calculatedReturn,
                static::_getFloatTolerance(), "Return formula mismatch for $accountKey $currency");
        }
    }
}
