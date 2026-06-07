<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use ovidiuro\myfinance2\App\Models\PeakProximityAlertSetting;
use ovidiuro\myfinance2\App\Models\PeakProximityNotification;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Models\Trade;

/**
 * Controller / route tests for the peak-proximity opt-in management UI.
 *
 * The page lists only symbols the user actually holds, so each test first creates a synthetic OPEN
 * BUY trade (symbol TST.*, which cannot exist in production) under the acting user via Eloquent, the
 * same convention as PriceAlertsFeatureTest. Settings rows are written to peak_proximity_alert_settings
 * (a new table with no FK to users). withoutMiddleware() skips the role gate; DatabaseTransactions
 * rolls back both connections so nothing reaches production.
 */
class PeakProximityAlertsUiTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL  = 'TST.AAA';
    private const SYMBOL2 = 'TST.BBB';

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
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    /**
     * Create a synthetic OPEN BUY holding for the acting user so its symbol is listed and toggleable.
     *
     * @param string $symbol
     *
     * @return void
     */
    private function _holdSymbol(string $symbol): void
    {
        Trade::create([
            'symbol'        => $symbol,
            'action'        => 'BUY',
            'status'        => 'OPEN',
            'quantity'      => '10.00000000',
            'unit_price'    => '100.0000',
            'fee'           => '0.00',
            'exchange_rate' => '1.0000',
            'timestamp'     => now(),
        ]);
    }

    private function _setting(string $symbol): ?PeakProximityAlertSetting
    {
        return PeakProximityAlertSetting::where('user_id', $this->_user->id)
            ->where('symbol', $symbol)
            ->first();
    }

    public function test_enable_route_upserts_enabled_setting(): void
    {
        $this->_holdSymbol(self::SYMBOL);

        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->post(route('myfinance2::peak-proximity-alerts.enable'), [
                'view'    => 'all',
                'symbols' => [self::SYMBOL],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $setting = $this->_setting(self::SYMBOL);
        $this->assertNotNull($setting);
        $this->assertSame(PeakProximityAlertSetting::ENABLED, $setting->status);
        $this->assertNull($setting->until);
    }

    public function test_disable_route_sets_disabled(): void
    {
        $this->_holdSymbol(self::SYMBOL);
        PeakProximityAlertSetting::create([
            'user_id' => $this->_user->id,
            'symbol'  => self::SYMBOL,
            'status'  => PeakProximityAlertSetting::ENABLED,
        ]);

        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->post(route('myfinance2::peak-proximity-alerts.disable'), [
                'view'    => 'all',
                'symbols' => [self::SYMBOL],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(PeakProximityAlertSetting::DISABLED, $this->_setting(self::SYMBOL)->status);
    }

    public function test_bulk_enable_multiple_symbols(): void
    {
        $this->_holdSymbol(self::SYMBOL);
        $this->_holdSymbol(self::SYMBOL2);

        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->post(route('myfinance2::peak-proximity-alerts.enable'), [
                'view'    => 'all',
                'symbols' => [self::SYMBOL, self::SYMBOL2],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(PeakProximityAlertSetting::ENABLED, $this->_setting(self::SYMBOL)->status);
        $this->assertSame(PeakProximityAlertSetting::ENABLED, $this->_setting(self::SYMBOL2)->status);
    }

    public function test_enable_until_future_date_is_stored(): void
    {
        $this->_holdSymbol(self::SYMBOL);
        $until = now()->addDays(7)->format('Y-m-d');

        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->post(route('myfinance2::peak-proximity-alerts.enable'), [
                'view'    => 'all',
                'symbols' => [self::SYMBOL],
                'until'   => $until,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $setting = $this->_setting(self::SYMBOL);
        $this->assertSame(PeakProximityAlertSetting::ENABLED, $setting->status);
        $this->assertSame($until, $setting->until->format('Y-m-d'));
    }

    public function test_enable_with_past_until_date_is_rejected(): void
    {
        $this->_holdSymbol(self::SYMBOL);
        $past = now()->subDay()->format('Y-m-d');

        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->post(route('myfinance2::peak-proximity-alerts.enable'), [
                'view'    => 'all',
                'symbols' => [self::SYMBOL],
                'until'   => $past,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        // Nothing was written for the symbol.
        $this->assertNull($this->_setting(self::SYMBOL));
    }

    public function test_index_active_view_excludes_disabled_all_view_includes_it(): void
    {
        $this->_holdSymbol(self::SYMBOL);
        PeakProximityAlertSetting::create([
            'user_id' => $this->_user->id,
            'symbol'  => self::SYMBOL,
            'status'  => PeakProximityAlertSetting::DISABLED,
        ]);

        // Active view hides the disabled symbol.
        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->get(route('myfinance2::peak-proximity-alerts.index', ['view' => 'active']))
            ->assertOk()
            ->assertDontSee(self::SYMBOL);

        // All view lists it.
        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->get(route('myfinance2::peak-proximity-alerts.index', ['view' => 'all']))
            ->assertOk()
            ->assertSee(self::SYMBOL);
    }

    public function test_history_page_renders_and_filters_by_symbol(): void
    {
        $this->_seedNotification(self::SYMBOL);

        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->get(route('myfinance2::peak-proximity-alerts.history'))
            ->assertOk()
            ->assertSee(self::SYMBOL);

        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->get(route('myfinance2::peak-proximity-alerts.history', ['symbol' => self::SYMBOL]))
            ->assertOk()
            ->assertSee(self::SYMBOL);
    }

    public function test_history_destroy_removes_record(): void
    {
        $notif = $this->_seedNotification(self::SYMBOL);

        $this->withoutMiddleware()
            ->actingAs($this->_user)
            ->delete(route('myfinance2::peak-proximity-alerts.history.destroy', $notif->id))
            ->assertRedirect(route('myfinance2::peak-proximity-alerts.history'))
            ->assertSessionHas('success');

        $this->assertNull(PeakProximityNotification::find($notif->id));
    }

    private function _seedNotification(string $symbol): PeakProximityNotification
    {
        return PeakProximityNotification::create([
            'user_id'               => $this->_user->id,
            'symbol'                => $symbol,
            'current_price'         => 100.0,
            'triggered_windows'     => '3m',
            'closest_proximity_pct' => -2.0,
            'sent_at'               => now(),
            'status'                => 'SENT',
        ]);
    }
}
