<?php

declare(strict_types=1);

use App\Enums\BroadcastAudience;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `broadcast_notifications` from
 * backend/database/migrations/0014_create_broadcast_notifications_table.sql.
 *
 * WHY A SEPARATE TABLE FROM `notifications`
 * ------------------------------------------------------------------
 * `notifications` is a strictly PER-USER inbox row (user_id, title, body,
 * is_read). It has no concept of an audience, a schedule, or an on/off switch.
 * Bolting those columns onto it would leave them NULL on every ordinary
 * notification and would force a read-time audience resolution into an
 * endpoint that today is a single indexed lookup.
 *
 * So the BROADCAST ITSELF (what the admin authored and manages: title, body,
 * link, audience, schedule window, enabled flag, author) lives here, and
 * DELIVERY is materialised at creation time as one ordinary `notifications`
 * row per resolved recipient. That keeps per-user read state working per
 * recipient for free, and keeps the inbox query a simple per-user lookup.
 *
 * Audience values are EXACTLY the four radio options the admin UI offers — no
 * invented targeting:
 *   all_teachers      -> every active user with role='instructor'
 *   all_staff         -> every active user with role='admin'
 *   selected_teachers -> the instructor ids checked in the picker (JSON array)
 *   specific_user     -> one user resolved from the free-text username/id box
 *
 * target_user_ids is a JSON array of users.id for 'selected_teachers', a
 * single-element array for 'specific_user', and NULL for the two "all"
 * audiences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_notifications', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('title', 190);
            $table->text('body');
            $table->string('link', 500)->nullable();
            $table->enum('audience', BroadcastAudience::values());
            $table->json('target_user_ids')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 36)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable()->useCurrentOnUpdate();

            // Server-side visibility gating is "is it enabled AND are we inside
            // its window", evaluated with one LEFT JOIN at read time.
            $table->index(['is_active', 'start_at', 'end_at'], 'idx_broadcasts_window');
            $table->index('created_at', 'idx_broadcasts_created_at');

            $table->foreign('created_by', 'fk_broadcasts_creator')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_notifications');
    }
};
