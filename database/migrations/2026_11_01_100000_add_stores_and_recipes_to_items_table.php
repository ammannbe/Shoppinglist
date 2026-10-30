<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Items get several shops (labels: the code, or the name of a shop without code) and the
 * recipes they were planned for ({name, url}). Codes of shops become optional and keep
 * their case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->json('stores')->nullable()->after('unit');
            $table->json('recipes')->nullable()->after('note');
        });

        // Not touching updated_at: the clients convert their copies themselves (RxDB migration).
        DB::table('items')->whereNotNull('store')->orderBy('id')->select(['id', 'store'])
            ->chunk(500, fn ($items) => $items->each(fn ($item) => DB::table('items')->where('id', $item->id)
                ->update(['stores' => json_encode([$item->store])])));

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('store');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('store', 10)->nullable()->after('unit');
        });

        DB::table('items')->whereNotNull('stores')->orderBy('id')->select(['id', 'stores'])
            ->chunk(500, fn ($items) => $items->each(fn ($item) => DB::table('items')->where('id', $item->id)
                ->update(['store' => mb_substr(json_decode($item->stores, true)[0] ?? '', 0, 10) ?: null])));

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['stores', 'recipes']);
        });
    }
};
