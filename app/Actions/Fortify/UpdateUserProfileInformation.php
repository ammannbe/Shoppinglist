<?php

namespace App\Actions\Fortify;

use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * "Change e-mail" in the settings. The name is not editable (local accounts keep the one of the
 * registration, linked accounts get it from the provider on every login, App\Auth\OidcLogin).
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        // A linked account keeps the address it logs in with (the settings offer no form, Fortify's route stays); unlinking first.
        if ($user->isLinkedToOidc()) {
            $input['email'] = $user->email;
        }

        $changed = mb_strtolower(trim((string) ($input['email'] ?? ''))) !== mb_strtolower($user->email);

        Validator::make($input, [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            // The address receives the password reset links: changing it needs the password.
            'current_password' => $changed ? ['required', 'string', 'current_password:web'] : [],
        ])->validateWithBag('updateProfileInformation');

        if (! $changed) {
            return;
        }

        if ($user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill(['email' => $input['email']])->save();
        }

        // The owner's address is part of the list documents of the members.
        ShoppingList::whereIn('id', $user->accessibleListIds())->get()->each->touchForMembers();
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
