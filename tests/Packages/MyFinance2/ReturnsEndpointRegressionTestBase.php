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
    // Subclasses MUST redeclare these 10 static properties for isolation:
    protected static ?array $returnsData = null;
    protected static ?string $responseHtml = null;
    protected static ?array $returnsDataDwOff = null;
    protected static ?string $responseHtmlDwOff = null;
    protected static ?array $returnsDataCashOff = null;
    protected static ?string $responseHtmlCashOff = null;
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

        // Fetch all response variants once — cached in static variables for all test methods.
        // The first (default) request hits Yahoo Finance (~30s). Subsequent toggle variants
        // use the populated array cache and complete in milliseconds.
        $needsFetch = static::$returnsData === null
            || static::$returnsDataDwOff === null
            || static::$returnsDataCashOff === null;

        if ($needsFetch) {
            $user = User::first();

            if (static::$returnsData === null) {
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
                static::$responseHtml = $response->getContent();
            }

            if (static::$returnsDataDwOff === null) {
                $response = $this->actingAs($user)
                    ->get(route('myfinance2::returns.index', [
                        'year' => $year,
                        'skip_overview' => 1,
                        'exclude_deposits_withdrawals' => 1,
                    ]));
                $response->assertStatus(200);
                static::$returnsDataDwOff = $response->viewData('returnsData');
                static::$responseHtmlDwOff = $response->getContent();
            }

            if (static::$returnsDataCashOff === null) {
                $response = $this->actingAs($user)
                    ->get(route('myfinance2::returns.index', [
                        'year' => $year,
                        'skip_overview' => 1,
                        'exclude_cash' => 1,
                    ]));
                $response->assertStatus(200);
                static::$returnsDataCashOff = $response->viewData('returnsData');
                static::$responseHtmlCashOff = $response->getContent();
            }
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

        $assertionsMade = false;
        foreach ($currencies as $currency => $currencyData) {
            $accountData = static::$returnsData[$accountId];

            // Use adjustedTotal (principal ± fees) to match what the FE main row displays.
            // Fall back to totalDeposits/totalWithdrawals value when an override is active,
            // since the override replaces the calculated total and fees don't apply on top.
            if ($currencyData['totalDeposits'] !== null) {
                $hasDepositsOverride = !empty($accountData['totalDepositsOverride'][$currency]);
                $expectedDeposits = $hasDepositsOverride
                    ? $accountData['totalDeposits'][$currency]['value']
                    : $accountData['deposits']['totals'][$currency]['adjustedTotal'];
                $this->assertEqualsWithDelta($currencyData['totalDeposits'],
                    $expectedDeposits,
                    static::_getFloatTolerance(), "Deposits mismatch for $accountKey $currency");
                $assertionsMade = true;
            }

            if ($currencyData['totalWithdrawals'] !== null) {
                $hasWithdrawalsOverride = !empty($accountData['totalWithdrawalsOverride'][$currency]);
                $expectedWithdrawals = $hasWithdrawalsOverride
                    ? $accountData['totalWithdrawals'][$currency]['value']
                    : $accountData['withdrawals']['totals'][$currency]['adjustedTotal'];
                $this->assertEqualsWithDelta($currencyData['totalWithdrawals'],
                    $expectedWithdrawals,
                    static::_getFloatTolerance(), "Withdrawals mismatch for $accountKey $currency");
                $assertionsMade = true;
            }
        }
        if (!$assertionsMade) {
            $this->addToAssertionCount(1); // all values intentionally null — skipped
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

        $assertionsMade = false;
        foreach ($currencies as $currency => $currencyData) {
            $accountData = static::$returnsData[$accountId];

            // null means "skip" — gross principal is a detail-drilldown subtotal, not the FE
            // main row (which shows totalPurchasesNet/totalSalesNet, tested separately).
            if ($currencyData['totalPurchases'] !== null) {
                $this->assertEqualsWithDelta($currencyData['totalPurchases'],
                    $accountData['totalPurchases'][$currency]['value'],
                    static::_getFloatTolerance(), "Purchases mismatch for $accountKey $currency");
                $assertionsMade = true;
            }
            if ($currencyData['totalSales'] !== null) {
                $this->assertEqualsWithDelta($currencyData['totalSales'],
                    $accountData['totalSales'][$currency]['value'],
                    static::_getFloatTolerance(), "Sales mismatch for $accountKey $currency");
                $assertionsMade = true;
            }
        }
        if (!$assertionsMade) {
            $this->addToAssertionCount(1); // all values intentionally null — skipped
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

    // ========== Purchases and Sales Display Tests ==========

    /**
     * Verify the rendered HTML uses totalPurchasesNet/totalSalesNet (not the gross totals)
     * for the summary row data-eur attribute.
     *
     * This catches blade template bugs where the wrong data key is used for display
     * (e.g., totalPurchases instead of totalPurchasesNet), which are invisible to
     * tests that only check view data values.
     */
    #[DataProvider('realAccountDataProvider')]
    public function test_purchases_and_sales_display_uses_net_values(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        if (static::$responseHtml === null) {
            $this->markTestSkipped('HTML response not available');
        }

        $testData = static::_getAccountTestData();
        $accountConfig = $testData[$accountKey];
        $accountId = $accountConfig['id'];
        $accountData = static::$returnsData[$accountId];
        $html = static::$responseHtml;

        $assertionsMade = false;

        $purchasesNet = $accountData['totalPurchasesNet']['EUR']['value'];
        $purchasesGross = $accountData['totalPurchases']['EUR']['value'];
        if (abs($purchasesNet - $purchasesGross) > static::_getFloatTolerance()) {
            $netFormatted = static::_encodeForAttr($accountData['totalPurchasesNet']['EUR']['formatted']);
            $this->assertStringContainsString(
                'data-eur="' . $netFormatted . '"',
                $html,
                "Purchases summary row for $accountKey must display totalPurchasesNet in data-eur"
            );
            $assertionsMade = true;
        }

        $salesNet = $accountData['totalSalesNet']['EUR']['value'];
        $salesGross = $accountData['totalSales']['EUR']['value'];
        if (abs($salesNet - $salesGross) > static::_getFloatTolerance()) {
            $netFormatted = static::_encodeForAttr($accountData['totalSalesNet']['EUR']['formatted']);
            $this->assertStringContainsString(
                'data-eur="' . $netFormatted . '"',
                $html,
                "Sales summary row for $accountKey must display totalSalesNet in data-eur"
            );
            $assertionsMade = true;
        }

        if (!$assertionsMade) {
            $this->addToAssertionCount(1); // gross == net (no fees), no display difference to verify
        }
    }

    #[DataProvider('realAccountDataProvider')]
    public function test_deposits_and_withdrawals_display_uses_adjusted_totals(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        if (static::$responseHtml === null) {
            $this->markTestSkipped('HTML response not available');
        }

        $testData = static::_getAccountTestData();
        $accountId = $testData[$accountKey]['id'];
        $accountData = static::$returnsData[$accountId];
        $html = static::$responseHtml;

        $hasDepositFees = ($accountData['deposits']['totals']['EUR']['fees'] ?? 0) > 0;
        $expectedDepositsEur = static::_encodeForAttr(isset($accountData['totalDepositsOverride']['EUR'])
            ? $accountData['totalDepositsOverride']['EUR']['overrideFormatted']
            : ($hasDepositFees
                ? $accountData['deposits']['totals']['EUR']['adjustedFormatted']
                : $accountData['deposits']['totals']['EUR']['formatted']));
        $this->assertStringContainsString(
            'data-eur="' . $expectedDepositsEur . '"',
            $html,
            "Deposits summary row for $accountKey must display adjusted total in data-eur"
        );

        $hasWithdrawalFees = ($accountData['withdrawals']['totals']['EUR']['fees'] ?? 0) > 0;
        $expectedWithdrawalsEur = static::_encodeForAttr(isset($accountData['totalWithdrawalsOverride']['EUR'])
            ? $accountData['totalWithdrawalsOverride']['EUR']['overrideFormatted']
            : ($hasWithdrawalFees
                ? $accountData['withdrawals']['totals']['EUR']['adjustedFormatted']
                : $accountData['withdrawals']['totals']['EUR']['formatted']));
        $this->assertStringContainsString(
            'data-eur="' . $expectedWithdrawalsEur . '"',
            $html,
            "Withdrawals summary row for $accountKey must display adjusted total in data-eur"
        );
    }

    #[DataProvider('realAccountDataProvider')]
    public function test_start_end_value_display_uses_correct_values(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        if (static::$responseHtml === null) {
            $this->markTestSkipped('HTML response not available');
        }

        $testData = static::_getAccountTestData();
        $accountId = $testData[$accountKey]['id'];
        $accountData = static::$returnsData[$accountId];
        $html = static::$responseHtml;

        $this->assertStringContainsString(
            'data-eur="' . static::_encodeForAttr($accountData['dec31Value']['EUR']['formatted']) . '"',
            $html,
            "End value row for $accountKey must display dec31Value in data-eur"
        );
        $this->assertStringContainsString(
            'data-eur="' . static::_encodeForAttr($accountData['jan1Value']['EUR']['formatted']) . '"',
            $html,
            "Start value row for $accountKey must display jan1Value in data-eur"
        );
    }

    #[DataProvider('realAccountDataProvider')]
    public function test_dividends_display_uses_correct_value(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        if (static::$responseHtml === null) {
            $this->markTestSkipped('HTML response not available');
        }

        $testData = static::_getAccountTestData();
        $accountId = $testData[$accountKey]['id'];
        $accountData = static::$returnsData[$accountId];
        $html = static::$responseHtml;

        if (($accountData['totalGrossDividends']['EUR']['value'] ?? 0) < static::_getFloatTolerance()) {
            $this->addToAssertionCount(1); // no dividends — no meaningful display value to check
            return;
        }

        $this->assertStringContainsString(
            'data-eur="' . static::_encodeForAttr($accountData['dividends']['totals']['EUR']['formatted']) . '"',
            $html,
            "Dividends row for $accountKey must display dividends totals formatted in data-eur"
        );
    }

    #[DataProvider('realAccountDataProvider')]
    public function test_return_value_display_uses_correct_value(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        if (static::$responseHtml === null) {
            $this->markTestSkipped('HTML response not available');
        }

        $testData = static::_getAccountTestData();
        $accountId = $testData[$accountKey]['id'];
        $accountData = static::$returnsData[$accountId];
        $html = static::$responseHtml;

        // Return row uses ['plain'] (absolute value + symbol, no sign/color HTML)
        $this->assertStringContainsString(
            'data-eur="' . $accountData['actualReturn']['EUR']['plain'] . '"',
            $html,
            "Return row for $accountKey must display actualReturn plain value in data-eur"
        );
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

            $depositFees = $accountData['deposits']['totals'][$currency]['fees'] ?? 0;
            $withdrawalFees = $accountData['withdrawals']['totals'][$currency]['fees'] ?? 0;
            $depositsForFormula = isset($accountData['totalDepositsOverride'][$currency])
                ? $accountData['totalDeposits'][$currency]['value']
                : $accountData['totalDeposits'][$currency]['value'] - $depositFees;
            $withdrawalsForFormula = isset($accountData['totalWithdrawalsOverride'][$currency])
                ? $accountData['totalWithdrawals'][$currency]['value']
                : $accountData['totalWithdrawals'][$currency]['value'] + $withdrawalFees;

            $calculatedReturn = $accountData['totalGrossDividends'][$currency]['value']
                + $accountData['dec31Value'][$currency]['value']
                - $accountData['jan1Value'][$currency]['value']
                - $depositsForFormula
                + $withdrawalsForFormula
                - ($accountData['totalPurchasesNet'][$currency]['value']
                    ?? $accountData['totalPurchases'][$currency]['value'])
                - ($accountData['totalTransferDeposits'][$currency]['value'] ?? 0)
                + ($accountData['totalSalesNet'][$currency]['value']
                    ?? $accountData['totalSales'][$currency]['value'])
                + ($accountData['totalTransferWithdrawals'][$currency]['value'] ?? 0);

            // For accounts with overrides (e.g., in-kind transfers), check against the expected
            // calculated return instead of the actual return (which has been overridden to 0)
            $expectedReturn = isset($currencyData['calculatedReturn'])
                ? $currencyData['calculatedReturn']
                : $accountData['actualReturn'][$currency]['value'];

            $this->assertEqualsWithDelta($expectedReturn, $calculatedReturn,
                static::_getFloatTolerance(), "Return formula mismatch for $accountKey $currency");
        }
    }

    // ========== Toggle Tests (D&W Off) ==========

    /**
     * Verify the return formula when Deposits & Withdrawals are toggled off.
     * D&W are excluded entirely — only end/start values, trades, and dividends count.
     */
    #[DataProvider('realAccountDataProvider')]
    public function test_dw_off_return_formula(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        if (static::$returnsDataDwOff === null) {
            $this->markTestSkipped('D&W-off response not available');
        }

        $testData = static::_getAccountTestData();
        $accountId = $testData[$accountKey]['id'];
        $defaultData = static::$returnsData[$accountId];
        $dwOffData = static::$returnsDataDwOff[$accountId];

        foreach (['EUR', 'USD'] as $currency) {
            $calculatedReturn = $defaultData['totalGrossDividends'][$currency]['value']
                + $defaultData['dec31Value'][$currency]['value']
                - $defaultData['jan1Value'][$currency]['value']
                - ($defaultData['totalPurchasesNet'][$currency]['value']
                    ?? $defaultData['totalPurchases'][$currency]['value'])
                - ($defaultData['totalTransferDeposits'][$currency]['value'] ?? 0)
                + ($defaultData['totalSalesNet'][$currency]['value']
                    ?? $defaultData['totalSales'][$currency]['value'])
                + ($defaultData['totalTransferWithdrawals'][$currency]['value'] ?? 0);

            $this->assertEqualsWithDelta($calculatedReturn,
                $dwOffData['actualReturn'][$currency]['value'],
                static::_getFloatTolerance(),
                "D&W-off return formula mismatch for $accountKey $currency");
        }
    }

    /**
     * Verify the rendered HTML for D&W-off shows the correct return total in data-eur,
     * and that D&W amounts are still displayed (values unchanged, just excluded from formula).
     */
    #[DataProvider('realAccountDataProvider')]
    public function test_dw_off_display(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        if (static::$returnsDataDwOff === null || static::$responseHtmlDwOff === null) {
            $this->markTestSkipped('D&W-off response not available');
        }

        $testData = static::_getAccountTestData();
        $accountId = $testData[$accountKey]['id'];
        $defaultData = static::$returnsData[$accountId];
        $dwOffData = static::$returnsDataDwOff[$accountId];
        $html = static::$responseHtmlDwOff;

        // Return row must reflect the D&W-off calculation
        $this->assertStringContainsString(
            'data-eur="' . $dwOffData['actualReturn']['EUR']['plain'] . '"',
            $html,
            "Return row for $accountKey must display D&W-off actualReturn in data-eur"
        );

        // D&W amounts must still be rendered (toggling only affects the formula, not visibility)
        $hasDepositFees = ($defaultData['deposits']['totals']['EUR']['fees'] ?? 0) > 0;
        $expectedDepositsEur = static::_encodeForAttr(isset($defaultData['totalDepositsOverride']['EUR'])
            ? $defaultData['totalDepositsOverride']['EUR']['overrideFormatted']
            : ($hasDepositFees
                ? $defaultData['deposits']['totals']['EUR']['adjustedFormatted']
                : $defaultData['deposits']['totals']['EUR']['formatted']));
        $this->assertStringContainsString(
            'data-eur="' . $expectedDepositsEur . '"',
            $html,
            "Deposits row for $accountKey must still be rendered when D&W is off"
        );

        $hasWithdrawalFees = ($defaultData['withdrawals']['totals']['EUR']['fees'] ?? 0) > 0;
        $expectedWithdrawalsEur = static::_encodeForAttr(isset($defaultData['totalWithdrawalsOverride']['EUR'])
            ? $defaultData['totalWithdrawalsOverride']['EUR']['overrideFormatted']
            : ($hasWithdrawalFees
                ? $defaultData['withdrawals']['totals']['EUR']['adjustedFormatted']
                : $defaultData['withdrawals']['totals']['EUR']['formatted']));
        $this->assertStringContainsString(
            'data-eur="' . $expectedWithdrawalsEur . '"',
            $html,
            "Withdrawals row for $accountKey must still be rendered when D&W is off"
        );
    }

    // ========== Toggle Tests (Cash Off) ==========

    /**
     * Verify the rendered HTML for Cash-off shows positions-only values in
     * the start/end value data-eur attributes (cash excluded).
     */
    #[DataProvider('realAccountDataProvider')]
    public function test_cash_off_display_uses_positions_values(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        if (static::$returnsDataCashOff === null || static::$responseHtmlCashOff === null) {
            $this->markTestSkipped('Cash-off response not available');
        }

        $testData = static::_getAccountTestData();
        $accountId = $testData[$accountKey]['id'];
        $defaultData = static::$returnsData[$accountId];
        $cashOffData = static::$returnsDataCashOff[$accountId];
        $html = static::$responseHtmlCashOff;

        // End value row must display positions-only value (not total incl. cash)
        $this->assertStringContainsString(
            'data-eur="' . static::_encodeForAttr($defaultData['dec31PositionsValue']['EUR']['formatted']) . '"',
            $html,
            "End value row for $accountKey must display dec31PositionsValue in data-eur when cash is off"
        );

        // Start value row must display positions-only value
        $this->assertStringContainsString(
            'data-eur="' . static::_encodeForAttr($defaultData['jan1PositionsValue']['EUR']['formatted']) . '"',
            $html,
            "Start value row for $accountKey must display jan1PositionsValue in data-eur when cash is off"
        );

        // Return row must reflect the cash-off calculation
        $this->assertStringContainsString(
            'data-eur="' . $cashOffData['actualReturn']['EUR']['plain'] . '"',
            $html,
            "Return row for $accountKey must display cash-off actualReturn in data-eur"
        );
    }

    /**
     * Verify the return formula when Cash is toggled off.
     * Positions-only values replace the full start/end portfolio values.
     */
    #[DataProvider('realAccountDataProvider')]
    public function test_cash_off_return_formula(string $accountKey): void
    {
        if ($accountKey === 'placeholder') {
            $this->markTestSkipped('Private test data package not available');
        }

        if (static::$returnsDataCashOff === null) {
            $this->markTestSkipped('Cash-off response not available');
        }

        $testData = static::_getAccountTestData();
        $accountId = $testData[$accountKey]['id'];
        $defaultData = static::$returnsData[$accountId];
        $cashOffData = static::$returnsDataCashOff[$accountId];

        foreach (['EUR', 'USD'] as $currency) {
            $depositFees = $defaultData['deposits']['totals'][$currency]['fees'] ?? 0;
            $withdrawalFees = $defaultData['withdrawals']['totals'][$currency]['fees'] ?? 0;
            $depositsForFormula = isset($defaultData['totalDepositsOverride'][$currency])
                ? $defaultData['totalDeposits'][$currency]['value']
                : $defaultData['totalDeposits'][$currency]['value'] - $depositFees;
            $withdrawalsForFormula = isset($defaultData['totalWithdrawalsOverride'][$currency])
                ? $defaultData['totalWithdrawals'][$currency]['value']
                : $defaultData['totalWithdrawals'][$currency]['value'] + $withdrawalFees;

            $calculatedReturn = $defaultData['totalGrossDividends'][$currency]['value']
                + $defaultData['dec31PositionsValue'][$currency]['value']
                - $defaultData['jan1PositionsValue'][$currency]['value']
                - $depositsForFormula
                + $withdrawalsForFormula
                - ($defaultData['totalPurchasesNet'][$currency]['value']
                    ?? $defaultData['totalPurchases'][$currency]['value'])
                - ($defaultData['totalTransferDeposits'][$currency]['value'] ?? 0)
                + ($defaultData['totalSalesNet'][$currency]['value']
                    ?? $defaultData['totalSales'][$currency]['value'])
                + ($defaultData['totalTransferWithdrawals'][$currency]['value'] ?? 0);

            $this->assertEqualsWithDelta($calculatedReturn,
                $cashOffData['actualReturn'][$currency]['value'],
                static::_getFloatTolerance(),
                "Cash-off return formula mismatch for $accountKey $currency");
        }
    }

    // ========== Helpers ==========

    /**
     * Encode a formatted value the same way Blade's {{ }} does, so it can be
     * searched inside an HTML attribute (e.g. data-eur="...").
     * Formatted values from MoneyFormat contain HTML tags that Blade escapes.
     */
    protected static function _encodeForAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
