<?php

namespace App\Http\Controllers;

use App\Models\Household;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Server rendered household pages (need a connection, like the account page).
 */
class HouseholdController extends Controller
{
    public function index(Request $request): View
    {
        return view('households.index', [
            'households' => $request->user()->households()->withCount('members')->orderBy('name')->get(),
            'accountManagerUrl' => self::accountManagerUrl(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Household::class);
        $name = $request->validate(['name' => ['required', 'string', 'max:255']])['name'];

        $household = DB::transaction(function () use ($request, $name) {
            $household = new Household(['name' => $name]);
            $household->creator()->associate($request->user());
            $household->save();
            $household->addMember($request->user(), 'admin');

            return $household;
        });

        return redirect()->route('households.show', $household);
    }

    public function show(Request $request, Household $household): View
    {
        Gate::authorize('view', $household);

        return view('households.show', [
            'household' => $household,
            'role' => $household->roleOf($request->user()),
            'members' => $household->members()->orderBy('email')->get(),
            'invitations' => $household->invitations()->pending()->orderBy('email')->get(),
            'lists' => $household->shoppingLists()->with('owner')->orderBy('name')->get(),
            'pendingMembers' => Household::managedLocally() ? 0 : $household->pendingMembers()->count(),
            'accountManagerUrl' => self::accountManagerUrl(),
        ]);
    }

    /**
     * HOUSEHOLDS_DRIVER=account-manager: the households are managed in the AccountManager.
     */
    private static function accountManagerUrl(): ?string
    {
        return ! Household::managedLocally() && filled(config('households.account_manager.url'))
            ? rtrim((string) config('households.account_manager.url'), '/').'/households'
            : null;
    }

    public function update(Request $request, Household $household): RedirectResponse
    {
        Gate::authorize('update', $household);
        $household->update($request->validate(['name' => ['required', 'string', 'max:255']]));
        DB::transaction(fn () => $household->touchLists());

        return back()->with('status', __('Saved.'));
    }

    public function destroy(Household $household): RedirectResponse
    {
        Gate::authorize('delete', $household);
        DB::transaction(fn () => $household->deleteWithShares());

        return redirect()->route('households.index')->with('status', __('Household deleted.'));
    }
}
