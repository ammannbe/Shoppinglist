<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Standard products from database/data/standard-products.csv (see SyncStandardProducts).
 * When the list changes, add a new migration that runs the command again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('shoppinglist:standard-products');
    }

    public function down(): void
    {
        // Removed own duplicates stay removed; the standard products are left too.
    }
};
