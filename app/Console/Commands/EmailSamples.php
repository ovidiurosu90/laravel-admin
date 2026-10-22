<?php

namespace App\Console\Commands;

use App\Mail\DailyLogErrors;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use ovidiuro\myfinance2\App\Services\EmailSamples as MyFinance2EmailSamples;

/**
 * Send one sample of every email, to see how they look in a mail client: the main app's own (the daily log errors
 * digest, from sample figures) and, when the MyFinance2 package is installed, each of its emails, which the package
 * builds (MyFinance2EmailSamples). Persists nothing.
 */
class EmailSamples extends Command
{
    protected $signature = 'emails:samples
                            {--to= : Recipient (default: the MyFinance2 alerts address, else the user\'s email)}
                            {--user-id= : User whose data the samples read (default: the first user)}
                            {--only= : Comma-separated kinds to send (see the list printed by --dry-run)}
                            {--dry-run : Build and list the samples without sending}';

    protected $description = 'Send one sample of every email (the daily log errors digest and each MyFinance2 email) '
        . 'to see how they look.';

    public function handle(): int
    {
        $user = $this->option('user-id') ? User::find((int) $this->option('user-id')) : User::first();
        if ($user === null) {
            $this->error('User not found.');

            return 1;
        }
        $myFinance2 = class_exists(MyFinance2EmailSamples::class);
        $to = $this->option('to')
            ?: (($myFinance2 ? MyFinance2EmailSamples::defaultRecipient() : null) ?: $user->email);

        // Read as this user (never saved).
        auth()->setUser($user);

        $only = $this->option('only') ? array_map('trim', explode(',', $this->option('only'))) : null;
        $samples = array_merge(
            $myFinance2 ? (new MyFinance2EmailSamples())->builders((int) $user->id) : [],
            ['daily-log-errors' => fn () => $this->dailyLogErrors()]
        );
        $sent = 0;
        foreach ($samples as $kind => $build) {
            if ($only !== null && !in_array($kind, $only, true)) {
                continue;
            }
            $sent += $this->sendSample($kind, $build, $to);
        }

        $verb = $this->option('dry-run') ? 'Built' : 'Sent';
        $this->info("{$verb} {$sent} sample(s)" . ($this->option('dry-run') ? '.' : " to {$to}."));

        return 0;
    }

    /**
     * Build, then send (or list, on --dry-run) one sample; a sample that cannot be built is reported and skipped.
     *
     * @return int 1 when built (and sent), else 0
     */
    private function sendSample(string $kind, \Closure $build, string $to): int
    {
        try {
            $mails = $build();
        } catch (\Throwable $e) {
            $this->warn("  {$kind}: skipped ({$e->getMessage()})");

            return 0;
        }
        if (empty($mails)) {
            $this->warn("  {$kind}: skipped (no data reads that way today)");

            return 0;
        }

        $count = 0;
        foreach ($mails instanceof Mailable ? [$mails] : $mails as $label => $mail) {
            $name = is_string($label) ? "{$kind} ({$label})" : $kind;
            $mail->render(); // resolves the subject, and fails here rather than half-sent
            $this->line("  {$name}: {$mail->subject}");
            if (!$this->option('dry-run')) {
                Mail::to($to)->send($mail);
            }
            $count++;
        }

        return $count;
    }

    private function dailyLogErrors(): DailyLogErrors
    {
        $day = Carbon::yesterday();
        $at  = $day->format('Y-m-d');

        return new DailyLogErrors($day, ['finance-api-cron.log' => [
            [
                'block' => "Process 1213321 dead!\n[{$at} 15:32:08] prod.INFO: START app:finance-api-cron "
                    . "refreshQuotes()\n[{$at} 15:32:08] prod.WARNING: refreshQuotes() unable to fetch: SAMPLE",
                'level' => 'WARNING', 'count' => 2, 'first' => '15:32:08', 'last' => '22:02:10',
            ],
            [
                'block' => "[{$at} 18:01:44] prod.ERROR: Sample error: connection refused",
                'level' => 'ERROR', 'count' => 1, 'first' => '18:01:44', 'last' => '18:01:44',
            ],
        ]]);
    }
}
