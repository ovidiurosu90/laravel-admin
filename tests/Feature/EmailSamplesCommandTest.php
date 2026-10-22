<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\DailyLogErrors;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use ovidiuro\myfinance2\App\Models\PriceAlert;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\Mail\PriceAlertCreated;
use ovidiuro\myfinance2\Mail\ReturnsMissingQuotes;
use Tests\TestCase;

/**
 * emails:samples sends the main app's own sample (daily log errors) plus each MyFinance2 sample the package builds,
 * read-only. Mail is faked, so nothing reaches an inbox. What each MyFinance2 sample contains is covered by
 * tests/Packages/MyFinance2/Feature/EmailSamplesFeatureTest.
 */
class EmailSamplesCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_sends_its_own_and_the_myfinance2_samples_to_the_given_address(): void
    {
        $user = User::first();
        if ($user === null) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        $hasAlert = PriceAlert::withoutGlobalScope(AssignedToUserScope::class)->where('user_id', $user->id)->exists();
        if (!$hasAlert) {
            $this->markTestSkipped('The first user has no price alert to sample.');
        }
        Mail::fake();

        $exit = Artisan::call('emails:samples', [
            '--to'      => 'samples@example.test',
            '--user-id' => $user->id,
            '--only'    => 'alert-created,daily-log-errors',
        ]);

        $this->assertSame(0, $exit, Artisan::output());

        foreach ([PriceAlertCreated::class, DailyLogErrors::class] as $mailable) {
            Mail::assertSent($mailable, fn ($mail) => $mail->hasTo('samples@example.test'));
        }
        // --only filters the package kinds too
        Mail::assertNotSent(ReturnsMissingQuotes::class);
    }

    public function test_dry_run_sends_nothing(): void
    {
        if (User::first() === null) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        Mail::fake();

        $exit = Artisan::call('emails:samples', ['--dry-run' => true, '--only' => 'daily-log-errors']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('daily-log-errors: [', Artisan::output());

        Mail::assertNothingSent();
    }
}
