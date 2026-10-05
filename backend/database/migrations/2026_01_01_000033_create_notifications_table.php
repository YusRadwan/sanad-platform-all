<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `notifications` from backend/database/migrations/0006, with the
 * `link` / `broadcast_id` columns, their FK and the two indexes from 0014
 * folded in.
 *
 * A per-user inbox row. Ordinary notifications keep broadcast_id NULL and are
 * never gated; a row delivered from a broadcast links back to it so that
 *   (a) enable/disable and the start/end window can gate visibility
 *       server-side at read time with one LEFT JOIN,
 *   (b) editing a broadcast can re-resolve its recipients, and
 *   (c) deleting a broadcast removes its delivered rows via ON DELETE CASCADE.
 *
 * `link` exists because the broadcast form has an optional "رابط توجيهي إضافي"
 * field and the old notification presenter was already emitting a hardcoded
 * `link => null` to satisfy the frontend Notification interface. The column
 * gives that key a real value instead of a permanent null — existing consumers
 * see no shape change, only null -> a real URL where one was supplied.
 *
 * NOTE ON THE NAME: this is the application's own table and has nothing to do
 * with Laravel's built-in `notifications` table (the one the Notification
 * facade + DatabaseChannel would create). That feature is NOT enabled here and
 * must not be, or the two would collide — the column shapes are incompatible
 * (Laravel's uses a morph target and a `data` JSON blob).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->string('title', 190);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->char('broadcast_id', 36)->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->useCurrent();

            // "my rows, newest first" is the only inbox query; the join back to
            // the broadcast is by primary key, so only these two need indexing.
            $table->index(['user_id', 'created_at'], 'idx_notifications_user_created');
            $table->index('broadcast_id', 'idx_notifications_broadcast');

            $table->foreign('user_id', 'fk_notifications_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->foreign('broadcast_id', 'fk_notifications_broadcast')
                ->references('id')->on('broadcast_notifications')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
