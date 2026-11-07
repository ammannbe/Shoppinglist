<?php

namespace Tests\Feature;

use App\Auth\OidcAccount;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\User;
use App\Notifications\SignInWithOidcNotice;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;
use LogicException;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Concerns\MocksOidcProvider;
use Tests\TestCase;

/**
 * A linked account (users.oidc_sub) logs in through the OIDC provider only (App\Auth\OidcAccount).
 */
class OidcLinkedAccountTest extends TestCase
{
    use MocksOidcProvider, RefreshDatabase;

    private const PASSWORD = 'secret-password';

    private const NEW_PASSWORD = 'new-secret-password';

    private const MESSAGE = 'This account is connected to Pocket ID. Sign in with Pocket ID.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'login.oidc.enabled' => true,
            'login.oidc.label' => 'Pocket ID',
            'login.oidc.registration' => true,
            'login.oidc.required_group' => null,
            'session.driver' => 'database',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function localUser(array $attributes = []): User
    {
        return User::factory()->create(['email' => 'shopper@example.com', 'password' => Hash::make(self::PASSWORD), ...$attributes]);
    }

    private function passkey(User $user, string $id = 'credential'): Passkey
    {
        return $user->passkeys()->create(['name' => 'iPhone', 'credential_id' => $id, 'credential' => []]);
    }

