<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard database-notifications table (the one
 * `php artisan notifications:table` would generate), written by hand since
 * that stub is not present in this app. Backs `Illuminate\Notifications\
 * Notifiable::notifications()`/`unreadNotifications()` used by
 * `App\Models\User` and the `/api/v1/notifications` endpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
