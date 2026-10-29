<?php

namespace App\Sync;

use App\Models\Unit;

class UnitResource extends CatalogResource
{
    protected function model(): string
    {
        return Unit::class;
    }
}
