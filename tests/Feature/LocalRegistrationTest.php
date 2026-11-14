<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AUTH_LOCAL_REGISTRATION (config/login.php, App\Auth\Registration, RestrictLocalRegistration). Same file as in
 * Chorelist.
 */
class LocalRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNUP = 'https://konto.example.com/register';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'login.oidc.enabled' => true,
            'login.oidc.label' => 'Pocket ID',
            'login.oidc.signup_url' => self::SIGNUP,
        ]);
    }

    private function register(): TestResponse
    {
        return $this->post('/register', [
            'email' => 'new@example.com',
            'password' => 'a-good-password-123',
            'password_confirmation' => 'a-good-password-123',
        ]);
    }

    public function test_the_local_registration_is_on_by_default(): void
    {
        $this->assertTrue(config('login.local.registration'));

        $this->get('/register')->assertOk()->assertSee('name="password"', false);
        $this->get('/login')->assertSee('href="/register"', false);
        $this->get('/')->assertSee('href="/register"', false);

        $this->register()->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_without_local_registration_the_page_redirects_to_the_signup_of_the_provider(): void
    {
        config(['login.local.registration' => false]);

        $this->get('/register')->assertStatus(302)->assertRedirect(self::SIGNUP);
        $this->register()->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);

        // "Register" links go to the signup of the provider.
        $this->get('/login')->assertSee('href="'.self::SIGNUP.'"', false)->assertDontSee('href="/register"', false);
        $this->get('/')->assertSee('href="'.self::SIGNUP.'"', false)->assertDontSee('href="/register"', false);
    }

    public function test_without_local_registration_and_signup_url_there_is_no_link(): void
    {
        config(['login.local.registration' => false, 'login.oidc.signup_url' => null]);

        $this->get('/register')->assertRedirect(route('login'));
        $this->get('/login')->assertOk()->assertDontSee('href="/register"', false)->assertDontSee('>Register<', false);
        $this->get('/')->assertDontSee('href="/register"', false);
    }

    public function test_existing_users_still_log_in_locally(): void
    {
        config(['login.local.registration' => false]);
        $user = User::factory()->create(['email' => 'local@example.com']);

        $this->post('/login', ['email' => 'local@example.com', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }
}
