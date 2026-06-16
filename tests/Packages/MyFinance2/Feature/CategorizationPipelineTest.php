<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ovidiuro\myfinance2\App\Models\Account;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\CategorizationService;
use ovidiuro\myfinance2\App\Services\TierCalculationService;
use ovidiuro\myfinance2\App\Services\TierDecision;

/**
 * End-to-end test of the categorisation pipeline, the seam the unit tests cannot cover:
 *
 *   trades + stats_historical
 *     -> SymbolPerformanceService (own CAGR)  +  DrawdownService (same-window VUSA CAGR)
 *     -> TierClassifier  ->  QuadrantClassifier
 *     -> CategorizationService::build() entry consumed by the views.
 *
 * It guards against contract drift between those well-unit-tested pieces: missing keys,
 * a mislabelled basis, ownership not flowing through, or the alpha being assembled from
 * the wrong pair of figures.
 *
 * Isolation (and why the test is fast): the synthetic trade is inserted under a user_id that
 * owns no real trades, and build() is run for that id, so the pipeline walks only that one
 * symbol plus the read-only VUSA.AS benchmark instead of a real portfolio. With the symbol's
 * own history seeded, DrawdownService does not fall back to HistoricalPriceCache for it, which
 * would otherwise make a live Yahoo Finance request (the test cache driver is "array", so it is
 * always cold). Running over a real portfolio fires dozens of those requests and takes ~30s.
 *
 * Production-database safety: tests here run against the real database (the base TestCase does
 * not refresh it). This test never saves a User and never inserts or updates a real row: it
 * writes only a stats_historical row and a trade for the synthetic symbol TST.CATPIPE (which
 * cannot exist in production) under an unused user_id, both via the query builder, and reads
 * VUSA.AS without touching it. DatabaseTransactions rolls back both connections afterwards, so
 * nothing persists. (We follow the OrdersFeatureTest pattern of acting as an existing user
 * without ever writing to it, and forgetGuards on teardown to avoid a stray User-model save.)
 *
 * Determinism: the synthetic symbol's tier is fixed entirely by its seeded entry price and
 * latest price, independent of any real data. The alpha assertion checks how the figure is
 * assembled (own CAGR minus the same-window VUSA CAGR) rather than an exact benchmark value,
 * since the VUSA series is whatever the database already holds.
 */
class CategorizationPipelineTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'TST.CATPIPE'; // varchar(16) limit on stats_historical.symbol

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    private ?Account $_account = null;
    private string $_conn = '';
    private int $_userId = 0;
    private string $_entryDate = '';
    private string $_today = '';

    protected function setUp(): void
    {
        parent::setUp();
        AssignedToUserScope::disable();

        $this->_conn = config('myfinance2.db_connection', 'myfinance2_mysql');

        // Act as a real user (read-only) so any incidental auth() call resolves, but never write
        // to the User model; forgetGuards on teardown avoids a stray save (per OrdersFeatureTest).
        $user = User::first();
        if (!$user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        $this->actingAs($user);

        $this->_account = Account::withoutGlobalScope(AssignedToUserScope::class)
            ->where('is_trade_account', 1)
            ->whereHas('currency', fn($q) => $q->where('iso_code', 'EUR'))
            ->first();
        if (!$this->_account) {
            $this->markTestSkipped('Requires at least 1 EUR trade account in database');
        }

        // A user_id that owns no real trades, so build() walks only the seeded synthetic symbol.
        $this->_userId = (int) DB::connection($this->_conn)->table('trades')->max('user_id') + 1;

        $this->_entryDate = now()->subYears(2)->format('Y-m-d');
        $this->_today     = now()->format('Y-m-d');
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Insert a row via the query builder (not Eloquent): the base MyFinance2Model creating hook
     * assigns user_id from auth and stats_historical has no user_id column, while trades must be
     * written under the unused $_userId rather than the acting user. Both tables are on the
     * myfinance2 connection and inside its rolled-back transaction.
     */
    private function _insert(string $table, array $values): void
    {
        DB::connection($this->_conn)->table($table)->insert($values);
    }

    /**
     * One open holding entered two years ago at 100, now worth 160: +60% raw, ~26.5%/yr CAGR.
     * The entry and latest prices are seeded, so the symbol's own return is fully deterministic
     * and independent of any real market data. The VUSA.AS benchmark is read from whatever the
     * database already holds (never written here). Everything is for the synthetic symbol under
     * the unused user_id, so no real row is touched.
     */
    private function _seedOpenWinner(float $latestPrice = 160.0): void
    {
        $now = now();

        // trades.user_id has a cross-database FK to the admin users table, and the unused id has
        // no matching user. We deliberately drop FK enforcement for this one synthetic insert
        // (session-scoped, restored immediately) since the row is rolled back and never persists;
        // a real user cannot be referenced without either polluting its portfolio or saving a User.
        Schema::connection($this->_conn)->withoutForeignKeyConstraints(function () use ($now) {
            $this->_insert('trades', [
                'user_id'           => $this->_userId,
                'symbol'            => self::SYMBOL,
                'action'            => 'BUY',
                'status'            => 'OPEN',
                'quantity'          => 10,
                'unit_price'        => 100.0,
                'fee'               => 0.0,
                'exchange_rate'     => 1.0,
                'account_id'        => $this->_account->id,
                'trade_currency_id' => $this->_account->currency_id,
                'timestamp'         => $this->_entryDate . ' 10:00:00',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        });

        foreach ([[$this->_entryDate, 100.0], [$this->_today, $latestPrice]] as [$date, $price]) {
            $this->_insert('stats_historical', [
                'date'              => $date,
                'symbol'            => self::SYMBOL,
                'unit_price'        => $price,
                'currency_iso_code' => 'EUR',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }
    }

    private function _entryFor(string $symbol): array
    {
        $result = (new CategorizationService())->build($this->_userId);
        $this->assertArrayHasKey($symbol, $result, 'Pipeline produced no entry for the seeded symbol');
        return $result[$symbol];
    }

    // =========================================================================
    // TESTS
    // =========================================================================

    public function test_owned_winner_is_classified_platinum_on_annualized_return(): void
    {
        $this->_seedOpenWinner();

        $entry = $this->_entryFor(self::SYMBOL);

        // Ownership flows from the open trade window all the way through.
        $this->assertTrue($entry['is_owned'], 'An open holding must be reported as owned');

        // Held two years at +60%, the tier is decided by the annualized CAGR (~26.5%/yr),
        // not the raw return, and lands in the top tier.
        $this->assertSame(TierCalculationService::PLATINUM, $entry['effective_tier']);
        $this->assertSame(TierDecision::BASIS_ANNUALIZED_RETURN, $entry['basis']);
        $this->assertEqualsWithDelta(26.5, $entry['basis_value'], 2.0);
    }

    public function test_alpha_is_own_cagr_minus_same_window_benchmark_cagr(): void
    {
        $this->_seedOpenWinner();

        $entry = $this->_entryFor(self::SYMBOL);

        // Own CAGR is deterministic from the seeded prices (~26.5%/yr).
        $this->assertEqualsWithDelta(26.5, $entry['annualized_pct'], 2.0);

        // The benchmark series is read from existing data; if it does not cover the two-year span
        // there is nothing to compare against, so skip rather than assert against absent data.
        if ($entry['vusa_same_window_pct'] === null) {
            $this->markTestSkipped('VUSA.AS history does not cover the holding span in this database');
        }

        // Full-year hold => the alpha is the annualized (not short-period raw) difference, and it
        // is exactly own CAGR minus the same-window benchmark CAGR. This guards the assembly seam
        // without depending on the benchmark's exact value.
        $this->assertFalse($entry['alpha_is_short_period']);
        $this->assertNotNull($entry['alpha_vs_vusa_pct']);
        $this->assertEqualsWithDelta(
            $entry['annualized_pct'] - $entry['vusa_same_window_pct'],
            $entry['alpha_vs_vusa_pct'],
            0.01
        );
    }

    public function test_entry_exposes_the_full_view_contract(): void
    {
        $this->_seedOpenWinner();

        $entry = $this->_entryFor(self::SYMBOL);

        // Keys every view (table, health card, quadrant chart) reads. A rename in the pipeline
        // that the views were not updated for would surface here.
        foreach ([
            'effective_tier', 'basis', 'basis_label', 'basis_value', 'confidence', 'is_owned',
            'quadrant', 'action', 'annualized_pct', 'relative_drawdown', 'periods',
            'vusa_same_window_pct', 'alpha_vs_vusa_pct', 'alpha_is_short_period', 'xirr_pct',
            'is_benchmark', 'is_borderline',
        ] as $key) {
            $this->assertArrayHasKey($key, $entry, "Missing pipeline key: {$key}");
        }

        // Quadrant period buttons: every horizon is present and self-consistent.
        foreach (['3m', '6m', '1y', '2y'] as $period) {
            $this->assertArrayHasKey($period, $entry['periods']);
            $p = $entry['periods'][$period];
            $this->assertArrayHasKey('gain', $p);
            $this->assertArrayHasKey('risk', $p);
            $this->assertArrayHasKey('quadrant', $p);
            $this->assertArrayHasKey('action', $p);
            // A quadrant and its action are decided together: either both set or both null.
            $this->assertSame($p['quadrant'] === null, $p['action'] === null);
        }

        // Top-level quadrant/action obey the same coupling.
        $this->assertSame($entry['quadrant'] === null, $entry['action'] === null);
    }

    public function test_previous_tier_state_keeps_a_near_boundary_position_sticky(): void
    {
        // A holding entered two years ago at 100, now ~120.6: ~9.8%/yr CAGR, which buckets as
        // Silver on the plain threshold but sits inside the Gold/Silver dead-band.
        $this->_seedOpenWinner(120.56);

        // No prior state recorded: the plain bucket applies and it lands Silver.
        $fresh = $this->_entryFor(self::SYMBOL);
        $this->assertEqualsWithDelta(9.8, $fresh['basis_value'], 0.5);
        $this->assertSame(TierCalculationService::SILVER, $fresh['effective_tier']);

        // Record that it last settled at Gold, then rebuild: hysteresis keeps it Gold rather than
        // flipping on a sub-band wiggle. The synthetic state row is rolled back with the test.
        $now = now();
        DB::connection($this->_conn)->table('symbol_tier_states')->insert([
            'user_id'    => $this->_userId,
            'symbol'     => self::SYMBOL,
            'tier'       => TierCalculationService::GOLD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sticky = $this->_entryFor(self::SYMBOL);
        $this->assertSame(TierCalculationService::GOLD, $sticky['effective_tier']);
        // The read path must not have overwritten the recorded state (the cron is the only writer).
        $persisted = DB::connection($this->_conn)->table('symbol_tier_states')
            ->where('user_id', $this->_userId)->where('symbol', self::SYMBOL)->value('tier');
        $this->assertSame(TierCalculationService::GOLD, $persisted);
    }
}
