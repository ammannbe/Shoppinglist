<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saving a suggestion now plans it: `planned` (planned, not on a list yet; the list step builds
 * the shopping list from all planned recipes) and `planned_at` (when it was planned, the date of
 * the offers in `uses_offers`). Existing entries are not planned; their date is the last save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_recipes', function (Blueprint $table) {
            $table->boolean('planned')->default(false)->after('draft_url');
            $table->timestamp('planned_at')->nullable()->after('planned');
            $table->index(['user_id', 'planned']);
        });

        DB::table('planned_recipes')->update(['planned_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('planned_recipes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'planned']);
            $table->dropColumn(['planned', 'planned_at']);
        });
    }
};
