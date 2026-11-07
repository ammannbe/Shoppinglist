<?php

namespace App\Http\Controllers;

use App\Households\AccountManagerHouseholds;
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
    /**
     * All households of the user with their details on one page (each one collapsible).
     */
    public function index(Request $request): View
    {
        return view('households.index', [
            'households' => $request->user()->households()
                ->with([
                    'members' => fn ($query) => $query->orderBy('email'),
                    'invitations' => fn ($query) => $query->pending()->orderBy('email'),
                    'shoppingLists' => fn ($query) => $query->with('owner')->orderBy('name'),
                ])
                ->withCount('pendingMembers')
                ->orderBy('name')
                ->get(),
            // Opened household: after an action, or a form that failed validation.
            'open' => $request->session()->get('household', $request->old('_household')),
            'accountManagerUrl' => AccountManagerHouseholds::portalUrl(),
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

        return $this->toHousehold($household);
    }

    /**
     * Former detail page (links in mails and bookmarks): the households page with this household opened.
     */
    public function show(Household $household): RedirectResponse
    {
        Gate::authorize('view', $household);

        return $this->toHousehold($household);
    }

    public function update(Request $request, Household $household): RedirectResponse
    {
        Gate::authorize('update', $household);
        $household->update($request->validate(['name' => ['required', 'string', 'max:255']]));
        DB::transaction(fn () => $household->touchLists());

        return $this->toHousehold($household, __('Saved.'));
    }

    public function destroy(Household $household): RedirectResponse
    {
        Gate::authorize('delete', $household);
        DB::transaction(fn () => $household->deleteWithShares());

        return redirect()->route('households.index')->with('status', __('Household deleted.'));
    }
}
