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
use ovidiuro\myfinance2\Mail\PeakProximityAlert;

/**
 * Feature tests for the (exit-focused) peak-proximity exit-hint alerts.
 *
 * Scope and isolation: the full WatchlistSymbolsDashboard makes live Yahoo Finance quote calls and
 * returns nothing for a synthetic symbol offline, so it cannot run deterministically in a test. Here
 * we exercise the service's own contract via evaluateItems(), the same loop evaluateForUser() runs
 * once the dashboard items are built. We feed it synthetic dashboard items (symbol TST.AAA, which
 * cannot exist in production).
 *
 * The refined rules under test:
 *  - the email gate is the gain-based tier (RUST / BRONZE), not the HOLD/EXIT action;
 *  - a 3M-only near-peak is context (INFO), never an email; an email needs a 6M/1Y/2Y window;
 *  - every near-peak symbol (actionable or info) becomes an OPEN inbox event;
 *  - cadence escalates: a new long window crossing into near-peak emails immediately, otherwise the
 *    reminder interval shrinks as confluence grows;
 *  - dismissing ends the episode; a later re-trigger opens a fresh one.
 *
 * Alerts are OFF by default: a symbol fires only with an ENABLED setting, so each test seeds one.
 *
 * Production-database safety: these run against the real database. They never save a User and write
 * only to peak_proximity_* tables under an unused user_id (no FK to users). Mail::fake() keeps mail
 * in-process; DatabaseTransactions rolls back both connections.
 */
class PeakProximityAlertsFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'TST.AAA';

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    private string $_conn = '';
    private int $_userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        AssignedToUserScope::enable();

        $this->_conn = config('myfinance2.db_connection', 'myfinance2_mysql');

        $user = User::first();
        if (!$user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        $this->actingAs($user);

        $this->_userId = (int) DB::connection($this->_conn)
            ->table((new PeakProximityNotification())->getTable())
            ->max('user_id') + 1;

        config(['alerts.peak_proximity.email_to' => 'peak-proximity-test@example.test']);
        config(['alerts.peak_proximity.exit_focused' => true]);
        config(['alerts.peak_proximity.exit_tiers' => ['RUST', 'BRONZE']]);

        Mail::fake();
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    /**
     * Exit-zone map for the four windows; pass a proximity per window (null windows are omitted).
     * Far-from-peak default keeps a window from triggering unless explicitly raised.
     *
     * @param array $proximities window => proximity_pct
     *
     * @return array
     */
    private function _zones(array $proximities): array
    {
        $defaults = ['3m' => -30.0, '6m' => -30.0, '1y' => -30.0, '2y' => -30.0];
        $dates    = ['3m' => '2026-05-20', '6m' => '2026-02-01', '1y' => '2025-09-01', '2y' => '2024-12-01'];

        $zones = [];
        foreach (array_merge($defaults, $proximities) as $window => $prox) {
            $zones[$window] = [
                'peak_price_date' => $dates[$window] ?? '2026-01-01',
                'proximity_pct'   => $prox,
                'in_zone'         => $prox >= -15.0,
            ];
        }

        return $zones;
    }

    /**
     * One synthetic dashboard item: owned, with the given tier/action and exit-zone proximities.
     *
     * @param array       $proximities window => proximity_pct
     * @param string|null $tier        effective_tier (e.g. BRONZE, PLATINUM)
     * @param string|null $action      head action (HOLD / EXIT); informational only
     * @param float       $rsi
     *
     * @return array
     */
    private function _items(
        array $proximities = ['6m' => -2.0],
        ?string $tier = 'BRONZE',
        ?string $action = 'EXIT',
        float $rsi = 50.0
    ): array
    {
        return [
            self::SYMBOL => [
                'price'                 => 100.0,
                'open_positions'        => [['marker' => true]],
                'technical_indicators'  => ['rsi' => $rsi],
                'categorization'        => [
                    'effective_tier' => $tier,
                    'action'         => $action,
                    'exit_zones'     => $this->_zones($proximities),
                ],
            ],
        ];
    }

    private function _seedSetting(
        string $status = PeakProximityAlertSetting::ENABLED,
        ?string $until = null
    ): PeakProximityAlertSetting
    {
        return PeakProximityAlertSetting::updateOrCreate(
            ['user_id' => $this->_userId, 'symbol' => self::SYMBOL],
            ['status' => $status, 'until' => $until]
        );
    }

    private function _openEvent(): ?PeakProximityAlertEvent
    {
        return PeakProximityAlertEvent::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)
            ->where('status', PeakProximityAlertEvent::STATUS_OPEN)
            ->first();
    }

    public function test_default_disabled_does_not_fire(): void
    {
        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items());

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(0, $stats['processed']);
        Mail::assertNothingSent();
        $this->assertSame(0, PeakProximityNotification::where('user_id', $this->_userId)->count());
        $this->assertNull($this->_openEvent());
    }

    public function test_disabled_symbol_does_not_fire(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::DISABLED);

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items());

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(0, $stats['processed']);
        Mail::assertNothingSent();
    }

    public function test_weak_tier_near_meaningful_peak_fires_then_throttles_same_day(): void
    {
        $this->_seedSetting();
        $service = new PeakProximityAlertService();

        $first = $service->evaluateItems($this->_userId, $this->_items(['6m' => -2.0]));
        $this->assertSame(1, $first['triggered']);
        $this->assertContains(self::SYMBOL, $first['symbols']);

        Mail::assertSent(PeakProximityAlert::class, fn (PeakProximityAlert $m) => $m->hasTo('peak-proximity-test@example.test'));

        $row = PeakProximityNotification::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)->where('status', 'SENT')->first();
        $this->assertNotNull($row);
        $this->assertSame('6m', $row->triggered_windows);

        $event = $this->_openEvent();
        $this->assertNotNull($event);
        $this->assertSame(PeakProximityAlertEvent::CLASS_ACTIONABLE, $event->classification);
        $this->assertSame(1, (int) $event->email_count);

        // Second same-day run is throttled (daily double-send guard).
        $second = $service->evaluateItems($this->_userId, $this->_items(['6m' => -2.0]));
        $this->assertSame(0, $second['triggered']);
        $this->assertSame(1, $second['skipped']);
        Mail::assertSent(PeakProximityAlert::class, 1);
        $this->assertSame(1, (int) $this->_openEvent()->email_count);
    }

    public function test_strong_tier_near_peak_does_not_email_but_creates_info_event(): void
    {
        $this->_seedSetting();

        $stats = (new PeakProximityAlertService())
            ->evaluateItems($this->_userId, $this->_items(['6m' => -2.0], 'PLATINUM', 'HOLD'));

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(1, $stats['info']);
        Mail::assertNothingSent();
        $this->assertSame(0, PeakProximityNotification::where('user_id', $this->_userId)->count());

        $event = $this->_openEvent();
        $this->assertNotNull($event);
        $this->assertSame(PeakProximityAlertEvent::CLASS_INFO, $event->classification);
    }

    public function test_exit_action_does_not_gate_a_strong_tier(): void
    {
        $this->_seedSetting();

        // Strong tier but head action EXIT: action is informational, so this is still INFO.
        $stats = (new PeakProximityAlertService())
            ->evaluateItems($this->_userId, $this->_items(['6m' => -2.0], 'PLATINUM', 'EXIT'));

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(1, $stats['info']);
        Mail::assertNothingSent();
    }

    public function test_3m_only_near_peak_is_info_not_actionable(): void
    {
        $this->_seedSetting();

        // Weak tier, but only the 3M context window is near peak: not actionable, no email.
        $stats = (new PeakProximityAlertService())
            ->evaluateItems($this->_userId, $this->_items(['3m' => -1.0], 'RUST', 'EXIT'));

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(1, $stats['info']);
        Mail::assertNothingSent();
        $this->assertSame(PeakProximityAlertEvent::CLASS_INFO, $this->_openEvent()->classification);
    }

    public function test_does_not_record_when_outside_threshold(): void
    {
        $this->_seedSetting();

        // 6M now 8% from peak, beyond its 5% threshold; nothing is near peak.
        $stats = (new PeakProximityAlertService())
            ->evaluateItems($this->_userId, $this->_items(['6m' => -8.0]));

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(0, $stats['processed']);
        Mail::assertNothingSent();
        $this->assertNull($this->_openEvent());
    }

    public function test_per_window_thresholds_allow_looser_long_term_peaks(): void
    {
        $this->_seedSetting();

        // 2Y is 9% from peak (within the looser 10% long-term limit), so it fires.
        $stats = (new PeakProximityAlertService())
            ->evaluateItems($this->_userId, $this->_items(['3m' => -6.0, '6m' => -15.0, '1y' => -20.0, '2y' => -9.0]));

        $this->assertSame(1, $stats['triggered']);
        $row = PeakProximityNotification::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)->where('status', 'SENT')->first();
        $this->assertNotNull($row);
        $this->assertSame('2y', $row->triggered_windows);
    }

    public function test_threshold_override_applies_uniformly_to_all_windows(): void
    {
        $this->_seedSetting();

        // The override replaces every window threshold. At 7%, 6M at -6 now qualifies (default 5%).
        $stats = (new PeakProximityAlertService())->evaluateItems(
            $this->_userId,
            $this->_items(['3m' => -15.0, '6m' => -6.0]),
            dryRun: false,
            thresholdPct: 7.0
        );

        $this->assertSame(1, $stats['triggered']);
        $row = PeakProximityNotification::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)->first();
        $this->assertSame('6m', $row->triggered_windows);
    }

    public function test_dry_run_sends_nothing_and_records_nothing(): void
    {
        $this->_seedSetting();

        $stats = (new PeakProximityAlertService())
            ->evaluateItems($this->_userId, $this->_items(['6m' => -2.0]), dryRun: true);

        $this->assertSame(1, $stats['triggered']);
        Mail::assertNothingSent();
        $this->assertSame(0, PeakProximityNotification::where('user_id', $this->_userId)->count());
        $this->assertNull($this->_openEvent());
    }

    public function test_symbol_filter_limits_evaluation(): void
    {
        $this->_seedSetting();

        $stats = (new PeakProximityAlertService())->evaluateItems(
            $this->_userId,
            $this->_items(['6m' => -2.0]),
            dryRun: false,
            filterSymbols: ['SOME.OTHER']
        );

        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['triggered']);
        Mail::assertNothingSent();
    }

    public function test_new_window_crossing_emails_immediately(): void
    {
        $this->_seedSetting();
        $service = new PeakProximityAlertService();

        // Episode opens with one long window near peak.
        $service->evaluateItems($this->_userId, $this->_items(['6m' => -2.0]));
        Mail::assertSent(PeakProximityAlert::class, 1);

        // Make it look like the open email went out yesterday, and clear today's throttle row, so
        // only the cadence logic decides the next send. One day < the 7-day single-window interval.
        $event = $this->_openEvent();
        $event->update(['last_emailed_at' => now()->subDay()]);
        PeakProximityNotification::where('user_id', $this->_userId)
            ->update(['sent_at' => now()->subDay()]);

        // A new long window (1Y) now also reaches peak: confluence 1 -> 2 emails immediately.
        $second = $service->evaluateItems($this->_userId, $this->_items(['6m' => -2.0, '1y' => -3.0]));

        $this->assertSame(1, $second['triggered']);
        Mail::assertSent(PeakProximityAlert::class, 2);
        $this->assertSame(2, (int) $this->_openEvent()->email_count);
        $this->assertSame(2, (int) $this->_openEvent()->last_emailed_meaningful_count);
    }

    public function test_same_confluence_within_interval_does_not_re_email(): void
    {
        $this->_seedSetting();
        $service = new PeakProximityAlertService();

        $service->evaluateItems($this->_userId, $this->_items(['6m' => -2.0]));

        // Emailed two days ago; clear today's throttle. One window near peak -> 7-day interval, so
        // two days is not enough and there is no new window to escalate.
        $this->_openEvent()->update(['last_emailed_at' => now()->subDays(2)]);
        PeakProximityNotification::where('user_id', $this->_userId)
            ->update(['sent_at' => now()->subDays(2)]);

        $second = $service->evaluateItems($this->_userId, $this->_items(['6m' => -2.0]));

        $this->assertSame(0, $second['triggered']);
        $this->assertSame(1, $second['skipped']);
        Mail::assertSent(PeakProximityAlert::class, 1);
    }

    public function test_dismiss_then_retrigger_opens_a_new_episode(): void
    {
        $this->_seedSetting();
        $service = new PeakProximityAlertService();

        $service->evaluateItems($this->_userId, $this->_items(['6m' => -2.0]));
        Mail::assertSent(PeakProximityAlert::class, 1);

        // Dismiss the open event and clear today's throttle.
        $this->_openEvent()->update([
            'status'       => PeakProximityAlertEvent::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ]);
        PeakProximityNotification::where('user_id', $this->_userId)
            ->update(['sent_at' => now()->subDay()]);

        // Re-trigger: no OPEN event remains, so a fresh episode opens and emails again.
        $second = $service->evaluateItems($this->_userId, $this->_items(['6m' => -2.0]));

        $this->assertSame(1, $second['triggered']);
        Mail::assertSent(PeakProximityAlert::class, 2);
        $this->assertSame(1, (int) $this->_openEvent()->email_count);
        $this->assertSame(
            2,
            PeakProximityAlertEvent::where('user_id', $this->_userId)->where('symbol', self::SYMBOL)->count()
        );
    }

    public function test_enable_until_past_date_reverts_to_disabled(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::ENABLED, now()->subDay()->format('Y-m-d'));

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items());

        $this->assertSame(0, $stats['triggered']);
        Mail::assertNothingSent();

        $setting = PeakProximityAlertSetting::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)->first();
        $this->assertSame(PeakProximityAlertSetting::DISABLED, $setting->status);
        $this->assertNull($setting->until);
    }

    public function test_enable_until_future_date_still_fires(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::ENABLED, now()->addDay()->format('Y-m-d'));

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items());

        $this->assertSame(1, $stats['triggered']);
        Mail::assertSent(PeakProximityAlert::class, 1);
    }

    public function test_pause_until_past_date_reverts_to_enabled(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::DISABLED, now()->subDay()->format('Y-m-d'));

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items());

        $this->assertSame(1, $stats['triggered']);
        Mail::assertSent(PeakProximityAlert::class, 1);

        $setting = PeakProximityAlertSetting::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)->first();
        $this->assertSame(PeakProximityAlertSetting::ENABLED, $setting->status);
        $this->assertNull($setting->until);
    }

    public function test_pause_until_future_date_stays_disabled(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::DISABLED, now()->addDay()->format('Y-m-d'));

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items());

        $this->assertSame(0, $stats['triggered']);
        Mail::assertNothingSent();
    }

    public function test_all_users_run_only_processes_users_with_enabled_alerts(): void
    {
        $service = new PeakProximityAlertService();

        $noPositionUserId = (int) DB::connection($this->_conn)->table('trades')->max('user_id') + 1;

        PeakProximityAlertSetting::updateOrCreate(
            ['user_id' => $noPositionUserId, 'symbol' => self::SYMBOL],
            ['status' => PeakProximityAlertSetting::ENABLED, 'until' => null]
        );

        $result      = $service->getUserIdsWithEnabledAlerts();
        $openUserIds = $service->getUserIdsWithOpenPositions();

        $this->assertNotContains($noPositionUserId, $result);
        foreach ($result as $id) {
            $this->assertContains($id, $openUserIds);
        }
    }
}
