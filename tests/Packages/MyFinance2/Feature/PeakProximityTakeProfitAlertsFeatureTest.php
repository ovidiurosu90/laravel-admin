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
use ovidiuro\myfinance2\App\Services\WeakTierProfit;
use ovidiuro\myfinance2\Mail\PeakProximityAlert;

/**
 * Feature tests for the weak-tier-in-profit side of the peak-proximity alerts (PeakProximityTakeProfitSignals).
 *
 * A held, opted-in symbol whose tier in use is weak (Rust or Bronze) while its open position is in profit gets a
 * TAKE PROFIT alert (signal TAKE_PROFIT), near peak or not, in place of its near-peak alert; the windows near peak
 * are context and raise the severity. One email when the episode opens, at once when a long window newly comes near
 * its peak, else a reminder every take_profit_reminder_days; the same-day dismissal and one-email-per-symbol-per-day
 * guards apply. A weak tier at a loss keeps the near-peak exit alert.
 *
 * Same isolation as PeakProximityRangeAlertsFeatureTest: synthetic dashboard items for TST.AAA fed to
 * evaluateItems(), the reading built by the real WeakTierProfit, an unused user_id, Mail::fake() and
 * DatabaseTransactions on both connections. Never saves a User.
 */
class PeakProximityTakeProfitAlertsFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'TST.AAA';
    private const NBSP   = "\u{00A0}";

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
        config(['alerts.peak_proximity.meaningful_windows' => ['6m', '1y', '2y']]);
        config(['alerts.peak_proximity.take_profit_reminder_days' => 7]);

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
     * One held dashboard item at price 96 on a settled $tier, the open position up $gainPct, with the given windows
     * (window => proximity %) near or off their peaks: the 6M at -2 % is near peak (threshold 5), the 1Y at -20 % is
     * not (threshold 8) unless overridden.
     */
    private function _items(float $gainPct = 71.4, string $tier = 'RUST', array $proximity = ['6m' => -2.0]): array
    {
        $exitZones = [];
        foreach ($proximity + ['1y' => -20.0] as $window => $pct) {
            $exitZones[$window] = ['peak_price_date' => '2026-10-09', 'proximity_pct' => $pct, 'in_zone' => true];
        }
        $cat = [
            'is_benchmark'   => false,
            'effective_tier' => $tier,
            'settled_tier'   => $tier,
            'has_override'   => false,
            'action'         => 'EXIT',
            'range_bound'    => null,
            'exit_zones'     => $exitZones,
        ];
        $cat['take_profit'] = (new WeakTierProfit())->detect($cat, $gainPct, $gainPct * 10.0);

        return [
            self::SYMBOL => [
                'price'                => 96.0,
                'open_positions'       => [['marker' => true]],
                'technical_indicators' => ['rsi' => 50.0],
                'unrealized_gain_eur'  => $gainPct * 10.0,
                'unrealized_gain_pct'  => $gainPct,
                'categorization'       => $cat,
            ],
        ];
    }

    private function _run(array $items): array
    {
        return (new PeakProximityAlertService())->evaluateItems($this->_userId, $items);
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

    private function _gainText(): string
    {
        return 'up +' . self::NBSP . '71.4' . self::NBSP . '% on the open' . self::NBSP . 'position';
    }

    /**
     * Near-peak alerts never email (no exit tier), so a test can follow the TAKE PROFIT cadence on its own.
     */
    private function _muteNearPeakEmails(): void
    {
        config(['alerts.peak_proximity.exit_tiers' => []]);
    }

    public function test_a_weak_tier_in_profit_near_peak_gets_only_the_take_profit_alert(): void
    {
        $stats = $this->_run($this->_items());

        $this->assertSame(1, $stats['triggered']);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_contains($m->subject, self::SYMBOL . ' (Rust; Weak tier in profit: TAKE PROFIT) => '
                . $this->_gainText() . ', near peak: ')
                && str_contains($m->subject, 'to 6M');
        });

        Mail::assertSent(PeakProximityAlert::class, 1);
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole();
        $this->assertSame(PeakProximityAlertEvent::CLASS_ACTIONABLE, $event->classification);
        $this->assertSame(PeakProximityAlertEvent::SEVERITY_HIGH, $event->severity);
        $this->assertSame(WeakTierProfit::ACTION, $event->head_action);
        $this->assertSame('6m', $event->meaningful_windows);
        $this->assertSame('6m', $event->last_emailed_windows);

        $notification = PeakProximityNotification::where('user_id', $this->_userId)->sole();
        $this->assertSame(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT, $notification->signal);
        $this->assertSame('Weak tier in profit: TAKE PROFIT (near peak 6m)', $notification->triggerLabel());

        // No near-peak alert: the TAKE PROFIT one carries the windows, so one position gets one message.
        $this->assertCount(0, $this->_events(PeakProximityAlertEvent::SIGNAL_NEAR_PEAK));

        // A later day: the TAKE PROFIT reminder is not due, and nothing else emails.
        $this->_clearTodaysThrottle();
        $this->assertSame(0, $this->_run($this->_items())['triggered']);
        Mail::assertSent(PeakProximityAlert::class, 1);
        $this->assertCount(0, $this->_events(PeakProximityAlertEvent::SIGNAL_NEAR_PEAK));
        $this->assertSame(1, (int) $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole()->email_count);
    }

    public function test_not_near_a_peak_still_emails_at_medium_severity(): void
    {
        $stats = $this->_run($this->_items(71.4, 'BRONZE', ['6m' => -12.0]));

        $this->assertSame(1, $stats['triggered']);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_ends_with($m->subject, self::SYMBOL . ' (Bronze; Weak tier in profit: TAKE PROFIT) => '
                . $this->_gainText());
        });
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole();
        $this->assertSame(PeakProximityAlertEvent::SEVERITY_MEDIUM, $event->severity);
        $this->assertNull($event->meaningful_windows);
        $this->assertCount(0, $this->_events(PeakProximityAlertEvent::SIGNAL_NEAR_PEAK));
    }

    public function test_a_weak_tier_at_a_loss_keeps_the_near_peak_exit(): void
    {
        $stats = $this->_run($this->_items(-8.0));

        $this->assertSame(1, $stats['triggered']);
        $this->assertCount(0, $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT));
        $this->assertCount(1, $this->_events(PeakProximityAlertEvent::SIGNAL_NEAR_PEAK));
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_contains($m->subject, self::SYMBOL . ' (Rust, EXIT) near peak => ');
        });
    }

    public function test_a_strong_tier_in_profit_stays_informational(): void
    {
        $stats = $this->_run($this->_items(71.4, 'SILVER'));

        $this->assertSame(0, $stats['triggered']);
        Mail::assertNothingSent();
        $this->assertCount(0, $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT));
        $this->assertSame(
            PeakProximityAlertEvent::CLASS_INFO,
            $this->_events(PeakProximityAlertEvent::SIGNAL_NEAR_PEAK)->sole()->classification
        );
    }

    public function test_reminders_follow_the_interval_and_a_new_long_window_emails_at_once(): void
    {
        $this->_muteNearPeakEmails();
        $this->_run($this->_items());
        Mail::assertSent(PeakProximityAlert::class, 1);

        // Emailed 3 days ago, the same windows near peak: under the 7-day interval, so no reminder.
        $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole()
            ->update(['last_emailed_at' => now()->subDays(3)]);
        $this->_clearTodaysThrottle();
        $this->assertSame(0, $this->_run($this->_items())['triggered']);

        // The 1Y window now near its peak too: emails at once, flagged as a reminder.
        $this->assertSame(1, $this->_run($this->_items(71.4, 'RUST', ['6m' => -2.0, '1y' => -3.0]))['triggered']);
        $this->assertSame(
            '6m,1y', $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole()->last_emailed_windows
        );

        // Emailed 8 days ago: the interval reminder goes.
        $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole()
            ->update(['last_emailed_at' => now()->subDays(8)]);
        $this->_clearTodaysThrottle();
        $this->assertSame(1, $this->_run($this->_items(71.4, 'RUST', ['6m' => -2.0, '1y' => -3.0]))['triggered']);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_contains($m->subject, 'Reminder: ' . self::SYMBOL);
        });
        $this->assertSame(3, (int) $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole()->email_count);
    }

    public function test_dismissed_today_is_not_reopened(): void
    {
        $this->_muteNearPeakEmails();
        $this->_run($this->_items());
        $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole()->update([
            'status' => PeakProximityAlertEvent::STATUS_DISMISSED, 'dismissed_at' => now(),
        ]);
        $this->_clearTodaysThrottle();

        $stats = $this->_run($this->_items());

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(
            PeakProximityAlertEvent::STATUS_DISMISSED,
            $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole()->status
        );
    }

    public function test_email_and_inbox_card_show_the_reading_and_the_windows(): void
    {
        $this->_run($this->_items());

        // Render the same email from the same item; the synthetic open position is only a marker (no account), so
        // the Open positions card is left empty.
        $item = $this->_items()[self::SYMBOL];
        $item['open_positions'] = [];
        $triggered = ['6m' => $item['categorization']['exit_zones']['6m']];
        $html = (new PeakProximityAlert(
            self::SYMBOL, $item, $triggered, false, ['3m' => 2.0, '6m' => 5.0, '1y' => 8.0, '2y' => 10.0],
            PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT
        ))->render();
        $this->assertStringContainsString('Weak tier in profit: TAKE PROFIT', $html);
        $this->assertStringContainsString('the tier in use is Rust', $html);
        $this->assertStringContainsString('Trigger target', $html);
        $this->assertStringContainsString('near its peak in 1 window', $html);

        $card = view('myfinance2::peakproximityalerts.inbox.partials.card', [
            'event'         => $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole(),
            'actionClass'   => [],
            'severityClass' => [],
            'windowsLabel'  => fn (?string $csv) => (string) $csv,
        ])->render();

        $this->assertStringContainsString('Weak tier in profit:', $card);
        $this->assertStringContainsString('>TAKE PROFIT</span>', $card);
        $this->assertStringContainsString($this->_gainText(), $card);
        $this->assertStringContainsString('Trigger target', $card);
        $this->assertStringContainsString('near peak', $card);
        $this->assertStringContainsString('last in profit', $card);
        $this->assertStringNotContainsString('>EXIT<', $card);
    }
}
