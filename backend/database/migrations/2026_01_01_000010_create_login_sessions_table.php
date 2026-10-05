<?php

declare(strict_types=1);

use App\Enums\LoginSessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of backend/database/migrations/0004_create_login_sessions_table.sql.
 *
 * The status ENUM values contain SPACES and MIXED CASE — 'Logged Out',
 * 'Session Expired', 'Suspicious Activity'. Those literals are shipped
 * straight to the frontend (types/auth.ts LoginSession) and rendered as-is,
 * so they are a wire contract, not an internal code. They are preserved
 * EXACTLY and must never be normalised to snake_case.
 *
 * App\Enums\LoginSessionStatus is the PHP-side mirror; the column list is fed
 * from it so the two cannot drift.
 *
 * NOTE: this table is the application's own audit trail of user logins. It is
 * unrelated to Laravel's session STORAGE (which is file-driven here, see
 * config/session.php) — nothing in this table is read to authenticate a
 * request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_sessions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->string('ip_address', 45);
            $table->string('user_agent', 255);
            $table->enum('status', LoginSessionStatus::values())
                ->default(LoginSessionStatus::Active->value);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('last_activity_at')->useCurrent();

            $table->index('user_id', 'idx_login_sessions_user');

            $table->foreign('user_id', 'fk_login_sessions_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_sessions');
    }
};
