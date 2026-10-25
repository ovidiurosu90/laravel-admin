<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use ovidiuro\myfinance2\App\Models\Currency;
use ovidiuro\myfinance2\App\Models\PriceAlert;
use ovidiuro\myfinance2\App\Models\StockSplit;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\DipBuyingPlanService;
use ovidiuro\myfinance2\App\Services\StoredTierReading;
use ovidiuro\myfinance2\App\Services\TierCalculationService;
use ovidiuro\myfinance2\Mail\DipBuyingAlert;
use ovidiuro\myfinance2\Mail\PortfolioPeakAlert;
use ovidiuro\myfinance2\Mail\PriceAlertCreated;
use ovidiuro\myfinance2\Mail\PriceAlertStateChanged;
use ovidiuro\myfinance2\Mail\PriceAlertTriggered;
use ovidiuro\myfinance2\Mail\ReturnsMissingQuotes;
use ovidiuro\myfinance2\Mail\SplitApplied;

/**
 * Every MyFinance2 email renders on the shared layout (one stylesheet, header, "MyFinance2 · ..." footer and button
 * row), without long dashes, and with its figures in the shared style: "- 50,000 €" and "- 2.50 %" in the body, the
 * compact "-3.68%" only in subjects. The price alert reads like the peak-proximity alert: "SYMBOL (Tier, today
 * Tier) <event> => <detail>" in the subject, the tier in the header and the same buttons.
 *
 * Synthetic rows only (symbol LAYOUT.TST), rolled back; the dip buying render reads the first user's plan read-only.
 */
class EmailLayoutConsistencyFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'LAYOUT.TST';
    private const NBSP   = "\u{00A0}";
    private const EMAILS = __DIR__ . '/../../../../vendor/ovidiuro/myfinance2/src/resources/views/emails';
    private const MAIL   = __DIR__ . '/../../../../vendor/ovidiuro/myfinance2/src/Mail';

    private ?User $_user = null;

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

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
        Auth::forgetGuards();
        parent::tearDown();
    }

    private function _alert(array $fields = []): PriceAlert
    {
        return PriceAlert::create(array_merge([
            'symbol'               => self::SYMBOL,
            'alert_type'           => 'PRICE_ABOVE',
            'target_price'         => '39.000000',
            'status'               => 'ACTIVE',
            'source'               => 'manual',
            'notification_channel' => 'email',
            'trigger_count'        => 2,
            'trade_currency_id'    => Currency::where('iso_code', 'EUR')->value('id'),
        ], $fields));
    }

    /**
     * Render a mailable, returning [subject, html].
     */
    private static function _render($mailable): array
    {
        $html = $mailable->render();

        return [$mailable->subject, $html];
    }

    private static function _assertSharedFrame(string $html, string $footerTitle): void
    {
        self::assertStringContainsString('<div class="container">', $html);
        self::assertStringContainsString('<strong>MyFinance2</strong> &middot; ' . $footerTitle, $html);
        self::assertStringContainsString('class="actions"', $html);
    }

    public function test_every_email_template_extends_the_shared_layout(): void
    {
        $templates = glob(self::EMAILS . '/*.blade.php');
        $this->assertNotEmpty($templates);
        foreach ($templates as $template) {
            $this->assertStringContainsString(
                "@extends('myfinance2::emails.layouts.base')", file_get_contents($template), basename($template)
            );
        }
    }

    public function test_every_email_title_leads_with_an_icon_of_its_meaning(): void
    {
        // 🔔 an alert to act on, ⚠️ a problem, ✅ a change confirmed, ✂️ a split applied, ⏸ / ▶ paused / resumed
        // (as their button); the colour emoji form, so ⚠ and ✂ without U+FE0F do not count.
        $icons = ['🔔', '&#128276;', "\u{26A0}\u{FE0F}", '&#9888;&#65039;', '✅', "\u{2702}\u{FE0F}", '⏸', '▶'];
        $templates = array_merge(
            glob(self::EMAILS . '/*.blade.php'),
            [__DIR__ . '/../../../../resources/views/emails/daily-log-errors.blade.php']
        );
        foreach ($templates as $template) {
            $source = file_get_contents($template);
            $start  = strpos($source, "@section('title'");
            $this->assertNotFalse($start, basename($template));
            $title = substr($source, $start, 200);
            $found = array_filter($icons, fn (string $icon): bool => str_contains($title, $icon));
            $this->assertNotEmpty($found, basename($template) . ' has no title icon');
        }
    }

    public function test_no_long_dashes_or_raw_number_format_in_emails(): void
    {
        $files = array_merge(
            glob(self::EMAILS . '/*.blade.php'), glob(self::EMAILS . '/*/*.blade.php'),
            glob(self::MAIL . '/*.php'), glob(self::MAIL . '/*/*.php')
        );
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/—|–|&mdash;|&ndash;/u', $source, basename($file));
            if (str_ends_with($file, '.blade.php')) {
                $this->assertStringNotContainsString('number_format(', $source, basename($file));
            }
        }
    }

    public function test_price_alert_reads_like_the_peak_alert(): void
    {
        $alert   = $this->_alert();
        $reading = StoredTierReading::fromState(
            TierCalculationService::SILVER, TierCalculationService::GOLD, null,
            ['range_bound' => null, 'take_profit' => null, 'tier_signal' => null]
        );

        [$subject, $html] = self::_render(new PriceAlertTriggered($alert, 39.12, [
            'today_gain_eur' => -353.47, 'today_gain_pct' => -6.31, 'gain_eur' => 234.8, 'gain_pct' => 4.68,
            'quantity' => 128.0, 'avg_unit_cost' => 37.36,
        ], false, $reading));

        $this->assertStringEndsWith(
            self::SYMBOL . ' (Gold) above target 39.00 € => now 39.12 €', $subject
        );
        self::_assertSharedFrame($html, 'Price alert #' . $alert->id . ' for ' . self::SYMBOL);
        $this->assertStringContainsString('max-width: 1024px', $html);
        $this->assertStringContainsString('<span class="badge bg-warning text-dark">Gold</span>', $html);
        $this->assertStringContainsString('Price alert: above target', $html);
        $this->assertStringContainsString('<h2>Summary</h2>', $html);
        $this->assertStringContainsString('<strong>Gold.</strong>', $html);
        $this->assertStringNotContainsString('today Silver', $html);
        $this->assertStringContainsString('Source: Manual, fired 3', $html);
        $buttons = ['→ Create Sell Order', '✏️ Manage Alert', '⏸ Pause Alert', '→ Open Watchlist Symbols'];
        foreach ($buttons as $button) {
            $this->assertStringContainsString($button, $html);
        }
        $this->assertStringContainsString('Sent at most once per day per alert', $html);
    }

    public function test_price_alert_at_the_target_and_split_warning(): void
    {
        $alert = $this->_alert();

        [$subject] = self::_render(new PriceAlertTriggered($alert, 39.0));
        $this->assertStringEndsWith(self::SYMBOL . ' at target 39.00 € => now 39.00 €', $subject);

        [$subject, $html] = self::_render(new PriceAlertTriggered($alert, 156.0, null, true));
        $this->assertStringEndsWith(self::SYMBOL . ' price alert may be stale => possible split', $subject);
        // The icon belongs to the title in the body, never the subject (like every other email)
        $this->assertStringNotContainsString("\u{26A0}", $subject);
        $this->assertStringContainsString('<h2>Possible stock split</h2>', $html);
        $this->assertStringNotContainsString('Create Sell Order', $html);
        $this->assertStringContainsString('⏸ Pause Alert', $html);
    }

    public function test_alert_created_and_paused_emails(): void
    {
        $alert = $this->_alert(['notes' => 'Layout note']);

        [$subject, $html] = self::_render(new PriceAlertCreated([$alert], 'manual', [self::SYMBOL => ['Acct A']]));
        $this->assertStringEndsWith(self::SYMBOL . ' price alert created => ▲ Above 39.00 €', $subject);
        self::_assertSharedFrame($html, 'Price alerts');
        $this->assertStringContainsString('<span class="badge bg-danger">▲ Above</span>', $html);
        $this->assertStringContainsString('Layout note', $html);

        [$subject, $html] = self::_render(new PriceAlertStateChanged([$alert], 'paused'));
        $this->assertStringEndsWith(self::SYMBOL . ' price alert #' . $alert->id . ' paused', $subject);
        self::_assertSharedFrame($html, 'Price alerts');
        $this->assertStringContainsString('PAUSED', $html);
        $this->assertStringContainsString('<span class="text-muted">n/a</span>', $html, 'No accounts reads n/a.');
    }

    public function test_split_applied_formats_quantities_and_alert_currency(): void
    {
        $split = new StockSplit([
            'symbol' => self::SYMBOL, 'split_date' => Carbon::parse('2026-10-01'),
            'ratio_numerator' => 4, 'ratio_denominator' => 1,
        ]);

        [$subject, $html] = self::_render(new SplitApplied($split, [
            'trades_updated'  => 1,
            'changed_trades'  => [[
                'id' => 1, 'account' => 'Acct A', 'date' => '2026-01-02', 'action' => 'BUY', 'old_quantity' => 1000.0,
                'new_quantity' => 4000.0, 'old_price' => 100.0, 'new_price' => 25.0, 'currency' => 'USD',
            ]],
            'alerts_adjusted' => 1,
            'changed_alerts'  => [[
                'id' => 2, 'alert_type' => 'PRICE_ABOVE', 'old_target_price' => 120.0, 'new_target_price' => 30.0,
                'currency' => 'USD',
            ]],
        ]));

        $this->assertStringContainsString('applied => 1 trade updated, 1 alert adjusted', $subject);
        self::_assertSharedFrame($html, 'Stock splits');
        $this->assertStringContainsString('4,000', $html);
        $this->assertMatchesRegularExpression('/30\.00<\/td>\s*<td>USD<\/td>/', $html);
    }

    public function test_returns_missing_quotes_renders_on_the_shared_layout(): void
    {
        [$subject, $html] = self::_render(new ReturnsMissingQuotes([2025 => [[
            'date' => '2025-12-31', 'symbol' => self::SYMBOL, 'account_name' => 'Acct A', 'quantity' => 12.5,
            'delisted' => true,
        ]]]));

        $this->assertStringContainsString('no price for ' . self::SYMBOL, $subject);
        self::_assertSharedFrame($html, 'Returns refresh');
        $this->assertStringContainsString('→ Returns 2025', $html);
        $this->assertStringContainsString('<span class="badge bg-secondary">Delisted</span>', $html);
    }

    public function test_portfolio_peak_alert_uses_the_shared_figure_style(): void
    {
        $row = [
            'metric' => 'change_eur', 'window' => '1y', 'current' => -51250.0, 'peak' => -50000.0,
            'peak_date' => '2026-09-01', 'proximity_pct' => -2.5, 'threshold_pct' => 3.0, 'triggered' => true,
            'is_trigger' => true, 'skipped' => false,
        ];

        [, $html] = self::_render(new PortfolioPeakAlert([$row], 12.34, -51250.0, -4.2, [$row]));

        self::_assertSharedFrame($html, 'Portfolio peak alert');
        $this->assertStringContainsString('- 51,250 €', $html);
        $this->assertStringContainsString('- 50,000 €', $html);
        $this->assertStringContainsString('-' . self::NBSP . '2.50' . self::NBSP . '%', $html);
        $this->assertStringContainsString('2.50' . self::NBSP . '% below peak (threshold 3' . self::NBSP . '%)', $html);
        $this->assertStringContainsString('-' . self::NBSP . '4.20' . self::NBSP . '%', $html);
        $this->assertStringNotContainsString('€-', $html);
        $this->assertStringNotContainsString('&euro;-', $html);
    }

    public function test_dip_buying_alert_renders_on_the_shared_layout(): void
    {
        $plan = (new DipBuyingPlanService())->buildForUser((int) $this->_user->id);
        if ($plan === null) {
            $this->markTestSkipped('The first user has no Dip Buying Plan enabled.');
        }

        [$subject, $html] = self::_render(new DipBuyingAlert($plan, 'band_deepened'));

        $this->assertStringContainsString('Dip Buying Plan: ', $subject);
        self::_assertSharedFrame($html, 'Dip Buying Plan');
        $this->assertStringContainsString('→ Open the Panel', $html);
    }
}
