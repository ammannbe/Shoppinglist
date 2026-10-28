<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only unit suggestions, managed on the server.
 */
#[Fillable(['name'])]
class Unit extends Model
{
    use Syncable;
}
