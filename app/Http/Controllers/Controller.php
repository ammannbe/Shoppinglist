<?php

namespace App\Http\Controllers;

use App\Models\Household;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    /**
     * Back to the households page with this household opened (also without JavaScript: `household` in the session).
     */
    protected function toHousehold(Household $household, ?string $status = null): RedirectResponse
    {
        $redirect = redirect()->route('households.index')->withFragment('household-'.$household->uuid)->with('household', $household->uuid);

        return $status !== null ? $redirect->with('status', $status) : $redirect;
    }
}
