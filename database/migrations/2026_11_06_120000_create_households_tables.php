<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Households (local or mirrored from the AccountManager) and the lists shared with them.
 * Access is resolved through the membership; shopping_list_user keeps one sync row per user
 * with access, `direct` tells shared by email (or the owner) from access through a household.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('account_manager_household_uuid')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('household_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['admin', 'member'])->default('member');
            $table->timestamp('joined_at')->nullable();

            $table->unique(['household_id', 'user_id']);
        });

        Schema::create('household_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->enum('role', ['admin', 'member'])->default('member');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'email']);
        });

        Schema::create('shopping_list_household', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shopping_list_id', 'household_id']);
        });

        Schema::table('shopping_list_user', function (Blueprint $table) {
            $table->boolean('direct')->default(true)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_list_user', function (Blueprint $table) {
            $table->dropColumn('direct');
        });

        Schema::dropIfExists('shopping_list_household');
        Schema::dropIfExists('household_invitations');
        Schema::dropIfExists('household_user');
        Schema::dropIfExists('households');
    }
};
