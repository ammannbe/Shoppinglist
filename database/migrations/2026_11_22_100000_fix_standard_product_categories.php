<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Corrected categories of standard products: ice cream and fish sticks are frozen, honey is a spread (database/data/standard-products.csv, see SyncStandardProducts).
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
