<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
            'recipes_connection' => 'encrypted:array',
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

    /**
     * IDs of all non-deleted lists the user is currently a member of.
     */
    public function accessibleListIds(): Builder
    {
        return ShoppingList::query()
            ->select('shopping_lists.id')
            ->join('shopping_list_user', 'shopping_list_user.shopping_list_id', '=', 'shopping_lists.id')
            ->where('shopping_list_user.user_id', $this->id)
            ->whereNull('shopping_list_user.deleted_at');
    }
}
