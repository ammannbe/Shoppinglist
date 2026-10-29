<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Short code of the shop, e.g. M (Migros), A (Aldi), L (Lidl).
            $table->string('store', 10)->nullable()->after('unit');
            // Free text, e.g. the offer the item was added for.
            $table->string('note')->nullable()->after('store');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['store', 'note']);
        });
    }
};
