<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Middleware\VerifyCsrfToken;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    // use RefreshDatabase; //NOTE You need a test database here

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::whereEmail(env('TEST_USER_EMAIL'))->first();

        $response = $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post('/login', [
                'email'    => $user->email,
                'password' => env('TEST_USER_PASSWORD'),
            ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::whereEmail(env('TEST_USER_EMAIL'))->first();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post('/login', [
                'email'    => $user->email,
                'password' => 'wrong-password',
            ]);

        $this->assertGuest();
    }
}

