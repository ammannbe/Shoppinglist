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

    public function test_invalid_values_and_guests_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/preferences', ['row_actions' => 'shake'])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->putJson('/preferences', ['row_actions' => 'swipe'])->assertUnauthorized();
    }
}