    private function foreignSession(User $user, string $id = 'other-device'): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * Passkey login with a credential the (mocked) verification accepts for $passkey.
     *
     * @return TestResponse<Response>
     */
    private function passkeyLogin(Passkey $passkey): TestResponse
    {
        $this->mock(VerifyPasskey::class, fn (MockInterface $mock) => $mock->shouldReceive('__invoke')->andReturn($passkey));
        $challenge = $this->getJson(route('passkey.login-options'))->assertOk()->json('options.challenge');
        $base64 = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return $this->postJson(route('passkey.login'), [
            'credential' => [
                'id' => $base64('credential'),
                'rawId' => $base64('credential'),
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => $base64((string) json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => config('app.url')])),
                    'authenticatorData' => $base64(hash('sha256', 'localhost', true)."\x01\x00\x00\x00\x01"),
                    'signature' => $base64('signature'),
                    'userHandle' => null,
                ],
                'clientExtensionResults' => [],
            ],
            'remember' => true,
        ]);
    }

    public function test_the_first_oidc_login_links_the_account_and_removes_the_local_login(): void
    {
        $user = $this->localUser(['remember_token' => 'old-remember-token']);
        $this->passkey($user);
        $this->foreignSession($user);
        Password::broker()->createToken($user);

        $this->signIn()->assertCookie(Auth::guard()->getRecallerName());

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('sub-123', $user->oidc_sub);
        $this->assertNull($user->password);
        $this->assertSame(0, $user->passkeys()->count());
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'shopper@example.com']);
    }

    public function test_linking_from_the_account_page_needs_the_password(): void
    {
        $user = $this->localUser(['email' => 'different@example.com']);
        $this->passkey($user);
        $this->provider()->shouldReceive('user')->andReturn($this->identity());
        $this->actingAs($user);

        // A session alone does not link (and does not go to the provider).
        $this->get(route('oidc.redirect', ['link' => 1]))->assertRedirect(route('account'))->assertSessionHasErrors('oidc');
        $this->assertFalse(session()->has('oidc.intent'));

        $this->from(route('account'))->post(route('oidc.link'), ['password' => 'wrong-password'])
            ->assertRedirect(route('account'))
            ->assertSessionHasErrorsIn('linkOidc', 'password');

        $this->post(route('oidc.link'), ['password' => self::PASSWORD])->assertRedirect(route('oidc.redirect', ['link' => 1]));

        $this->get(route('oidc.redirect', ['link' => 1]))->assertRedirect('https://login.example.com/authorize');
        $this->foreignSession($user);
        $this->get(route('oidc.callback'))->assertRedirect(route('account'));

        $user->refresh();
        $this->assertSame('sub-123', $user->oidc_sub);
        $this->assertNull($user->password);
        $this->assertSame(0, $user->passkeys()->count());
        // Other devices are logged out, this one stays logged in.
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_password_confirmation_for_linking_is_used_once_and_expires(): void
    {
        $user = $this->localUser(['email' => 'different@example.com']);
        $this->provider();
        $this->actingAs($user);

        $this->post(route('oidc.link'), ['password' => self::PASSWORD]);
        $this->get(route('oidc.redirect', ['link' => 1]))->assertRedirect('https://login.example.com/authorize');
        $this->get(route('oidc.redirect', ['link' => 1]))->assertRedirect(route('account'));

        $this->post(route('oidc.link'), ['password' => self::PASSWORD]);
        $this->travel(OidcAccount::PASSWORD_TIMEOUT + 1)->seconds();
        $this->get(route('oidc.redirect', ['link' => 1]))->assertRedirect(route('account'));
    }

    public function test_a_linked_account_cannot_log_in_with_a_password(): void
    {
        $user = User::factory()->linked()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'any-password'])
            ->assertSessionHasErrors(['email' => self::MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_password_left_on_a_linked_account_does_not_log_in(): void
    {
        $user = User::factory()->linked()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make(self::PASSWORD)]);

        $this->post(route('login.store'), ['email' => mb_strtoupper($user->email), 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => self::MESSAGE]);

        $this->assertGuest();
    }

    public function test_other_failed_logins_keep_the_usual_message(): void
    {
        $this->localUser();

        foreach (['shopper@example.com', 'nobody@example.com'] as $email) {
            $this->post(route('login.store'), ['email' => $email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => __('auth.failed', locale: 'en')]);
        }

        $this->assertGuest();
    }

    public function test_without_the_local_login_a_linked_account_is_told_to_use_the_provider(): void
    {
        config(['login.local.enabled' => false, 'login.local.emergency_emails' => []]);
        $user = User::factory()->linked()->create();
        $this->localUser();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'any-password'])
            ->assertSessionHasErrors(['email' => self::MESSAGE]);
        $this->post(route('login.store'), ['email' => 'shopper@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => __('auth.failed', locale: 'en')]);

        $this->assertGuest();
    }

    public function test_a_passkey_left_on_a_linked_account_does_not_log_in(): void
    {
        $user = User::factory()->linked()->create();
        DB::table('passkeys')->insert(['user_id' => $user->id, 'name' => 'Old', 'credential_id' => 'old', 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $this->passkeyLogin(Passkey::query()->firstOrFail())
            ->assertUnprocessable()
            ->assertJsonPath('message', self::MESSAGE);

        $this->assertGuest();
    }

    public function test_an_unlinked_account_logs_in_with_a_passkey(): void
    {
        $user = $this->localUser();

        $this->passkeyLogin($this->passkey($user))->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_password_reset_for_a_linked_account_only_points_to_the_provider(): void
    {
        Notification::fake();
        $linked = User::factory()->linked()->create();
        $local = $this->localUser();
        $sent = __(Password::RESET_LINK_SENT, locale: 'en');

        // The page answers the same for both, the mail tells the owner of the linked account to use the provider.
        foreach ([$linked, $local] as $user) {
            $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])
                ->assertRedirect(route('password.request'))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', $sent);
        }

        Notification::assertSentTo($linked, SignInWithOidcNotice::class);
        Notification::assertNotSentTo($linked, ResetPassword::class);
        Notification::assertSentTo($local, ResetPassword::class);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $linked->email]);

        // Within the broker's throttle both are asked to wait.
        foreach ([$linked, $local] as $user) {
            $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasErrors(['email' => __(Password::RESET_THROTTLED, locale: 'en')]);
        }

        // Then "sent" again, but one notice per NOTICE_DECAY_SECONDS.
        $this->travel(61)->seconds();
        $this->post(route('password.email'), ['email' => $linked->email])->assertSessionHas('status', $sent);
        Notification::assertSentToTimes($linked, SignInWithOidcNotice::class, 1);

        $this->travel(PasswordResetLinkController::NOTICE_DECAY_SECONDS)->seconds();
        $this->post(route('password.email'), ['email' => $linked->email])->assertSessionHas('status', $sent);
        Notification::assertSentToTimes($linked, SignInWithOidcNotice::class, 2);
    }

    public function test_a_reset_link_cannot_give_a_linked_account_a_password(): void
    {
        $user = User::factory()->linked()->create();
        $token = Password::broker()->createToken($user);
        $reset = ['email' => $user->email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        // Without a valid token the usual answer, so the form does not tell whether the address is linked.
        $this->post(route('password.update'), [...$reset, 'token' => 'wrong-token'])->assertSessionHasErrors(['email' => __(Password::INVALID_TOKEN, locale: 'en')]);

        $this->post(route('password.update'), [...$reset, 'token' => $token])->assertSessionHasErrors(['email' => self::MESSAGE]);

        $this->assertNull($user->fresh()->password);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertGuest();
    }

    public function test_an_unlinked_account_without_password_can_still_reset_it(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => null]);

        $this->post(route('password.email'), ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $user->fresh()->password));
    }

    public function test_unlinking_needs_a_recent_oidc_login(): void
    {
        $user = User::factory()->linked()->create();
        $this->provider()->shouldReceive('user')->andReturn($this->identity(['sub' => 'someone-else']), $this->identity());
        $this->actingAs($user);

        $this->get(route('account'))->assertOk()
            ->assertSee('For your security, confirm with Pocket ID first.')
            ->assertSee(route('oidc.redirect', ['confirm' => 1]), false)
            ->assertDontSee('new-password', false);
        $this->delete(route('oidc.unlink'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertRedirect(route('oidc.redirect', ['confirm' => 1]));
        $this->assertNotNull($user->fresh()->oidc_sub);

        $this->get(route('oidc.redirect', ['confirm' => 1]))->assertRedirect('https://login.example.com/authorize');

        // Another provider account does not confirm.
        $this->get(route('oidc.callback'))->assertRedirect(route('account'))->assertSessionHasErrors(['oidc' => 'This sign-in belongs to another account.']);
        $this->assertFalse(app(OidcAccount::class)->hasRecentSignIn());
        $this->assertAuthenticatedAs($user);

        $this->get(route('oidc.redirect', ['confirm' => 1]));
        $this->get(route('oidc.callback'))->assertRedirect(route('account').'#oidc');
        $this->assertTrue(app(OidcAccount::class)->hasRecentSignIn());
        $this->get(route('account'))->assertSee('new-password', false);

        $this->travel(OidcAccount::SIGN_IN_TIMEOUT + 1)->seconds();
        $this->assertFalse(app(OidcAccount::class)->hasRecentSignIn());
    }

    public function test_unlinking_needs_a_valid_new_password_and_then_allows_the_local_login(): void
    {
        $user = User::factory()->linked()->create();
        $this->actingAs($user)->withSession(['oidc.signed_in_at' => now()->getTimestamp()]);
        $this->foreignSession($user);

        foreach ([['short', 'short'], [self::NEW_PASSWORD, 'something-else'], ['', '']] as [$password, $confirmation]) {
            $this->from(route('account'))->delete(route('oidc.unlink'), ['password' => $password, 'password_confirmation' => $confirmation])
                ->assertRedirect(route('account'))
                ->assertSessionHasErrorsIn('unlinkOidc', 'password');
        }
        $this->assertSame('sub-123', $user->fresh()->oidc_sub);

        $this->delete(route('oidc.unlink'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertRedirect(route('account'))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNull($user->oidc_sub);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $user->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);

        Auth::logout();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => self::NEW_PASSWORD])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_account_page_of_a_linked_account_has_no_password_or_passkeys(): void
    {
        $user = User::factory()->linked()->create();

        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('Connected with Pocket ID')
            ->assertDontSee('current-password', false)
            ->assertDontSee(route('account.passkeys'), false)
            ->assertDontSee(route('oidc.link'), false);

        $this->getJson('/me')->assertJsonPath('linked', true);
    }

    public function test_the_account_page_of_an_unlinked_account_keeps_password_and_passkeys(): void
    {
        $this->actingAs($this->localUser())->get(route('account'))->assertOk()
            ->assertDontSee('Connected with Pocket ID')
            ->assertSee('current-password', false)
            ->assertSee(route('account.passkeys'), false)
            ->assertSee(route('oidc.link'), false);

        $this->getJson('/me')->assertJsonPath('linked', false);
    }

    public function test_a_linked_account_keeps_its_email_address(): void
    {
        $user = User::factory()->linked()->create(['email' => 'shopper@example.com']);

        $this->actingAs($user)->put(route('user-profile-information.update'), ['name' => 'Neuer Name', 'email' => 'other@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Neuer Name', $user->fresh()->name);
        $this->assertSame('shopper@example.com', $user->fresh()->email);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_linked_account_cannot_get_a_password_on_the_model(): void
    {
        $user = User::factory()->linked()->create();

        $this->expectException(LogicException::class);

        $user->forceFill(['password' => self::NEW_PASSWORD])->save();
    }

    public function test_a_linked_account_cannot_get_a_passkey(): void
    {
        $user = User::factory()->linked()->create();

        $this->expectException(LogicException::class);

        $this->passkey($user);
    }

    public function test_the_migration_removes_the_local_login_of_linked_accounts_only(): void
    {
        $linked = $this->localUser();
        $this->passkey($linked, 'linked-passkey');
        DB::table('users')->where('id', $linked->id)->update(['oidc_sub' => 'sub-123']);
        $emergency = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->passkey($emergency, 'emergency-passkey');
        $withoutPassword = User::factory()->create(['password' => null]);
        $this->foreignSession($linked, 'linked-session');
        $this->foreignSession($emergency, 'emergency-session');
        Password::broker()->createToken($linked);
        Password::broker()->createToken($withoutPassword);

        $migration = require database_path('migrations/2026_11_10_090000_remove_local_login_from_linked_accounts.php');
        $migration->up();

        $this->assertNull($linked->fresh()->password);
        $this->assertNull(DB::table('users')->where('id', $linked->id)->value('remember_token'));
        $this->assertDatabaseMissing('passkeys', ['credential_id' => 'linked-passkey']);
        $this->assertDatabaseMissing('sessions', ['id' => 'linked-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $linked->email]);

        // Unlinked accounts (emergency addresses, accounts without password) are untouched.
        $this->assertTrue(Hash::check(self::PASSWORD, (string) $emergency->fresh()->password));
        $this->assertDatabaseHas('passkeys', ['credential_id' => 'emergency-passkey']);
        $this->assertDatabaseHas('sessions', ['id' => 'emergency-session']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $withoutPassword->email]);
    }

    public function test_the_command_unlinks_accounts_for_a_new_password(): void
    {
        $linked = User::factory()->linked()->create();
        $other = User::factory()->linked('sub-other')->create();

        $this->command('users:unlink-oidc')->assertFailed();
        $this->command('users:unlink-oidc', ['emails' => [mb_strtoupper($linked->email)]])->assertSuccessful();

        $this->assertNull($linked->fresh()->oidc_sub);
        $this->assertNull($linked->fresh()->password);
        $this->assertSame('sub-other', $other->fresh()->oidc_sub);

        $this->command('users:unlink-oidc', ['--all' => true])->expectsConfirmation('Unlink 1 account(s)?', 'yes')->assertSuccessful();
        $this->assertNull($other->fresh()->oidc_sub);
    }
}
