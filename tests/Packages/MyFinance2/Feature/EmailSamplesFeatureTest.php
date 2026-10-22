<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use ovidiuro\myfinance2\App\Models\PriceAlert;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\EmailSamples;
use ovidiuro\myfinance2\Mail\PriceAlertCreated;
use ovidiuro\myfinance2\Mail\PriceAlertStateChanged;
use ovidiuro\myfinance2\Mail\PriceAlertTriggered;
use ovidiuro\myfinance2\Mail\ReturnsMissingQuotes;
use ovidiuro\myfinance2\Mail\SplitApplied;
use Tests\TestCase;

/**
 * EmailSamples builds one sample of each MyFinance2 email from the first user's data, read-only; the host app's
 * emails:samples sends them. Nothing is sent here (Mail is faked and the samples are only built and rendered); the
 * heavy dashboard-backed kinds (peak-proximity, portfolio-peak, dip-buying) are covered by their own feature tests.
 */
class EmailSamplesFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private ?User $_user = null;

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->_user = User::first();
        if (!$this->_user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }
        $this->actingAs($this->_user);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_builders_list_every_myfinance2_kind_in_send_order(): void
    {
        $kinds = array_keys((new EmailSamples())->builders((int) $this->_user->id));

        $this->assertSame([
            'price-alert', 'price-alert-split', 'alert-created', 'alert-paused', 'peak-proximity',
            'portfolio-peak', 'dip-buying', 'split-applied', 'returns-missing-quotes',
        ], $kinds);
    }

    public function test_light_kinds_build_their_mailable_from_the_users_alert(): void
    {
        $hasAlert = PriceAlert::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->_user->id)->exists();
        if (!$hasAlert) {
            $this->markTestSkipped('The first user has no price alert to sample.');
        }
        $builders = (new EmailSamples())->builders((int) $this->_user->id);

        $expected = [
            'price-alert'            => PriceAlertTriggered::class,
            'price-alert-split'      => PriceAlertTriggered::class,
            'alert-created'          => PriceAlertCreated::class,
            'alert-paused'           => PriceAlertStateChanged::class,
            'split-applied'          => SplitApplied::class,
            'returns-missing-quotes' => ReturnsMissingQuotes::class,
        ];
        $subjects = [];
        foreach ($expected as $kind => $mailable) {
            $mail = $builders[$kind]();
            $this->assertInstanceOf($mailable, $mail, $kind);
            $this->assertNotSame('', $mail->render(), $kind);
            $subjects[$kind] = $mail->subject;
        }
        $this->assertStringContainsString('possible split', $subjects['price-alert-split']);
        $this->assertStringNotContainsString('possible split', $subjects['price-alert']);
        // Icons lead the title in the body, never a subject
        $icon = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{23F8}]/u';
        foreach ($subjects as $kind => $subject) {
            $this->assertDoesNotMatchRegularExpression($icon, $subject, $kind);
        }
        Mail::assertNothingSent();
    }

    public function test_default_recipient_is_the_alerts_address_when_set(): void
    {
        config(['alerts.email_to' => 'alerts@example.test']);
        $this->assertSame('alerts@example.test', EmailSamples::defaultRecipient());

        config(['alerts.email_to' => null]);
        $this->assertNull(EmailSamples::defaultRecipient());
    }
}
