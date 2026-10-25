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
use ovidiuro\myfinance2\App\Services\SettledTierResolver;
use ovidiuro\myfinance2\App\Services\TierSignalChip;
use ovidiuro\myfinance2\App\Services\TierSignalSeverity;
use ovidiuro\myfinance2\App\Services\WeakTierProfit;
use ovidiuro\myfinance2\Mail\PeakProximityAlert;

/**
 * Feature tests for the tier-signal side of the peak-proximity alerts (PeakProximityTierSignals).
 *
 * An opted-in symbol whose tier signal chip (TierSignalChip) shows with an action gets an alert of that signal
 * (RARE_HIGH: SELL / TRIM, RARE_LOW: ADD / BUY, DETERIORATING: REVIEW), whatever the tier; a rare-low BUY also on a
 * symbol not held. Never when the chip defers to a TAKE PROFIT (or range-bound) reading, never for a chip without an
 * action. It goes before the near-peak alert under the one-email-per-symbol-per-day guard. One email when the episode
 * opens, for a SELL / TRIM at once when a long window newly comes near its peak, else a reminder every
 * tier_signal_reminder_days; the same-day dismissal guard applies. By default every signal is an inbox card for your
 * awareness (INFO), never emailed; the email path is tested with the signal listed in tier_signal_emails (a
 * deterioration then emails once, with no reminders). A dismissed deterioration is not reopened while the same drop
 * lasts (a new drop opens a new one).
 *
 * Same isolation as PeakProximityTakeProfitAlertsFeatureTest: synthetic dashboard items for TST.AAA fed to
 * evaluateItems(), the chip built by the real TierSignalChip and TierSignalSeverity, an unused user_id,
 * Mail::fake() and DatabaseTransactions on both connections. Never saves a User.
 */
