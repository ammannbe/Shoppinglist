<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_language_is_used_for_guests(): void
    {
        $this->get('/login', ['Accept-Language' => 'de-DE,de;q=0.9'])->assertSee('Passwort vergessen?');
        $this->get('/login', ['Accept-Language' => 'en-US'])->assertSee('Forgot your password?');
    }

    public function test_guests_switch_the_language_with_a_cookie(): void
    {
        $this->from('/login')->put('/locale', ['locale' => 'en'])
            ->assertRedirect('/login')
            ->assertCookie('locale', 'en');

        $this->withCookie('locale', 'en')->get('/login', ['Accept-Language' => 'de'])->assertSee('Forgot your password?');
    }

    public function test_the_locale_is_stored_per_user(): void
    {
        $user = User::factory()->create(['locale' => 'de_CH']);

        $this->actingAs($user)->putJson('/locale', ['locale' => 'en'])->assertNoContent();

        $this->assertSame('en', $user->fresh()->locale);
        $this->actingAs($user->fresh())->getJson('/me')->assertJsonPath('locale', 'en');
    }

    public function test_unsupported_locales_are_rejected(): void
    {
        $this->putJson('/locale', ['locale' => 'fr'])->assertUnprocessable();
    }

    public function test_registration_stores_the_current_locale_and_mails_use_it(): void
    {
        Notification::fake();

        $this->post('/register', [
            'email' => 'neu@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ], ['Accept-Language' => 'de-CH'])->assertRedirect();

        $user = User::firstWhere('email', 'neu@example.com');
        $this->assertSame('de_CH', $user->locale);
        Notification::assertSentTo($user, VerifyEmail::class, function ($notification, $channels, $notifiable, $locale) {
            return $locale === 'de_CH';
        });
    }
}
