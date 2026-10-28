<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Auth\ActivateController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesSyntheticUsers;
use Tests\TestCase;

/**
 * The roles of a new account: a finance invite token gives financeadmin, anything else user; unverified variants until
 * activation, which swaps them for the verified ones. Works with or without the MyFinance2 package. Throwaway users
 * (CreatesSyntheticUsers), rolled back.
 */
class OnboardingRolesTest extends TestCase
{
    use CreatesSyntheticUsers;
    use DatabaseTransactions;

    private const TOKEN = 'tst-finance-invite-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.finance_invite_token' => self::TOKEN]);
    }

    public function test_a_valid_finance_invite_token_gives_financeadmin(): void
    {
        $this->assertSame('financeadmin', RegisterController::registrationRole(self::TOKEN, true)?->slug);
        $this->assertSame('unverifiedfinanceadmin', RegisterController::registrationRole(self::TOKEN, false)?->slug);
    }

    public function test_any_other_token_gives_user(): void
    {
        foreach ([null, '', 'wrong-token', ['array']] as $token) {
            $this->assertSame('user', RegisterController::registrationRole($token, true)?->slug);
            $this->assertSame('unverified', RegisterController::registrationRole($token, false)?->slug);
        }
    }

    public function test_no_configured_token_never_gives_financeadmin(): void
    {
        config(['auth.finance_invite_token' => null]);

        $this->assertSame('user', RegisterController::registrationRole('', true)?->slug);
        $this->assertSame('user', RegisterController::registrationRole(self::TOKEN, true)?->slug);
    }

    public function test_activation_swaps_the_unverified_role_for_the_verified_one(): void
    {
        $finance = $this->syntheticUser(['unverifiedfinanceadmin']);
        $plain   = $this->syntheticUser(['unverified']);
        $already = $this->syntheticUser(['financeadmin']);

        $this->assertSame('financeadmin', ActivateController::activationRole($finance)?->slug);
        $this->assertSame('user', ActivateController::activationRole($plain)?->slug);
        $this->assertNull(ActivateController::activationRole($already));
    }
}
