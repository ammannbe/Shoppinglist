<?php

namespace Tests\Feature;

use App\Auth\OidcClaims;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;
use SocialiteProviders\OIDC\InvalidTokenException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class OidcLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'login.oidc.enabled' => true,
            'login.oidc.label' => 'Pocket ID',
            'login.oidc.registration' => true,
            'login.oidc.required_group' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function identity(array $claims = []): SocialiteUser
    {
        $claims = [
            'sub' => 'sub-123',
            'email' => 'shopper@example.com',
            'email_verified' => true,
            'name' => 'Pocket Shopper',
            ...$claims,
        ];

        return (new SocialiteUser)->setRaw($claims)->map([
            'id' => $claims['sub'],
            'email' => $claims['email'],
            'name' => $claims['name'],
            'nickname' => null,
        ]);
    }

    private function provider(): Mockery\MockInterface
    {
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(new RedirectResponse('https://login.example.com/authorize'));

        Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return TestResponse<Response>
     */
    private function signIn(array $claims = []): TestResponse
    {
        $this->provider()->shouldReceive('user')->andReturn($this->identity($claims));

        return $this->get(route('oidc.callback'));
    }

    public function test_the_routes_are_hidden_while_oidc_is_disabled(): void
    {
        config(['login.oidc.enabled' => false]);

        $this->get(route('oidc.redirect'))->assertNotFound();
        $this->get(route('oidc.callback'))->assertNotFound();
    }

    public function test_the_redirect_goes_to_the_provider(): void
    {
        $this->provider();

        $this->get(route('oidc.redirect'))->assertRedirect('https://login.example.com/authorize');
    }

    public function test_a_linked_user_is_logged_in(): void
    {
        $user = User::factory()->create(['oidc_sub' => 'sub-123', 'email' => 'other@example.com']);

        $this->signIn()->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_verified_local_account_is_linked_by_email(): void
    {
        $user = User::factory()->create(['email' => 'Shopper@Example.com']);
        $user->passkeys()->create(['name' => 'Own', 'credential_id' => 'own', 'credential' => []]);

        $this->signIn();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('sub-123', $user->fresh()->oidc_sub);
        $this->assertNotNull($user->fresh()->password);
        $this->assertSame(1, $user->passkeys()->count());
    }

    public function test_an_unverified_local_account_loses_password_passkeys_and_sessions_when_linked(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'shopper@example.com']);
        $user->passkeys()->create(['name' => 'Foreign', 'credential_id' => 'foreign', 'credential' => []]);
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'foreign-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $this->signIn();

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->password);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(0, $user->passkeys()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'foreign-session']);
    }

    public function test_an_unverified_provider_email_is_not_linked(): void
    {
        $user = User::factory()->create(['email' => 'shopper@example.com']);

        $this->signIn(['email_verified' => false])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->oidc_sub);
    }

    public function test_an_account_linked_to_another_login_is_not_taken_over(): void
    {
        $user = User::factory()->create(['email' => 'shopper@example.com', 'oidc_sub' => 'someone-else']);

        $this->signIn();

        $this->assertGuest();
        $this->assertSame('someone-else', $user->fresh()->oidc_sub);
    }

    public function test_an_unknown_user_gets_a_verified_account_without_password(): void
    {
        $this->signIn();

        $user = User::query()->where('oidc_sub', 'sub-123')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('shopper@example.com', $user->email);
        $this->assertSame('Pocket Shopper', $user->name);
        $this->assertNull($user->password);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_no_account_is_created_when_oidc_registration_is_disabled(): void
    {
        config(['login.oidc.registration' => false]);

        $this->signIn();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'shopper@example.com']);
    }

    public function test_the_required_group_is_enforced(): void
    {
        config(['login.oidc.required_group' => 'svc-shoppinglist']);
        User::factory()->create(['oidc_sub' => 'sub-123']);
        $this->provider()->shouldReceive('user')->andReturn(
            $this->identity(['groups' => ['svc-other']]),
            $this->identity(['groups' => ['svc-shoppinglist']]),
        );

        $this->get(route('oidc.callback'));
        $this->assertGuest();

        $this->get(route('oidc.callback'));
        $this->assertAuthenticated();
    }

    public function test_provider_errors_do_not_log_in(): void
    {
        $this->provider()->shouldReceive('user')->andThrow(new RuntimeException('Invalid state'));

        $this->get(route('oidc.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('oidc');

        $this->assertGuest();
    }

    public function test_a_logged_in_user_can_link_and_unlink_the_login(): void
    {
        $user = User::factory()->create(['email' => 'different@example.com']);
        $this->provider()->shouldReceive('user')->andReturn($this->identity());

        $this->actingAs($user)->get(route('oidc.redirect', ['link' => 1]));
        $this->get(route('oidc.callback'))->assertRedirect(route('account'));

        $this->assertSame('sub-123', $user->fresh()->oidc_sub);
        $this->assertDatabaseMissing('users', ['email' => 'shopper@example.com']);

        $this->delete(route('oidc.unlink'))->assertRedirect(route('account'));
        $this->assertNull($user->fresh()->oidc_sub);
    }

    public function test_a_user_without_password_cannot_unlink(): void
    {
        $user = User::factory()->create(['oidc_sub' => 'sub-123', 'password' => null]);

        $this->actingAs($user)->delete(route('oidc.unlink'))->assertForbidden();

        $this->assertSame('sub-123', $user->fresh()->oidc_sub);
    }

    public function test_a_login_linked_elsewhere_cannot_be_linked_again(): void
    {
        User::factory()->create(['oidc_sub' => 'sub-123']);
        $user = User::factory()->create();
        $this->provider()->shouldReceive('user')->andReturn($this->identity());

        $this->actingAs($user)->get(route('oidc.redirect', ['link' => 1]));
        $this->get(route('oidc.callback'));

        $this->assertNull($user->fresh()->oidc_sub);
    }

    public function test_the_id_token_claims_are_checked(): void
    {
        $valid = (object) ['iss' => 'https://login.example.com', 'aud' => 'client', 'exp' => now()->addMinute()->getTimestamp()];

        OidcClaims::assertValid($valid, 'https://login.example.com/', 'client');

        foreach ([
            ['iss' => 'https://evil.example.com'],
            ['aud' => 'other-client'],
            ['aud' => ['client', 'other-client']],
            ['exp' => now()->subMinute()->getTimestamp()],
        ] as $override) {
            try {
                OidcClaims::assertValid((object) [...(array) $valid, ...$override], 'https://login.example.com', 'client');
                $this->fail('Invalid claims were accepted: '.json_encode($override));
            } catch (InvalidTokenException) {
                $this->addToAssertionCount(1);
            }
        }

        OidcClaims::assertValid((object) [...(array) $valid, 'aud' => ['client', 'other'], 'azp' => 'client'], 'https://login.example.com', 'client');
    }

    public function test_without_local_login_only_emergency_addresses_can_log_in_with_a_password(): void
    {
        config(['login.local.enabled' => false, 'login.local.emergency_emails' => ['admin@example.com']]);
        User::factory()->create(['email' => 'user@example.com', 'password' => Hash::make('secret-password')]);
        $admin = User::factory()->create(['email' => 'admin@example.com', 'password' => Hash::make('secret-password')]);

        $this->post(route('login.store'), ['email' => 'user@example.com', 'password' => 'secret-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => 'admin@example.com', 'password' => 'secret-password']);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_without_local_login_passkey_logins_are_off(): void
    {
        config(['login.local.enabled' => false]);

        $this->get(route('passkey.login-options'))->assertNotFound();
    }

    public function test_the_login_page_shows_the_button_and_hides_the_form_without_local_login(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Pocket ID')->assertSee('name="password"', false);

        config(['login.local.enabled' => false]);

        $this->get(route('login'))->assertOk()->assertSee(route('oidc.redirect'), false)->assertDontSee('name="password"', false);
        $this->get(route('login', ['local' => 1]))->assertSee('name="password"', false);
    }

    public function test_a_user_without_password_sees_how_to_set_one(): void
    {
        $user = User::factory()->create(['oidc_sub' => 'sub-123', 'password' => null]);

        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertDontSee('current_password', false);
    }
}
