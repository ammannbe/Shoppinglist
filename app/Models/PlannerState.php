<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * State of the week planner of a user (PlannerStateController), `updated_at` in milliseconds.
 */
#[Fillable(['user_id', 'state', 'updated_at'])]
class PlannerState extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected function casts(): array
    {
        return ['state' => 'array', 'updated_at' => 'integer'];
    }
}
