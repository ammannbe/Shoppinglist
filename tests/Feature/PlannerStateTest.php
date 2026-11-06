<?php

namespace Tests\Feature;

use App\Http\Controllers\PlannerStateController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlannerStateTest extends TestCase
{
    use RefreshDatabase;

    private const STATE = ['offers' => [['id' => 'a', 'name' => 'Rüebli', 'selected' => true]], 'pantry' => [['name' => 'Reis', 'amount' => null, 'selected' => true]]];

    public function test_it_needs_a_verified_account(): void
    {
        $this->getJson('/planner/state')->assertUnauthorized();
        $this->putJson('/planner/state', ['state' => self::STATE, 'updated_at' => 1])->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->getJson('/planner/state')->assertForbidden();
        $this->actingAs($unverified)->putJson('/planner/state', ['state' => self::STATE, 'updated_at' => 1])->assertForbidden();
    }

    public function test_the_state_is_stored_per_user(): void
    {
        Carbon::setTestNow('2026-11-06 12:00:00');
        $now = now()->getTimestampMs();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->getJson('/planner/state')->assertExactJson(['state' => null, 'updated_at' => 0]);

        $this->actingAs($user)->putJson('/planner/state', ['state' => self::STATE, 'updated_at' => $now - 1000])
            ->assertOk()
            ->assertJson(['state' => self::STATE, 'updated_at' => $now - 1000, 'accepted' => true]);

        $this->actingAs($user)->getJson('/planner/state')->assertExactJson(['state' => self::STATE, 'updated_at' => $now - 1000]);
        $this->actingAs($other)->getJson('/planner/state')->assertExactJson(['state' => null, 'updated_at' => 0]);
    }

    public function test_the_last_write_wins(): void
    {
        Carbon::setTestNow('2026-11-06 12:00:00');
        $now = now()->getTimestampMs();
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/planner/state', ['state' => self::STATE, 'updated_at' => $now - 1000])->assertOk();

        // An older change (another device that was offline) does not overwrite the newer one.
        $this->actingAs($user)->putJson('/planner/state', ['state' => ['offers' => []], 'updated_at' => $now - 2000])
            ->assertOk()
            ->assertJson(['state' => self::STATE, 'updated_at' => $now - 1000, 'accepted' => false]);
        // Same time: the stored one stays.
        $this->actingAs($user)->putJson('/planner/state', ['state' => ['offers' => []], 'updated_at' => $now - 1000])
            ->assertJson(['accepted' => false]);

        $this->actingAs($user)->putJson('/planner/state', ['state' => ['offers' => []], 'updated_at' => $now])
            ->assertJson(['state' => ['offers' => []], 'updated_at' => $now, 'accepted' => true]);
    }

    public function test_a_timestamp_in_the_future_is_capped(): void
    {
        Carbon::setTestNow('2026-11-06 12:00:00');
        $now = now()->getTimestampMs();
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/planner/state', ['state' => self::STATE, 'updated_at' => $now + 3_600_000])
            ->assertJson(['updated_at' => $now, 'accepted' => true]);

        Carbon::setTestNow('2026-11-06 12:00:05');
        $this->actingAs($user)->putJson('/planner/state', ['state' => [], 'updated_at' => $now + 5000])
            ->assertJson(['state' => [], 'accepted' => true]);
    }

    public function test_the_state_is_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/planner/state', ['updated_at' => 1])->assertJsonValidationErrors('state');
        $this->actingAs($user)->putJson('/planner/state', ['state' => 'offers', 'updated_at' => 1])->assertJsonValidationErrors('state');
        $this->actingAs($user)->putJson('/planner/state', ['state' => self::STATE])->assertJsonValidationErrors('updated_at');
        $this->actingAs($user)->putJson('/planner/state', ['state' => self::STATE, 'updated_at' => 'now'])->assertJsonValidationErrors('updated_at');

        $large = ['wishes' => str_repeat('x', PlannerStateController::MAX_BYTES)];
        $this->actingAs($user)->putJson('/planner/state', ['state' => $large, 'updated_at' => 1])->assertJsonValidationErrors('state');
        $this->actingAs($user)->getJson('/planner/state')->assertJson(['state' => null]);
    }
}
