<?php

namespace App\Console\Commands;

use App\Mail\DailyLogErrors;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class EmailDailyLogErrors extends Command
{
    protected $signature = 'logs:email-daily-errors
                            {--date= : Date to process in Y-m-d format (defaults to yesterday)}';

    protected $description = 'Email a summary of WARNING and above log entries from the previous day.';

    private const LEVELS = ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
    private const CONTEXT_LINES = 5;
    private const LOG_FILES = ['laravel.log', 'finance-api-cron.log', 'stats-cron.log'];

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $results = [];

        foreach (self::LOG_FILES as $filename) {
            $filepath = storage_path("logs/{$filename}");

            if (! file_exists($filepath)) {
                continue;
            }

            $entries = $this->extractErrorEntries($filepath, $date);

            if (! empty($entries)) {
                $results[$filename] = $entries;
            }
        }

        if (empty($results)) {
            $this->info("No errors found for {$date->toDateString()}.");

            return 0;
        }

        Mail::to(config('mail.from.address'))->send(new DailyLogErrors($date, $results));

        $total = array_sum(array_map('count', $results));
        $this->info("Sent error summary for {$date->toDateString()}: {$total} block(s) across " . count($results) . ' file(s).');

        return 0;
    }

    private function extractErrorEntries(string $filepath, Carbon $date): array
    {
        $levelList = implode('|', self::LEVELS);
        $dateStr = preg_quote($date->toDateString(), '/');
        $pattern = '/^\[' . $dateStr . ' \d{2}:\d{2}:\d{2}\] \w+\.(?:' . $levelList . '):/';

        $handle = fopen($filepath, 'r');
        if (! $handle) {
            return [];
        }

        $contextSize = self::CONTEXT_LINES;
        $beforeBuffer = [];
        $blocks = [];
        $currentLines = null;
        $afterRemaining = 0;

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\n\r");
            $isError = (bool) preg_match($pattern, $line);

            if ($isError) {
                if ($currentLines === null) {
                    $currentLines = $beforeBuffer;
                }
                $currentLines[] = $line;
                $afterRemaining = $contextSize;
            } elseif ($currentLines !== null) {
                $currentLines[] = $line;
                $afterRemaining--;

                if ($afterRemaining <= 0) {
                    $blocks[] = implode("\n", $currentLines);
                    $currentLines = null;
                }
            }

            $beforeBuffer[] = $line;
            if (count($beforeBuffer) > $contextSize) {
                array_shift($beforeBuffer);
            }
        }

        if ($currentLines !== null) {
            $blocks[] = implode("\n", $currentLines);
        }

        fclose($handle);

        return $blocks;
    }
}
