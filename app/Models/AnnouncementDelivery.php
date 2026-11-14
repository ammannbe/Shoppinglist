<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An announcement sent to a user by push or email (unique per announcement, user and channel).
 */
class AnnouncementDelivery extends Model
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $guarded = [];
}
