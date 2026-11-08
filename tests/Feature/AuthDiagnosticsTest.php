<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Temporary diagnostics: App\Http\Middleware\LogRejectedRememberCookie and the "Remember token changed" log in User::booted().
 */
class AuthDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    public function test_a_rejected_remember_cookie_is_logged_with_the_reason(): void
    {
        $user = User::factory()->create(['remember_token' => 'current-token']);
        Log::spy();

        $this->withCredentials()->withCookie($this->recallerName(), $user->id.'|old-token|hash')
            ->getJson('/me')
            ->assertUnauthorized();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Remember cookie rejected'
                && $context['reason'] === 'token_mismatch' && $context['path'] === 'me'
        );
    }

    public function test_a_valid_remember_cookie_signs_in_without_a_log(): void
    {
        $user = User::factory()->create(['remember_token' => 'current-token']);
        Log::spy();

        $this->withCredentials()->withCookie($this->recallerName(), $user->id.'|current-token|'.$user->getAuthPassword())
            ->getJson('/me')
            ->assertOk();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_changed_remember_token_is_logged_without_the_token(): void
    {
        $user = User::factory()->create(['remember_token' => 'current-token']);
        Log::spy();

        $user->forceFill(['remember_token' => 'new-token'])->save();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Remember token changed'
                && $context['user_id'] === $user->id
                && $context['old'] === substr(hash('sha256', 'current-token'), 0, 8)
                && $context['new'] === substr(hash('sha256', 'new-token'), 0, 8)
                && ! str_contains(json_encode($context), 'new-token')
        );
    }
}
