<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use ovidiuro\myfinance2\App\Models\DipBuyingNotification;
use ovidiuro\myfinance2\App\Models\DipBuyingSetting;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\DipBuyingBacktestService;
use ovidiuro\myfinance2\App\Services\DipBuyingPlanService;

/**
 * Controller / route tests for the Dip Buying Plan settings, history and backtest report, plus the
 * backtest mistake-detection (early exhaustion and cash drag) exercised on a synthetic path via the
 * pure private timeline builders so it never touches real trades or the read-only VUSA benchmark.
 *
 * Settings rows are written to dip_buying_settings (a new table with no FK to users) for the acting
 * user; withoutMiddleware() skips the role gate; DatabaseTransactions rolls back both connections.
 */
class DipBuyingPlanFeatureTest extends TestCase
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

        // Tests run against the production DB; the acting user may already own a real settings row.
        // Clear it so every test starts from a known empty slate. DatabaseTransactions (on both the
        // default and the myfinance2 connection) rolls this back, so no real data is touched.
        DipBuyingSetting::where('user_id', $this->_user->id)->delete();
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_save_route_persists_settings_and_clears_cache(): void
    {
        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->post(route('myfinance2::dip-buying-alerts.save'), [
                'pool_amount_eur' => 12000,
                'enabled'         => '1',
                'email_enabled'   => '1',
                'bands'           => '',
            ])
            ->assertRedirect(route('myfinance2::dip-buying-alerts.index'))
            ->assertSessionHas('success');

        $setting = DipBuyingSetting::where('user_id', $this->_user->id)->first();
        $this->assertNotNull($setting);
        $this->assertSame(DipBuyingSetting::ENABLED, $setting->status);
        $this->assertTrue($setting->email_enabled);
        $this->assertEqualsWithDelta(12000.0, (float) $setting->pool_amount_eur, 0.001);
        $this->assertNull($setting->bands, 'Blank ladder JSON stores null (use the default).');
    }

    public function test_save_route_rejects_invalid_bands_json(): void
    {
        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->post(route('myfinance2::dip-buying-alerts.save'), [
                'pool_amount_eur' => 10000,
                'bands'           => '[{"dd":10}]', // missing target
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull(DipBuyingSetting::where('user_id', $this->_user->id)->first());
    }

    public function test_settings_and_history_pages_render(): void
    {
        DipBuyingNotification::create($this->_notificationRow());

        $this->withoutMiddleware()->actingAs($this->_user)
            ->get(route('myfinance2::dip-buying-alerts.index'))
            ->assertOk()
            ->assertSee('Dip Buying Plan');

        $this->withoutMiddleware()->actingAs($this->_user)
            ->get(route('myfinance2::dip-buying-alerts.history'))
            ->assertOk()
            ->assertSee('Crossed behind');
    }

    public function test_history_page_renders_new_episode_badge(): void
    {
        DipBuyingNotification::create(array_merge($this->_notificationRow(), [
            'trigger'     => 'new_episode',
            'anchor_date' => '2025-06-15',
        ]));

        $this->withoutMiddleware()->actingAs($this->_user)
            ->get(route('myfinance2::dip-buying-alerts.history'))
            ->assertOk()
            ->assertSee('New episode');
    }

    public function test_notification_persists_and_retrieves_anchor_date(): void
    {
        $anchorDate = '2025-06-15';

        DipBuyingNotification::create(array_merge($this->_notificationRow(), [
            'anchor_date' => $anchorDate,
        ]));

        $saved = DipBuyingNotification::where('user_id', $this->_user->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($saved->anchor_date);
        $this->assertSame($anchorDate, $saved->anchor_date->format('Y-m-d'));
    }

    public function test_backtest_page_renders(): void
    {
        $this->withoutMiddleware()->actingAs($this->_user)
            ->get(route('myfinance2::dip-buying-alerts.backtest'))
            ->assertOk()
            ->assertSee('Self-validation backtest');
    }

    public function test_panel_hidden_when_feature_disabled(): void
    {
        // No setting row => engine returns null => no plan on /positions.
        $plan = (new DipBuyingPlanService())->buildForUser((int) $this->_user->id);
        $this->assertNull($plan);
    }

    public function test_backtest_detects_early_exhaustion(): void
    {
        $service = new DipBuyingBacktestService();

        // All-in at a shallow 11% drawdown, then the episode goes to 36%: early exhaustion.
        $buys = [
            ['date' => '2025-02-25', 'eur' => 10000.0, 'dd' => 11.0],
        ];
        $actual = $this->_invoke($service, '_actualTimeline', [$buys, 10000.0, 36.0]);

        $this->assertTrue($actual['early_exhaustion']);
        $this->assertSame(11.0, $actual['exhaustion_dd']);
        $this->assertSame(100.0, $actual['deployed_pct']);
    }

    public function test_episode_spans_split_on_hysteresis(): void
    {
        $service = new DipBuyingBacktestService();

        // Two dips separated by a recovery below T/2, on the effective-drawdown axis. With T = 5 the
        // exit floor is 2.5, so this must yield two distinct episodes, each anchored at its trailing
        // peak: the lowest-drawdown (highest-price) day since the previous episode closed.
        $effDd = [
            '2025-01-01' => 0.0,
            '2025-01-02' => 6.0,   // enter episode 1
            '2025-01-03' => 12.0,  // low 1
            '2025-01-04' => 4.0,   // still above exit (2.5), stays open
            '2025-01-05' => 1.0,   // recovers below exit, closes episode 1; calmest day in the gap
            '2025-01-06' => 2.0,   // shallower recovery than 01-05, so not the trailing peak
            '2025-01-07' => 8.0,   // enter episode 2
            '2025-01-08' => 9.0,   // low 2
            '2025-01-09' => 0.0,   // closes episode 2
        ];

        $spans = $this->_invoke($service, '_episodeSpans', [$effDd, 5.0]);

        $this->assertCount(2, $spans);

        $this->assertSame('2025-01-01', $spans[0]['peak_date']);
        $this->assertSame('2025-01-03', $spans[0]['low_date']);
        $this->assertSame('2025-01-05', $spans[0]['end_date']);
        $this->assertSame(12.0, $spans[0]['max_dd']);

        // Episode 2's peak is 01-05 (dd 1.0), the highest point in the gap, not 01-06 (dd 2.0).
        $this->assertSame('2025-01-05', $spans[1]['peak_date']);
        $this->assertSame('2025-01-08', $spans[1]['low_date']);
        $this->assertSame(9.0, $spans[1]['max_dd']);
    }

    public function test_episode_spans_threshold_filters_shallow_dips(): void
    {
        $service = new DipBuyingBacktestService();

        // A single 9% dip: visible at T = 5, hidden at T = 12.
        $effDd = ['d1' => 0.0, 'd2' => 9.0, 'd3' => 0.0];

        $this->assertCount(1, $this->_invoke($service, '_episodeSpans', [$effDd, 5.0]));
        $this->assertCount(0, $this->_invoke($service, '_episodeSpans', [$effDd, 12.0]));
    }

    public function test_min_drop_resolves_and_clamps(): void
    {
        $service = new DipBuyingBacktestService();

        $default = (float) config('alerts.dip_buying.min_drop_pct', 5);
        $this->assertSame($default, $this->_invoke($service, '_resolveMinDrop', [null]));
        $this->assertSame($default, $this->_invoke($service, '_resolveMinDrop', [0.0]));
        $this->assertSame(8.0, $this->_invoke($service, '_resolveMinDrop', [8.0]));
        $this->assertSame(50.0, $this->_invoke($service, '_resolveMinDrop', [999.0]));
        $this->assertSame(1.0, $this->_invoke($service, '_resolveMinDrop', [0.2]));
    }

    public function test_drop_mode_resolves_and_defaults(): void
    {
        $service = new DipBuyingBacktestService();

        // Known axes pass through; anything else (null, empty, junk) falls back to the live default.
        $this->assertSame('effective', $this->_invoke($service, '_resolveMode', [null]));
        $this->assertSame('effective', $this->_invoke($service, '_resolveMode', ['']));
        $this->assertSame('effective', $this->_invoke($service, '_resolveMode', ['bogus']));
        $this->assertSame('effective', $this->_invoke($service, '_resolveMode', ['effective']));
        $this->assertSame('change', $this->_invoke($service, '_resolveMode', ['change']));
        $this->assertSame('vusa', $this->_invoke($service, '_resolveMode', ['vusa']));

        $this->assertSame(['effective', 'change', 'vusa'], array_keys(DipBuyingBacktestService::dropModes()));
    }

    public function test_backtest_guided_timeline_matches_shared_ladder(): void
    {
        $service = new DipBuyingBacktestService();
        $engine  = new DipBuyingPlanService();
        $bands   = $engine->resolveBands(config('alerts.dip_buying.bands'));

        // At a 36% low the ladder is fully deployed (target 100%), keeping nothing in reserve if the
        // user had already exhausted at the same depth, and the guided target equals resolveBand.
        $guided = $this->_invoke($service, '_guidedTimeline', [$bands, 10000.0, 36.0, 11.0]);

        $this->assertSame(
            (float) $engine->resolveBand(36.0, $bands)['target'],
            $guided['target_pct'],
            'Guided target comes straight from the shared ladder, so they cannot diverge.'
        );
        // At -11% (where the user exhausted), the ladder would have deployed only 30%, keeping 70%.
        $this->assertEqualsWithDelta(7000.0, $guided['reserve_kept_eur'], 0.01);
    }

    /**
     * @return array
     */
    private function _notificationRow(): array
    {
        return [
            'user_id'               => $this->_user->id,
            'effective_dd_pct'      => 16.2,
            'vusa_dd_pct'           => 8.1,
            'portfolio_dd_pct'      => 16.2,
            'driver'                => 'portfolio',
            'target_pct'            => 40,
            'deployed_pct'          => 15.0,
            'deployed_eur'          => 1500.0,
            'pool_amount_eur'       => 10000.0,
            'suggested_tranche_eur' => 2500.0,
            'verdict'               => 'behind',
            'trigger'               => 'crossed_behind',
            'sent_at'               => now(),
            'status'                => 'SENT',
        ];
    }

    /**
     * Invoke a private method on a service for the pure-timeline assertions.
     *
     * @param object $obj
     * @param string $method
     * @param array  $args
     *
     * @return mixed
     */
    private function _invoke(object $obj, string $method, array $args)
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }
}
