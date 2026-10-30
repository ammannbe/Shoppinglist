<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offers of the shops, read by the AI or entered by hand, for the week planner. Shared ones
 * are visible to all users; expired ones are removed daily (offers:prune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Shop as shown on items: the code of a standard shop or a name.
            $table->string('store')->nullable();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('regular_price', 10, 2)->nullable();
            $table->string('discount', 100)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('shared')->default(true);
            $table->timestamps();

            $table->index(['shared', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