class PeakProximityTierSignalAlertsFeatureTest extends TestCase
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
        config(['alerts.peak_proximity.meaningful_windows' => ['6m', '1y', '2y']]);
        config(['alerts.peak_proximity.tier_signal_reminder_days' => 7]);
        config(['alerts.peak_proximity.tier_signal_emails' => []]);

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
     * One dashboard item at price 96 on a settled Rust tier with a tier signal ($signal overrides
     * SettledTier::toArray(): a held deterioration by default), the 6M window at $sixMonth % from its peak (near
     * peak from -5 %), the 1Y at -20 % (not near). $extra merges into the categorization (e.g. a take_profit).
     */
    private function _items(
        array $signal = [],
        bool $held = true,
        float $sixMonth = -12.0,
        array $extra = []
    ): array
    {
        $exitZones = [];
        foreach (['6m' => $sixMonth, '1y' => -20.0] as $window => $pct) {
            $exitZones[$window] = ['peak_price_date' => '2026-10-09', 'proximity_pct' => $pct, 'in_zone' => true];
        }
        $cat = array_replace([
            'is_benchmark'   => false,
            'effective_tier' => 'RUST',
            'settled_tier'   => 'RUST',
            'today_tier'     => 'RUST',
            'has_override'   => false,
            'action'         => 'EXIT',
            'range_bound'    => null,
            'take_profit'    => null,
            'exit_zones'     => $exitZones,
            'tier_signal'    => array_replace([
                'settled_tier'          => 'RUST',
                'status'                => SettledTierResolver::STATUS_DETERIORATING,
                'first_date'            => '2025-10-13',
                'last_date'             => '2026-10-12',
                'at_or_above_today_pct' => 100.0,
                'at_or_below_today_pct' => 13.2,
                'signal'                => SettledTierResolver::SIGNAL_DETERIORATING,
                'action'                => $held ? SettledTierResolver::ACTION_REVIEW : null,
                'deteriorating_days'    => 26,
                'drop_from'             => 'PLATINUM',
                'explanation'           => 'Four tiers below its settled Platinum tier today (two or more for 26'
                    . ' trading days); the tier now follows today.',
            ], $signal),
        ], $extra);
        $cat['tier_signal_chip'] = (new TierSignalChip(20))->detect($cat);

        $item = [
            'price'                => 96.0,
            'open_positions'       => $held ? [['marker' => true]] : [],
            'technical_indicators' => ['rsi' => 50.0, 'ma200_diff_pct' => 2.0],
            'unrealized_gain_eur'  => $held ? -120.0 : null,
            'unrealized_gain_pct'  => $held ? -12.0 : null,
            'categorization'       => $cat,
        ];
        $severity = new TierSignalSeverity(5.0, 10.0, 70.0, ['6m', '1y', '2y'],
            ['3m' => 2.0, '6m' => 5.0, '1y' => 8.0, '2y' => 10.0]);

        return $severity->attach([self::SYMBOL => $item], ['silver_symbols' => [
            ['symbol' => self::SYMBOL, 'mvalue_pct' => 2.0],
        ]]);
    }

    private function _rareHighSell(): array
    {
        return [
            'status' => SettledTierResolver::STATUS_OK, 'signal' => SettledTierResolver::SIGNAL_RARE_HIGH,
            'action' => SettledTierResolver::ACTION_SELL, 'at_or_above_today_pct' => 19.2, 'deteriorating_days' => 0,
        ];
    }

    private function _emailSignals(string ...$signals): void
    {
        config(['alerts.peak_proximity.tier_signal_emails' => $signals]);
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

    public function test_a_held_deterioration_is_an_inbox_card_for_your_awareness_never_emailed(): void
    {
        $stats = $this->_run($this->_items());

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(1, $stats['info']);
        Mail::assertNothingSent();
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING)->sole();
        $this->assertSame(PeakProximityAlertEvent::CLASS_INFO, $event->classification);
        $this->assertSame(PeakProximityAlertEvent::SEVERITY_MEDIUM, $event->severity);
        $this->assertSame('REVIEW', $event->head_action);
        $this->assertSame('Deteriorating', $event->summary['tier_signal']['label']);
        $this->assertNotNull($event->summary['drop_since']);
        $this->assertSame(0, PeakProximityNotification::where('user_id', $this->_userId)->count());

        // A later run refreshes the same card, still without an email.
        $this->assertSame(1, $this->_run($this->_items())['info']);
        Mail::assertNothingSent();
        $this->assertSame(0, (int) $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING)->sole()->email_count);
    }

    public function test_by_default_a_rare_high_is_an_inbox_card_never_emailed(): void
    {
        $stats = $this->_run($this->_items($this->_rareHighSell()));

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(1, $stats['info']);
        Mail::assertNothingSent();
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_RARE_HIGH)->sole();
        $this->assertSame(PeakProximityAlertEvent::CLASS_INFO, $event->classification);
        $this->assertSame('SELL', $event->head_action);
    }

    public function test_an_emailed_deterioration_goes_once_and_never_reminds(): void
    {
        $this->_emailSignals(SettledTierResolver::SIGNAL_DETERIORATING);

        $this->assertSame(1, $this->_run($this->_items())['triggered']);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_ends_with($m->subject, self::SYMBOL
                . ' (Rust; Deteriorating: REVIEW) => down from Platinum for 26 trading days');
        });
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING)->sole();
        $this->assertSame(PeakProximityAlertEvent::CLASS_ACTIONABLE, $event->classification);
        $this->assertSame(
            'Tier signal: Deteriorating',
            PeakProximityNotification::where('user_id', $this->_userId)->sole()->triggerLabel()
        );

        // A later day, and a week after the email: still no reminder for a deterioration.
        $this->_clearTodaysThrottle();
        $this->assertSame(0, $this->_run($this->_items())['triggered']);
        $event->update(['last_emailed_at' => now()->subDays(8)]);
        $this->assertSame(0, $this->_run($this->_items())['triggered']);
        $this->assertSame(1, (int) $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING)->sole()->email_count);
    }

    public function test_a_deterioration_near_peak_lets_the_near_peak_alert_email_the_same_day(): void
    {
        $stats = $this->_run($this->_items([], true, -2.0));

        $this->assertSame(1, $stats['triggered']);
        $this->assertSame(1, $stats['info']);
        Mail::assertSent(PeakProximityAlert::class, 1);
        $this->assertSame(
            PeakProximityAlertEvent::CLASS_INFO,
            $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING)->sole()->classification
        );
        $this->assertSame(1, (int) $this->_events(PeakProximityAlertEvent::SIGNAL_NEAR_PEAK)->sole()->email_count);
    }

    public function test_a_rare_high_reminds_after_the_interval(): void
    {
        $this->_emailSignals(SettledTierResolver::SIGNAL_RARE_HIGH);
        $this->_run($this->_items($this->_rareHighSell()));
        $this->_clearTodaysThrottle();
        $this->assertSame(0, $this->_run($this->_items($this->_rareHighSell()))['triggered']);

        $this->_events(PeakProximityAlertEvent::SIGNAL_RARE_HIGH)->sole()
            ->update(['last_emailed_at' => now()->subDays(7)]);
        $this->assertSame(1, $this->_run($this->_items($this->_rareHighSell()))['triggered']);
        $this->assertSame(2, (int) $this->_events(PeakProximityAlertEvent::SIGNAL_RARE_HIGH)->sole()->email_count);
    }

    public function test_a_dismissed_deterioration_stays_dismissed_while_the_drop_lasts(): void
    {
        $this->_run($this->_items());
        $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING)->sole()->update([
            'status' => PeakProximityAlertEvent::STATUS_DISMISSED, 'dismissed_at' => now()->subDay(),
        ]);
        $this->_clearTodaysThrottle();

        // A later day, the same drop (26 trading days, begun before the alert opened): not reopened.
        $this->assertSame(0, $this->_run($this->_items(['deteriorating_days' => 27]))['info']);
        $this->assertCount(1, $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING));

        // A new drop: the dismissed alert opened before it began (60 days ago, the drop is 20 trading days old).
        $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING)->sole()
            ->update(['opened_at' => now()->subDays(60)]);
        $this->assertSame(1, $this->_run($this->_items(['deteriorating_days' => 20]))['info']);
        $this->assertCount(2, $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING));
    }

    public function test_a_rare_high_near_peak_emails_first_and_the_near_peak_alert_waits(): void
    {
        $this->_emailSignals(SettledTierResolver::SIGNAL_RARE_HIGH);
        $items = $this->_items($this->_rareHighSell(), true, -2.0);
        $this->assertSame(1, $this->_run($items)['triggered']);

        Mail::assertSent(PeakProximityAlert::class, 1);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_contains($m->subject, self::SYMBOL . ' (Rust; Rare high: SELL) => Rust or better on only 19')
                && str_contains($m->subject, ', near peak: -2.00% to 6M');
        });
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_RARE_HIGH)->sole();
        $this->assertSame(PeakProximityAlertEvent::SEVERITY_HIGH, $event->severity, 'a 6M window near peak');

        // The near-peak alert opened too, reading the chip's action; the daily guard held its email.
        $nearPeak = $this->_events(PeakProximityAlertEvent::SIGNAL_NEAR_PEAK)->sole();
        $this->assertSame('SELL', $nearPeak->head_action);
        $this->assertSame('Rare high', $nearPeak->summary['tier_signal']['label']);
        $this->assertSame(0, (int) $nearPeak->email_count);

        // A later day: the tier signal reminder is not due, so the near-peak email goes, naming the signal.
        $this->_clearTodaysThrottle();
        $this->assertSame(1, $this->_run($items)['triggered']);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_contains($m->subject, self::SYMBOL . ' (Rust; Rare high: SELL) near peak => ');
        });
    }

    public function test_a_sell_emails_again_at_once_when_a_long_window_newly_nears_its_peak(): void
    {
        $this->_emailSignals(SettledTierResolver::SIGNAL_RARE_HIGH);
        $this->assertSame(1, $this->_run($this->_items($this->_rareHighSell()))['triggered']);

        $this->_clearTodaysThrottle();
        $this->assertSame(1, $this->_run($this->_items($this->_rareHighSell(), true, -2.0))['triggered']);
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_RARE_HIGH)->sole();
        $this->assertSame(2, (int) $event->email_count);
        $this->assertSame('6m', $event->last_emailed_windows);
    }

    public function test_a_rare_low_buy_fires_on_a_symbol_not_held(): void
    {
        $this->_emailSignals(SettledTierResolver::SIGNAL_RARE_LOW);
        $items = $this->_items([
            'settled_tier' => 'PLATINUM', 'status' => SettledTierResolver::STATUS_OK,
            'signal' => SettledTierResolver::SIGNAL_RARE_LOW, 'action' => SettledTierResolver::ACTION_BUY,
            'at_or_below_today_pct' => 3.2, 'deteriorating_days' => 0,
        ], false, -2.0, ['today_tier' => 'GOLD', 'effective_tier' => 'PLATINUM']);

        $this->assertSame(1, $this->_run($items)['triggered']);
        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $m)
        {
            $m->build();
            return str_contains($m->subject, '; Rare low: BUY) => Gold or lower on only 3');
        });
        $this->assertSame('BUY', $this->_events(PeakProximityAlertEvent::SIGNAL_RARE_LOW)->sole()->head_action);
        $this->assertCount(0, $this->_events(PeakProximityAlertEvent::SIGNAL_NEAR_PEAK), 'near peak: held only');
    }

    public function test_a_chip_without_an_action_never_alerts(): void
    {
        $stats = $this->_run($this->_items([], false));

        $this->assertSame(0, $stats['triggered']);
        $this->assertCount(0, $this->_events());
        Mail::assertNothingSent();
    }

    public function test_a_signal_deferring_to_take_profit_raises_only_the_take_profit_alert(): void
    {
        $takeProfit = (new WeakTierProfit())->detect(['effective_tier' => 'RUST', 'settled_tier' => 'RUST'], 3.8);
        $items      = $this->_items([], true, -12.0, ['take_profit' => $takeProfit]);
        $items[self::SYMBOL]['categorization'] = TierSignalChip::noteOnWinner($items[self::SYMBOL]['categorization']);

        $this->assertSame(1, $this->_run($items)['triggered']);
        $this->assertCount(0, $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING));
        $event = $this->_events(PeakProximityAlertEvent::SIGNAL_TAKE_PROFIT)->sole();
        $this->assertStringContainsString(
            'Also deteriorating: four tiers below', $event->summary['take_profit']['title']
        );
    }

    public function test_a_same_day_dismissal_is_not_reopened_and_a_disabled_symbol_is_ignored(): void
    {
        $this->_run($this->_items());
        $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING)->sole()->update([
            'status' => PeakProximityAlertEvent::STATUS_DISMISSED, 'dismissed_at' => now(),
        ]);
        $this->_clearTodaysThrottle();

        $this->assertSame(0, $this->_run($this->_items())['info']);
        $this->assertCount(1, $this->_events(PeakProximityAlertEvent::SIGNAL_DETERIORATING));

        PeakProximityAlertSetting::where('user_id', $this->_userId)
            ->update(['status' => PeakProximityAlertSetting::DISABLED]);
        $this->_events()->each->delete();
        $this->assertSame(0, $this->_run($this->_items())['info']);
        $this->assertCount(0, $this->_events());
    }

    public function test_emails_and_inbox_cards_show_the_reading_and_the_windows(): void
    {
        $items = $this->_items($this->_rareHighSell(), true, -2.0);
        $this->_run($items);

        // Render the emails from the same item; the synthetic open position is only a marker (no account), so the
        // Open positions card is left empty.
        $item = $items[self::SYMBOL];
        $item['open_positions'] = [];
        $triggered  = ['6m' => $item['categorization']['exit_zones']['6m']];
        $thresholds = ['3m' => 2.0, '6m' => 5.0, '1y' => 8.0, '2y' => 10.0];
        $html = (new PeakProximityAlert(
            self::SYMBOL, $item, $triggered, false, $thresholds, PeakProximityAlertEvent::SIGNAL_RARE_HIGH
        ))->render();
        $this->assertStringContainsString('Rust; Rare high: SELL.', $html);
        $this->assertStringContainsString('class="signal-note">Rare high: Rust today, but Rust or better', $html);
        $this->assertStringContainsString('Severity: High (6M near peak).', $html);
        $this->assertStringContainsString('Trigger target', $html);
        $this->assertStringContainsString('near its peak in 1 window', $html);
        $this->assertStringContainsString('Peak-proximity alert, tier signal on ' . self::SYMBOL, $html);
        $this->assertStringNotContainsString('realized on partial sells', $html);

        // An open position with partial sells: a note says what the Current gain and Cost cover.
        $sold = $item + ['performance' => ['has_data' => true, 'window_count' => 1, 'windows' => [[
            'index' => 1, 'is_open' => true, 'start_date' => now()->subYear(), 'end_date' => null,
            'remaining_cost_eur' => 400.0, 'invested_eur' => 1000.0, 'realized_gain_eur' => -50.0,
            'total_gain_eur' => -80.0, 'percentage_gain' => -8.0, 'dividends_eur' => 0.0,
        ]]]];
        $soldHtml = (new PeakProximityAlert(
            self::SYMBOL, $sold, $triggered, false, $thresholds, PeakProximityAlertEvent::SIGNAL_RARE_HIGH
        ))->render();
        $this->assertStringContainsString('realized on partial sells in this window', $soldHtml);
        $this->assertStringContainsString('the Cost is what is still held', $soldHtml);

        // The near-peak email of the same symbol carries the same reading, so whichever goes first says it all.
        $nearPeakHtml = (new PeakProximityAlert(self::SYMBOL, $item, $triggered, false, $thresholds))->render();
        $this->assertStringContainsString('class="signal-note">Rare high:', $nearPeakHtml);

        $render = fn (string $signal) => view('myfinance2::peakproximityalerts.inbox.partials.card', [
            'event'         => $this->_events($signal)->sole(),
            'severityClass' => [],
            'windowsLabel'  => fn (?string $csv) => (string) $csv,
        ])->render();

        $card = $render(PeakProximityAlertEvent::SIGNAL_RARE_HIGH);
        $this->assertStringContainsString('Rare high: <span class="fw-semibold">SELL', $card);
        $this->assertStringContainsString('fa-circle-exclamation', $card, 'High severity');
        $this->assertStringContainsString('Rust or better on only 19', $card);
        $this->assertStringContainsString('Trigger target', $card);
        $this->assertStringContainsString('last signal seen', $card);
        $this->assertStringNotContainsString('>EXIT<', $card);

        // The near-peak card leads with its own chip, on the alert's action (the tier signal's, as the email), then
        // the tier signal chip as context; never the quadrant's EXIT.
        $nearPeakCard = $render(PeakProximityAlertEvent::SIGNAL_NEAR_PEAK);
        $this->assertStringContainsString('fa-mountain', $nearPeakCard);
        $this->assertStringContainsString('Near peak: <span class="fw-semibold">SELL', $nearPeakCard);
        $this->assertStringContainsString('Rare high: <span class="fw-semibold">SELL', $nearPeakCard);
        $this->assertStringContainsString('last near peak', $nearPeakCard);
        $this->assertStringNotContainsString('>EXIT<', $nearPeakCard);
    }
}
