<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesSyntheticUsers;
use Tests\Concerns\ExercisesTheMiddleware;
use Tests\TestCase;

/**
 * A user manages only their own profile and account; the only way to act as someone else is an admin impersonating a
 * non-admin. Throwaway users (CreatesSyntheticUsers), rolled back; two-step verification switched off.
 */
class UserIsolationTest extends TestCase
{
    use CreatesSyntheticUsers;
    use DatabaseTransactions;
    use ExercisesTheMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exerciseTheMiddleware();

        config(['laravel2step.laravel2stepEnabled' => false]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_users_open_their_own_profile_settings(): void
    {
        $user = $this->syntheticUser(['user']);

        $this->actingAs($user)->get("/profile/{$user->name}/edit")->assertOk();
    }

    public function test_users_cannot_open_someone_elses_profile_settings(): void
    {
        $victim = $this->syntheticUser(['user']);

        $this->actingAs($this->syntheticUser(['user']))
            ->get("/profile/{$victim->name}/edit")
            ->assertForbidden();
    }

    public function test_users_cannot_change_someone_elses_profile(): void
    {
        $victim = $this->syntheticUser(['user']);
        $before = $this->_snapshot($victim);

        $this->actingAs($this->syntheticUser(['user']))
            ->put("/profile/{$victim->name}", ['bio' => 'changed by someone else', 'location' => 'elsewhere'])
            ->assertForbidden();

        $this->assertSame($before, $this->_snapshot($victim));
    }

    public function test_users_cannot_change_someone_elses_email_or_name(): void
    {
        $victim = $this->syntheticUser(['user']);
        $before = $this->_snapshot($victim);

        $this->actingAs($this->syntheticUser(['user']))
            ->put("/profile/{$victim->id}/updateUserAccount", [
                'name'  => $victim->name . '-taken',
                'email' => 'taken-' . $victim->email,
            ])
            ->assertForbidden();

        $this->assertSame($before, $this->_snapshot($victim));
    }

    public function test_users_cannot_change_someone_elses_password(): void
    {
        $victim = $this->syntheticUser(['user']);
        $before = $this->_snapshot($victim);

        $this->actingAs($this->syntheticUser(['user']))
            ->put("/profile/{$victim->id}/updateUserPassword", [
                'password'              => 'taken-123',
                'password_confirmation' => 'taken-123',
            ])
            ->assertForbidden();

        $this->assertSame($before, $this->_snapshot($victim));
    }

    public function test_users_cannot_delete_someone_elses_account(): void
    {
        $victim = $this->syntheticUser(['user']);
        $before = $this->_snapshot($victim);

        $this->actingAs($this->syntheticUser(['user']))
            ->delete("/profile/{$victim->id}/deleteUserAccount", ['checkConfirmDelete' => '1'])
            ->assertForbidden();

        $this->assertSame($before, $this->_snapshot($victim));
    }

    public function test_admins_can_impersonate_a_user_act_as_them_and_leave(): void
    {
        $admin = $this->syntheticUser(['admin']);
        $user  = $this->syntheticUser(['user']);

        $this->actingAs($admin)
            ->get("/impersonate/take/{$user->id}")
            ->assertRedirect('/home');
        $this->assertSame($user->id, Auth::id());
        $this->assertTrue(app('impersonate')->isImpersonating());

        // Leaving goes through the manager: test requests do not carry the session cookie, so a second request would
        // not see the impersonation started by the first one.
        app('impersonate')->leave();
        $this->assertSame($admin->id, Auth::id());
    }

    public function test_an_impersonating_admin_manages_the_users_profile(): void
    {
        $user = $this->syntheticUser(['user']);

        $this->actingAs($this->syntheticUser(['admin']))->get("/impersonate/take/{$user->id}");

        $this->get("/profile/{$user->name}/edit")->assertOk();
    }

    public function test_users_who_are_not_admins_cannot_impersonate(): void
    {
        $other = $this->syntheticUser(['user']);
        foreach ([['user'], ['financeadmin']] as $roles) {
            $user = $this->syntheticUser($roles);

            $this->actingAs($user)->get("/impersonate/take/{$other->id}")->assertForbidden();
            $this->assertSame($user->id, Auth::id());
        }
    }

    public function test_admins_cannot_be_impersonated(): void
    {
        $admin = $this->syntheticUser(['admin']);
        $otherAdmin = $this->syntheticUser(['admin']);

        $this->actingAs($admin)->get("/impersonate/take/{$otherAdmin->id}");

        $this->assertSame($admin->id, Auth::id());
    }

    public function test_leave_and_take_needs_an_ongoing_impersonation(): void
    {
        $other = $this->syntheticUser(['user']);

        $this->actingAs($this->syntheticUser(['user']))
            ->get("/impersonate/leave-and-take/{$other->id}")
            ->assertForbidden();
    }

    /**
     * The victim's account and profile rows, to prove a refused request changed nothing.
     *
     * @return array<string, mixed>
     */
    private function _snapshot(User $user): array
    {
        return [
            'user'    => (array) DB::table('users')->where('id', $user->id)->first(),
            'profile' => (array) DB::table('profiles')->where('user_id', $user->id)->first(),
        ];
    }
}
