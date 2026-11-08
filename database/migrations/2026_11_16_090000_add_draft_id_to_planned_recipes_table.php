<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft of the recipe app an AI idea was handed to: asked for until it is imported (the entry
 * then becomes the own recipe, App\Recipes\RecipeDrafts) or expired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_recipes', function (Blueprint $table) {
            $table->uuid('draft_id')->nullable()->after('draft_url');
            $table->timestamp('draft_expires_at')->nullable()->after('draft_id');
        });
    }

    public function down(): void
    {
        Schema::table('planned_recipes', function (Blueprint $table) {
            $table->dropColumn(['draft_id', 'draft_expires_at']);
        });
    }
};
