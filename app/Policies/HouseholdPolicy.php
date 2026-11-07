<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

/**
 * Admins invite and remove members, rename and delete the household; members can leave.
 * With HOUSEHOLDS_DRIVER=account-manager the households come from the AccountManager and are read only here.
 */
class HouseholdPolicy
{
    public function view(User $user, Household $household): bool
    {
        return $household->hasMember($user);
    }

    public function create(User $user): bool
    {
        return Household::managedLocally();
    }

    public function update(User $user, Household $household): bool
    {
        return Household::managedLocally() && $household->isAdmin($user);
    }

    public function delete(User $user, Household $household): bool
    {
        return $this->update($user, $household);
    }

    /**
     * Invite, change roles and remove other members.
     */
    public function manageMembers(User $user, Household $household): bool
    {
        return $this->update($user, $household);
    }

    public function leave(User $user, Household $household): bool
    {
        return Household::managedLocally() && $household->hasMember($user);
    }
}
