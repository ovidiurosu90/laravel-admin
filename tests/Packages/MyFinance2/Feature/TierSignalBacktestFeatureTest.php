<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use ovidiuro\myfinance2\App\Models\PeakProximityAlertEvent;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\SettledTierResolver;
use ovidiuro\myfinance2\App\Services\TierHistoryRecorder;
use ovidiuro\myfinance2\App\Services\TierSignalBacktestService;

/**
 * Feature test for the signal backtest (TierSignalBacktestService and the /peak-proximity-alerts/backtest view): the
 * stored history and closes are loaded, the signals replayed without look-ahead, the episodes measured against
 * VUSA.AS, and the report renders.
 *
 * Isolation: synthetic symbol_tier_history and stats_historical rows for TST.BTEST under an unused user_id, written
 * with the query builder inside DatabaseTransactions on both connections; the VUSA.AS closes are read, never
 * written. The backtest loads closes from a year before the first readable day; the synthetic closes start before
 * that and the dates sit inside the stored VUSA.AS history, so no price is fetched over the network. Never saves a
 * User.
 */
class TierSignalBacktestFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'TST.BTEST';

    private string $_conn;
    private int $_userId = 0;
    /** @var string[] the synthetic weekdays, oldest first */
    private array $_days = [];

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function setUp(): void
    {
        parent::setUp();
        AssignedToUserScope::enable();

        $user = User::first();
        if (!$user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        $this->actingAs($user);

        $this->_conn   = config('myfinance2.db_connection', 'myfinance2_mysql');
        $this->_userId = (int) DB::connection($this->_conn)->table('symbol_tier_history')->max('user_id') + 1;
        $vusaFirst = DB::connection($this->_conn)->table('stats_historical')->where('symbol', 'VUSA.AS')->min('date');
        if ($vusaFirst === null || $vusaFirst > '2025-01-03') {
            $this->markTestSkipped('Requires stored VUSA.AS closes from early January 2025');
        }
        $this->_seed();

        $pricesFrom = date('Y-m-d', strtotime($this->_days[60] . ' -366 days'));
        $vusaClose  = DB::connection($this->_conn)->table('stats_historical')
            ->where('symbol', 'VUSA.AS')->where('date', $pricesFrom)->exists();
        if (!$vusaClose) {
            $this->markTestSkipped("No stored VUSA.AS close on {$pricesFrom}: the backtest would fetch the gap");
        }
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    /**
     * 130 weekdays from 2025-10-15 of a held symbol on the market basis, your own return 15 % (Own Lifetime Gold):
     * a 1Y reading of 2 % (Bronze), then 12 % (Gold) from day 80, a rare high (SELL). Its closes: a V from 100 down to
     * 60 and back over the year before (from 2024-12-30), then falling 0.5 a day from 100. The backtest loads closes
     * from a year before the first readable day (day 60, a Wednesday, so a Monday): a day with a synthetic and a
     * VUSA.AS close, so StoredCloses finds no gap to fetch. Days 60 to 79 are range-bound (|1Y| under 10 %, low at
     * 60 % of the high, your record Gold) near the low of their 52-week range: an ADD.
     */
    private function _seed(): void
    {
        $now     = now();
        $history = [];
        $closes  = [];
        $before  = $this->_weekdays('2024-12-30', '2025-10-14');
        $half    = intdiv(count($before), 2);
        $rise    = count($before) - 1 - $half;
        foreach ($before as $k => $date) {
            $price    = $k <= $half ? 100.0 - 40.0 * $k / $half : 60.0 + 40.0 * ($k - $half) / $rise;
            $closes[] = $this->_close($date, $price, $now);
        }
        $this->_days = array_slice($this->_weekdays('2025-10-15', '2026-06-30'), 0, 130);
        foreach ($this->_days as $i => $date) {
            $value     = $i >= 80 ? 12.0 : 2.0;
            $history[] = [
                'user_id' => $this->_userId, 'symbol' => self::SYMBOL, 'date' => $date, 'basis' => 'market_momentum',
                'basis_value' => $value, 'market_value' => $value, 'own_value' => 15.0, 'holding_value' => null,
                'holding_since' => null, 'tier' => $value > 10 ? 'GOLD' : 'BRONZE', 'is_owned' => 1,
                'source' => 'backfill', 'created_at' => $now, 'updated_at' => $now,
            ];
            $closes[] = $this->_close($date, 100.0 - 0.5 * $i, $now);
        }
        DB::connection($this->_conn)->table('symbol_tier_history')->insert($history);
        DB::connection($this->_conn)->table('stats_historical')->insert($closes);
    }

    /**
     * @return string[] the weekdays from $from to $to, inclusive
     */
    private function _weekdays(string $from, string $to): array
    {
        return array_map(
            fn (Carbon $day) => $day->format('Y-m-d'),
            TierHistoryRecorder::weekdays(Carbon::parse($from), Carbon::parse($to))
        );
    }

    private function _close(string $date, float $price, $now): array
    {
        return [
            'date' => $date, 'symbol' => self::SYMBOL, 'unit_price' => $price, 'currency_iso_code' => 'EUR',
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    public function test_the_rare_high_is_replayed_and_measured_against_the_benchmark(): void
    {
        $report = (new TierSignalBacktestService())->report($this->_userId, null, 20.0, 50.0, true);

        $this->assertSame(1, $report['symbols']);
        $this->assertSame([], $report['missing_prices']);
        $this->assertSame(70, $report['days'], 'days 60 to 129: the settled tier needs 60 stored days');

        $high = array_values(array_filter(
            $report['episodes'], fn ($e) => $e['signal'] === SettledTierResolver::SIGNAL_RARE_HIGH
        ));
        $this->assertCount(1, $high);
        $this->assertSame(self::SYMBOL, $high[0]['symbol']);
        $this->assertSame('SELL', $high[0]['action']);
        $this->assertSame($this->_days[80], $high[0]['start']);
        $this->assertNotNull($high[0]['forward'][21], '21 later closes exist');
        $this->assertNull($high[0]['forward'][126], 'past the seeded closes');

        $summary = $report['summary'][SettledTierResolver::SIGNAL_RARE_HIGH . '|all']['horizons'][21];
        $this->assertSame(1, $summary['n']);
        $this->assertGreaterThan(0, $report['baseline'][21]['n']);
    }

    public function test_the_range_bound_add_is_replayed_from_what_was_stored_each_day(): void
    {
        $report = (new TierSignalBacktestService())->report($this->_userId, null, 20.0, 50.0, true);

        $add = array_values(array_filter(
            $report['episodes'], fn ($e) => $e['signal'] === PeakProximityAlertEvent::SIGNAL_RANGE_LOW
        ));
        $this->assertCount(1, $add);
        $this->assertSame('ADD', $add[0]['action']);
        $this->assertSame($this->_days[60], $add[0]['start'], 'the first readable day');
        $this->assertSame(20, $add[0]['days'], 'until the 1Y reading leaves the range-bound band on day 80');
        $this->assertNotNull($add[0]['forward'][21]);
        $this->assertArrayHasKey(PeakProximityAlertEvent::SIGNAL_RANGE_LOW . '|held', $report['summary']);
    }

    public function test_the_report_renders(): void
    {
        $report = (new TierSignalBacktestService())->report($this->_userId, null, 20.0, 50.0, true);
        $html   = view('myfinance2::peakproximityalerts.crud.backtest', ['report' => $report])->render();

        $this->assertStringContainsString('Tier Signal Backtest', $html);
        $this->assertStringContainsString('Rare high · SELL / TRIM', $html);
        $this->assertStringContainsString('Range-bound · ADD', $html);
        $this->assertStringContainsString('Baseline: every day', $html);
        $this->assertStringContainsString('Every episode (', $html);
        $this->assertStringContainsString(self::SYMBOL, $html);
        $this->assertStringContainsString('20 % (in use)', $html);
    }
}
