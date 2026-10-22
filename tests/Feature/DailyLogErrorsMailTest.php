<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\DailyLogErrors;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * The daily log digest reads like the MyFinance2 emails: an "[APP_NAME-ENV]" subject prefix ("[Admin-PROD]") with the
 * counts after "=>", and their shared layout. The exception email's subject has the same prefix. Renders only;
 * nothing is sent or stored.
 */
class DailyLogErrorsMailTest extends TestCase
{
    private static function _mail(): DailyLogErrors
    {
        return new DailyLogErrors(Carbon::parse('2026-10-21'), ['finance-api-cron.log' => [[
            'block' => '[2026-10-21 15:32:08] prod.WARNING: refreshQuotes() unable to fetch: TST',
            'level' => 'WARNING', 'count' => 2, 'first' => '15:32:08', 'last' => '22:02:10',
        ]]]);
    }

    public function test_subject_is_prefixed_with_the_app_name_and_environment(): void
    {
        $mail = self::_mail();
        $html = $mail->render();

        $env = strtoupper(app()->environment());
        $this->assertSame(
            '[' . config('app.name') . "-{$env}] Daily Log Errors 2026-10-21 => 1 issue, 2 occurrences",
            $mail->subject
        );
        // The MyFinance2 emails' shared layout: icon-led header, the environment on the sub-line, plurals spelled
        // out, the button row and the app's own name in the footer.
        $this->assertMatchesRegularExpression('/<h1>\s*⚠️ Daily Log Errors\s*<\/h1>/u', $html);
        $this->assertStringContainsString("{$env} &middot; Wednesday, October 21 2026", $html);
        $this->assertStringContainsString('1 distinct issue, 2 occurrences across 1 file', $html);
        $this->assertStringNotContainsString('(s)', $html);
        $this->assertStringContainsString('→ Open Logs', $html);
        $this->assertStringContainsString(url('logs'), $html);
        $footer = '<strong>' . config('app.name') . '</strong> &middot; Daily log errors';
        $this->assertStringContainsString($footer, $html);
        $this->assertStringContainsString('refreshQuotes() unable to fetch: TST', $html);
    }

    public function test_uses_the_package_layout_when_installed(): void
    {
        $this->assertSame(DailyLogErrors::PACKAGE_LAYOUT, DailyLogErrors::layout());
        // A rule only the package's stylesheet has.
        $this->assertStringContainsString('.bg-orange', self::_mail()->render());
    }

    public function test_falls_back_to_the_main_app_layout_without_the_package(): void
    {
        // Hide the package's views (an empty hint path), as if it were not installed; restored below.
        $finder = app('view')->getFinder();
        $hints  = $finder->getHints()['myfinance2'];
        $finder->replaceNamespace('myfinance2', [sys_get_temp_dir() . '/no-myfinance2-views']);
        $finder->flush();

        try {
            $this->assertSame(DailyLogErrors::FALLBACK_LAYOUT, DailyLogErrors::layout());

            $mail = self::_mail();
            $html = $mail->render();
        } finally {
            $finder->replaceNamespace('myfinance2', $hints);
            $finder->flush();
        }

        $this->assertStringContainsString('Daily Log Errors 2026-10-21 => 1 issue, 2 occurrences', $mail->subject);
        $this->assertStringNotContainsString('.bg-orange', $html);
        $this->assertMatchesRegularExpression('/<h1>\s*⚠️ Daily Log Errors\s*<\/h1>/u', $html);
        $this->assertStringContainsString('1 distinct issue, 2 occurrences across 1 file', $html);
        $this->assertStringContainsString('→ Open Logs', $html);
        $this->assertStringContainsString(
            '<strong>' . config('app.name') . '</strong> &middot; Daily log errors', $html
        );
        $this->assertStringContainsString('refreshQuotes() unable to fetch: TST', $html);
    }

    public function test_exception_email_subject_has_the_same_prefix(): void
    {
        $env = strtoupper(app()->environment());
        $expected = app()->environment() === 'production'
            ? '[' . config('app.name') . '] Exception'
            : '[' . config('app.name') . "-{$env}] Exception";

        $this->assertSame($expected, config('exceptions.emailExceptionSubjectDefault'));
    }

    public function test_production_has_no_environment_suffix(): void
    {
        $this->app['env'] = 'production';

        $mail = self::_mail();
        $mail->render();

        $this->assertStringStartsWith('[' . config('app.name') . '] Daily Log Errors', $mail->subject);
    }
}
