<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use ovidiuro\myfinance2\App\Models\Account;
use ovidiuro\myfinance2\App\Models\Trade;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\Positions;

/**
 * Regression tests for the cost2 bug (May 2026).
 *
 * Bug: updateAccountDataTotal used cost_in_account_currency (net after SELL proceeds)
 * instead of cost2_in_account_currency (original proportional cost of remaining shares).
 *
 * Impact: positions with prior profitable sells appeared more profitable than their
 * actual unrealized P&L, and closing them caused a disproportionately large drop in
 * the User Overview change figure. Prior loss-making sells had the opposite effect.
 *
 * Fix: updateAccountDataTotal now uses overall_change2_in_account_currency and
 * cost2_in_account_currency so total_change always represents unrealized P&L only.
 *
 * Observable symptom (NXPI, May 2026): selling NXPI dropped the overview change by
 * ~€17,000 instead of the expected ~€7,000, because past profitable sells had driven
 * cost_in_account_currency far below the actual cost basis of the remaining shares.
 *
 * Cleanup: DatabaseTransactions wraps every test in a rolled-back transaction across
 * both DB connections, so no test data ever reaches the production database.
 */
class PositionsCost2RegressionTest extends TestCase
{
    use DatabaseTransactions;

    private const TEST_SYMBOL = 'TST.COST2REG'; // varchar(16) limit on trades.symbol

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    private ?Account $_account = null;

