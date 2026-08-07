<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Models\Trade;
use ovidiuro\myfinance2\App\Services\HistoricalBackfill;

/**
 * Guard tests for the on-demand historical backfill endpoint behind the chart
 * modal's "Populate historical data" button.
 *
 * Only the rejection paths are covered: every one of them returns before the
 * Yahoo Finance call, so the suite makes no live network request. The happy path
 * is a single-symbol run of the same code the --historical cron uses and is
 * exercised manually (see the package README).
 *
 * A synthetic OPEN BUY trade (symbol TST.*, which cannot exist in production)
 * gives the acting user a known symbol; DatabaseTransactions rolls back both
 * connections so nothing reaches production.
 */
class PopulateHistoricalDataTest extends TestCase
{
    use DatabaseTransactions;

    private const URL     = '/populate-historical-data';
    private const SYMBOL  = 'TST.AAA';
    private const UNKNOWN = 'TST.ZZZ';

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    private ?User $_user = null;

    protected function setUp(): void
    {
        parent::setUp();
        AssignedToUserScope::enable();

        $this->_user = User::first();
        if (!$this->_user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        $this->actingAs($this->_user);
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    private function _holdSymbol(string $symbol): void
    {
        Trade::create([
            'symbol'        => $symbol,
            'action'        => 'BUY',
            'status'        => 'OPEN',
            'quantity'      => '10.00000000',
            'unit_price'    => '100.0000',
            'fee'           => '0.00',
            'exchange_rate' => '1.0000',
            'timestamp'     => now(),
        ]);
    }

    public function test_symbol_is_required(): void
    {
        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('symbol');
    }

    public function test_dates_must_be_iso_formatted(): void
    {
        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->postJson(self::URL, [
                'symbol' => self::SYMBOL,
                'start'  => '01/01/2025',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start');
    }

    public function test_unknown_symbol_is_rejected(): void
    {
        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->postJson(self::URL, ['symbol' => self::UNKNOWN])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Unknown symbol ' . self::UNKNOWN . '!']);
    }

    public function test_known_symbol_with_inverted_range_is_rejected(): void
    {
        $this->_holdSymbol(self::SYMBOL);

        // Passes the known-symbol gate, then fails on the range, so the request
        // still returns without contacting the finance API.
        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->postJson(self::URL, [
                'symbol' => self::SYMBOL,
                'start'  => '2025-06-01',
                'end'    => '2025-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Start date must not be after the end date!',
            ]);
    }

    public function test_default_start_date_is_the_documented_one(): void
    {
        // The chart modal's tooltip and the README both name this date; it is the
        // range the button backfills when the request carries no explicit start.
        $this->assertSame('2025-01-01', HistoricalBackfill::DEFAULT_START_DATE);
    }
}
