<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DailyLogErrors extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Carbon $date,
        public readonly array $results,
    ) {
    }

    public function build(): static
    {
        $env = strtoupper(config('app.env'));
        $dateLabel = $this->date->toDateString();

        $highlighted = [];
        foreach ($this->results as $filename => $blocks) {
            $highlighted[$filename] = array_map([$this, 'highlightBlock'], $blocks);
        }

        return $this->subject("[{$env}] Daily Log Errors - {$dateLabel}")
            ->view('emails.daily-log-errors')
            ->with([
                'date' => $this->date,
                'results' => $this->results,
                'highlighted' => $highlighted,
            ]);
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
