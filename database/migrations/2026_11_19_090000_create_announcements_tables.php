<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Local copy of the announcements of the AccountManager (App\Announcements\AccountManagerAnnouncements).
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_manager_uuid')->unique();
            $table->string('type', 20);
            $table->json('title');
            $table->json('body_html');
            $table->json('excerpt');
            $table->json('channels');
            $table->string('link', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->timestamp('publish_at')->index();
            $table->timestamp('expires_at')->nullable();
            // updated_at of the AccountManager (same value again: nothing to do).
            $table->timestamp('remote_updated_at');
            // Push and email are dispatched once per announcement (claimed by the first run).
            $table->timestamp('push_dispatched_at')->nullable();
            $table->timestamp('email_dispatched_at')->nullable();
            $table->timestamps();
        });

        // Read (list "News") and dismissed (banner) per user, also for users without AccountManager account.
        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id']);
        });

        // One row per announcement, user and channel (push, email): claimed before sending, so nobody gets it twice.
        Schema::create('announcement_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            // Package of the queue run (reported as batch to the AccountManager).
            $table->string('batch', 100);
            // null: claimed, not sent yet; sent or failed afterwards.
            $table->string('status', 10)->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id', 'channel']);
            $table->index(['announcement_id', 'channel', 'batch']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Announcements by email: all, maintenance (default: only maintenance notices) or none (unsubscribed).
            $table->string('announcement_emails', 20)->default('maintenance')->after('preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('announcement_emails'));
        Schema::dropIfExists('announcement_deliveries');
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
    }
};
