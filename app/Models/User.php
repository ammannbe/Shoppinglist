<?php

namespace App\Models;

use App\Casts\EncryptedArrayOrNull;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token', 'recipes_connection'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'recipes_connection' => EncryptedArrayOrNull::class,
        ];
    }

    /**
     * Mails (verification, password reset, invitations) use the user's language.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class)->withPivot('role', 'joined_at');
    }

    /**
     * IDs of all non-deleted lists the user can access: shared by email (or own), or shared
     * with a household the user is a member of. Resolved at runtime, so it does not depend on
     * the sync rows of household members.
     */
    public function accessibleListIds(): Builder
    {
        return ShoppingList::query()
            ->select('shopping_lists.id')
            ->where(function (Builder $query) {
                $query->whereExists(fn ($sub) => $sub->from('shopping_list_user')
                    ->whereColumn('shopping_list_user.shopping_list_id', 'shopping_lists.id')
                    ->where('shopping_list_user.user_id', $this->id)
                    ->where('shopping_list_user.direct', true)
                    ->whereNull('shopping_list_user.deleted_at'))
                    ->orWhereExists(fn ($sub) => $sub->from('shopping_list_household')
                        ->join('household_user', 'household_user.household_id', '=', 'shopping_list_household.household_id')
                        ->whereColumn('shopping_list_household.shopping_list_id', 'shopping_lists.id')
                        ->where('household_user.user_id', $this->id));
            });
    }
}
