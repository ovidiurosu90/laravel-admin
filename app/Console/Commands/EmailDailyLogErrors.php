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

    // Regexes matched against the error line's "env.LEVEL: message" text (timestamp
    // stripped). A line matching any of these is treated as non-actionable and never
    // triggers a digest block. Empty by default: nothing is hidden until a pattern is
    // added here deliberately. Prefer fixing noise at its source; use this only for
    // genuinely benign, unfixable-in-app noise (e.g. third-party infrastructure lines).
    // Example: '/prod\.WARNING: SomeVendor: expected transient .*/'
    private const SUPPRESS_PATTERNS = [];

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

        $issues = array_sum(array_map('count', $results));
        $occurrences = array_sum(array_map(
            fn (array $entries): int => array_sum(array_column($entries, 'count')),
            $results
        ));
        $this->info(
            "Sent error summary for {$date->toDateString()}: {$issues} distinct issue(s) "
            . "({$occurrences} occurrence(s)) across " . count($results) . ' file(s).'
        );

        return 0;
    }

    /**
     * Extract WARNING+ blocks for the given date, then group identical blocks so a
     * message that recurs every minute becomes one entry with an occurrence count and
     * a first/last time range, rather than dozens of near-duplicate blocks.
     *
     * @return array<int, array{signature: string, level: string, count: int,
     *     first: string, last: string, block: string}>
     */
    private function extractErrorEntries(string $filepath, Carbon $date): array
    {
        $blocks = $this->readBlocks($filepath, $date);

        return $this->groupBlocks($blocks, $date);
    }

    /**
     * Scan the file and return raw blocks (error line(s) plus surrounding context).
     *
     * @return array<int, string>
     */
    private function readBlocks(string $filepath, Carbon $date): array
    {
        $dateStr = preg_quote($date->toDateString(), '/');
        $levelList = implode('|', self::LEVELS);
        $pattern = '/^\[' . $dateStr . ' \d{2}:\d{2}:\d{2}\] (\w+\.(?:' . $levelList . '):.*)$/';

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
            $isError = preg_match($pattern, $line, $m) && ! $this->isSuppressed($m[1]);

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

    /**
     * Collapse blocks that carry the same set of error messages (ignoring timestamps
     * and volatile tokens) into a single entry with a count and time range.
     *
     * @param  array<int, string>  $blocks
     * @return array<int, array{signature: string, level: string, count: int,
     *     first: string, last: string, block: string}>
     */
    private function groupBlocks(array $blocks, Carbon $date): array
    {
        $dateStr = preg_quote($date->toDateString(), '/');
        $levelList = implode('|', self::LEVELS);
        // Same trigger definition as readBlocks(): only WARNING+ lines on the target
        // date that are not suppressed. This keeps context lines (including an adjacent
        // day's lines pulled in as context) out of the grouping signature.
        $errorLine = '/^\[' . $dateStr . ' (\d{2}:\d{2}:\d{2})\] ((\w+\.(' . $levelList . ')): (.*))$/';

        $entries = [];

        foreach ($blocks as $block) {
            $signatureParts = [];
            $times = [];
            $topLevel = null;

            foreach (explode("\n", $block) as $line) {
                if (! preg_match($errorLine, $line, $m) || $this->isSuppressed($m[2])) {
                    continue;
                }
                $times[] = $m[1];
                $signatureParts[] = $m[4] . ': ' . $this->normalizeMessage($m[5]);
                $topLevel = $this->mostSevere($topLevel, $m[4]);
            }

            if (empty($signatureParts)) {
                continue;
            }

            $signature = implode("\n", $signatureParts);
            $first = min($times);
            $last = max($times);

            if (! isset($entries[$signature])) {
                $entries[$signature] = [
                    'signature' => $signature,
                    'level' => $topLevel,
                    'count' => 0,
                    'first' => $first,
                    'last' => $last,
                    'block' => $block,
                ];
            }

            $entries[$signature]['count']++;
            $entries[$signature]['first'] = min($entries[$signature]['first'], $first);
            $entries[$signature]['last'] = max($entries[$signature]['last'], $last);
        }

        $entries = array_values($entries);

        usort($entries, function (array $a, array $b): int {
            $severity = $this->levelRank($b['level']) <=> $this->levelRank($a['level']);

            return $severity !== 0 ? $severity : $b['count'] <=> $a['count'];
        });

        return $entries;
    }

    /**
     * Strip volatile tokens so otherwise-identical errors group together (e.g. two
     * 503s that differ only by Yahoo's rotating crumb / query string).
     */
    private function normalizeMessage(string $message): string
    {
        $message = preg_replace('/crumb=[^&\s`\'"]+/', 'crumb=…', $message);
        $message = preg_replace('/symbols=[^\s`\'"]+/', 'symbols=…', $message);

        return $message;
    }

    private function isSuppressed(string $errorText): bool
    {
        foreach (self::SUPPRESS_PATTERNS as $pattern) {
            if (preg_match($pattern, $errorText)) {
                return true;
            }
        }

        return false;
    }

    private function mostSevere(?string $current, string $candidate): string
    {
        if ($current === null) {
            return $candidate;
        }

        return $this->levelRank($candidate) > $this->levelRank($current)
            ? $candidate
            : $current;
    }

    private function levelRank(?string $level): int
    {
        $rank = array_flip(self::LEVELS);

        return $rank[$level] ?? -1;
    }
}
