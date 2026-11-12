<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Categories of the standard products (database/data/standard-products.csv, see SyncStandardProducts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('shoppinglist:standard-products');
    }

    public function down(): void
    {
        // The categories stay, the column goes with the previous migration.
    }
};
