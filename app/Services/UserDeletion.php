<?php

namespace App\Services;

use App\Http\Controllers\ImageController;
use App\Models\Household;
use App\Models\Item;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a user for good (webhook user.deleted of the AccountManager, docs/ENTITLEMENTS-API.md 5.2):
 *
 * - own lists without other members are deleted; shared ones go to another member (shared by email
 *   first, then through a household, the longest first), so the others keep them;
 * - memberships in other users' lists end like leaving, households are left like removing a member
 *   (local households: an admin-less one goes to its longest member, an empty one is deleted;
 *   mirrored ones: the AccountManager decides);
 * - products, shops, offers, planned recipes, planner state, passkeys and sessions go with the user
 *   (FK cascades), photos of own products unless items of other lists still show them.
 *
 * Idempotent: an already deleted user is not found again.
 */
class UserDeletion
{
    public function delete(User $user): void
    {
        $images = Product::withTrashed()->where('owner_id', $user->id)->whereNotNull('image')->pluck('image')->all();

        DB::transaction(function () use ($user): void {
            ShoppingList::where('owner_id', $user->id)->get()->each(fn (ShoppingList $list) => $this->handOverOrDelete($list, $user));

            $user->memberships()->with('shoppingList')->get()
                ->each(fn (Membership $membership) => $membership->shoppingList?->removeMember($user));

            $user->households()->get()->each(fn (Household $household) => $this->leave($household, $user));

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();
            }

            $user->delete();
        });

        foreach ($images as $image) {
            if (! Product::withTrashed()->where('image', $image)->exists() && ! Item::withTrashed()->where('image', $image)->exists()) {
                Storage::delete([ImageController::DIRECTORY.'/'.$image, ImageController::DIRECTORY.'/'.$image.'-thumb']);
            }
        }
    }

    private function handOverOrDelete(ShoppingList $list, User $user): void
    {
        $next = Membership::where('shopping_list_id', $list->id)
            ->where('user_id', '!=', $user->id)
            ->orderByDesc('direct')
            ->orderBy('created_at')
            ->first();

        if ($next === null) {
            $list->delete();
            $list->touchForMembers();

            return;
        }

        $list->owner_id = $next->user_id;
        $list->version++;
        $list->save();

        // The new owner keeps the list even if a household share ends later.
        $next->direct = true;
        $next->save();

        $list->memberships()->where('user_id', $user->id)->delete();
        $list->touchForMembers();
    }

    private function leave(Household $household, User $user): void
    {
        $lastAdmin = $household->isLastAdmin($user);
        $household->removeMember($user);

        if ($household->isMirrored()) {
            return;
        }

        if (! $household->members()->exists()) {
            $household->deleteWithShares();
        } elseif ($lastAdmin) {
            $next = $household->members()->orderByPivot('joined_at')->first();
            $household->changeRole($next, 'admin');
        }
    }
}
