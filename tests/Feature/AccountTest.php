<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The test client sends "Accept-Language: en-us" by default.
        $this->withHeader('Accept-Language', 'de-CH');
    }

    public function test_the_account_page_needs_a_login(): void
    {
        $this->get('/account')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/account')
            ->assertOk()
            ->assertSee('Passwort ändern');
    }

    public function test_users_change_their_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);

        $this->actingAs($user)->from('/account')->put('/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertRedirect('/account')->assertSessionHas('status', 'password-updated');

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
    }

    public function test_the_current_password_is_required(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);

        $this->actingAs($user)->from('/account')->put('/user/password', [
            'current_password' => 'wrong',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertRedirect('/account')->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_managing_passkeys_requires_a_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/passkeys')->assertRedirect('/user/confirm-password');
        $this->actingAs($user)->getJson('/user/passkeys/options')->assertStatus(423);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->get('/account/passkeys')->assertOk()->assertSee('Passkey hinzufügen');
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->getJson('/user/passkeys/options')
            ->assertOk()
            ->assertJsonPath('options.user.name', $user->email);
    }

    public function test_passkey_login_options_are_available_for_guests(): void
    {
        $this->getJson('/passkeys/login/options')->assertOk()->assertJsonStructure(['options' => ['challenge']]);
        $this->get('/login')->assertSee('Mit Passkey anmelden')->assertSee('username webauthn', false);
    }
}
