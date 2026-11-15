<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Withdrawn announcements (in "deleted" or missing in a full sync) are hidden, not deleted: when they come back
     * they are shown again but never sent twice (same model as RecipeManager and Chorelist).
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('remote_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('withdrawn_at');
        });
    }
};
