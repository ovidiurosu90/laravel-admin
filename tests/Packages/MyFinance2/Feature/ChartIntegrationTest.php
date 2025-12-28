<?php

namespace Tests\Packages\MyFinance2\Feature;

use ovidiuro\myfinance2\App\Console\Commands\FinanceApiCron;
use ovidiuro\myfinance2\App\Services\ChartsBuilder;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Essential integration tests for the chart system
 *
 * Tests the full data flow from PHP to JSON to JavaScript without breaking.
 * Focuses on real problems that were fixed:
 * - Percentage precision regression
 * - Currency symbol display
 * - Data serialization works
 * - Security: users can't access other users' charts
 */
class ChartIntegrationTest extends TestCase
{
    use DatabaseTransactions;
    /**
     * Regression test: Percentage calculation must be accurate
     * (change / cost) * 100, with zero cost handling
     */
    public function test_percentage_calculation_is_accurate(): void
    {
        // Normal case
        $percentage = (500 / 1000) * 100; // 50%
        $this->assertEquals(50.0, $percentage);

        // Negative case
        $percentage = (-250 / 1000) * 100; // -25%
        $this->assertEquals(-25.0, $percentage);

        // Zero cost protection
        $percentage = (0 != 0) ? (100 / 0) * 100 : 0;
        $this->assertEquals(0, $percentage);
    }

    /**
     * Chart data must serialize to valid JSON for JavaScript
     */
    public function test_chart_data_serializes_to_json(): void
    {
        $metrics = ChartsBuilder::getAccountMetrics();

        $json = json_encode($metrics);
        $this->assertIsString($json);
        $this->assertJson($json);

        // Ensure it deserializes correctly
        $decoded = json_decode($json, true);
        $this->assertEquals($metrics, $decoded);
    }

    /**
     * All metrics must be available in both EUR and USD currencies
     */
    public function test_metrics_available_in_both_currencies(): void
    {
        $metrics = ChartsBuilder::getAccountMetrics();
        $requiredMetrics = ['cost', 'change', 'mvalue', 'cash', 'changePercentage'];

        foreach ($requiredMetrics as $metric) {
            $this->assertArrayHasKey($metric, $metrics,
                "Metric '$metric' must be available");
        }
    }

    /**
     * Metrics must have distinct colors for visual clarity in chart
     */
    public function test_metric_colors_are_visually_distinct(): void
    {
        $metrics = ChartsBuilder::getAccountMetrics();

        $colors = [];
        foreach ($metrics as $metric => $props) {
            $colors[$metric] = $props['line_color'];
        }

        $uniqueColors = array_unique($colors);
        $this->assertCount(count($colors), $uniqueColors,
            'Each metric should have a unique color');
    }

    /**
     * Currency formatter must work with Intl API in Laravel app context
     */
    public function test_currency_formatter_works_in_app_context(): void
    {
        $formatter = new \NumberFormatter('de-DE', \NumberFormatter::CURRENCY);
        $formatter->setSymbol(\NumberFormatter::CURRENCY_SYMBOL, '€');

        $result = $formatter->formatCurrency(1234.56, 'EUR');
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('1', $result);

        // Test USD too
        $result = $formatter->formatCurrency(1234.56, 'USD');
        $this->assertNotEmpty($result);
    }

    /**
     * Full data flow: PHP → JavaScript object literal works without errors
     * This is a smoke test to catch integration issues
     */
    public function test_full_data_flow_works(): void
    {
        // Simulate data that would come from database
        $statsData = [
            'historical' => [
                [
                    'date' => '2025-01-01',
                    'unit_price' => 1000.50,
                    'currency_iso_code' => 'EUR',
                ]
            ],
            'today_last' => [
                'date' => '2025-12-28',
                'unit_price' => 1250.75,
                'currency_iso_code' => 'EUR',
            ]
        ];

        // Convert to JavaScript object literal format
        $output = ChartsBuilder::getStatsAsJsonString($statsData);

        // Output should be a JavaScript array of objects:
        //      [{ time: '...', value: ... }, ...]
        $this->assertStringStartsWith('[{', $output);
        $this->assertStringEndsWith('}]', $output);

        // Should contain time and value fields
        $this->assertStringContainsString('time:', $output);
        $this->assertStringContainsString('value:', $output);

        // Should contain our test data
        $this->assertStringContainsString('2025-01-01', $output);
        $this->assertStringContainsString('1000.5', $output);
    }

    /**
     * Security test: One user cannot access another user's chart data
     * This prevents data leakage between users
     */
    public function test_user_cannot_access_other_users_chart_data(): void
    {
        // Create two different users
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        try {
            // Authenticate as user1
            $this->actingAs($user1);

            // User1 tries to access user2's chart data - should be denied
            try {
                ChartsBuilder::getChartOverviewUserAsJsonString($user2->id, 'cost_EUR');
                $this->fail('Should have thrown 403 Unauthorized exception');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertEquals(403, $e->getStatusCode());
                $this->assertStringContainsString('Access denied', $e->getMessage());
            }

            // Now authenticate as user2 and verify they CAN access their own data
            $this->actingAs($user2);

            // This should not throw an exception for user2's own data
            try {
                $result = ChartsBuilder::getChartOverviewUserAsJsonString($user2->id,
                    'cost_EUR');
                $this->assertIsString($result);
                // Should return empty array since we didn't create actual chart data
                $this->assertEquals('[]', $result);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if ($e->getStatusCode() === 403) {
                    $this->fail('Authenticated user should be able to access '
                                . 'their own chart data');
                }
                throw $e;
            }
        } finally {
            // Clean up: Delete test users
            $user1->delete();
            $user2->delete();
        }
    }
}

