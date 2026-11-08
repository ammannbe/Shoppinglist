<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The remember-me cookie signs users in again after the session expired, also accounts without password
 * (linked to the OIDC login, User::getAuthPassword()).
 */
class RememberLoginTest extends TestCase
{
    use RefreshDatabase;

    private function recaller(User $user, string $token): string
    {
        return $user->id.'|'.$token.'|'.Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());
    }

    public function test_an_account_without_password_is_signed_in_by_the_remember_cookie(): void
    {
        $user = User::factory()->linked()->create(['remember_token' => 'remember-token']);

        $this->assertNull($user->password);

        $this->withCredentials()
            ->withCookie(Auth::guard('web')->getRecallerName(), $this->recaller($user, 'remember-token'))
            ->getJson('/me')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_account_with_password_is_signed_in_by_the_remember_cookie(): void
    {
        $user = User::factory()->create(['remember_token' => 'remember-token']);

        $this->withCredentials()
            ->withCookie(Auth::guard('web')->getRecallerName(), $this->recaller($user, 'remember-token'))
            ->getJson('/me')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_remember_token_is_refused(): void
    {
        $user = User::factory()->linked()->create(['remember_token' => 'remember-token']);

        $this->withCredentials()
            ->withCookie(Auth::guard('web')->getRecallerName(), $this->recaller($user, 'other-token'))
            ->getJson('/me')
            ->assertUnauthorized();
    }

    public function test_an_account_without_password_cannot_sign_in_with_an_empty_password(): void
    {
        $user = User::factory()->linked()->create();

        $this->assertFalse(Auth::guard('web')->validate(['email' => $user->email, 'password' => '']));
    }
}
