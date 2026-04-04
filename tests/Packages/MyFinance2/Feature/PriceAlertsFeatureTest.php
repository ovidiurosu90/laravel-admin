<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use ReflectionClass;
use ovidiuro\myfinance2\App\Console\Commands\FinanceApiCron;
use ovidiuro\myfinance2\App\Models\PriceAlert;
use ovidiuro\myfinance2\App\Models\PriceAlertNotification;
use ovidiuro\myfinance2\App\Models\Trade;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\AlertService;
use ovidiuro\myfinance2\Mail\PriceAlertCreated;
use ovidiuro\myfinance2\Mail\PriceAlertStateChanged;
use ovidiuro\myfinance2\Mail\PriceAlertTriggered;

/**
 * Feature tests for the Price Alerts module.
 *
 * Covers: CRUD, status transitions, evaluation engine, throttle, dedup,
 * lookback years, projected gain, notification history, and suggestion engine.
 *
 * DatabaseTransactions wraps each test in a rolled-back transaction so no test
 * data reaches the production database.
 */
class PriceAlertsFeatureTest extends TestCase
{
    use DatabaseTransactions;

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
        Mail::fake();
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        // Clean up the cron interval gate cache key regardless of test outcome.
        // Without this, a failed assertion in test_evaluate_alerts_interval_gate_prevents_early_rerun
        // would leave the key set for up to 1 hour, which only affects the cron gate, not data.
        Cache::forget('finance-api-cron:alerts:last-eval');
        Auth::forgetGuards();
        parent::tearDown();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function _createAlert(array $fields = []): PriceAlert
    {
        return PriceAlert::create(array_merge([
            'symbol'               => 'TEST.TST',
            'alert_type'           => 'PRICE_ABOVE',
            'target_price'         => '220.000000',
            'status'               => 'ACTIVE',
            'notification_channel' => 'email',
            'trigger_count'        => 0,
        ], $fields));
    }

    private function _createTrade(array $fields = []): Trade
    {
        return Trade::create(array_merge([
            'symbol'        => 'TEST.TST',
            'action'        => 'BUY',
            'status'        => 'OPEN',
            'quantity'      => '10.00000000',
            'unit_price'    => '100.0000',
            'fee'           => '0.00',
            'exchange_rate' => '1.0000',
            'timestamp'     => now(),
        ], $fields));
    }

    private function _postAlertAction(string $route, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->post($route, $data);
    }

    // =========================================================================
    // SMOKE TESTS
    // =========================================================================

    /**
     * Basic sanity: the price alerts list is accessible and returns the expected view.
     */
    public function test_index_returns_200(): void
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->get(route('myfinance2::price-alerts.index'));

