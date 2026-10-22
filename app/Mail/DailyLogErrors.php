<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class DailyLogErrors extends Mailable
{
    use Queueable, SerializesModels;

    /** The MyFinance2 emails' shared layout, so this email reads like them while that package is installed. */
    public const PACKAGE_LAYOUT = 'myfinance2::emails.layouts.base';

    /** The main app's own copy of that frame (same sections, plainer styles), used without the package. */
    public const FALLBACK_LAYOUT = 'emails.layouts.base';

    public function __construct(
        public readonly Carbon $date,
        public readonly array $results,
    ) {
    }

    public function build(): static
    {
        $dateLabel = $this->date->toDateString();

        $highlighted = [];
        foreach ($this->results as $filename => $entries) {
            $highlighted[$filename] = array_map(
                fn (array $entry): string => $this->highlightBlock($entry['block']),
                $entries
            );
        }

        $issues = array_sum(array_map('count', $this->results));
        $occurrences = array_sum(array_map(
            fn (array $entries): int => (int) array_sum(array_column($entries, 'count')),
            $this->results
        ));
        $counts = self::counted($issues, 'issue') . ', ' . self::counted($occurrences, 'occurrence');

        // "SUBJECT <what> => <detail>", the shape of the MyFinance2 alert subjects.
        return $this->subject("{$this->appLabel()} Daily Log Errors {$dateLabel} => {$counts}")
            ->view('emails.daily-log-errors')
            ->with([
                'date' => $this->date,
                'results' => $this->results,
                'highlighted' => $highlighted,
                'summary' => self::counted($issues, 'distinct issue') . ', '
                    . self::counted($occurrences, 'occurrence') . ' across '
                    . self::counted(count($this->results), 'file'),
                'logsUrl' => url('logs'),
                'layout' => self::layout(),
            ]);
    }

    /**
     * The subject prefix, the same rule as the MyFinance2 emails ("[MyFinance2-PROD]"): the app name (APP_NAME),
     * with the environment (APP_ENV) appended unless it is "production", e.g. "[Admin-PROD]" or "[Admin-LOCAL]".
     */
    private function appLabel(): string
    {
        $name = config('app.name');
        $env = app()->environment();

        return $env === 'production' ? "[{$name}]" : '[' . $name . '-' . strtoupper($env) . ']';
    }

    /**
     * The layout the email extends: the MyFinance2 package's when it is installed, else the main app's fallback.
     */
    public static function layout(): string
    {
        return View::exists(self::PACKAGE_LAYOUT) ? self::PACKAGE_LAYOUT : self::FALLBACK_LAYOUT;
    }

    /**
     * "1 issue", "2 issues": the count with its noun in the right number.
     */
    public static function counted(int $count, string $noun): string
    {
        return $count . ' ' . Str::plural($noun, $count);
    }

    private function highlightBlock(string $block): string
    {
        $lines = explode("\n", $block);
        $html = '';

        foreach ($lines as $line) {
            $css = $this->lineCssClass($line);
            $escaped = htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html .= $css
                ? "<span class=\"{$css}\">{$escaped}</span>\n"
                : "{$escaped}\n";
        }

        return rtrim($html);
    }

    private function lineCssClass(string $line): string
    {
        if (preg_match('/\] \w+\.EMERGENCY:/', $line)) {
            return 'line-emergency';
        }
        if (preg_match('/\] \w+\.ALERT:/', $line)) {
            return 'line-alert';
        }
        if (preg_match('/\] \w+\.CRITICAL:/', $line)) {
            return 'line-critical';
        }
        if (preg_match('/\] \w+\.ERROR:/', $line)) {
            return 'line-error';
        }
        if (preg_match('/\] \w+\.WARNING:/', $line)) {
            return 'line-warning';
        }

        return '';
    }
}
