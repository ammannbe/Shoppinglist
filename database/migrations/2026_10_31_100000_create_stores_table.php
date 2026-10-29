<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Shops for the items: full name and short code (items.store keeps the code). Like units:
 * own shops per user, the shops without owner are public standard shops and read-only.
 */
return new class extends Migration
{
    /** Standard shops, the codes the week planner assigns (App\Ai\Assistant::SHOPS). */
    private const STANDARD = ['Migros' => 'M', 'Coop' => 'C', 'Aldi' => 'A', 'Lidl' => 'L', 'Denner' => 'D'];

    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 10);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(precision: 3);
            $table->softDeletes(precision: 3);

            $table->index(['owner_id', 'updated_at']);
        });

        $now = now()->format('Y-m-d H:i:s.v');
        DB::table('stores')->insert(collect(self::STANDARD)->map(fn (string $code, string $name) => [
            'id' => (string) Str::uuid7(), 'name' => $name, 'code' => $code, 'created_at' => $now, 'updated_at' => $now,
        ])->values()->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
