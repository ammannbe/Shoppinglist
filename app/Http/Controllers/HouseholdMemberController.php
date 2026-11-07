<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admins change roles and remove members, members leave. At least one admin has to remain.
 */
class HouseholdMemberController extends Controller
{
    public function update(Request $request, Household $household, User $member): RedirectResponse
    {
        Gate::authorize('manageMembers', $household);
        abort_unless($household->hasMember($member), 404);
        $role = $request->validate(['role' => ['required', Rule::in(Household::ROLES)]])['role'];

        if ($role !== 'admin' && $household->isLastAdmin($member)) {
            throw ValidationException::withMessages(['role' => __('A household needs at least one admin.')]);
        }

        $household->changeRole($member, $role);

        return back()->with('status', __('Saved.'));
    }

    /**
     * Remove a member, or leave the household. Only access goes, the lists stay with their owners.
     */
    public function destroy(Request $request, Household $household, User $member): RedirectResponse
    {
        $leaving = $member->is($request->user());
        Gate::authorize($leaving ? 'leave' : 'manageMembers', $household);
        abort_unless($household->hasMember($member), 404);

        if ($household->isLastAdmin($member)) {
            throw ValidationException::withMessages(['member' => $leaving
                ? __('You are the last admin. Make another member an admin first, or delete the household.')
                : __('A household needs at least one admin.')]);
        }

        DB::transaction(fn () => $household->removeMember($member));

        return $leaving
            ? redirect()->route('households.index')->with('status', __('You left the household.'))
            : back()->with('status', __('Member removed.'));
    }
}
