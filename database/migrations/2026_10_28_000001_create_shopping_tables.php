<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All synced tables use client generated UUIDs, millisecond timestamps
 * (sync checkpoints) and a version counter (conflict detection).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);
        });

        Schema::create('shopping_list_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);

            $table->unique(['shopping_list_id', 'user_id']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->decimal('amount', 10, 3)->nullable();
            $table->string('unit')->nullable();
            $table->boolean('done')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);

            $table->index(['shopping_list_id', 'updated_at']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);

            $table->index(['owner_id', 'updated_at']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
        Schema::dropIfExists('products');
        Schema::dropIfExists('items');
        Schema::dropIfExists('shopping_list_user');
        Schema::dropIfExists('shopping_lists');
    }
};
