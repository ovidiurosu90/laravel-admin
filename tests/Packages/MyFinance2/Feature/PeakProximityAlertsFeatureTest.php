<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use ovidiuro\myfinance2\App\Models\PeakProximityAlertSetting;
use ovidiuro\myfinance2\App\Models\PeakProximityNotification;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\PeakProximityAlertService;
use ovidiuro\myfinance2\Mail\PeakProximityAlert;

/**
 * Feature tests for the peak-proximity exit-hint alerts.
 *
 * Scope and isolation: the full WatchlistSymbolsDashboard makes live Yahoo Finance quote calls and
 * returns nothing for a synthetic symbol offline, so it cannot run deterministically in a test. The
 * dashboard build is covered by CategorizationPipelineTest and the DrawdownService unit tests. Here
 * we exercise the service's own contract, the part this feature adds, via evaluateItems(), which is
 * the same loop evaluateForUser() runs once the dashboard items are built. We feed it synthetic
 * dashboard items (symbol TST.AAA, which cannot exist in production) and assert the opt-in gate, the
 * trigger threshold, the email dispatch, the audit row, and the once-per-day-per-symbol throttle.
 *
 * These alerts are OFF by default: a symbol fires only when the user has an ENABLED
 * PeakProximityAlertSetting row, so each triggering test seeds one first.
 *
 * Production-database safety: tests here run against the real database (the base TestCase does not
 * refresh it). This test never saves a User, never inserts a real row: it writes only
 * peak_proximity_notifications and peak_proximity_alert_settings rows under an unused user_id (those
 * tables have no FK to users), and Mail::fake() means no email leaves the process.
 * DatabaseTransactions rolls back both connections.
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

        // Act as a real user (read-only) so any incidental auth() call resolves, but never write to
        // the User model; forgetGuards on teardown avoids a stray save (per CategorizationPipelineTest).
        $user = User::first();
        if (!$user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        $this->actingAs($user);

        // A user_id that owns no real notifications, so the throttle query sees only our rows.
        $this->_userId = (int) DB::connection($this->_conn)
            ->table((new PeakProximityNotification())->getTable())
            ->max('user_id') + 1;

        // Deterministic recipient so the send does not depend on the synthetic user's email.
        config(['alerts.peak_proximity.email_to' => 'peak-proximity-test@example.test']);
        config(['alerts.peak_proximity.threshold_pct' => 5]);

        Mail::fake();
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    /**
     * Build one synthetic dashboard item whose 3M window is within 5% of its peak (so it triggers)
     * while the other windows are far below (so they do not). open_positions is non-empty so the
     * symbol is treated as owned. Mail::fake() does not render the view, so a marker entry suffices.
     *
     * @param float $proximity3m the 3M proximity_pct (>= -5 triggers)
     *
     * @return array
     */
    private function _items(float $proximity3m = -2.0): array
    {
        return [
            self::SYMBOL => [
                'price'          => 100.0,
                'open_positions' => [['marker' => true]],
                'categorization' => [
                    'exit_zones' => [
                        '3m' => [
                            'peak_price_eur'  => 102.0,
                            'peak_price_date' => '2026-05-01',
                            'proximity_pct'   => $proximity3m,
                            'in_zone'         => true,
                        ],
                        '6m' => [
                            'peak_price_eur'  => 140.0,
                            'peak_price_date' => '2026-02-01',
                            'proximity_pct'   => -28.57,
                            'in_zone'         => false,
                        ],
                        '1y' => [
                            'peak_price_eur'  => 160.0,
                            'peak_price_date' => '2025-09-01',
                            'proximity_pct'   => -37.5,
                            'in_zone'         => false,
                        ],
                        '2y' => null,
                    ],
                ],
            ],
        ];
    }

    /**
     * Seed a per-symbol opt-in setting for the synthetic user.
     *
     * @param string      $status ENABLED | DISABLED
     * @param string|null $until  auto-revert date (Y-m-d) or null for permanent
     * @param string      $symbol
     *
     * @return PeakProximityAlertSetting
     */
    private function _seedSetting(
        string $status = PeakProximityAlertSetting::ENABLED,
        ?string $until = null,
        string $symbol = self::SYMBOL
    ): PeakProximityAlertSetting
    {
        return PeakProximityAlertSetting::updateOrCreate(
            ['user_id' => $this->_userId, 'symbol' => $symbol],
            ['status' => $status, 'until' => $until]
        );
    }

    public function test_default_disabled_does_not_fire(): void
    {
        // No setting row at all: the symbol is opted out by default.
        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items(-2.0));

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(0, $stats['processed']);
        Mail::assertNothingSent();
        $this->assertSame(
            0,
            PeakProximityNotification::where('user_id', $this->_userId)->count()
        );
    }

    public function test_disabled_symbol_does_not_fire(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::DISABLED);

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items(-2.0));

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(0, $stats['processed']);
        Mail::assertNothingSent();
    }

    public function test_enabled_symbol_fires_then_throttles_same_day(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::ENABLED);
        $service = new PeakProximityAlertService();

        $first = $service->evaluateItems($this->_userId, $this->_items(-2.0));
        $this->assertSame(1, $first['triggered']);
        $this->assertContains(self::SYMBOL, $first['symbols']);

        Mail::assertSent(PeakProximityAlert::class, function (PeakProximityAlert $mail) {
            return $mail->hasTo('peak-proximity-test@example.test');
        });

        $row = PeakProximityNotification::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)
            ->where('status', 'SENT')
            ->first();
        $this->assertNotNull($row, 'A SENT audit row must exist for the triggered symbol');
        $this->assertSame('3m', $row->triggered_windows);
        $this->assertEqualsWithDelta(-2.0, (float) $row->closest_proximity_pct, 0.001);
        $this->assertSame('3m:2026-05-01', $row->peak_dates);

        // Second same-day run is throttled.
        $second = $service->evaluateItems($this->_userId, $this->_items(-2.0));
        $this->assertSame(0, $second['triggered']);
        $this->assertSame(1, $second['skipped']);
        Mail::assertSent(PeakProximityAlert::class, 1);
        $this->assertSame(
            1,
            PeakProximityNotification::where('user_id', $this->_userId)
                ->where('symbol', self::SYMBOL)
                ->count()
        );
    }

    public function test_does_not_trigger_when_outside_threshold(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::ENABLED);

        // 3M now 8% from peak, beyond the tight 2% near-term threshold; no window qualifies.
        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items(-8.0));

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(1, $stats['skipped']);
        Mail::assertNothingSent();
        $this->assertSame(
            0,
            PeakProximityNotification::where('user_id', $this->_userId)->count()
        );
    }

    public function test_per_window_thresholds_allow_looser_long_term_peaks(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::ENABLED);

        // 3M is 6% from peak (beyond the 2% near-term limit, so it does NOT fire), but 2Y is 9%
        // from peak (within the looser 10% long-term limit, so it DOES fire). This proves the
        // thresholds are applied per window rather than uniformly.
        $items = [
            self::SYMBOL => [
                'price'          => 100.0,
                'open_positions' => [['marker' => true]],
                'categorization' => [
                    'exit_zones' => [
                        '3m' => ['peak_price_date' => '2026-05-20', 'proximity_pct' => -6.0, 'in_zone' => true],
                        '6m' => ['peak_price_date' => '2026-03-01', 'proximity_pct' => -15.0, 'in_zone' => false],
                        '1y' => ['peak_price_date' => '2025-10-01', 'proximity_pct' => -20.0, 'in_zone' => false],
                        '2y' => ['peak_price_date' => '2024-12-01', 'proximity_pct' => -9.0, 'in_zone' => false],
                    ],
                ],
            ],
        ];

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $items);

        $this->assertSame(1, $stats['triggered']);

        $row = PeakProximityNotification::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)
            ->where('status', 'SENT')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('2y', $row->triggered_windows);
        $this->assertEqualsWithDelta(-9.0, (float) $row->closest_proximity_pct, 0.001);
    }

    public function test_threshold_override_applies_uniformly_to_all_windows(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::ENABLED);

        // The --threshold override (passed as $thresholdPct) replaces every window's threshold.
        // At 7% uniform, the 3M at -6% now qualifies even though its per-window limit is 2%.
        $items = [
            self::SYMBOL => [
                'price'          => 100.0,
                'open_positions' => [['marker' => true]],
                'categorization' => [
                    'exit_zones' => [
                        '3m' => ['peak_price_date' => '2026-05-20', 'proximity_pct' => -6.0, 'in_zone' => true],
                        '6m' => ['peak_price_date' => '2026-03-01', 'proximity_pct' => -15.0, 'in_zone' => false],
                        '1y' => null,
                        '2y' => null,
                    ],
                ],
            ],
        ];

        $stats = (new PeakProximityAlertService())
            ->evaluateItems($this->_userId, $items, dryRun: false, thresholdPct: 7.0);

        $this->assertSame(1, $stats['triggered']);
        $row = PeakProximityNotification::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)
            ->first();
        $this->assertSame('3m', $row->triggered_windows);
    }

    public function test_dry_run_sends_nothing_and_records_nothing(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::ENABLED);

        $stats = (new PeakProximityAlertService())
            ->evaluateItems($this->_userId, $this->_items(-2.0), dryRun: true);

        $this->assertSame(1, $stats['triggered']);
        Mail::assertNothingSent();
        $this->assertSame(
            0,
            PeakProximityNotification::where('user_id', $this->_userId)->count()
        );
    }

    public function test_symbol_filter_limits_evaluation(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::ENABLED);

        $stats = (new PeakProximityAlertService())->evaluateItems(
            $this->_userId,
            $this->_items(-2.0),
            dryRun: false,
            filterSymbols: ['SOME.OTHER']
        );

        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['triggered']);
        Mail::assertNothingSent();
    }

    public function test_enable_until_past_date_reverts_to_disabled(): void
    {
        // Enabled but the "enable until" window has passed: normalization flips it to permanently
        // disabled and clears the date, so it must not fire.
        $this->_seedSetting(PeakProximityAlertSetting::ENABLED, now()->subDay()->format('Y-m-d'));

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items(-2.0));

        $this->assertSame(0, $stats['triggered']);
        Mail::assertNothingSent();

        $setting = PeakProximityAlertSetting::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)
            ->first();
        $this->assertSame(PeakProximityAlertSetting::DISABLED, $setting->status);
        $this->assertNull($setting->until);
    }

    public function test_enable_until_future_date_still_fires(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::ENABLED, now()->addDay()->format('Y-m-d'));

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items(-2.0));

        $this->assertSame(1, $stats['triggered']);
        Mail::assertSent(PeakProximityAlert::class, 1);
    }

    public function test_pause_until_past_date_reverts_to_enabled(): void
    {
        // Disabled with a "pause until" date in the past: normalization flips it back to permanently
        // enabled, so it fires.
        $this->_seedSetting(PeakProximityAlertSetting::DISABLED, now()->subDay()->format('Y-m-d'));

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items(-2.0));

        $this->assertSame(1, $stats['triggered']);
        Mail::assertSent(PeakProximityAlert::class, 1);

        $setting = PeakProximityAlertSetting::where('user_id', $this->_userId)
            ->where('symbol', self::SYMBOL)
            ->first();
        $this->assertSame(PeakProximityAlertSetting::ENABLED, $setting->status);
        $this->assertNull($setting->until);
    }

    public function test_pause_until_future_date_stays_disabled(): void
    {
        $this->_seedSetting(PeakProximityAlertSetting::DISABLED, now()->addDay()->format('Y-m-d'));

        $stats = (new PeakProximityAlertService())->evaluateItems($this->_userId, $this->_items(-2.0));

        $this->assertSame(0, $stats['triggered']);
        Mail::assertNothingSent();
    }

    public function test_all_users_run_only_processes_users_with_enabled_alerts(): void
    {
        $service = new PeakProximityAlertService();

        // A user id one past the highest trade owner: it has no open positions by construction.
        $noPositionUserId = (int) DB::connection($this->_conn)
            ->table('trades')
            ->max('user_id') + 1;

        // Even with an ENABLED setting, a user holding no positions is excluded by the intersection
        // with getUserIdsWithOpenPositions().
        PeakProximityAlertSetting::updateOrCreate(
            ['user_id' => $noPositionUserId, 'symbol' => self::SYMBOL],
            ['status' => PeakProximityAlertSetting::ENABLED, 'until' => null]
        );

        $result      = $service->getUserIdsWithEnabledAlerts();
        $openUserIds = $service->getUserIdsWithOpenPositions();

        $this->assertNotContains($noPositionUserId, $result);

        // The intersection contract: every returned id holds positions AND enabled at least one symbol.
        foreach ($result as $id) {
            $this->assertContains($id, $openUserIds);
        }
    }
}
