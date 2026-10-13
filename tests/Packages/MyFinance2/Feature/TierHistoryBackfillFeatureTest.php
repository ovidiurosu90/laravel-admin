<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ovidiuro\myfinance2\App\Models\Account;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\CategorizationService;
use ovidiuro\myfinance2\App\Services\PerformanceDataset;
use ovidiuro\myfinance2\App\Services\SettledTierResolver;
use ovidiuro\myfinance2\App\Services\SymbolPerformanceService;
use ovidiuro\myfinance2\App\Services\TierCalculationService;
use ovidiuro\myfinance2\App\Services\TierDecision;
use ovidiuro\myfinance2\App\Services\TierHistoryRecorder;

/**
 * The daily tier history end to end: trades + stats_historical -> PerformanceDataset ->
 * SymbolPerformanceService::computeAsOf + DrawdownService::momentumAsOf -> TierClassifier ->
 * symbol_tier_history, and the history read back into CategorizationService::build().
 *
 * Production-database safety (see the package CLAUDE.md): DatabaseTransactions on both connections,
 * a synthetic TST.* symbol only, an unused user_id (trade inserted with FK checks off for the
 * session), stats_historical and symbol_tier_history written through the query builder, prices seeded
 * far enough back that HistoricalPriceCache is never needed (and fetching is disabled anyway), never
 * a User save.
 */
class TierHistoryBackfillFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'TST.TIERHIST';

    private ?Account $_account = null;
    private string $_conn = '';
    private int $_userId = 0;

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function setUp(): void
    {
        parent::setUp();
        AssignedToUserScope::disable();
        $this->_conn = config('myfinance2.db_connection', 'myfinance2_mysql');

        if (!Schema::connection($this->_conn)->hasTable('symbol_tier_history')) {
            $this->markTestSkipped('symbol_tier_history does not exist; run the migrations first');
        }
        $user = User::first();
        if (!$user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        $this->actingAs($user);

        $this->_account = Account::withoutGlobalScope(AssignedToUserScope::class)
            ->where('is_trade_account', 1)
            ->whereHas('currency', fn ($q) => $q->where('iso_code', 'EUR'))
            ->first();
        if (!$this->_account) {
            $this->markTestSkipped('Requires at least 1 EUR trade account in database');
        }

        $this->_userId = (int) DB::connection($this->_conn)->table('trades')->max('user_id') + 1;
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

    private function _buy(string $date, float $price): void
    {
        $now = now();
        Schema::connection($this->_conn)->withoutForeignKeyConstraints(function () use ($date, $price, $now) {
            DB::connection($this->_conn)->table('trades')->insert([
                'user_id'           => $this->_userId,
                'symbol'            => self::SYMBOL,
                'action'            => 'BUY',
                'status'            => 'OPEN',
                'quantity'          => 10,
                'unit_price'        => $price,
                'fee'               => 0.0,
                'exchange_rate'     => 1.0,
                'account_id'        => $this->_account->id,
                'trade_currency_id' => $this->_account->currency_id,
                'timestamp'         => $date . ' 10:00:00',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        });
    }

    /**
     * One close per weekday from $from to $to, priced by $priceOn(Y-m-d).
     */
    private function _seedPrices(string $from, string $to, callable $priceOn): void
    {
        $now  = now();
        $rows = [];
        foreach (TierHistoryRecorder::weekdays(Carbon::parse($from), Carbon::parse($to)) as $day) {
            $date   = $day->format('Y-m-d');
            $rows[] = [
                'date'              => $date,
                'symbol'            => self::SYMBOL,
                'unit_price'        => $priceOn($date),
                'currency_iso_code' => 'EUR',
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection($this->_conn)->table('stats_historical')->insert($chunk);
        }
    }

    /**
     * Bought at 100 on 2024-06-03. Flat at 100 until the end of 2024, then 108 (+8 %, Silver) in the
     * first quarter of 2025 and 113 (+13 %, Gold) in the second.
     */
    private function _seedSilverThenGold(): void
    {
        $this->_buy('2024-06-03', 100.0);
        $this->_seedPrices('2023-10-02', '2025-06-30', fn (string $d) => match (true) {
            $d < '2025-01-01' => 100.0,
            $d < '2025-04-01' => 108.0,
            default           => 113.0,
        });
    }

    private function _record(string $from, string $to, bool $dryRun = false): array
    {
        return (new TierHistoryRecorder())->recordRange(
            $this->_userId, Carbon::parse($from), Carbon::parse($to), null,
            TierHistoryRecorder::SOURCE_BACKFILL, $dryRun, false
        );
    }

    private function _stored(): \Illuminate\Support\Collection
    {
        return DB::connection($this->_conn)->table('symbol_tier_history')
            ->where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->date, 0, 10));
    }

    // =========================================================================
    // TESTS
    // =========================================================================

    public function test_backfill_stores_one_row_per_weekday_with_the_expected_tiers(): void
    {
        $this->_seedSilverThenGold();
        $weekdays = count(TierHistoryRecorder::weekdays(Carbon::parse('2025-01-01'), Carbon::parse('2025-06-30')));

        $summary = $this->_record('2025-01-01', '2025-06-30');
        $rows    = $this->_stored();

        $this->assertSame($weekdays, $summary['rows']);
        $this->assertCount($weekdays, $rows);
        $this->assertSame([], $summary['skipped']);

        // 2025-02-03: the 1Y market return (100 -> 108, +8 %) decides, held or not -> Silver.
        $this->assertSame(TierCalculationService::SILVER, $rows['2025-02-03']->tier);
        $this->assertSame(TierDecision::BASIS_MARKET_MOMENTUM, $rows['2025-02-03']->basis);
        $this->assertEqualsWithDelta(8.0, (float) $rows['2025-02-03']->basis_value, 0.01);
        // Both candidates are stored: own return +8 %, and the 1Y market move from 100 to 108.
        $this->assertEqualsWithDelta(8.0, (float) $rows['2025-02-03']->own_value, 0.01);
        $this->assertEqualsWithDelta(8.0, (float) $rows['2025-02-03']->market_value, 0.01);
        // The open holding period: bought on 2024-06-03, +8 % so far.
        $this->assertEqualsWithDelta(8.0, (float) $rows['2025-02-03']->holding_value, 0.01);
        $this->assertSame('2024-06-03', substr((string) $rows['2025-02-03']->holding_since, 0, 10));
        $this->assertSame(1, (int) $rows['2025-02-03']->is_owned);

        // 2025-05-05: +13 % over the year -> Gold. Your own return is stored next to it: annualized once
        // held a year (2025-06-30, about +12 %/y).
        $this->assertSame(TierCalculationService::GOLD, $rows['2025-05-05']->tier);
        $this->assertSame(TierCalculationService::GOLD, $rows['2025-06-30']->tier);
        $this->assertEqualsWithDelta(12.1, (float) $rows['2025-06-30']->own_value, 0.2);
        $this->assertSame('backfill', $rows['2025-06-30']->source);
    }

    public function test_a_day_without_a_close_is_not_a_trading_day_and_stale_rows_are_removed(): void
    {
        $this->_seedSilverThenGold();
        // An exchange holiday: no close stored for that weekday.
        DB::connection($this->_conn)->table('stats_historical')
            ->where('symbol', self::SYMBOL)->where('date', '2025-04-18')->delete();
        // A row left from an earlier run for that day must go.
        $now = now();
        DB::connection($this->_conn)->table('symbol_tier_history')->insert([
            'user_id' => $this->_userId, 'symbol' => self::SYMBOL, 'date' => '2025-04-18',
            'basis' => TierDecision::BASIS_MARKET_MOMENTUM, 'basis_value' => 13.0,
            'tier' => TierCalculationService::GOLD, 'is_owned' => 1, 'source' => 'backfill',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->_record('2025-04-14', '2025-04-25');
        $rows = $this->_stored();

        $this->assertArrayNotHasKey('2025-04-18', $rows->all());
        $this->assertCount(9, $rows);
    }

    public function test_rerun_is_idempotent(): void
    {
        $this->_seedSilverThenGold();
        $this->_record('2025-01-01', '2025-06-30');
        $before = $this->_stored()->map(fn ($r) => [$r->tier, $r->basis, $r->basis_value])->all();

        $this->_record('2025-01-01', '2025-06-30');
        $after = $this->_stored()->map(fn ($r) => [$r->tier, $r->basis, $r->basis_value])->all();

        $this->assertSame($before, $after);
    }

    public function test_days_before_the_first_trade_are_not_stored(): void
    {
        $this->_seedSilverThenGold();

        // Not owned yet and not on the watchlist: unrated, so nothing is stored.
        $summary = $this->_record('2024-05-01', '2024-05-31');

        $this->assertSame(0, $summary['rows']);
        $this->assertCount(0, $this->_stored());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->_seedSilverThenGold();

        $summary = $this->_record('2025-01-01', '2025-01-31', true);

        $this->assertGreaterThan(0, $summary['rows']);
        $this->assertSame(TierCalculationService::SILVER, array_key_first($summary['tiers'][self::SYMBOL]));
        $this->assertCount(0, $this->_stored());
    }

    public function test_as_of_today_matches_the_live_performance(): void
    {
        $today = Carbon::today()->format('Y-m-d');
        $this->_buy(Carbon::today()->subYears(2)->format('Y-m-d'), 100.0);
        $this->_seedPrices(Carbon::today()->subYears(3)->format('Y-m-d'), $today, fn () => 150.0);
        SymbolPerformanceService::clearCache($this->_userId);

        $live = (new SymbolPerformanceService())->handle($this->_userId)[self::SYMBOL];
        $asOf = (new SymbolPerformanceService())->computeAsOf(
            Carbon::today(), new PerformanceDataset($this->_userId, Carbon::today(), false)
        )[self::SYMBOL];

        $this->assertEqualsWithDelta($live['percentage_gain'], $asOf['percentage_gain'], 1e-6);
        $this->assertEqualsWithDelta(
            $live['annualized_percentage_gain'], $asOf['annualized_percentage_gain'], 1e-6
        );
        $this->assertSame($live['total_days'], $asOf['total_days']);
        SymbolPerformanceService::clearCache($this->_userId);
    }

    /**
     * Today Platinum on the 1Y market return, with 100 stored weekdays of Silver before it (settled Silver).
     */
    private function _seedSettledSilverTodayPlatinum(): void
    {
        // Today: bought two years ago at 100; the price jumped to 160 within the last year, so the 1Y
        // market return is +60 % (Platinum).
        $today   = Carbon::today();
        $yearAgo = $today->copy()->subMonths(11)->format('Y-m-d');
        $this->_buy($today->copy()->subYears(2)->format('Y-m-d'), 100.0);
        $this->_seedPrices(
            $today->copy()->subYears(3)->format('Y-m-d'), $today->format('Y-m-d'),
            fn (string $d) => $d < $yearAgo ? 100.0 : 160.0
        );

        // 100 stored weekdays before today with the market at +7 % (Silver) while your own return was 30 %:
        // compared on the same measure as today (market), Platinum today is a rare high.
        $now  = now();
        $rows = [];
        $day  = TierHistoryRecorder::weekdaysBefore($today, 100);
        foreach (TierHistoryRecorder::weekdays($day, $today->copy()->subDay()) as $d) {
            $rows[] = [
                'user_id' => $this->_userId, 'symbol' => self::SYMBOL, 'date' => $d->format('Y-m-d'),
                'basis' => TierDecision::BASIS_ANNUALIZED_RETURN, 'basis_value' => 7.0,
                'market_value' => 7.0, 'own_value' => 30.0,
                'tier' => TierCalculationService::SILVER, 'is_owned' => 1, 'source' => 'backfill',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::connection($this->_conn)->table('symbol_tier_history')->insert($rows);
        SymbolPerformanceService::clearCache($this->_userId);
    }

    public function test_categorization_exposes_history_settled_tier_and_signal(): void
    {
        $this->_seedSettledSilverTodayPlatinum();

        $entry = (new CategorizationService())->build($this->_userId)[self::SYMBOL];

        $this->assertSame(TierCalculationService::PLATINUM, $entry['today_tier']);
        $this->assertSame(TierCalculationService::SILVER, $entry['settled_tier']);
        $this->assertSame(SettledTierResolver::SIGNAL_RARE_HIGH, $entry['tier_signal']['signal']);
        $this->assertSame(SettledTierResolver::ACTION_SELL, $entry['tier_signal']['action']);
        $this->assertSame(100, $entry['tier_history']['days']);
        $this->assertSame('S 100%', $entry['tier_history']['shares_text']);
        $this->assertSame(TierDecision::BASIS_MARKET_MOMENTUM, $entry['basis']);
        $this->assertSame(SettledTierResolver::MEASURE_MARKET, $entry['tier_signal']['measure']);
        // The settled tier is the effective tier; today's tier is kept and shown as an outlined chip.
        $this->assertSame(TierCalculationService::SILVER, $entry['effective_tier']);
        $this->assertSame(TierCalculationService::PLATINUM, $entry['today_chip']['tier']);
        $this->assertStringStartsWith('Settled Silver:', $entry['tier_tooltip']);
        SymbolPerformanceService::clearCache($this->_userId);
    }

    public function test_cron_persists_the_settled_tier_and_keeps_its_first_day(): void
    {
        $this->_seedSettledSilverTodayPlatinum();
        $state = fn () => DB::connection($this->_conn)->table('symbol_tier_states')
            ->where('user_id', $this->_userId)->where('symbol', self::SYMBOL)->first();

        (new CategorizationService())->build($this->_userId, persistState: true);
        $first = $state();
        $this->assertNotNull($first);
        $this->assertSame(TierCalculationService::PLATINUM, $first->tier);
        $this->assertSame(TierCalculationService::SILVER, $first->settled_tier);
        $this->assertSame(Carbon::today()->format('Y-m-d'), $first->settled_since);
        // Stored for the alerts page; a symbol with a big 1Y move is not range-bound, so it is null.
        $this->assertTrue(property_exists($first, 'range_bound'));
        $this->assertNull($first->range_bound);

        // Unchanged settled tier on a later run: the first day is kept.
        DB::connection($this->_conn)->table('symbol_tier_states')->where('id', $first->id)
            ->update(['settled_since' => '2026-01-05']);
        (new CategorizationService())->build($this->_userId, persistState: true);
        $this->assertSame('2026-01-05', $state()->settled_since);
        SymbolPerformanceService::clearCache($this->_userId);
    }
}
