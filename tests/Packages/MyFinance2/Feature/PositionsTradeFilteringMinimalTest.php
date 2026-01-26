<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ovidiuro\myfinance2\App\Models\Trade;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\Positions;

/**
 * Minimal regression test for Positions::getTrades()
 *
 * Background: Jan 16, 2026 - /returns broke /positions by including CLOSED trades
 *
 * Conservative approach: Uses EXISTING production data, creates NO new records.
 * Tests skip if required data (OPEN/CLOSED trades) doesn't exist in database.
 * DatabaseTransactions used as safety net, but no data is actually created or modified.
 */
class PositionsTradeFilteringMinimalTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable user scope for CLI-like test context
        AssignedToUserScope::disable();
    }

    protected function tearDown(): void
    {
        // Re-enable user scope for test isolation
        AssignedToUserScope::enable();
        parent::tearDown();
    }

    /**
     * MUST-HAVE TEST 1: Historical queries exclude CLOSED trades by default
     *
     * This is the exact bug from Jan 16, 2026.
     */
    public function test_historical_excludes_closed_by_default(): void
    {
        // Use existing production data (without user scope for CLI context)
        $existingOpenTrade = Trade::withoutGlobalScopes()->where('status', 'OPEN')->first();
        $existingClosedTrade = Trade::withoutGlobalScopes()->where('status', 'CLOSED')->first();

        // Skip if required data doesn't exist - conservative approach, no test data creation
        if (!$existingOpenTrade || !$existingClosedTrade) {
            $this->markTestSkipped('Requires at least 1 OPEN and 1 CLOSED trade in database');
        }

        // Act: Get historical trades with default behavior
        $positions = new Positions();
        $trades = $positions->getTrades(new \DateTime('2099-12-31')); // Far future date to include all

        // Assert: Should NOT include any CLOSED trades
        $closedCount = $trades->where('status', 'CLOSED')->count();
        $this->assertEquals(0, $closedCount, 'Historical query should exclude CLOSED trades by default');
    }

    /**
     * MUST-HAVE TEST 2: Historical queries include CLOSED when flag is set
     *
     * Returns page needs this behavior.
     */
    public function test_historical_includes_closed_when_flag_set(): void
    {
        // Use existing production data (without user scope for CLI context)
        $existingOpenTrade = Trade::withoutGlobalScopes()->where('status', 'OPEN')->first();
        $existingClosedTrade = Trade::withoutGlobalScopes()->where('status', 'CLOSED')->first();

        // Skip if required data doesn't exist - conservative approach, no test data creation
        if (!$existingOpenTrade || !$existingClosedTrade) {
            $this->markTestSkipped('Requires at least 1 OPEN and 1 CLOSED trade in database');
        }

        // Act: Get historical trades WITH includeClosedTrades flag
        $positions = new Positions();
        $positions->setIncludeClosedTrades(true);
        $trades = $positions->getTrades(new \DateTime('2099-12-31'));

        // Assert: Should include CLOSED trades
        $closedCount = $trades->where('status', 'CLOSED')->count();
        $this->assertGreaterThan(0, $closedCount, 'Historical query with flag should include CLOSED trades');
    }

    /**
     * MUST-HAVE TEST 3: Current queries never include CLOSED
     *
     * No date = current positions only.
     */
    public function test_current_never_includes_closed(): void
    {
        // Act: Get current trades (no date)
        $positions = new Positions();
        $trades = $positions->getTrades();

        // Assert: Should NOT include any CLOSED trades
        $closedCount = $trades->where('status', 'CLOSED')->count();
        $this->assertEquals(0, $closedCount, 'Current query should never include CLOSED trades');
    }
}

