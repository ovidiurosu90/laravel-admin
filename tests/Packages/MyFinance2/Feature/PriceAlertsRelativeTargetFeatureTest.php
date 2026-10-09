<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use ovidiuro\myfinance2\App\Models\PriceAlert;
use ovidiuro\myfinance2\App\Models\PriceAlertNotification;
use ovidiuro\myfinance2\App\Models\StockSplit;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\AlertService;
use ovidiuro\myfinance2\App\Services\ApplySplitService;
use ovidiuro\myfinance2\App\Services\RelativeAlertTargetService;
use ovidiuro\myfinance2\Mail\PriceAlertTriggered;

/**
 * Feature tests for RELATIVE price-alert targets ("5% below the 52W high").
 *
 * Synthetic TST.* symbols only; stats_historical rows are seeded with the query builder so the
 * reference windows are covered and no backfill runs. Live quotes and backfills are stubbed, so
 * nothing calls the network. Engine tests run under an unused user id so they never touch the
 * real user's alerts. Everything is rolled back by DatabaseTransactions on both connections.
 */
class PriceAlertsRelativeTargetFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'TST.REL';

    private ?User $_user = null;

    private string $_conn = '';

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function setUp(): void
    {
        parent::setUp();

        AssignedToUserScope::enable();
        $this->_conn = (string) config('myfinance2.db_connection', 'myfinance2_mysql');
        $this->_user = User::first();

        if (!$this->_user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }

        $this->actingAs($this->_user);
        Mail::fake();
        $this->_stubTargetService(100.0);
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
     * Seed one close per day from $days days ago to yesterday at $price, with overrides by
     * "days ago" offset.
     *
     * @param array<int, float> $overrides daysAgo => close
     */
    private function _seedCloses(string $symbol, int $days, float $price, array $overrides = []): void
    {
        $rows = [];
        for ($i = $days; $i >= 1; $i--) {
            $rows[] = [
                'date'              => Carbon::today()->subDays($i)->format('Y-m-d'),
                'symbol'            => $symbol,
                'unit_price'        => $overrides[$i] ?? $price,
                'currency_iso_code' => 'USD',
                'created_at'        => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::connection($this->_conn)->table('stats_historical')->insert($chunk);
        }
    }

    /**
     * Replace the web-side service with one that has a fixed live price and never backfills or
     * fetches fallback history.
     */
    private function _stubTargetService(?float $livePrice): void
    {
        $stub = new class($livePrice) extends RelativeAlertTargetService
        {
            public function __construct(private ?float $_stubPrice)
            {
                parent::__construct();
            }

            public function livePrice(string $symbol): ?float
            {
                return $this->_stubPrice;
            }

            public function backfill(string $symbol, string $start, string $end): void
            {
            }

            public function fallbackCloses(string $symbol): array
            {
                return [];
            }
        };

        $this->app->instance(RelativeAlertTargetService::class, $stub);
    }

    /**
     * Alert engine with stubbed live quotes.
     *
     * @param array<string, float> $prices symbol => price
     */
    private function _engine(array $prices): AlertService
    {
        return new class($prices) extends AlertService
        {
            public function __construct(private array $_stubPrices)
            {
                parent::__construct();
            }

            protected function _getQuotes(array $symbols): array
            {
                $quotes = [];
                foreach ($symbols as $symbol) {
                    if (isset($this->_stubPrices[$symbol])) {
                        $quotes[$symbol] = ['price' => $this->_stubPrices[$symbol]];
                    }
                }

                return $quotes;
            }
        };
    }

    private function _unusedUserId(): int
    {
        return (int) DB::connection($this->_conn)->table('price_alerts')->max('user_id') + 1000;
    }

    /**
     * Insert an alert with the query builder (explicit user id, no creating hook).
     */
    private function _insertAlert(int $userId, array $fields): PriceAlert
    {
        $id = DB::connection($this->_conn)->table('price_alerts')->insertGetId(array_merge([
            'user_id'              => $userId,
            'symbol'               => self::SYMBOL,
            'alert_type'           => 'PRICE_ABOVE',
            'target_price'         => 1.0,
            'target_mode'          => 'RELATIVE',
            'reference_type'       => 'HIGH',
            'reference_window'     => '1y',
            'offset_pct'           => 5,
            'status'               => 'ACTIVE',
            'source'               => 'manual',
            'notification_channel' => 'email',
            'trigger_count'        => 0,
            'created_at'           => now(),
            'updated_at'           => now(),
        ], $fields));

        return PriceAlert::withoutGlobalScope(AssignedToUserScope::class)->findOrFail($id);
    }

    private function _post(string $route, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware()->actingAs($this->_user)->post($route, $data);
    }

    private function _relativePayload(array $overrides = []): array
    {
        return array_merge([
            'symbol'        => self::SYMBOL,
            'alert_type'    => 'PRICE_ABOVE',
            'target_mode'   => 'RELATIVE',
            'reference_key' => 'HIGH:1y',
            'offset_pct'    => '5',
            'target_price'  => '1',
            'status'        => 'ACTIVE',
        ], $overrides);
    }

    private function _latestAlert(): PriceAlert
    {
        return PriceAlert::where('symbol', self::SYMBOL)->orderByDesc('id')->firstOrFail();
    }

    // =========================================================================
    // STORE / UPDATE
    // =========================================================================

    public function test_store_relative_alert_resolves_target_from_stored_history(): void
    {
        $this->_seedCloses(self::SYMBOL, 400, 100.0, [40 => 120.0]);

        $this->_post(route('myfinance2::price-alerts.store'), $this->_relativePayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('myfinance2::price-alerts.index'));

        $alert = $this->_latestAlert();
        $this->assertSame('RELATIVE', $alert->target_mode);
        $this->assertSame('HIGH', $alert->reference_type);
        $this->assertSame('1y', $alert->reference_window);
        $this->assertEqualsWithDelta(120.0, (float) $alert->reference_price, 0.000001);
        $this->assertSame(Carbon::today()->subDays(40)->format('Y-m-d'), $alert->reference_date->format('Y-m-d'));
        // The submitted preview (1) is ignored; the server resolves 120 x 0.95
        $this->assertEqualsWithDelta(114.0, (float) $alert->target_price, 0.000001);
        $this->assertNotNull($alert->reference_resolved_at);
    }

    public function test_store_relative_alert_bumps_reference_with_a_new_live_high(): void
    {
        $this->_seedCloses(self::SYMBOL, 400, 100.0);
        $this->_stubTargetService(130.0);

        $this->_post(route('myfinance2::price-alerts.store'), $this->_relativePayload())
            ->assertSessionHasNoErrors();

        $alert = $this->_latestAlert();
        $this->assertEqualsWithDelta(130.0, (float) $alert->reference_price, 0.000001);
        $this->assertSame(Carbon::today()->format('Y-m-d'), $alert->reference_date->format('Y-m-d'));
        $this->assertEqualsWithDelta(123.5, (float) $alert->target_price, 0.000001);
    }

    public function test_store_fixed_alert_keeps_reference_columns_empty(): void
    {
        $this->_post(route('myfinance2::price-alerts.store'), [
            'symbol'       => self::SYMBOL,
            'alert_type'   => 'PRICE_ABOVE',
            'target_price' => '150',
            'status'       => 'ACTIVE',
        ])->assertSessionHasNoErrors();

        $alert = $this->_latestAlert();
        $this->assertSame('FIXED', $alert->target_mode);
        $this->assertEqualsWithDelta(150.0, (float) $alert->target_price, 0.000001);
        $this->assertNull($alert->reference_type);
        $this->assertNull($alert->offset_pct);
        $this->assertNull($alert->reference_price);
    }

    public function test_store_fixed_alert_ignores_leftover_relative_inputs(): void
    {
        // The hidden relative inputs never block (or leak into) a FIXED alert
        $this->_post(route('myfinance2::price-alerts.store'), [
            'symbol'        => self::SYMBOL,
            'alert_type'    => 'PRICE_ABOVE',
            'target_mode'   => 'FIXED',
            'target_price'  => '150',
            'reference_key' => 'HIGH:1y',
            'offset_pct'    => '-3',
            'status'        => 'ACTIVE',
        ])->assertSessionHasNoErrors();

        $alert = $this->_latestAlert();
        $this->assertSame('FIXED', $alert->target_mode);
        $this->assertNull($alert->reference_type);
        $this->assertNull($alert->offset_pct);
    }

    public function test_store_rejects_high_offset_above_95(): void
    {
        $this->_seedCloses(self::SYMBOL, 400, 100.0);

        $this->_post(route('myfinance2::price-alerts.store'), $this->_relativePayload(['offset_pct' => '96']))
            ->assertSessionHasErrors('offset_pct');

        $this->assertFalse(PriceAlert::where('symbol', self::SYMBOL)->exists());
    }

    public function test_store_accepts_low_offset_up_to_500(): void
    {
        $this->_seedCloses(self::SYMBOL, 400, 100.0, [10 => 50.0]);

        $this->_post(route('myfinance2::price-alerts.store'), $this->_relativePayload([
            'reference_key' => 'LOW:1y',
            'offset_pct'    => '500',
        ]))->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(300.0, (float) $this->_latestAlert()->target_price, 0.000001);
    }

    public function test_store_rejects_relative_target_on_order_alert(): void
    {
        $this->_seedCloses(self::SYMBOL, 400, 100.0);

        $this->_post(route('myfinance2::price-alerts.store'), $this->_relativePayload(['source' => 'order']))
            ->assertSessionHasErrors('target_mode');
    }

    public function test_store_rejects_window_without_enough_history(): void
    {
        // 60 days of history cannot resolve a 2Y high, and the (stubbed) backfill adds nothing
        $this->_seedCloses(self::SYMBOL, 60, 100.0);

        $payload = $this->_relativePayload(['reference_key' => 'HIGH:2y']);
        $this->_post(route('myfinance2::price-alerts.store'), $payload)->assertSessionHasErrors('reference_key');

        $this->assertFalse(PriceAlert::where('symbol', self::SYMBOL)->exists());
    }

    public function test_update_switching_to_fixed_clears_reference_columns(): void
    {
        $this->_seedCloses(self::SYMBOL, 400, 100.0);
        $this->_post(route('myfinance2::price-alerts.store'), $this->_relativePayload())->assertSessionHasNoErrors();
        $alert = $this->_latestAlert();

        $this->withoutMiddleware()->actingAs($this->_user)
            ->put(route('myfinance2::price-alerts.update', $alert->id), [
                'symbol'       => self::SYMBOL,
                'alert_type'   => 'PRICE_ABOVE',
                'target_mode'  => 'FIXED',
                'target_price' => '140',
                'status'       => 'ACTIVE',
            ])->assertSessionHasNoErrors();

        $alert->refresh();
        $this->assertSame('FIXED', $alert->target_mode);
        $this->assertEqualsWithDelta(140.0, (float) $alert->target_price, 0.000001);
        $this->assertNull($alert->reference_type);
        $this->assertNull($alert->reference_window);
        $this->assertNull($alert->offset_pct);
        $this->assertNull($alert->reference_price);
        $this->assertNull($alert->reference_resolved_at);
    }

    public function test_update_relative_alert_re_resolves_target(): void
    {
        $this->_seedCloses(self::SYMBOL, 400, 100.0, [20 => 110.0]);
        $this->_post(route('myfinance2::price-alerts.store'), $this->_relativePayload())->assertSessionHasNoErrors();
        $alert = $this->_latestAlert();

        $this->withoutMiddleware()->actingAs($this->_user)
            ->put(route('myfinance2::price-alerts.update', $alert->id), $this->_relativePayload([
                'reference_key' => 'LOW:3m',
                'offset_pct'    => '10',
            ]))->assertSessionHasNoErrors();

        $alert->refresh();
        $this->assertSame('LOW', $alert->reference_type);
        $this->assertSame('3m', $alert->reference_window);
        $this->assertEqualsWithDelta(110.0, (float) $alert->target_price, 0.000001);
    }

    public function test_resume_resolves_the_target_immediately(): void
    {
        $this->_seedCloses(self::SYMBOL, 400, 100.0, [5 => 200.0]);
        $alert = $this->_insertAlert($this->_user->id, ['status' => 'PAUSED', 'target_price' => 1.0]);

        $this->_post(route('myfinance2::price-alerts.resume', $alert->id), [])->assertSessionHas('success');

        $alert->refresh();
        $this->assertSame('ACTIVE', $alert->status);
        $this->assertEqualsWithDelta(190.0, (float) $alert->target_price, 0.000001);
        $this->assertNotNull($alert->reference_resolved_at);
    }

    // =========================================================================
    // PREVIEW ENDPOINT
    // =========================================================================

    public function test_references_endpoint_returns_all_eight_references(): void
    {
        $this->_seedCloses(self::SYMBOL, 400, 100.0, [30 => 125.0]);

        $response = $this->withoutMiddleware()->actingAs($this->_user)
            ->getJson(route('myfinance2::price-alerts.references', ['symbol' => self::SYMBOL]));

        // JSON drops the zero fraction (100.0 is encoded as 100), so compare prices numerically
        $response->assertOk()
            ->assertJsonPath('references.HIGH:1y.covered', true)
            ->assertJsonPath('references.HIGH:1y.direction', -1)
            ->assertJsonPath('references.LOW:3m.direction', 1)
            ->assertJsonPath('references.HIGH:2y.covered', false);
        $this->assertEqualsWithDelta(100.0, $response->json('live_price'), 0.000001);
        $this->assertEqualsWithDelta(125.0, $response->json('references.HIGH:1y.price'), 0.000001);
        $this->assertCount(8, $response->json('references'));
    }

    // =========================================================================
    // ENGINE
    // =========================================================================

    public function test_engine_refreshes_target_and_fires_with_target_label(): void
    {
        config(['alerts.email_to' => 'alerts@example.test', 'alerts.market_hours_only' => false]);
        $userId = $this->_unusedUserId();
        $this->_seedCloses(self::SYMBOL, 400, 100.0, [15 => 110.0]);
        $alert = $this->_insertAlert($userId, ['target_price' => 500.0]);

        // Target 110 x 0.95 = 104.5; the live price 105 is above it
        $stats = $this->_engine([self::SYMBOL => 105.0])->evaluateAlerts($userId);

        $this->assertSame(1, $stats['triggered']);
        $alert->refresh();
        $this->assertEqualsWithDelta(104.5, (float) $alert->target_price, 0.000001);
        $this->assertEqualsWithDelta(110.0, (float) $alert->reference_price, 0.000001);

        $notification = PriceAlertNotification::where('price_alert_id', $alert->id)->firstOrFail();
        $this->assertEqualsWithDelta(104.5, (float) $notification->target_price, 0.000001);
        $this->assertStringStartsWith('5% below 52W high, ref 110', (string) $notification->target_label);
        Mail::assertSent(PriceAlertTriggered::class);
    }

    public function test_engine_target_follows_a_new_window_high(): void
    {
        $userId = $this->_unusedUserId();
        $this->_seedCloses(self::SYMBOL, 400, 100.0);
        $alert = $this->_insertAlert($userId, []);

        $this->_engine([self::SYMBOL => 90.0])->evaluateAlerts($userId);
        $alert->refresh();
        $this->assertEqualsWithDelta(95.0, (float) $alert->target_price, 0.000001);

        // A live price above the stored high lifts the reference (and the target) with it
        $this->_engine([self::SYMBOL => 120.0])->evaluateAlerts($userId);
        $alert->refresh();
        $this->assertEqualsWithDelta(114.0, (float) $alert->target_price, 0.000001);
        $this->assertSame(Carbon::today()->format('Y-m-d'), $alert->reference_date->format('Y-m-d'));
    }

    public function test_engine_skips_relative_alert_without_history_coverage(): void
    {
        config(['alerts.email_to' => 'alerts@example.test']);
        $userId = $this->_unusedUserId();
        $this->_seedCloses(self::SYMBOL, 20, 100.0);
        $alert = $this->_insertAlert($userId, ['target_price' => 1.0]);

        $stats = $this->_engine([self::SYMBOL => 105.0])->evaluateAlerts($userId);

        $this->assertSame(0, $stats['triggered']);
        $this->assertSame(1, $stats['skipped']);
        $alert->refresh();
        $this->assertEqualsWithDelta(1.0, (float) $alert->target_price, 0.000001);
        $this->assertFalse(PriceAlertNotification::where('price_alert_id', $alert->id)->exists());
    }

    public function test_engine_keeps_the_target_an_expired_relative_alert_expired_with(): void
    {
        $userId = $this->_unusedUserId();
        $this->_seedCloses(self::SYMBOL, 400, 100.0);
        $alert = $this->_insertAlert($userId, ['target_price' => 80.0, 'expires_at' => now()->subDay()]);

        $this->_engine([self::SYMBOL => 90.0])->evaluateAlerts($userId);

        $alert->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $alert->target_price, 0.000001);
        $this->assertNull($alert->reference_resolved_at);
        $this->assertFalse($alert->isReferenceStale());
    }

    public function test_engine_leaves_fixed_alert_target_untouched(): void
    {
        $userId = $this->_unusedUserId();
        $this->_seedCloses(self::SYMBOL, 400, 100.0);
        $alert = $this->_insertAlert($userId, [
            'target_mode'      => 'FIXED',
            'reference_type'   => null,
            'reference_window' => null,
            'offset_pct'       => null,
            'target_price'     => 150.0,
        ]);

        $this->_engine([self::SYMBOL => 105.0])->evaluateAlerts($userId);

        $alert->refresh();
        $this->assertEqualsWithDelta(150.0, (float) $alert->target_price, 0.000001);
        $this->assertNull($alert->reference_resolved_at);
    }

    // =========================================================================
    // SPLITS, EMAILS, BADGES
    // =========================================================================

    public function test_split_apply_skips_relative_alerts(): void
    {
        $fixed    = $this->_insertAlert($this->_user->id, [
            'target_mode' => 'FIXED', 'reference_type' => null, 'reference_window' => null,
            'offset_pct'  => null, 'target_price' => 100.0,
        ]);
        $relative = $this->_insertAlert($this->_user->id, ['target_price' => 100.0]);

        $split = StockSplit::create([
            'symbol'            => self::SYMBOL,
            'split_date'        => Carbon::today()->format('Y-m-d'),
            'ratio_numerator'   => 4,
            'ratio_denominator' => 1,
        ]);
        $summary = (new ApplySplitService())->apply($split);

        $this->assertSame(1, $summary['alerts_adjusted']);
        $this->assertSame([$fixed->id], array_column($summary['changed_alerts'], 'id'));
        $this->assertEqualsWithDelta(25.0, (float) $fixed->refresh()->target_price, 0.000001);
        $this->assertEqualsWithDelta(100.0, (float) $relative->refresh()->target_price, 0.000001);
    }

    public function test_triggered_email_shows_the_relative_label(): void
    {
        $alert = $this->_insertAlert($this->_user->id, [
            'target_price'    => 104.5,
            'reference_price' => 110.0,
            'reference_date'  => Carbon::today()->subDays(15)->format('Y-m-d'),
        ]);

        $html = (new PriceAlertTriggered($alert, 105.0))->render();

        $this->assertStringContainsString('5% below 52W high', $html);
        $this->assertStringContainsString('ref 110.00', $html);
    }

    public function test_badge_target_shows_short_label_for_relative_and_price_for_fixed(): void
    {
        $relative = $this->_insertAlert($this->_user->id, ['reference_resolved_at' => now()]);
        $fixed    = $this->_insertAlert($this->_user->id, [
            'target_mode' => 'FIXED', 'reference_type' => null, 'reference_window' => null, 'offset_pct' => null,
        ]);

        $relativeHtml = view('myfinance2::partials.alert-badge-target', [
            'alert' => $relative, 'priceHtml' => '114.00 $',
        ])->render();
        $fixedHtml    = view('myfinance2::partials.alert-badge-target', [
            'alert' => $fixed, 'priceHtml' => '150.00 $',
        ])->render();

        $this->assertStringContainsString('52W H -5%', $relativeHtml);
        $this->assertStringNotContainsString('114.00', $relativeHtml);
        $this->assertStringNotContainsString('fa-exclamation-triangle', $relativeHtml);
        $this->assertSame('150.00 $', trim($fixedHtml));
    }

    public function test_badge_target_flags_a_stale_reference(): void
    {
        $stale = $this->_insertAlert($this->_user->id, ['reference_resolved_at' => now()->subDays(2)]);

        $html = view('myfinance2::partials.alert-badge-target', ['alert' => $stale, 'priceHtml' => ''])->render();

        $this->assertStringContainsString('fa-exclamation-triangle', $html);
    }
}
