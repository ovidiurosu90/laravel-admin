<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use App\Models\User;

/**
 * Integration tests for the /returns endpoint
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
 * - Expected runtime: ~30 seconds (fetches fresh data from Yahoo Finance API)
 *
 * This isolation is intentional for regression testing - tests should not
 * depend on or affect production cache state.
 */
class ReturnsEndpointRegressionTest extends TestCase
{
    private static ?array $returnsData = null;
    private static ?float $testStartTime = null;
    private static ?string $cachedTestDataProviderClass = null;
    private static bool $classLoaded = false;
    private static bool $cacheCleared = false;

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
    private static function getTestDataProviderClass(): ?string
    {
        // Return cached value if already loaded
        if (self::$classLoaded) {
            return self::$cachedTestDataProviderClass;
        }

        // Try config helper first (works after Laravel bootstrap)
        try {
            $class = config('test-data.providers.returns');
            if (is_string($class) && class_exists($class)) {
                self::$cachedTestDataProviderClass = $class;
                self::$classLoaded = true;
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
                    self::$cachedTestDataProviderClass = $class;
                    self::$classLoaded = true;
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
            self::$cachedTestDataProviderClass = $envClass;
            self::$classLoaded = true;
            return $envClass;
        }

        self::$classLoaded = true; // Mark as "attempted" to avoid repeated failures
        return null;
    }

    /**
     * Check if private test data package is available
     */
    private static function hasPrivateTestData(): bool
    {
        return self::getTestDataProviderClass() !== null;
    }

    /**
     * Get the default test year from private package
     */
    private static function getDefaultTestYear(): int
    {
        $class = self::getTestDataProviderClass();
        if ($class === null) {
            return (int) date('Y');
        }
        return $class::getDefaultTestYear();
    }

    /**
     * Get account test data from private package
     */
    private static function getAccountTestData(int $year): array
    {
        $class = self::getTestDataProviderClass();
        if ($class === null) {
            return [];
        }
        return $class::getAccountTestData($year);
    }

    /**
     * Get float tolerance from private package
     */
    private static function getFloatTolerance(): float
    {
        $class = self::getTestDataProviderClass();
        if ($class === null) {
            return 0.01;
        }
        return $class::getFloatTolerance();
    }

    /**
     * Get max duration from private package
     */
    private static function getMaxDurationSeconds(): int
    {
        $class = self::getTestDataProviderClass();
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
        self::$testStartTime = microtime(true);
    }

    /**
     * Verify all tests completed within the time limit
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$testStartTime !== null) {
            $elapsed = microtime(true) - self::$testStartTime;
            $maxDuration = self::getMaxDurationSeconds();
            $message = sprintf(
                'ReturnsEndpointRegressionTest (year %d) completed in %.2f seconds (limit: %d seconds)',
                self::getDefaultTestYear(),
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
        if (!self::hasPrivateTestData()) {
            $this->markTestSkipped(
                'Private test data package (admin-mydata) is not installed. '
                . 'These regression tests require private account data. '
                . 'If you are a maintainer, install the private admin-mydata package.'
            );
        }

        // Fetch returns data only once (cached in static variable for all test methods)
        if (self::$returnsData === null) {
            $user = User::first();

            // Clear the test's isolated array cache (not production cache)
            // This ensures consistent test behavior regardless of test order
            // Note: PHPUnit uses CACHE_DRIVER=array, so this clears an empty in-memory cache
            if (!self::$cacheCleared) {
                $response = $this->actingAs($user)
                    ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
                    ->post(route('myfinance2::returns.clear-cache', ['year' => self::getDefaultTestYear()]));

                // Verify the endpoint works (even though it clears an empty array cache)
                $response->assertRedirect();
                $response->assertSessionHas('success');

                self::$cacheCleared = true;
            }

            // Single request to get data for all accounts
            $response = $this->actingAs($user)
                ->get(route('myfinance2::returns.index', ['year' => self::getDefaultTestYear()]));

            $response->assertStatus(200);
            $response->assertViewIs('myfinance2::returns.dashboard');
            $response->assertViewHas('returnsData');
            $response->assertViewHas('selectedYear', self::getDefaultTestYear());
            $response->assertViewHas('availableYears');

            self::$returnsData = $response->viewData('returnsData');
        }
    }

    // ========== Data Providers ==========

    /**
     * Provide account keys to test (data is loaded in test methods)
     * When config isn't available during provider evaluation, return a placeholder
     * The test method will skip if private data isn't actually available
     *
     * @return array<string, array<int, string>>
     */
    public static function accountWithCurrenciesDataProvider(): array
    {
        try {
            $accountData = self::getAccountTestData(self::getDefaultTestYear());
            if (empty($accountData)) {
                // Return a placeholder to satisfy PHPUnit (won't have any real test data)
                return ['placeholder' => ['placeholder']];
            }
            $data = [];
            foreach ($accountData as $accountKey => $accountConfig) {
                $testName = strtolower($accountKey);
                $data[$testName] = [$accountKey];
            }
            return $data;
        } catch (\Exception $e) {
            // Return placeholder - test will skip if private data isn't available
            return ['placeholder' => ['placeholder']];
        }
    }

    /**
     * Provide account keys for account info tests
     *
     * @return array<string, array<int, string>>
     */
    public static function accountInfoDataProvider(): array
    {
        try {
            $accountData = self::getAccountTestData(self::getDefaultTestYear());
            if (empty($accountData)) {
                // Return a placeholder to satisfy PHPUnit (won't have any real test data)
                return ['placeholder' => ['placeholder']];
            }
            $data = [];
            foreach ($accountData as $accountKey => $accountConfig) {
                $testName = strtolower($accountKey);
                $data[$testName] = [$accountKey];
            }
            return $data;
        } catch (\Exception $e) {
            // Return placeholder - test will skip if private data isn't available
            return ['placeholder' => ['placeholder']];
        }
    }

    // ========== Account Info Tests ==========

    #[DataProvider('accountInfoDataProvider')]
    public function test_account_info(string $accountKey): void
    {
        // Skip if this is the placeholder test case from data provider fallback
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = self::getAccountTestData(self::getDefaultTestYear());
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $accountName = $accountConfig['name'];

        $this->assertArrayHasKey($accountId, self::$returnsData);
        $accountData = self::$returnsData[$accountId];
        $this->assertArrayHasKey('account', $accountData);
        $this->assertEquals($accountName, $accountData['account']->name);
    }

    // ========== Jan1 Values Tests ==========

    #[DataProvider('accountWithCurrenciesDataProvider')]
    public function test_jan1_values(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = self::getAccountTestData(self::getDefaultTestYear());
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = self::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['jan1Value'],
                $accountData['jan1Value'][$currency]['value'],
                self::getFloatTolerance(), "Jan1 value mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['jan1PositionsValue'],
                $accountData['jan1PositionsValue'][$currency]['value'], self::getFloatTolerance(),
                "Jan1 positions value mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['jan1CashValue'],
                $accountData['jan1CashValue'][$currency]['value'],
                self::getFloatTolerance(), "Jan1 cash value mismatch for $accountKey $currency");
            // Verify that positions + cash = total
            $this->assertEqualsWithDelta(
                $accountData['jan1Value'][$currency]['value'],
                $accountData['jan1PositionsValue'][$currency]['value'] + $accountData['jan1CashValue'][$currency]['value'],
                self::getFloatTolerance(),
                "Jan1 total should equal positions + cash for $accountKey $currency"
            );
        }
    }

    // ========== Dec31 Values Tests ==========

    #[DataProvider('accountWithCurrenciesDataProvider')]
    public function test_dec31_values(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = self::getAccountTestData(self::getDefaultTestYear());
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = self::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['dec31Value'],
                $accountData['dec31Value'][$currency]['value'],
                self::getFloatTolerance(), "Dec31 value mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['dec31PositionsValue'],
                $accountData['dec31PositionsValue'][$currency]['value'], self::getFloatTolerance(),
                "Dec31 positions value mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['dec31CashValue'],
                $accountData['dec31CashValue'][$currency]['value'],
                self::getFloatTolerance(), "Dec31 cash value mismatch for $accountKey $currency");
            // Verify that positions + cash = total
            $this->assertEqualsWithDelta(
                $accountData['dec31Value'][$currency]['value'],
                $accountData['dec31PositionsValue'][$currency]['value'] + $accountData['dec31CashValue'][$currency]['value'],
                self::getFloatTolerance(),
                "Dec31 total should equal positions + cash for $accountKey $currency"
            );
        }
    }

    // ========== Deposits and Withdrawals Tests ==========

    #[DataProvider('accountWithCurrenciesDataProvider')]
    public function test_deposits_and_withdrawals(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = self::getAccountTestData(self::getDefaultTestYear());
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = self::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['totalDeposits'], $accountData['totalDeposits'][$currency]['value'],
                self::getFloatTolerance(), "Deposits mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['totalWithdrawals'],
                $accountData['totalWithdrawals'][$currency]['value'], self::getFloatTolerance(),
                "Withdrawals mismatch for $accountKey $currency");
        }
    }

    // ========== Purchases and Sales Tests ==========

    #[DataProvider('accountWithCurrenciesDataProvider')]
    public function test_purchases_and_sales(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = self::getAccountTestData(self::getDefaultTestYear());
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = self::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['totalPurchases'], $accountData['totalPurchases'][$currency]['value'],
                self::getFloatTolerance(), "Purchases mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['totalSales'], $accountData['totalSales'][$currency]['value'],
                self::getFloatTolerance(), "Sales mismatch for $accountKey $currency");
        }
    }

    // ========== Purchases and Sales Net Tests ==========

    #[DataProvider('accountWithCurrenciesDataProvider')]
    public function test_purchases_and_sales_net(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = self::getAccountTestData(self::getDefaultTestYear());
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = self::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['totalPurchasesNet'],
                $accountData['totalPurchasesNet'][$currency]['value'], self::getFloatTolerance(),
                "Purchases net mismatch for $accountKey $currency");
            $this->assertEqualsWithDelta($currencyData['totalSalesNet'], $accountData['totalSalesNet'][$currency]['value'],
                self::getFloatTolerance(), "Sales net mismatch for $accountKey $currency");
        }
    }

    // ========== Gross Dividends Tests ==========

    #[DataProvider('accountWithCurrenciesDataProvider')]
    public function test_gross_dividends(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = self::getAccountTestData(self::getDefaultTestYear());
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = self::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['totalGrossDividends'],
                $accountData['totalGrossDividends'][$currency]['value'], self::getFloatTolerance(),
                "Gross dividends mismatch for $accountKey $currency");
        }
    }

    // ========== Return Value Tests ==========

    #[DataProvider('accountWithCurrenciesDataProvider')]
    public function test_return_value(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = self::getAccountTestData(self::getDefaultTestYear());
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = self::$returnsData[$accountId];
            $this->assertEqualsWithDelta($currencyData['actualReturn'], $accountData['actualReturn'][$currency]['value'],
                self::getFloatTolerance(), "Return value mismatch for $accountKey $currency");
        }
    }

    // ========== Return Formula Tests ==========

    #[DataProvider('accountWithCurrenciesDataProvider')]
    public function test_return_formula(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        $testData = self::getAccountTestData(self::getDefaultTestYear());
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $currencies = $accountConfig['currencies'];

        foreach ($currencies as $currency => $currencyData) {
            $accountData = self::$returnsData[$accountId];
            $calculatedReturn = $accountData['totalGrossDividends'][$currency]['value']
                + $accountData['dec31Value'][$currency]['value']
                - $accountData['jan1Value'][$currency]['value']
                - $accountData['totalDeposits'][$currency]['value']
                + $accountData['totalWithdrawals'][$currency]['value']
                - ($accountData['totalPurchasesNet'][$currency]['value'] ?? $accountData['totalPurchases'][$currency]['value'])
                + ($accountData['totalSalesNet'][$currency]['value'] ?? $accountData['totalSales'][$currency]['value']);

            // For accounts with overrides (e.g., in-kind transfers), check against the expected calculated return
            // instead of the actual return (which has been overridden to 0)
            $expectedReturn = isset($currencyData['calculatedReturn'])
                ? $currencyData['calculatedReturn']
                : $accountData['actualReturn'][$currency]['value'];

            $this->assertEqualsWithDelta($expectedReturn, $calculatedReturn,
                self::getFloatTolerance(), "Return formula mismatch for $accountKey $currency");
        }
    }

}
