<?php

namespace Tests\Feature;

use App\Auth\OidcNudge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Banner "Link with …" (AUTH_OIDC_NUDGE, App\Auth\OidcNudge, resources/js/oidc-nudge.js). Same file as in Chorelist.
 */
class OidcNudgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'login.oidc.enabled' => true,
            'login.oidc.label' => 'Pocket ID',
            'login.oidc.nudge' => true,
            'login.oidc.nudge_days' => null,
        ]);
    }

    public function test_it_is_off_when_disabled(): void
    {
        config(['login.oidc.nudge' => false]);

        $this->actingAs(User::factory()->create())->getJson('/me')->assertJsonPath('account.oidc.nudge', null);
    }

    public function test_unlinked_users_get_the_banner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/me')
            ->assertJsonPath('account.oidc.nudge', ['days' => null, 'dismissed_at' => null])
            ->assertJsonPath('account.oidc.provider', 'Pocket ID');
        $this->assertTrue(OidcNudge::shows($user));
    }

    public function test_linked_users_and_a_disabled_oidc_login_get_none(): void
    {
        $linked = User::factory()->linked('sub-nudge')->create();
        $this->actingAs($linked)->getJson('/me')->assertJsonPath('account.oidc.nudge', null);
        $this->assertFalse(OidcNudge::shows($linked));

        config(['login.oidc.enabled' => false]);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/me')->assertJsonPath('account.oidc', null);
        $this->assertFalse(OidcNudge::shows($user));
    }

    public function test_later_hides_it_for_good_without_days(): void
    {
        $user = User::factory()->create();
        $this->freezeSecond();

        $this->actingAs($user)->postJson(route('oidc.nudge.dismiss'))->assertOk()
            ->assertJsonPath('nudge.dismissed_at', now()->getTimestampMs());

        $this->assertNotNull($user->refresh()->oidc_nudge_dismissed_at);
        $this->assertFalse(OidcNudge::shows($user, now()->addYears(5)));
    }

    public function test_it_comes_back_after_the_days(): void
    {
        config(['login.oidc.nudge_days' => 30]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('oidc.nudge.dismiss'))->assertOk();
        $this->getJson('/me')->assertJsonPath('account.oidc.nudge.days', 30);

        $user->refresh();
        $this->assertFalse(OidcNudge::shows($user, now()->addDays(29)));
        $this->assertTrue(OidcNudge::shows($user, now()->addDays(30)->addMinute()));
    }

    public function test_zero_days_means_once(): void
    {
        config(['login.oidc.nudge_days' => 0]);

        $this->assertNull(OidcNudge::days());
    }

    public function test_dismissing_needs_a_login(): void
    {
        $this->postJson(route('oidc.nudge.dismiss'))->assertUnauthorized();
    }

    public function test_the_app_shell_contains_the_banner_without_user_data(): void
    {
        $this->get('/')->assertOk()->assertSee('x-data="oidcNudge"', false)->assertSee('Link now', false);
    }
}
