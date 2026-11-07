<?php

namespace App\Http\Controllers;

use App\Jobs\ReportKontoShares;
use App\Models\Household;
use App\Models\ShoppingList;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * "Share with household": the owner shares a list with a household they belong to, all its
 * members get access (also later members). Works with both households drivers.
 */
class ListHouseholdController extends Controller
{
    public function store(Request $request, ShoppingList $list): Response
    {
        abort_unless($list->isOwnedBy($request->user()), 403);
        $uuid = $request->validate(['household' => ['required', 'uuid']])['household'];
        $household = Household::where('uuid', $uuid)->firstOrFail();
        abort_unless($household->hasMember($request->user()), 403);

        DB::transaction(fn () => $list->shareWith($household));
        ReportKontoShares::dispatchFor($household);

        return response()->noContent();
    }

    public function destroy(Request $request, ShoppingList $list, Household $household): Response
    {
        abort_unless($list->isOwnedBy($request->user()), 403);

        DB::transaction(fn () => $list->unshareWith($household));
        ReportKontoShares::dispatchFor($household);

        return response()->noContent();
    }
}
