<?php

namespace Tests\Feature;

use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\MocksOidcProvider;
use Tests\TestCase;

/**
 * "Manage account" in the settings (AccountController, resources/js/account.js): JSON endpoints, no account page.
 */
class AccountTest extends TestCase
{
    use MocksOidcProvider, RefreshDatabase;

    private const PASSWORD = 'old-password-123';

    protected function setUp(): void
    {
        parent::setUp();

        // The test client sends "Accept-Language: en-us" by default.
        $this->withHeader('Accept-Language', 'de-CH');
        config([
            'login.oidc.enabled' => true,
            'login.oidc.label' => 'Pocket ID',
            'login.oidc.registration' => true,
            'login.oidc.required_group' => null,
            'session.driver' => 'database',
        ]);
    }

    private function localUser(): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
    }

    private function createList(User $user, string $name = 'Wocheneinkauf'): ShoppingList
    {
        $id = (string) Str::uuid();
        $this->actingAs($user)->postJson('/sync/lists/push', [['newDocumentState' => ['id' => $id, 'name' => $name]]])
            ->assertOk()->assertExactJson([]);

        return ShoppingList::findOrFail($id);
    }

    public function test_the_former_account_page_redirects_to_the_settings(): void
    {
        $this->get('/account')->assertRedirect('/login');

        $this->actingAs($this->localUser())->get('/account')->assertRedirect('/#/settings');
    }

    public function test_the_settings_contain_the_account_section_disabled_offline(): void
    {
        $shell = $this->get('/')->assertOk()->getContent();
        $this->assertIsString($shell);

        // Part of the cached app shell (no user data): rendered from /me, no link to an account page.
        $this->assertStringContainsString('x-data="accountSettings"', $shell);
        $this->assertStringNotContainsString('href="/account"', $shell);

        // Every action of "Manage account" is disabled offline (only cancelling works), the AccountManager link is dimmed.
        $section = Str::betweenFirst($shell, 'x-data="accountSettings"', '</dialog>');
        preg_match_all('/<button[^>]*>/', $section, $buttons);
        $offline = array_filter($buttons[0], fn (string $button): bool => ! str_contains($button, 'closeAccountDialog()')
            && ! str_contains($button, 'cancelConfirmation()') && ! str_contains($button, '!onlineNow'));
        $this->assertSame([], array_values($offline));
        $this->assertStringContainsString('data-online class="account-row"', $section);
    }

    public function test_the_account_state_of_a_local_account(): void
    {
        $user = $this->localUser();
        $user->passkeys()->create(['name' => 'iPhone', 'credential_id' => 'a', 'credential' => []]);
        // Local accounts change e-mail, password and passkeys here, also with the AccountManager connected.
        config(['households.account_manager.url' => 'https://konto.example.com', 'households.account_manager.label' => 'NarrenKonto']);

        $this->actingAs($user)->getJson('/me')->assertOk()
            ->assertJsonPath('account', [
                'has_password' => true,
                'oidc' => ['enabled' => true, 'provider' => 'Pocket ID', 'signup_url' => null, 'confirmed' => false],
                'manager' => null,
                'passkeys' => 1,
            ])
            ->assertJsonPath('notice', null);

        config(['login.oidc.enabled' => false]);
        $this->getJson('/me')->assertJsonPath('account.oidc', null);
    }

    public function test_linked_accounts_manage_their_profile_in_the_account_manager_when_connected(): void
    {
        $user = User::factory()->linked()->create();

        $this->actingAs($user)->getJson('/me')->assertJsonPath('account.manager', null);

        config(['households.account_manager.url' => 'https://konto.example.com/', 'households.account_manager.label' => 'NarrenKonto']);
        $this->getJson('/me')->assertJsonPath('account.manager', ['name' => 'NarrenKonto', 'url' => 'https://konto.example.com/profile']);

        // OIDC login turned off: still linked, the provider is named but unlinking is not offered.
        config(['login.oidc.enabled' => false]);
        $this->getJson('/me')->assertJsonPath('account.oidc.enabled', false)->assertJsonPath('account.oidc.provider', 'Pocket ID');
    }

    public function test_users_change_their_password(): void
    {
        $user = $this->localUser();

        $this->actingAs($user)->putJson('/user/password', [
            'current_password' => self::PASSWORD,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
    }

    public function test_the_current_password_is_required(): void
    {
        $user = $this->localUser();

        $this->actingAs($user)->putJson('/user/password', [
            'current_password' => 'wrong',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_users_change_their_email_with_the_password_but_not_their_name(): void
    {
        Notification::fake();
        $user = $this->localUser();
        $user->forceFill(['name' => 'Registriert'])->save();
        $member = User::factory()->create();
        $list = $this->createList($user);
        $list->addMember($member);
        $this->travel(5)->seconds();

        $this->actingAs($user)->putJson('/user/profile-information', ['email' => 'new@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/user/profile-information', ['email' => 'new@example.com', 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertNotSame('new@example.com', $user->fresh()->email);

        $this->putJson('/user/profile-information', ['email' => 'new@example.com', 'current_password' => self::PASSWORD, 'name' => 'Anders'])
            ->assertOk();

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertSame('Registriert', $user->name);
        Notification::assertSentTo($user, VerifyEmail::class);
        // The member pulls the list again (owner address).
        $this->assertTrue($list->memberships()->where('user_id', $member->id)->firstOrFail()->updated_at->isAfter(now()->subSecond()));
    }

    public function test_passkeys_are_listed_and_managing_them_requires_a_password_confirmation(): void
    {
        $user = $this->localUser();
        $passkey = $user->passkeys()->create(['name' => 'iPhone', 'credential_id' => 'Y3JlZGVudGlhbA', 'credential' => []]);

        $this->actingAs($user)->getJson('/account/passkeys')->assertOk()
            ->assertJsonPath('0.id', $passkey->id)
            ->assertJsonPath('0.name', 'iPhone')
            ->assertJsonPath('0.used', fn (string $text): bool => str_starts_with($text, 'Hinzugefügt'));

        $this->getJson('/user/confirmed-password-status')->assertExactJson(['confirmed' => false]);
        $this->getJson('/user/passkeys/options')->assertStatus(423);
        $this->deleteJson("/user/passkeys/{$passkey->id}")->assertStatus(423);

        $this->postJson('/user/confirm-password', ['password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/user/confirm-password', ['password' => self::PASSWORD])->assertCreated();

        $this->getJson('/user/confirmed-password-status')->assertExactJson(['confirmed' => true]);
        $this->getJson('/user/passkeys/options')->assertOk()->assertJsonPath('options.user.name', $user->email);
        $this->deleteJson("/user/passkeys/{$passkey->id}")->assertOk();
        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_passkey_login_options_are_available_for_guests(): void
    {
        $this->getJson('/passkeys/login/options')->assertOk()->assertJsonStructure(['options' => ['challenge']]);
        $this->get('/login')->assertSee('Mit Passkey anmelden')->assertSee('username webauthn', false);
    }

    public function test_local_accounts_are_deleted_with_the_password(): void
    {
        $user = $this->localUser();
        $member = User::factory()->create();
        $private = $this->createList($user, 'Privat');
        $shared = $this->createList($user, 'Geteilt');
        $shared->addMember($member);

        $this->actingAs($user)->deleteJson('/account', ['password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertModelExists($user);

        $this->deleteJson('/account', ['password' => self::PASSWORD])->assertOk()->assertExactJson(['redirect' => '/?deleted=1']);

        // App\Services\UserDeletion: private lists go, shared ones go to another member.
        $this->assertModelMissing($user);
        $this->assertNull(ShoppingList::withTrashed()->find($private->id));
        $this->assertSame($member->id, $shared->fresh()?->owner_id);
        $this->assertGuest();
    }

    public function test_a_local_account_without_password_cannot_be_deleted(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)->deleteJson('/account')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertModelExists($user);
    }

    public function test_linked_accounts_are_deleted_after_a_recent_confirmation_at_the_provider(): void
    {
        $user = User::factory()->linked()->create();
        $this->provider()->shouldReceive('user')->andReturn($this->identity());

        $this->actingAs($user)->deleteJson('/account')
            ->assertStatus(409)
            ->assertJsonPath('redirect', route('oidc.redirect', ['confirm' => 1, 'for' => 'delete']));
        $this->assertModelExists($user);

        // Back from the provider, the settings open the delete dialog again.
        $this->get(route('oidc.redirect', ['confirm' => 1, 'for' => 'delete']))->assertRedirect('https://login.example.com/authorize');
        $this->get(route('oidc.callback'))->assertRedirect('/?account=delete#/settings');
        $this->getJson('/me')->assertJsonPath('account.oidc.confirmed', true);

        $this->deleteJson('/account')->assertOk()->assertExactJson(['redirect' => '/?deleted=1']);
        $this->assertModelMissing($user);
    }

    public function test_linked_accounts_cannot_be_deleted_while_the_oidc_login_is_off(): void
    {
        config(['login.oidc.enabled' => false]);
        $user = User::factory()->linked()->create();

        $this->actingAs($user)->withSession(['oidc.signed_in_at' => now()->getTimestamp()])->deleteJson('/account')->assertStatus(409);
        $this->assertModelExists($user);
    }
}
