<?php

namespace Tests\Feature;

use App\Models\ShoppingList;
use App\Models\User;
use App\Notifications\ShoppingListShared;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use RefreshDatabase;

    private function listOf(User $owner): ShoppingList
    {
        $list = new ShoppingList(['name' => 'Coop']);
        $list->owner()->associate($owner)->save();
        $list->addMember($owner);

        return $list;
    }

    public function test_owners_share_lists_with_existing_users(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $list = $this->listOf($owner);

        $this->actingAs($owner)->postJson("/lists/{$list->id}/members", ['email' => strtoupper($member->email)])
            ->assertNoContent();

        $this->assertTrue($list->hasMember($member));
        Notification::assertSentTo($member, ShoppingListShared::class);
    }

    public function test_unknown_users_are_invited(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $list = $this->listOf($owner);

        $this->actingAs($owner)->postJson("/lists/{$list->id}/members", ['email' => 'neu@example.com'])
            ->assertNoContent();

        $invited = User::firstWhere('email', 'neu@example.com');
        $this->assertNull($invited->email_verified_at);
        $this->assertTrue($list->hasMember($invited));
        Notification::assertSentTo($invited, ShoppingListShared::class, function ($notification) use ($invited) {
            return str_contains($notification->toMail($invited)->actionUrl, '/reset-password/');
        });
    }

    public function test_only_owners_share_lists(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $list = $this->listOf($owner);
        $list->addMember($member);

        $this->actingAs($member)->postJson("/lists/{$list->id}/members", ['email' => 'x@example.com'])
            ->assertForbidden();
    }

    public function test_owners_remove_members_and_members_leave(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $other = User::factory()->create();
        $list = $this->listOf($owner);
        $list->addMember($member);
        $list->addMember($other);

        $this->actingAs($member)->deleteJson("/lists/{$list->id}/members/{$other->email}")->assertForbidden();
        $this->actingAs($member)->deleteJson("/lists/{$list->id}/members/{$owner->email}")->assertForbidden();

        $this->actingAs($owner)->deleteJson("/lists/{$list->id}/members/{$other->email}")->assertNoContent();
        $this->actingAs($member)->deleteJson("/lists/{$list->id}/members/{$member->email}")->assertNoContent();
        $this->actingAs($owner)->deleteJson("/lists/{$list->id}/members/{$owner->email}")->assertStatus(422);

        $this->assertFalse($list->hasMember($member));
        $this->assertFalse($list->hasMember($other));
        $this->assertTrue($list->hasMember($owner));
    }

    public function test_reset_password_verifies_invited_users(): void
    {
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
