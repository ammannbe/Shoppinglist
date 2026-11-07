<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Invitation by email. Only the hash of the token is stored, the link in the mail carries the token.
 */
#[Fillable(['email', 'role', 'expires_at'])]
class HouseholdInvitation extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Create an invitation (replacing an open one for the same address) and return the plain token.
     *
     * @return array{0: HouseholdInvitation, 1: string}
     */
    public static function issue(Household $household, User $inviter, string $email, string $role): array
    {
        $household->invitations()->where('email', $email)->whereNull('accepted_at')->delete();

        $token = Str::random(48);
        $invitation = new self([
            'email' => $email,
            'role' => $role,
            'expires_at' => now()->addDays(config('households.invitation_days')),
        ]);
        $invitation->token_hash = self::hash($token);
        $invitation->household()->associate($household);
        $invitation->inviter()->associate($inviter);
        $invitation->save();

        return [$invitation, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return self::where('token_hash', self::hash($token))->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