        $response->assertStatus(200);
        $response->assertViewIs('myfinance2::alerts.crud.dashboard');
    }

    /**
     * Basic sanity: the notification history page is accessible.
     */
    public function test_notification_history_returns_200(): void
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->get(route('myfinance2::price-alerts.history'));

        $response->assertStatus(200);
    }

    // =========================================================================
    // STORE
    // =========================================================================

    /**
     * Covers: PriceAlertController@store creates the record and sends PriceAlertCreated email.
     */
    public function test_store_creates_alert_and_sends_created_email(): void
    {
        $symbol = 'STORE.TST';

        $this->_postAlertAction(route('myfinance2::price-alerts.store'), [
            'symbol'               => $symbol,
            'alert_type'           => 'PRICE_ABOVE',
            'target_price'         => '150.00',
            'status'               => 'ACTIVE',
            'notification_channel' => 'email',
        ])
            ->assertRedirect(route('myfinance2::price-alerts.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('price_alerts', [
            'symbol'     => $symbol,
            'alert_type' => 'PRICE_ABOVE',
        ], config('myfinance2.db_connection'));

        Mail::assertSent(PriceAlertCreated::class);
    }

    // =========================================================================
    // HARD DELETE
    // =========================================================================

    /**
     * Covers: forceDelete() — PriceAlert records are permanently removed (not soft-deleted).
     * Contrast with Order::withTrashed() which finds the deleted record.
     */
    public function test_destroy_hard_deletes_alert(): void
    {
        $alert = $this->_createAlert();
        $id = $alert->id;

        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->delete(route('myfinance2::price-alerts.destroy', $id))
            ->assertRedirect(route('myfinance2::price-alerts.index'))
            ->assertSessionHas('success');

        // forceDelete() means withTrashed()->find() also returns null
        $this->assertNull(PriceAlert::withTrashed()->find($id));
    }

    // =========================================================================
    // STATUS TRANSITIONS: PAUSE / RESUME
    // =========================================================================

    /**
     * Covers: PriceAlertController@pause transitions ACTIVE → PAUSED + sends state-changed email.
     */
    public function test_pause_transitions_active_to_paused(): void
    {
        $alert = $this->_createAlert(['status' => 'ACTIVE']);

        $this->_postAlertAction(route('myfinance2::price-alerts.pause', $alert->id))
            ->assertRedirect(route('myfinance2::price-alerts.index'))
            ->assertSessionHas('success');

        $alert->refresh();
        $this->assertEquals('PAUSED', $alert->status);
        Mail::assertSent(PriceAlertStateChanged::class);
    }

    /**
     * Covers: Guard — pausing an already-PAUSED alert is rejected.
     */
    public function test_pause_fails_for_non_active_alert(): void
    {
        $alert = $this->_createAlert(['status' => 'PAUSED']);

        $this->_postAlertAction(route('myfinance2::price-alerts.pause', $alert->id))
            ->assertRedirect(route('myfinance2::price-alerts.index'))
            ->assertSessionHas('error');

        $alert->refresh();
        $this->assertEquals('PAUSED', $alert->status);
        Mail::assertNotSent(PriceAlertStateChanged::class);
    }

    /**
     * Covers: PriceAlertController@resume transitions PAUSED → ACTIVE + sends state-changed email.
     */
    public function test_resume_transitions_paused_to_active(): void
    {
        $alert = $this->_createAlert(['status' => 'PAUSED']);

        $this->_postAlertAction(route('myfinance2::price-alerts.resume', $alert->id))
            ->assertRedirect(route('myfinance2::price-alerts.index'))
            ->assertSessionHas('success');

        $alert->refresh();
        $this->assertEquals('ACTIVE', $alert->status);
        Mail::assertSent(PriceAlertStateChanged::class);
    }

    /**
     * Covers: Guard — resuming an already-ACTIVE alert is rejected.
     */
    public function test_resume_fails_for_non_paused_alert(): void
    {
        $alert = $this->_createAlert(['status' => 'ACTIVE']);

        $this->_postAlertAction(route('myfinance2::price-alerts.resume', $alert->id))
            ->assertRedirect(route('myfinance2::price-alerts.index'))
            ->assertSessionHas('error');

        $alert->refresh();
        $this->assertEquals('ACTIVE', $alert->status);
        Mail::assertNotSent(PriceAlertStateChanged::class);
    }

    // =========================================================================
    // EVALUATION ENGINE
    // =========================================================================

    /**
     * Covers: End-to-end triggering — PriceAlertTriggered sent, notification created,
     * trigger_count incremented, last_triggered_at set.
     *
     * Uses real Finance API. Skipped if the API is unavailable.
     */
    public function test_evaluate_alerts_triggers_alert_and_sends_triggered_email(): void
    {
        // PRICE_BELOW with absurd target ensures any real price will satisfy the condition
        $alert = $this->_createAlert([
            'symbol'       => 'AAPL',
            'alert_type'   => 'PRICE_BELOW',
            'target_price' => '999999.999999',
            'status'       => 'ACTIVE',
        ]);

        $initialTriggerCount = $alert->trigger_count;

        $service = new AlertService();
        $stats = $service->evaluateAlerts($this->_user->id);

        // Skip if the Finance API returned no quote for the symbol
        if ($stats['skipped'] >= 1 && $stats['triggered'] === 0) {
            $hasNotification = PriceAlertNotification::where('user_id', $this->_user->id)
                ->where('symbol', 'AAPL')
                ->exists();
            if (!$hasNotification) {
                $this->markTestSkipped('Finance API unavailable — no quote returned for AAPL');
            }
        }

        $this->assertGreaterThanOrEqual(1, $stats['triggered']);

        $notification = PriceAlertNotification::where('user_id', $this->_user->id)
            ->where('symbol', 'AAPL')
            ->where('status', 'SENT')
            ->first();
        $this->assertNotNull($notification, 'A PriceAlertNotification row must exist with status=SENT');

        Mail::assertSent(PriceAlertTriggered::class);

        $alert->refresh();
        $this->assertGreaterThan($initialTriggerCount, $alert->trigger_count);
        $this->assertNotNull($alert->last_triggered_at);
    }

    /**
     * Covers: _getNotifiedTodaySymbols() throttle — daily per-symbol deduplication
     * fires before quote lookup, so already-notified symbols are skipped.
     */
    public function test_evaluate_alerts_skips_symbol_already_notified_today(): void
    {
        $symbol = 'THROTTLE.TST';

        $alert = $this->_createAlert(['symbol' => $symbol, 'status' => 'ACTIVE']);

        // Insert a notification record for today to trigger the throttle
        PriceAlertNotification::create([
            'price_alert_id'       => $alert->id,
            'user_id'              => $this->_user->id,
            'symbol'               => $symbol,
            'notification_channel' => 'email',
            'current_price'        => '100.00',
            'target_price'         => '220.00',
            'alert_type'           => 'PRICE_ABOVE',
            'status'               => 'SENT',
            'sent_at'              => now(),
        ]);

        $countBefore = PriceAlertNotification::where('user_id', $this->_user->id)
            ->where('symbol', $symbol)
            ->count();

        $service = new AlertService();
        $stats = $service->evaluateAlerts($this->_user->id);

        $this->assertGreaterThanOrEqual(1, $stats['skipped']);

        $countAfter = PriceAlertNotification::where('user_id', $this->_user->id)
            ->where('symbol', $symbol)
            ->count();
        $this->assertEquals($countBefore, $countAfter, 'No new notification should be created for throttled symbol');
    }

    /**
     * Covers: PriceAlert::canFire() early-exit — expired alerts are skipped before quote lookup.
     */
    public function test_evaluate_alerts_skips_expired_alert(): void
    {
        $symbol = 'EXPIRED.TST';

        $this->_createAlert([
            'symbol'     => $symbol,
            'status'     => 'ACTIVE',
            'expires_at' => Carbon::yesterday(),
        ]);

        $countBefore = PriceAlertNotification::where('user_id', $this->_user->id)
            ->where('symbol', $symbol)
            ->count();

        $service = new AlertService();
        $stats = $service->evaluateAlerts($this->_user->id);

        $this->assertGreaterThanOrEqual(1, $stats['skipped']);

        $countAfter = PriceAlertNotification::where('user_id', $this->_user->id)
            ->where('symbol', $symbol)
            ->count();
        $this->assertEquals($countBefore, $countAfter, 'No notification should be created for expired alert');
    }

    /**
     * Covers: FinanceApiCronAlertsTrait cache gate — evaluateAlerts() returns early
     * when the interval cache key is still fresh.
     */
    public function test_evaluate_alerts_interval_gate_prevents_early_rerun(): void
    {
        $this->_createAlert(['status' => 'ACTIVE']);

        // Set last-eval to now so the interval gate fires immediately
        Cache::put('finance-api-cron:alerts:last-eval', time(), 3600);

        $countBefore = PriceAlertNotification::where('user_id', $this->_user->id)->count();

        $command = new FinanceApiCron();
        $command->evaluateAlerts();

        $countAfter = PriceAlertNotification::where('user_id', $this->_user->id)->count();
        $this->assertEquals($countBefore, $countAfter, 'No notifications created when interval gate blocks early rerun');
    }

    // =========================================================================
    // LOOKBACK YEARS
    // =========================================================================

    /**
     * Covers: getLookbackYears() returns 2 when position has been held 730+ days.
     */
    public function test_get_lookback_years_returns_2_for_position_held_over_730_days(): void
    {
        $this->_createTrade([
            'symbol'    => 'LOOKBACK.TST',
            'action'    => 'BUY',
            'status'    => 'OPEN',
            'timestamp' => now()->subDays(731),
        ]);

        $service = new AlertService();
        $result = $service->getLookbackYears($this->_user->id, 'LOOKBACK.TST');

        $this->assertEquals(2, $result);
    }

    /**
     * Covers: getLookbackYears() returns 1 for recent positions and the no-position default.
     */
    public function test_get_lookback_years_returns_1_for_position_held_under_730_days(): void
    {
        $this->_createTrade([
            'symbol'    => 'LOOKBACK2.TST',
            'action'    => 'BUY',
            'status'    => 'OPEN',
            'timestamp' => now()->subDays(365),
        ]);

        $service = new AlertService();

        $this->assertEquals(1, $service->getLookbackYears($this->_user->id, 'LOOKBACK2.TST'));
        $this->assertEquals(1, $service->getLookbackYears($this->_user->id, 'NOPOS.TST'));
    }

    // =========================================================================
    // ALL USED SYMBOLS
    // =========================================================================

    /**
     * Covers: getAllUsedSymbols() includes ACTIVE alert symbols and excludes PAUSED ones.
     * If broken, all alert evaluations would silently skip due to missing quotes.
     */
    public function test_all_used_symbols_includes_active_alert_symbols_but_not_paused(): void
    {
        $activeSymbol = 'ACTIVE.ALT';
        $pausedSymbol = 'PAUSED.ALT';

        $this->_createAlert(['symbol' => $activeSymbol, 'status' => 'ACTIVE']);
        $this->_createAlert(['symbol' => $pausedSymbol, 'status' => 'PAUSED']);

        $command = new FinanceApiCron();
        $symbols = $command->getAllUsedSymbols();

        $this->assertContains($activeSymbol, $symbols);
        $this->assertNotContains($pausedSymbol, $symbols);
    }

    // =========================================================================
    // PROJECTED GAIN CALCULATION
    // =========================================================================

    /**
     * Covers: _calculateProjectedGainForUser() arithmetic for an EUR position.
     * avg_cost = 110, total_qty = 20, target = 150 → gain = 800, pct ≈ 36.36%
     */
    public function test_calculate_projected_gain_returns_correct_arithmetic(): void
    {
        $symbol = 'GAIN.TST';

        $this->_createTrade(['symbol' => $symbol, 'quantity' => '10.00000000', 'unit_price' => '100.0000']);
        $this->_createTrade(['symbol' => $symbol, 'quantity' => '10.00000000', 'unit_price' => '120.0000']);

        $alert = $this->_createAlert(['symbol' => $symbol, 'target_price' => '150.000000']);

        $service = new AlertService();
        $m = (new \ReflectionClass(AlertService::class))->getMethod('_calculateProjectedGainForUser');
        $m->setAccessible(true);
        $result = $m->invokeArgs($service, [$this->_user->id, $alert, 150.00, 'EUR']);

        $this->assertNotNull($result);
        $this->assertTrue($result['has_position']);
        $this->assertEqualsWithDelta(110.00, $result['avg_cost'], 0.01);
        $this->assertEqualsWithDelta(20.0, $result['total_qty'], 0.001);
        $this->assertEqualsWithDelta(800.00, $result['gain_value'], 0.01);
        $this->assertEqualsWithDelta(36.3636, $result['gain_pct'], 0.001);
        $this->assertEqualsWithDelta(800.00, $result['gain_eur'], 0.01);
    }

    /**
     * Covers: null guard — alerts for symbols with no open position return null.
     */
    public function test_calculate_projected_gain_returns_null_when_no_open_position(): void
    {
        $alert = $this->_createAlert(['symbol' => 'NOPOS.TST', 'target_price' => '195.000000']);

        $service = new AlertService();
        $m = (new \ReflectionClass(AlertService::class))->getMethod('_calculateProjectedGainForUser');
        $m->setAccessible(true);
        $result = $m->invokeArgs($service, [$this->_user->id, $alert, 195.00, 'EUR']);

        $this->assertNull($result);
    }

    // =========================================================================
    // NOTIFICATION DELETE / RE-TRIGGER
    // =========================================================================

    /**
     * Covers: PriceAlertNotificationController@destroy removes the record (hard delete)
     * and clears the daily throttle so the alert can fire again.
     */
    public function test_notification_delete_removes_record_and_allows_retriggering(): void
    {
        $symbol = 'RETRIG.TST';

        $alert = $this->_createAlert(['symbol' => $symbol, 'status' => 'ACTIVE']);

        $notification = PriceAlertNotification::create([
            'price_alert_id'       => $alert->id,
            'user_id'              => $this->_user->id,
            'symbol'               => $symbol,
            'notification_channel' => 'email',
            'current_price'        => '100.00',
            'target_price'         => '110.00',
            'alert_type'           => 'PRICE_ABOVE',
            'status'               => 'SENT',
            'sent_at'              => now(),
        ]);
        $notifId = $notification->id;

        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->delete(route('myfinance2::price-alerts.history.destroy', $notifId))
            ->assertRedirect(route('myfinance2::price-alerts.history'))
            ->assertSessionHas('success');

        // Hard delete — no soft-delete on PriceAlertNotification
        $this->assertNull(PriceAlertNotification::find($notifId));

        // Throttle cleared: symbol no longer in today's notified list
        $service = new AlertService();
        $reflection = new ReflectionClass(AlertService::class);
        $getNotifiedToday = $reflection->getMethod('_getNotifiedTodaySymbols');
        $getNotifiedToday->setAccessible(true);
        $notifiedSymbols = $getNotifiedToday->invokeArgs($service, [$this->_user->id]);

        $this->assertNotContains($symbol, $notifiedSymbols);
    }

    // =========================================================================
    // SUGGESTION ENGINE
    // =========================================================================

    /**
     * Covers: suggestAlerts() dry-run — computes suggestions without DB writes.
     * Uses real Finance API. Skipped if user has no open BUY trades.
     */
    public function test_suggest_alerts_dry_run_returns_correct_stats_structure(): void
    {
        $hasOpenTrades = Trade::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->_user->id)
            ->where('status', 'OPEN')
            ->where('action', 'BUY')
            ->exists();

        if (!$hasOpenTrades) {
            $this->markTestSkipped('Requires at least 1 open BUY trade for the test user');
        }

        $countBefore = PriceAlert::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->_user->id)
            ->count();

        $service = new AlertService();
        $stats = $service->suggestAlerts($this->_user->id, dryRun: true);

        $this->assertArrayHasKey('created', $stats);
        $this->assertArrayHasKey('skipped', $stats);
        $this->assertArrayHasKey('symbols', $stats);
        $this->assertArrayHasKey('created_ids', $stats);
        $this->assertArrayHasKey('dry_run', $stats);
        $this->assertTrue($stats['dry_run']);
        $this->assertSame([], $stats['created_ids']);
        $this->assertGreaterThanOrEqual(1, $stats['created'] + $stats['skipped']);

        $countAfter = PriceAlert::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->_user->id)
            ->count();
        $this->assertEquals($countBefore, $countAfter, 'Dry run must not insert any records');
    }

    /**
     * Covers: _getExistingAlertSymbols() dedup — a symbol that already has an ACTIVE
     * PRICE_ABOVE alert must be counted as skipped, not created again.
     */
    public function test_suggest_alerts_skips_symbol_with_existing_active_alert(): void
    {
        $symbol = 'DEDUP.TST';

        $this->_createTrade(['symbol' => $symbol, 'action' => 'BUY', 'status' => 'OPEN']);
        $this->_createAlert([
            'symbol'     => $symbol,
            'alert_type' => 'PRICE_ABOVE',
            'status'     => 'ACTIVE',
        ]);

        $service = new AlertService();
        $stats = $service->suggestAlerts($this->_user->id, dryRun: false);

        $this->assertGreaterThanOrEqual(1, $stats['skipped']);

        $alertCount = PriceAlert::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->_user->id)
            ->where('symbol', $symbol)
            ->count();
        $this->assertEquals(1, $alertCount, 'No duplicate alert should be created for already-alerted symbol');
    }

    // =========================================================================
    // WATCHLIST QUERY
    // =========================================================================

    /**
     * Covers: The PriceAlert::whereIn('symbol')->where('status', 'ACTIVE') query used by
     * WatchlistSymbolsDashboard returns only ACTIVE alerts, not PAUSED.
     */
    public function test_watchlist_active_alerts_query_returns_only_active(): void
    {
        $symbol = 'WL.TEST1';

        $this->_createAlert(['symbol' => $symbol, 'status' => 'ACTIVE']);
        $this->_createAlert(['symbol' => $symbol, 'status' => 'PAUSED']);

        $alerts = PriceAlert::withoutGlobalScope(AssignedToUserScope::class)
            ->whereIn('symbol', [$symbol])
            ->where('status', 'ACTIVE')
            ->get();

        $this->assertCount(1, $alerts);
        $this->assertEquals('ACTIVE', $alerts->first()->status);
    }
}
