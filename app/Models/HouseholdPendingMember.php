<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Member of a household mirrored from the AccountManager who has no user here yet (only their
 * oidc_sub is known). Becomes a household member on the first OIDC login with that subject.
 */
#[Fillable(['household_id', 'oidc_sub', 'role'])]
class HouseholdPendingMember extends Model
{
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
