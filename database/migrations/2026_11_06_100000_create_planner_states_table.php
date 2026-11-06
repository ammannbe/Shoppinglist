<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * State of the week planner per user (offers taken, pantry, recipes, list), so it continues on
 * another device. One JSON document, last write wins by the client's timestamp. Server only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planner_states', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->json('state');
            // Milliseconds of the client's last change (not a Laravel timestamp).
            $table->unsignedBigInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planner_states');
    }
};
