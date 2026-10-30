<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recipes the week planner suggested (history per user): to plan or cook them again later and
 * to import AI ideas into the recipe app afterwards. Server only, like the offers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Suggestion key: "recipe-<id>" (own recipe, one entry) or "idea-<uuid>" (AI idea).
            $table->string('key', 100);
            $table->string('source', 10);
            $table->unsignedBigInteger('recipe_id')->nullable();
            $table->string('name');
            $table->string('url', 2048)->nullable();
            $table->unsignedSmallInteger('servings')->nullable();
            // reason, uses_offers, uses_pantry and for AI ideas ingredients, instructions, category, complexity.
            $table->json('data');
            // Import wizard of the recipe app the AI idea was handed to.
            $table->string('draft_url', 2048)->nullable();
            // Last time it went into a shopping list.
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_recipes');
    }
};
