<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sorting (item order per list, shared; manual order of lists per member), own units
 * per user like products (units without owner stay public and read-only) and
 * product images (copied to the items, so members of shared lists see them too).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            // manual, name_asc, name_desc, newest, oldest (null: manual)
            $table->string('item_sort', 20)->nullable()->after('name');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->double('position')->nullable()->after('done');
            $table->uuid('image')->nullable()->after('unit');
        });

        Schema::table('products', function (Blueprint $table) {
            // File name (UUID) in storage/app/private/images, see ImageController.
            $table->uuid('image')->nullable()->after('name');
        });

        Schema::table('shopping_list_user', function (Blueprint $table) {
            $table->double('position')->nullable()->after('user_id');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1)->after('name');

            $table->index(['owner_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['owner_id', 'updated_at']);
            $table->dropColumn(['owner_id', 'version']);
            $table->unique('name');
        });

        Schema::table('shopping_list_user', function (Blueprint $table) {
            $table->dropColumn('position');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['position', 'image']);
        });

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropColumn('item_sort');
        });
    }
};
