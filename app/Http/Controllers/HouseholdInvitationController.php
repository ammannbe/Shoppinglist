<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Notifications\HouseholdInvited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Invitations by email. The link only works for the invited address: the invitee logs in
 * (or registers) with it and accepts, which also proves the address.
 */
class HouseholdInvitationController extends Controller
{
    public function store(Request $request, Household $household): RedirectResponse
    {
        Gate::authorize('manageMembers', $household);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(Household::ROLES)],
        ]);
        $email = Str::lower($validated['email']);
        $user = User::firstWhere('email', $email);

        if ($user !== null && $household->hasMember($user)) {
            throw ValidationException::withMessages(['email' => __(':email is already a member.', ['email' => $email])]);
        }

        [, $token] = DB::transaction(fn () => HouseholdInvitation::issue($household, $request->user(), $email, $validated['role']));
        $notification = new HouseholdInvited($request->user(), $household, $token);

        if ($user !== null) {
            $user->notify($notification);
        } else {
            Notification::route('mail', $email)->notify($notification->locale($request->user()->locale ?? app()->getLocale()));
        }

        return $this->toHousehold($household, __('Invitation sent to :email.', ['email' => $email]));
    }

    public function destroy(Household $household, HouseholdInvitation $invitation): RedirectResponse
    {
        Gate::authorize('manageMembers', $household);
        $invitation->delete();

        return $this->toHousehold($household, __('Invitation withdrawn.'));
    }

    /**
     * Landing page of the link in the mail (also for guests, who log in or register first).
     */
    public function show(Request $request, string $token): View
    {
        $invitation = HouseholdInvitation::findByToken($token);

        if ($request->user() === null && $invitation?->isPending()) {
            // Back here after logging in or registering.
            $request->session()->put('url.intended', $request->url());
        }

        return view('households.invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'state' => $this->state($request->user(), $invitation),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();
        $invitation = HouseholdInvitation::findByToken($token);
        abort_unless($this->state($user, $invitation) === 'acceptable', 403);

        return $this->join($user, $invitation);
    }

    /**
     * Accepting an open invitation on the households page (needs the verified invited address).
     */
    public function acceptOpen(Request $request, HouseholdInvitation $invitation): RedirectResponse
    {
        abort_unless(Household::managedLocally(), 403);
        $invitation = HouseholdInvitation::pending()->find($invitation->id);
        if ($invitation === null) {
            return redirect()->route('households.index')->withErrors(['household' => __('This invitation is invalid or has expired.')]);
        }
        if (! $invitation->isFor($request->user())) {
            return redirect()->route('households.index')->withErrors(['household' => __('This invitation is for :email. Log out and log in with this address to accept it.', ['email' => $invitation->email])]);
        }

        return $this->join($request->user(), $invitation);
    }

    public function decline(Request $request, HouseholdInvitation $invitation): RedirectResponse
    {
        abort_unless(Household::managedLocally(), 403);
        abort_unless($invitation->isFor($request->user()), 404);
        $invitation->delete();

        return redirect()->route('households.index')->with('status', __('Invitation declined.'));
    }

    private function join(User $user, HouseholdInvitation $invitation): RedirectResponse
    {
        DB::transaction(function () use ($user, $invitation) {
            $invitation->household->addMember($user, $invitation->role);
            $invitation->forceFill(['accepted_at' => now()])->save();

            // The link came by mail to this address (on the households page it is verified already).
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
        });

        return $this->toHousehold($invitation->household, __('Welcome to the household.'));
    }

    /**
     * invalid | read_only | expired | accepted | member | guest | other_account | acceptable
     */
    private function state(?User $user, ?HouseholdInvitation $invitation): string
    {
        return match (true) {
            $invitation === null => 'invalid',
            ! Household::managedLocally() => 'read_only',
            $user !== null && $invitation->household->hasMember($user) => 'member',
            $invitation->accepted_at !== null => 'accepted',
            ! $invitation->isPending() => 'expired',
            $user === null => 'guest',
            Str::lower($user->email) !== $invitation->email => 'other_account',
            default => 'acceptable',
        };
    }
}
