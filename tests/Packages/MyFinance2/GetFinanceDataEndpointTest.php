<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2;

use Tests\TestCase;
use App\Models\User;

/**
 * Integration test for the /get-finance-data endpoint
 *
 * This is a real integration test that:
 * - Makes actual HTTP requests to the endpoint
 * - Uses real authentication
 * - Makes real Yahoo Finance API calls (no mocking)
 * - Verifies the response structure and data types
 */
class GetFinanceDataEndpointTest extends TestCase
{
    /**
     * Test get-finance-data endpoint with symbol and timestamp
     *
     * @return void
     */
    public function test_get_finance_data_with_symbol_and_timestamp(): void
    {
        $user = User::first();

        $response = $this->actingAs($user)
            ->getJson('/get-finance-data?symbol=NFLX&timestamp=2025-12-23');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'price',
            'currency',
            'name',
            'quote_timestamp',
            'available_quantity',
            'fiftyTwoWeekHigh',
            'fiftyTwoWeekHighChangePercent',
            'fiftyTwoWeekLow',
            'fiftyTwoWeekLowChangePercent',
        ]);

        $data = $response->json();

        // Verify data types and basic constraints
        $this->assertTrue(
            is_float($data['price']) || is_int($data['price']),
            'Price should be numeric'
        );
        $this->assertGreaterThan(0, $data['price'],
            'Price should be greater than 0');

        $this->assertIsString($data['currency']);
        $this->assertEquals('USD', $data['currency'],
            'NFLX should trade in USD');

        $this->assertIsString($data['name']);
        $this->assertStringContainsString('Netflix', $data['name'],
            'Name should contain "Netflix"');

        $this->assertIsString($data['quote_timestamp']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $data['quote_timestamp'],
            'Timestamp should match yyyy-mm-dd HH:MM:SS format'
        );

        // Verify 52-week high/low values
        $this->assertTrue(
            is_float($data['fiftyTwoWeekHigh']) || is_int($data['fiftyTwoWeekHigh']),
            'fiftyTwoWeekHigh should be numeric'
        );
        $this->assertGreaterThan(0, $data['fiftyTwoWeekHigh']);

        $this->assertTrue(
            is_float($data['fiftyTwoWeekLow']) || is_int($data['fiftyTwoWeekLow']),
            'fiftyTwoWeekLow should be numeric'
        );
        $this->assertGreaterThan(0, $data['fiftyTwoWeekLow']);

        $this->assertGreaterThan($data['fiftyTwoWeekLow'],
            $data['fiftyTwoWeekHigh'],
            '52-week high should be greater than 52-week low');

        // Verify change percentages are numeric
        $this->assertTrue(
            is_float($data['fiftyTwoWeekHighChangePercent']) ||
            is_int($data['fiftyTwoWeekHighChangePercent']),
            'fiftyTwoWeekHighChangePercent should be numeric'
        );
        $this->assertTrue(
            is_float($data['fiftyTwoWeekLowChangePercent']) ||
            is_int($data['fiftyTwoWeekLowChangePercent']),
            'fiftyTwoWeekLowChangePercent should be numeric'
        );
    }

    /**
     * Test get-finance-data endpoint without timestamp
     * (should use current date/latest quote)
     *
     * @return void
     */
    public function test_get_finance_data_without_timestamp(): void
    {
        $user = User::first();

        $response = $this->actingAs($user)
            ->getJson('/get-finance-data?symbol=NFLX');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'price',
            'currency',
            'name',
            'quote_timestamp',
            'available_quantity',
            'fiftyTwoWeekHigh',
            'fiftyTwoWeekHighChangePercent',
            'fiftyTwoWeekLow',
            'fiftyTwoWeekLowChangePercent',
        ]);

        $data = $response->json();

        $this->assertTrue(
            is_float($data['price']) || is_int($data['price']),
            'Price should be numeric'
        );
        $this->assertGreaterThan(0, $data['price']);
        $this->assertEquals('USD', $data['currency']);
        $this->assertStringContainsString('Netflix', $data['name']);
    }
}