    protected function setUp(): void
    {
        parent::setUp();
        AssignedToUserScope::disable();

        $user = User::first();
        if (!$user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        // actingAs so the MyFinance2Model::creating() hook can assign user_id
        $this->actingAs($user);

        $this->_account = Account::withoutGlobalScopes()
            ->where('is_trade_account', 1)
            ->whereHas('currency', fn($q) => $q->where('iso_code', 'EUR'))
            ->first();
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        // forgetGuards resets auth state without triggering a user model save
        Auth::forgetGuards();
        parent::tearDown();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function _createTrade(array $overrides = []): Trade
    {
        if (!$this->_account) {
            $this->markTestSkipped('Requires at least 1 EUR trade account in database');
        }

        return Trade::create(array_merge([
            'symbol'            => self::TEST_SYMBOL,
            'action'            => 'BUY',
            'status'            => 'OPEN',
            'quantity'          => 100,
            'unit_price'        => 50.0,
            'fee'               => 0.0,
            'exchange_rate'     => 1.0,
            'account_id'        => $this->_account->id,
            'trade_currency_id' => $this->_account->currency_id,
            'timestamp'         => now(),
        ], $overrides));
    }

    /**
     * Run the Positions pipeline for the test symbol up to updateAccountDataTotal.
     * Injects a known current price to avoid Yahoo Finance calls.
     *
     * Mirrors the pipeline in Positions::handle() from addMarketValue onward:
     *   addMarketValue → addOverallChange → addCost → addCost2
     *   → addRealizedGain → addOverallChange2 → updateAccountDataTotal
     *
     * @return array{total_cost: float, total_change: float, total_market_value: float}
     */
    private function _runPipeline(float $currentPrice): array
    {
        $trades = Trade::withoutGlobalScopes()
            ->with('accountModel', 'tradeCurrencyModel')
            ->where('symbol', self::TEST_SYMBOL)
            ->where('status', 'OPEN')
            ->orderBy('timestamp')
            ->get();

        $positions = Positions::tradesToPositions($trades);

        if (empty($positions[$this->_account->id][self::TEST_SYMBOL])) {
            return ['total_cost' => 0.0, 'total_change' => 0.0, 'total_market_value' => 0.0];
        }

        $position = &$positions[$this->_account->id][self::TEST_SYMBOL];
        $position['price']         = $currentPrice;
        $position['exchange_rate'] = 1.0; // EUR account + EUR trades: no conversion needed

        Positions::addMarketValue($position);
        Positions::addOverallChange($position);
        Positions::addCost($position);
        Positions::addCost2($position);
        Positions::addRealizedGain($position);
        Positions::addOverallChange2($position);

        $positionAccountData = [
            'total_change'                 => 0.0,
            'total_cost'                   => 0.0,
            'total_market_value'           => 0.0,
            'total_change_formatted'       => '',
            'total_cost_formatted'         => '',
            'total_market_value_formatted' => '',
        ];

        Positions::updateAccountDataTotal($positionAccountData, $position);

        return $positionAccountData;
    }

    // =========================================================================
    // TESTS
    // =========================================================================

    /**
     * Baseline: no prior sells → cost equals cost2.
     * total_cost and total_change are unambiguous regardless of which field is used.
     */
    public function test_no_prior_sells_total_cost_equals_full_buy_cost(): void
    {
        // Buy 100 shares at €50 = €5,000
        $this->_createTrade(['quantity' => 100, 'unit_price' => 50.0]);

        // Current price €80 → MValue = 100 × €80 = €8,000; unrealized gain = €3,000
        $totals = $this->_runPipeline(currentPrice: 80.0);

        $this->assertEqualsWithDelta(5000.0, $totals['total_cost'], 0.01,
            'total_cost should equal the full BUY cost when no sells exist');
        $this->assertEqualsWithDelta(8000.0, $totals['total_market_value'], 0.01);
        $this->assertEqualsWithDelta(3000.0, $totals['total_change'], 0.01,
            'total_change should equal unrealized P&L (MValue - cost basis)');
    }

    /**
     * Core regression: a prior profitable SELL reduces cost_in_account_currency
     * (inflating the apparent gain) but total_cost must still use cost2.
     *
     * Setup:
     *   BUY  100 @ €50  → cost = cost2 = €5,000
     *   SELL  40 @ €100 → cost  = €5,000 − €4,000 = €1,000  (proceeds subtracted)
     *                     cost2 = 60/100 × €5,000 = €3,000  (proportional original cost)
     *   Current price €80, 60 remaining shares → MValue = €4,800
     *
     * Correct unrealized P&L : 60 × (€80 − €50) = €1,800
     * Buggy result (old code) : MValue − cost    = €4,800 − €1,000 = €3,800  ← inflated
     */
    public function test_prior_profitable_sell_does_not_inflate_total_change(): void
    {
        $this->_createTrade([
            'quantity'   => 100,
            'unit_price' => 50.0,
            'timestamp'  => now()->subDays(10),
        ]);
        $this->_createTrade([
            'action'     => 'SELL',
            'quantity'   => 40,
            'unit_price' => 100.0,
            'timestamp'  => now()->subDays(5),
        ]);

        $totals = $this->_runPipeline(currentPrice: 80.0);

        $this->assertEqualsWithDelta(3000.0, $totals['total_cost'], 0.01,
            'total_cost must use cost2 (€3,000), not the net-after-sell cost (€1,000)');
        $this->assertEqualsWithDelta(4800.0, $totals['total_market_value'], 0.01);
        $this->assertEqualsWithDelta(1800.0, $totals['total_change'], 0.01,
            'total_change must equal unrealized P&L (€1,800), not inflated total return (€3,800)');
    }

    /**
     * Prior loss-making sell: proceeds are less than the cost of sold shares,
     * which drives cost_in_account_currency above cost2.
     * total_cost must still use cost2 — the correct basis for remaining shares.
     *
     * Setup:
     *   BUY  100 @ €80  → cost = cost2 = €8,000
     *   SELL  40 @ €50  → cost  = €8,000 − €2,000 = €6,000  (less proceeds subtracted)
     *                     cost2 = 60/100 × €8,000 = €4,800
     *   Current price €60, 60 remaining shares → MValue = €3,600
     *
     * Correct unrealized P&L : 60 × (€60 − €80) = −€1,200
     * Buggy result (old code) : MValue − cost    = €3,600 − €6,000 = −€2,400  ← worse than reality
     */
    public function test_prior_loss_making_sell_does_not_deflate_total_change(): void
    {
        $this->_createTrade([
            'quantity'   => 100,
            'unit_price' => 80.0,
            'timestamp'  => now()->subDays(10),
        ]);
        $this->_createTrade([
            'action'     => 'SELL',
            'quantity'   => 40,
            'unit_price' => 50.0,
            'timestamp'  => now()->subDays(5),
        ]);

        $totals = $this->_runPipeline(currentPrice: 60.0);

        $this->assertEqualsWithDelta(4800.0, $totals['total_cost'], 0.01,
            'total_cost must use cost2 (€4,800), not the inflated net cost (€6,000)');
        $this->assertEqualsWithDelta(3600.0, $totals['total_market_value'], 0.01);
        $this->assertEqualsWithDelta(-1200.0, $totals['total_change'], 0.01,
            'total_change must equal unrealized P&L (−€1,200), not deflated value (−€2,400)');
    }

    /**
     * Multiple buy lots: cost2 proportionally scales across all lots combined.
     *
     * Setup:
     *   BUY   60 @ €40 = €2,400
     *   BUY   40 @ €60 = €2,400  → total cost = cost2 = €4,800
     *   SELL  50 @ €80 = €4,000 proceeds → cost  = €4,800 − €4,000 = €800
     *                                       cost2 = 50/100 × €4,800 = €2,400
     *   Current price €70, 50 remaining shares → MValue = €3,500
     *
     * Correct unrealized P&L: MValue − cost2 = €3,500 − €2,400 = €1,100
     */
    public function test_multiple_buy_lots_cost2_scales_proportionally(): void
    {
        $this->_createTrade([
            'quantity'   => 60,
            'unit_price' => 40.0,
            'timestamp'  => now()->subDays(20),
        ]);
        $this->_createTrade([
            'quantity'   => 40,
            'unit_price' => 60.0,
            'timestamp'  => now()->subDays(15),
        ]);
        $this->_createTrade([
            'action'     => 'SELL',
            'quantity'   => 50,
            'unit_price' => 80.0,
            'timestamp'  => now()->subDays(5),
        ]);

        $totals = $this->_runPipeline(currentPrice: 70.0);

        $this->assertEqualsWithDelta(2400.0, $totals['total_cost'], 0.01,
            'total_cost must be proportional cost2 of remaining 50 shares (€2,400)');
        $this->assertEqualsWithDelta(3500.0, $totals['total_market_value'], 0.01);
        $this->assertEqualsWithDelta(1100.0, $totals['total_change'], 0.01,
            'total_change must equal unrealized P&L (€1,100)');
    }
}
