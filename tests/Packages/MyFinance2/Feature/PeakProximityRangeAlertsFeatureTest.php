<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use ovidiuro\myfinance2\App\Models\PeakProximityAlertEvent;
use ovidiuro\myfinance2\App\Models\PeakProximityAlertSetting;
use ovidiuro\myfinance2\App\Models\PeakProximityNotification;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\PeakProximityAlertService;
use ovidiuro\myfinance2\App\Services\RangeBoundPattern;
use ovidiuro\myfinance2\App\Services\TierDecision;
use ovidiuro\myfinance2\Mail\PeakProximityAlert;

/**
 * Feature tests for the range-bound side of the peak-proximity alerts (PeakProximityRangeSignals).
 *
 * A held, opted-in range-bound symbol is read on its swing: an alert opens with signal RANGE_HIGH (TRIM) when its
 * price is near the high of its 52-week range and RANGE_LOW (ADD) near the low, whatever its tier, and it never gets
 * a near-peak exit alert. Mid-range raises nothing. By default only ADD and BUY email
 * (alerts.peak_proximity.range_emails) and TRIM is an inbox card; the email path is tested with TRIM listed too:
 * one email when an episode opens, then a reminder every range_reminder_days; the same-day dismissal and
 * one-email-per-symbol-per-day guards apply.
 *
 * Same isolation as PeakProximityAlertsFeatureTest: synthetic dashboard items for TST.AAA fed to evaluateItems(),
 * the range reading built by the real RangeBoundPattern from synthetic figures, an unused user_id, Mail::fake() and
 * DatabaseTransactions on both connections. Never saves a User.
 */
class PeakProximityRangeAlertsFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'TST.AAA';

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    private int $_userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        AssignedToUserScope::enable();

        $user = User::first();
        if (!$user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        $this->actingAs($user);

        $this->_userId = (int) DB::connection(config('myfinance2.db_connection', 'myfinance2_mysql'))
            ->table((new PeakProximityNotification())->getTable())
            ->max('user_id') + 1;

        config(['alerts.peak_proximity.email_to' => 'peak-proximity-test@example.test']);
        config(['alerts.peak_proximity.exit_focused' => true]);
        config(['alerts.peak_proximity.exit_tiers' => ['RUST', 'BRONZE']]);
        config(['alerts.peak_proximity.range_reminder_days' => 7]);
        // Every range-bound signal emails here, so the email path is tested on TRIM too (by default it is an inbox
        // card: test_by_default_a_trim_is_an_inbox_card_and_an_add_still_emails).
        config(['alerts.peak_proximity.range_emails' => ['RANGE_HIGH', 'RANGE_LOW', 'RANGE_BUY']]);

        PeakProximityAlertSetting::updateOrCreate(
            ['user_id' => $this->_userId, 'symbol' => self::SYMBOL],
            ['status' => PeakProximityAlertSetting::ENABLED, 'until' => null]
        );

        Mail::fake();
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    /**
     * One held dashboard item whose price sits at $price in a 52-week range of 65 to 100 (MSFT-like: 1Y +5.2 %,
     * Own Lifetime Platinum, 3 of 3 won), so 97 is near the high and 70 near the low. The 6M window is near its
     * peak, so a non-range-bound symbol on the same tier would raise a near-peak alert.
     */
    private function _items(float $price, string $tier = 'GOLD', bool $held = true): array
    {
        $cat = [
            'is_benchmark'     => false,
            'effective_tier'   => $tier,
            'action'           => 'EXIT',
            'candidates'       => ['market_1y_pct' => 5.2],
            'closing_extremes' => [
                'low_eur' => 65.0, 'high_eur' => 100.0, 'low_native' => 65.0, 'high_native' => 100.0,
                'low_date' => '2026-04-07', 'high_date' => '2026-10-09',
            ],
            'own_rule'         => [
                'tier' => 'PLATINUM', 'value' => 27.5, 'basis' => TierDecision::BASIS_ANNUALIZED_RETURN,
            ],
            'exit_zones'       => [
                '6m' => ['peak_price_date' => '2026-10-09', 'proximity_pct' => -2.0, 'in_zone' => true],
            ],
        ];
        $perf = [
            'window_count' => 4,
            'win_rate'     => ['wins' => 3, 'completed' => 3],
            'windows'      => [['is_open' => $held]],
        ];
        $cat['range_bound'] = (new RangeBoundPattern())->detect($cat, $perf, $price);

        return [
            self::SYMBOL => [
                'price'                => $price,
                'open_positions'       => $held ? [['marker' => true]] : [],
                'technical_indicators' => ['rsi' => 50.0],
                'unrealized_gain_eur'  => 120.0,
                'unrealized_gain_pct'  => 21.0,
                'today_gain_eur'       => 15.0,
                'today_gain_pct'       => 1.2,
                'categorization'       => $cat,
            ],
        ];
    }

    private function _run(float $price, string $tier = 'GOLD', bool $held = true): array
    {
        return (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items($price, $tier, $held));
    }

    private function _events(?string $signal = null)
    {
        return PeakProximityAlertEvent::where('user_id', $this->_userId)->where('symbol', self::SYMBOL)
            ->when($signal !== null, fn ($q) => $q->where('signal', $signal))
            ->get();
    }

    private function _clearTodaysThrottle(): void
    {
        PeakProximityNotification::where('user_id', $this->_userId)->update(['sent_at' => now()->subDay()]);
    }

    public function test_near_the_high_emails_a_trim_whatever_the_tier(): void
    {
        // Gold never emails on a near-peak; range-bound near the high it is a TRIM.
        $stats = $this->_run(97.0, 'GOLD');

        $this->assertSame(1, $stats['triggered']);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_contains($m->subject, self::SYMBOL
                . " (Gold; Range-bound, near the high: TRIM) => at 91\u{00A0}% of its 52-week range");
        });

        $this->assertCount(0, $this->_events(PeakProximityAlertEvent::SIGNAL_NEAR_PEAK));
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_RANGE_HIGH)->sole();
        $this->assertSame(PeakProximityAlertEvent::CLASS_ACTIONABLE, $event->classification);
        $this->assertSame('TRIM', $event->head_action);
        $this->assertSame(PeakProximityAlertEvent::STATUS_OPEN, $event->status);

        $notification = PeakProximityNotification::where('user_id', $this->_userId)->sole();
        $this->assertSame(PeakProximityAlertEvent::SIGNAL_RANGE_HIGH, $notification->signal);
        $this->assertSame('Range-bound, near the high: TRIM', $notification->triggerLabel());
    }

    public function test_by_default_a_trim_is_an_inbox_card_and_an_add_still_emails(): void
    {
        config(['alerts.peak_proximity.range_emails' => ['RANGE_LOW', 'RANGE_BUY']]);

        $stats = $this->_run(97.0);

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(1, $stats['info']);
        Mail::assertNothingSent();
        $trim = $this->_events(PeakProximityAlertEvent::SIGNAL_RANGE_HIGH)->sole();
        $this->assertSame(PeakProximityAlertEvent::CLASS_INFO, $trim->classification);
        $this->assertSame('TRIM', $trim->head_action);
        $this->assertSame(0, (int) $trim->email_count);
        $this->assertSame(0, PeakProximityNotification::where('user_id', $this->_userId)->count());

        $card = view('myfinance2::peakproximityalerts.inbox.partials.card', [
            'event'         => $trim,
            'severityClass' => [],
            'windowsLabel'  => fn (?string $csv) => (string) $csv,
        ])->render();
        $this->assertStringContainsString('this range-bound alert does not email', $card);

        // Near the low the same symbol reads ADD, which still emails.
        $this->assertSame(1, $this->_run(70.0)['triggered']);
        Mail::assertSent(PeakProximityAlert::class, 1);
        $this->assertSame(
            PeakProximityAlertEvent::CLASS_ACTIONABLE,
            $this->_events(PeakProximityAlertEvent::SIGNAL_RANGE_LOW)->sole()->classification
        );
    }

    public function test_near_the_low_emails_an_add_on_a_weak_tier(): void
    {
        $stats = $this->_run(70.0, 'RUST');

        $this->assertSame(1, $stats['triggered']);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_contains($m->subject, self::SYMBOL
                . " (Rust; Range-bound, near the low: ADD) => at 14\u{00A0}% of its 52-week range");
        });
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_RANGE_LOW)->sole();
        $this->assertSame('ADD', $event->head_action);
        $this->assertCount(1, $this->_events());
    }

    public function test_an_exited_symbol_near_the_low_emails_a_buy(): void
    {
        $stats = $this->_run(70.0, 'GOLD', false);

        $this->assertSame(1, $stats['triggered']);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_contains($m->subject, self::SYMBOL
                . " (Gold; Range-bound, near the low: BUY) => at 14\u{00A0}% of its 52-week range");
        });
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_RANGE_BUY)->sole();
        $this->assertSame('BUY', $event->head_action);
        $this->assertSame(PeakProximityAlertEvent::CLASS_ACTIONABLE, $event->classification);
        $this->assertSame(
            'Range-bound, near the low: BUY',
            PeakProximityNotification::where('user_id', $this->_userId)->sole()->triggerLabel()
        );
    }

    public function test_an_exited_symbol_near_the_high_raises_nothing_yet(): void
    {
        // WAIT (no position near the high) has no alert yet; nor does the near-peak path, which needs a position.
        $stats = $this->_run(97.0, 'GOLD', false);

        $this->assertSame(0, $stats['processed']);
        Mail::assertNothingSent();
        $this->assertCount(0, $this->_events());
    }

    public function test_mid_range_raises_nothing_not_even_the_near_peak_exit(): void
    {
        // Rust and near its 6M peak would email an exit; range-bound mid-range it reads HOLD: no alert at all.
        $stats = $this->_run(82.0, 'RUST');

        $this->assertSame(0, $stats['processed']);
        Mail::assertNothingSent();
        $this->assertCount(0, $this->_events());
    }

    public function test_reminder_follows_the_range_interval(): void
    {
        $this->_run(97.0);
        Mail::assertSent(PeakProximityAlert::class, 1);

        // Emailed 3 days ago: under the 7-day interval, so no reminder.
        $this->_events()->sole()->update(['last_emailed_at' => now()->subDays(3)]);
        $this->_clearTodaysThrottle();
        $this->assertSame(0, $this->_run(97.0)['triggered']);

        // Emailed 8 days ago: the reminder goes, flagged as one.
        $this->_events()->sole()->update(['last_emailed_at' => now()->subDays(8)]);
        $this->assertSame(1, $this->_run(97.0)['triggered']);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_contains($m->subject, 'Reminder: ' . self::SYMBOL);
        });
        $this->assertSame(2, (int) $this->_events()->sole()->email_count);
    }

    public function test_one_email_per_symbol_per_day(): void
    {
        $this->_run(97.0);
        // Same day, now near the low: a new ADD episode opens in the inbox, but the daily guard holds the email.
        $stats = $this->_run(70.0);

        $this->assertSame(0, $stats['triggered']);
        Mail::assertSent(PeakProximityAlert::class, 1);
        $this->assertCount(1, $this->_events(PeakProximityAlertEvent::SIGNAL_RANGE_LOW));
    }

    public function test_dismissed_today_is_not_reopened(): void
    {
        $this->_run(97.0);
        $this->_events()->sole()->update([
            'status' => PeakProximityAlertEvent::STATUS_DISMISSED, 'dismissed_at' => now(),
        ]);
        $this->_clearTodaysThrottle();

        $stats = $this->_run(97.0);

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(PeakProximityAlertEvent::STATUS_DISMISSED, $this->_events()->sole()->status);
    }

    public function test_email_and_inbox_card_show_the_range_levels(): void
    {
        $this->_run(97.0);

        Mail::assertSent(PeakProximityAlert::class, 1);

        // Render the same email from the same item; the synthetic open position is only a marker (no account), so
        // the Open positions card is left empty.
        $item = $this->_items(97.0)[self::SYMBOL];
        $item['open_positions'] = [];
        $html = (new PeakProximityAlert(
            self::SYMBOL, $item, [], false, [], PeakProximityAlertEvent::SIGNAL_RANGE_HIGH
        ))->render();
        $this->assertStringContainsString('Range-bound, near the high: TRIM', $html);
        $this->assertStringContainsString("TRIM line, 70\u{00A0}% of the range", $html);
        $this->assertStringContainsString('52-week low', $html);
        $this->assertStringNotContainsString('Trigger target', $html);
        $this->assertStringContainsString('which nudged the position toward its 52-week high today.', $html);
        // The shared frame and buttons (the price alert's too): TRIM prefills a sell order.
        $this->assertStringContainsString('<strong>MyFinance2</strong> &middot;', $html);
        $this->assertStringContainsString('→ Create Sell Order', $html);
        $this->assertStringContainsString('action=SELL', $html);
        $this->assertStringContainsString('✏️ Manage Alerts', $html);
        $this->assertStringContainsString('→ Open Watchlist Symbols', $html);
        $this->assertStringContainsString('Sent at most once per day per symbol.', $html);
        // One name per value: the Quadrant's per-window market return (not "Gain").
        $this->assertStringContainsString('Market return</th>', $html);

        $card = view('myfinance2::peakproximityalerts.inbox.partials.card', [
            'event'         => $this->_events()->sole(),
            'severityClass' => [],
            'windowsLabel'  => fn (?string $csv) => (string) $csv,
        ])->render();

        $this->assertStringContainsString('Range-bound, near the high:', $card);
        $this->assertStringContainsString('>TRIM</span>', $card);
        $this->assertStringContainsString("at 91\u{00A0}% of its 52-week range", $card);
        $this->assertStringContainsString('in zone', $card);
        $this->assertStringContainsString('which nudged the position toward its 52-week high today.', $card);
        $this->assertStringContainsString('This range-bound alert emails whatever the tier', $card);
        $this->assertStringNotContainsString('>EXIT<', $card);
    }

    public function test_email_and_inbox_card_show_todays_move_pct_when_not_held(): void
    {
        // A symbol not held has no EUR move nor unrealized gain, only the day's % from its quote.
        $item = $this->_items(97.0)[self::SYMBOL];
        $item['open_positions']      = [];
        $item['today_gain_eur']      = null;
        $item['today_gain_pct']      = -6.31;
        $item['unrealized_gain_eur'] = null;
        $item['unrealized_gain_pct'] = null;
        $html = (new PeakProximityAlert(
            self::SYMBOL, $item, [], false, [], PeakProximityAlertEvent::SIGNAL_RANGE_HIGH
        ))->render();

        $this->assertStringContainsString("Today's move:", $html);
        $this->assertStringContainsString('- 6.31 %', $html);
        $this->assertStringContainsString('which moved the price away from its 52-week high today.', $html);
        $this->assertStringNotContainsString('unrealized gain', $html);

        $line = view('myfinance2::peakproximityalerts.inbox.partials.today-move', [
            'todayEur' => null, 'todayPct' => -6.31,
        ])->render();
        $this->assertStringContainsString("Today's move:", $line);
        $this->assertStringContainsString('- 6.31 %', $line);
        $this->assertStringContainsString('which moved the price away from its peak today.', $line);
    }

    public function test_a_superstar_shows_its_star_in_the_email_and_the_inbox_card(): void
    {
        $items = $this->_items(97.0);
        $items[self::SYMBOL]['categorization']['superstar'] = [
            'count' => 5, 'total' => 7, 'title' => 'Superstar: Platinum or Gold under 5 of the 7 tier approaches.',
        ];
        (new PeakProximityAlertService())->evaluateItems($this->_userId, $items);

        $item = $items[self::SYMBOL];
        $item['open_positions'] = [];
        $html = (new PeakProximityAlert(
            self::SYMBOL, $item, [], false, [], PeakProximityAlertEvent::SIGNAL_RANGE_HIGH
        ))->render();
        $this->assertStringContainsString('&#9733;', $html);

        $card = view('myfinance2::peakproximityalerts.inbox.partials.card', [
            'event'         => $this->_events()->sole(),
            'severityClass' => [],
            'windowsLabel'  => fn (?string $csv) => (string) $csv,
        ])->render();
        $this->assertStringContainsString('fa-star', $card);
        $this->assertStringContainsString('under 5 of the 7 tier approaches', $card);
    }
}
