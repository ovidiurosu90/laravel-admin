<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

use ovidiuro\myfinance2\App\Models\PortfolioPeakNotification;
use ovidiuro\myfinance2\App\Models\PortfolioPeakSetting;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\PortfolioPeakAlertService;
use ovidiuro\myfinance2\Mail\PortfolioPeakAlert;

/**
 * Controller / route tests for the Portfolio Peak Alert settings and history, plus end-to-end
 * evaluateForUser() scenarios (opt-in guards, per-metric toggles, proximity trigger, once-per-day
 * and reminder cadence, dry-run) driven off a seeded overview chart series.
 *
 * Conventions (see the package CLAUDE.md): DatabaseTransactions on both connections rolls back every
 * DB row; the acting user is read-only (actingAs, never saved). The settings/notifications rows are
 * written under a synthetic user id that cannot exist in production, so the acting user's real
 * portfolio and chart files are never touched. Seeding a series writes a chart JSON file, which is
 * NOT rolled back, so each written chart directory is deleted in tearDown().
 */
class PortfolioPeakAlertsFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    private ?User $_user = null;
    private int $_syntheticUserId = 0;

    /** @var array<int, int> synthetic user ids whose chart dir was written and must be removed */
    private array $_seededUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        AssignedToUserScope::enable();

        $this->_user = User::first();
        if (!$this->_user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        $this->actingAs($this->_user);

        // Synthetic id, safely above any real user, for the opt-in rows and the seeded chart files.
        $this->_syntheticUserId = (int) User::max('id') + 1000;

        // Route the email to a throwaway address so _send() never looks up a User by the synthetic id.
        config(['alerts.portfolio_peak.email_to' => 'ppa-test@example.test']);
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->_seededUserIds) as $id) {
            Storage::disk('local')->deleteDirectory("charts/user/{$id}");
        }
        $this->_seededUserIds = [];

        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Write an overview chart series file (date => value) for a synthetic user, in the stored
     * JS-literal/JSON point format ChartsBuilder reads. Records the id for tearDown cleanup.
     *
     * @param int                  $userId
     * @param string               $metric  change_EUR | changePercentage_EUR
     * @param array<string, float> $series  date => value
     *
     * @return void
     */
    private function _writeSeries(int $userId, string $metric, array $series): void
    {
        $points = [];
        foreach ($series as $date => $value) {
            $points[] = ['time' => $date, 'value' => $value];
        }

        Storage::disk('local')->put(
            "charts/user/{$userId}/overview/{$metric}.json",
            json_encode($points)
        );
        $this->_seededUserIds[] = $userId;
    }

    /**
     * Build a daily date => value series ending today from oldest-first values, so every point sits
     * inside every rolling window and the window peak equals max($values).
     *
     * @param array<int, float> $values
     *
     * @return array<string, float>
     */
    private function _recentSeries(array $values): array
    {
        $series = [];
        $n      = count($values);
        foreach (array_values($values) as $i => $v) {
            $date          = now()->subDays($n - 1 - $i)->format('Y-m-d');
            $series[$date] = (float) $v;
        }
        return $series;
    }

    /**
     * Persist an opt-in settings row for the synthetic user.
     *
     * @param array $overrides
     *
     * @return PortfolioPeakSetting
     */
    private function _setting(array $overrides = []): PortfolioPeakSetting
    {
        return PortfolioPeakSetting::updateOrCreate(
            ['user_id' => $this->_syntheticUserId],
            array_merge([
                'status'             => PortfolioPeakSetting::ENABLED,
                'email_enabled'      => true,
                'change_eur_enabled' => true,
                'change_pct_enabled' => true,
            ], $overrides)
        );
    }

    /**
     * Evaluate the synthetic user with no authenticated user, so the ChartsBuilder ownership check
     * is skipped (the command sets the acting user itself in production).
     *
     * @param bool $dryRun
     *
     * @return string
     */
    private function _evaluate(bool $dryRun = false): string
    {
        Auth::forgetGuards();
        return (new PortfolioPeakAlertService())->evaluateForUser($this->_syntheticUserId, $dryRun);
    }

    // -------------------------------------------------------------------------
    // Opt-in guards
    // -------------------------------------------------------------------------

    public function test_skipped_when_no_setting(): void
    {
        Mail::fake();
        $this->assertSame('skipped', $this->_evaluate());
        Mail::assertNothingSent();
    }

    public function test_skipped_when_disabled_or_email_off(): void
    {
        Mail::fake();

        $this->_setting(['status' => PortfolioPeakSetting::DISABLED]);
        $this->assertSame('skipped', $this->_evaluate(), 'DISABLED is skipped.');

        $this->_setting(['status' => PortfolioPeakSetting::ENABLED, 'email_enabled' => false]);
        $this->assertSame('skipped', $this->_evaluate(), 'Email channel off is skipped.');

        Mail::assertNothingSent();
    }

    public function test_metric_toggle_respected(): void
    {
        Mail::fake();

        // Only the return% series would trigger, but change_pct is disabled and no EUR series exists.
        $this->_setting(['change_pct_enabled' => false, 'change_eur_enabled' => true]);
        $this->_writeSeries($this->_syntheticUserId, 'changePercentage_EUR',
            $this->_recentSeries([5, 8, 10, 10]));

        $this->assertSame('skipped', $this->_evaluate());
        Mail::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Trigger + send
    // -------------------------------------------------------------------------

    public function test_sent_when_within_threshold(): void
    {
        Mail::fake();

        $this->_setting();
        $this->_writeSeries($this->_syntheticUserId, 'changePercentage_EUR',
            $this->_recentSeries([4, 7, 9, 10])); // ends at its own high => proximity 0

        $this->assertSame('sent', $this->_evaluate());

        $notif = PortfolioPeakNotification::where('user_id', $this->_syntheticUserId)->first();
        $this->assertNotNull($notif);
        $this->assertSame('SENT', $notif->status);
        $this->assertStringContainsString('change_pct', (string) $notif->triggered_metrics);

        Mail::assertSent(PortfolioPeakAlert::class);
    }

    public function test_sent_when_negative_peak_recovered(): void
    {
        Mail::fake();

        $this->_setting(['change_pct_enabled' => false]); // isolate the EUR metric
        // 1Y high -30000, recovered to -31500 => -5% proximity, within the 1Y threshold (5%).
        $this->_writeSeries($this->_syntheticUserId, 'change_EUR',
            $this->_recentSeries([-30000, -70000, -45000, -31500]));

        $this->assertSame('sent', $this->_evaluate());

        $notif = PortfolioPeakNotification::where('user_id', $this->_syntheticUserId)->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('change_eur', (string) $notif->triggered_metrics);
        $this->assertStringContainsString('1y', (string) $notif->triggered_windows);

        Mail::assertSent(PortfolioPeakAlert::class);
    }

    // -------------------------------------------------------------------------
    // Cadence guards
    // -------------------------------------------------------------------------

    public function test_skipped_when_already_sent_today(): void
    {
        Mail::fake();

        $this->_setting();
        PortfolioPeakNotification::create([
            'user_id' => $this->_syntheticUserId,
            'status'  => 'SENT',
            'sent_at' => now(),
        ]);

        // A triggering series is present, but the once-per-day guard short-circuits first.
        $this->_writeSeries($this->_syntheticUserId, 'changePercentage_EUR',
            $this->_recentSeries([4, 7, 9, 10]));

        $this->assertSame('skipped', $this->_evaluate());
        Mail::assertNothingSent();
    }

    public function test_skipped_when_reminder_not_due(): void
    {
        Mail::fake();
        config(['alerts.portfolio_peak.reminder_days' => 7]);

        $this->_setting();
        PortfolioPeakNotification::create([
            'user_id' => $this->_syntheticUserId,
            'status'  => 'SENT',
            'sent_at' => now()->subDays(3),
        ]);
        $this->_writeSeries($this->_syntheticUserId, 'changePercentage_EUR',
            $this->_recentSeries([4, 7, 9, 10]));

        $this->assertSame('skipped', $this->_evaluate());
        Mail::assertNothingSent();
    }

    public function test_reminder_sent_when_interval_elapsed(): void
    {
        Mail::fake();
        config(['alerts.portfolio_peak.reminder_days' => 7]);

        $this->_setting();
        PortfolioPeakNotification::create([
            'user_id' => $this->_syntheticUserId,
            'status'  => 'SENT',
            'sent_at' => now()->subDays(8),
        ]);
        $this->_writeSeries($this->_syntheticUserId, 'changePercentage_EUR',
            $this->_recentSeries([4, 7, 9, 10]));

        $this->assertSame('sent', $this->_evaluate());
        Mail::assertSent(PortfolioPeakAlert::class);
    }

    public function test_dry_run_does_not_write(): void
    {
        Mail::fake();

        $this->_setting();
        $this->_writeSeries($this->_syntheticUserId, 'changePercentage_EUR',
            $this->_recentSeries([4, 7, 9, 10]));

        $this->assertSame('sent', $this->_evaluate(true));

        $this->assertSame(0, PortfolioPeakNotification::where('user_id', $this->_syntheticUserId)->count());
        Mail::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Settings routes + page rendering
    // -------------------------------------------------------------------------

    public function test_save_route_persists_settings(): void
    {
        // Act as an unsaved User carrying the synthetic id, so the row updateOrCreate() writes can
        // never collide with a real user's settings row (the controller keys the row on auth id).
        $synthetic     = new User();
        $synthetic->id = $this->_syntheticUserId;

        $this->withoutMiddleware()
            ->actingAs($synthetic)
            ->post(route('myfinance2::portfolio-peak-alerts.save'), [
                'enabled'            => '1',
                'email_enabled'      => '1',
                'change_eur_enabled' => '1',
                // change_pct_enabled intentionally omitted => unchecked
            ])
            ->assertRedirect(route('myfinance2::portfolio-peak-alerts.index'))
            ->assertSessionHas('success');

        $setting = PortfolioPeakSetting::where('user_id', $this->_syntheticUserId)->first();
        $this->assertNotNull($setting);
        $this->assertSame(PortfolioPeakSetting::ENABLED, $setting->status);
        $this->assertTrue($setting->email_enabled);
        $this->assertTrue($setting->change_eur_enabled);
        $this->assertFalse($setting->change_pct_enabled, 'Omitted checkbox stores false.');
    }

    public function test_settings_and_history_pages_render(): void
    {
        $this->withoutMiddleware()->actingAs($this->_user)
            ->get(route('myfinance2::portfolio-peak-alerts.index'))
            ->assertOk();

        $this->withoutMiddleware()->actingAs($this->_user)
            ->get(route('myfinance2::portfolio-peak-alerts.history'))
            ->assertOk();
    }
}
