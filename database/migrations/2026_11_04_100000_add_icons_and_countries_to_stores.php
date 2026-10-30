<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shop icons (standard shops: a file in resources/images/shops, own shops: a small image as
 * data URL) and the countries of the standard shops; more standard shops (SyncStandardStores).
 * Existing users are in Switzerland, new ones get their country from the device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->mediumText('icon')->nullable()->after('code');
            $table->json('countries')->nullable()->after('icon');
        });

        DB::table('users')->orderBy('id')->each(function (object $user) {
            $preferences = json_decode($user->preferences ?? '{}', true) ?: [];
            DB::table('users')->where('id', $user->id)->update(['preferences' => json_encode(['country' => 'CH', ...$preferences])]);
        });

        Artisan::call('shoppinglist:standard-stores');
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['icon', 'countries']);
        });
    }
};
