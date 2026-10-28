<?php

namespace App\Http\Controllers;

use App\Models\ShoppingList;
use App\Models\User;
use App\Notifications\ShoppingListShared;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sharing needs a connection: the server has to look up or invite users.
 */
class MemberController extends Controller
{
    public function store(Request $request, ShoppingList $list): Response
    {
        abort_unless($list->isOwnedBy($request->user()), 403);

        $email = Str::lower($request->validate([
            'email' => ['required', 'email', 'max:255'],
        ])['email']);

        [$member, $invited] = DB::transaction(function () use ($list, $email) {
            $member = User::firstWhere('email', $email);
            $invited = $member === null;

            // Unknown users get an account and set their password via the invitation link.
            $member ??= User::create([
                'email' => $email,
                'password' => Str::random(64),
                'locale' => $list->owner->locale,
            ]);
            $list->addMember($member);

            return [$member, $invited];
        });

        if (! $member->is($request->user())) {
            $member->notify(new ShoppingListShared($request->user(), $list, $invited));
        }

        return response()->noContent();
    }

    /**
     * The owner removes a member, or a member leaves the list.
     */
    public function destroy(Request $request, ShoppingList $list, string $email): Response
    {
        $member = User::where('email', Str::lower($email))->firstOrFail();

        abort_unless($list->isOwnedBy($request->user()) || $member->is($request->user()), 403);
        abort_if($list->isOwnedBy($member), 422, 'The owner cannot be removed.');
        abort_unless($list->hasMember($member), 404);

        DB::transaction(fn () => $list->removeMember($member));

        return response()->noContent();
    }
}
