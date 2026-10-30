<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_returned_until_changed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/me')->assertJsonPath('preferences.row_actions', 'swipe');

        $this->actingAs($user)->putJson('/preferences', ['row_actions' => 'longpress'])->assertNoContent();

        $this->actingAs($user->fresh())->getJson('/me')->assertJsonPath('preferences.row_actions', 'longpress');
    }

    public function test_standard_shops_can_be_hidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/me')->assertJsonPath('preferences.hidden_stores', []);
        $this->actingAs($user)->putJson('/preferences', ['hidden_stores' => ['D', 'L']])->assertNoContent();
        $this->actingAs($user->fresh())->getJson('/me')->assertJsonPath('preferences.hidden_stores', ['D', 'L'])
            ->assertJsonPath('preferences.row_actions', 'swipe');

        $this->actingAs($user)->putJson('/preferences', ['hidden_stores' => []])->assertNoContent();
        $this->actingAs($user->fresh())->getJson('/me')->assertJsonPath('preferences.hidden_stores', []);

        $this->actingAs($user)->putJson('/preferences', ['hidden_stores' => 'D'])->assertUnprocessable();
    }

    public function test_the_country_of_the_shops_is_stored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/me')->assertJsonPath('preferences.country', null);
        $this->actingAs($user)->putJson('/preferences', ['country' => 'DE', 'hidden_stores' => ['Volg']])->assertNoContent();
        $this->actingAs($user->fresh())->getJson('/me')->assertJsonPath('preferences.country', 'DE')
            ->assertJsonPath('preferences.hidden_stores', ['Volg']);

        $this->actingAs($user)->putJson('/preferences', ['country' => 'FR'])->assertUnprocessable();
    }

    public function test_the_region_of_shops_with_regional_offers_is_stored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/me')->assertJsonPath('preferences.regions', [])
            ->assertJsonPath('regions.M.migros-aare', 'Migros Aare (AG, BE, SO)');
        $this->actingAs($user)->putJson('/preferences', ['regions' => ['M' => 'migros-aare']])->assertNoContent();
        $this->actingAs($user->fresh())->getJson('/me')->assertJsonPath('preferences.regions', ['M' => 'migros-aare']);

        $this->actingAs($user)->putJson('/preferences', ['regions' => ['M' => null]])->assertNoContent();
        $this->actingAs($user->fresh())->getJson('/me')->assertJsonPath('preferences.regions', []);

        $this->actingAs($user)->putJson('/preferences', ['regions' => ['M' => 'migros-bern']])->assertUnprocessable();
        $this->actingAs($user)->putJson('/preferences', ['regions' => ['C' => 'migros-aare']])->assertUnprocessable();
    }

    public function test_invalid_values_and_guests_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/preferences', ['row_actions' => 'shake'])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->putJson('/preferences', ['row_actions' => 'swipe'])->assertUnauthorized();
    }
}
