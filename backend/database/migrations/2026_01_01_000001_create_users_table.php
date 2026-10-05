<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of backend/database/migrations/0001_create_users_table.sql.
 *
 * The `role` ENUM is the single source of truth for role vocabulary across
 * the whole stack and matches frontend/src/types/user.ts
 * ('student'|'instructor'|'admin') exactly. No alias vocabulary
 * ('teacher'/'staff') has ever existed — do not reintroduce it.
 *
 * The column list is fed from App\Enums\UserRole so the database ENUM and the
 * PHP backed enum can never drift apart. That is the only structural change
 * versus the .sql original; the resulting DDL is identical.
 *
 * `password_hash` keeps its legacy name rather than becoming Laravel's
 * conventional `password`: the column name is part of the schema contract with
 * the existing data, and Eloquent's auth integration is satisfied by
 * overriding getAuthPassword() on the model (App\Models\User), which is a
 * first-party extension point — no need to rename a production column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('username', 100);
            $table->string('email', 190);
            $table->string('password_hash', 255);
            $table->string('full_name', 190);
            $table->enum('role', UserRole::values());
            $table->boolean('is_active')->default(true);
            $table->string('avatar_url', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable()->useCurrentOnUpdate();
            $table->dateTime('deleted_at')->nullable();

            $table->unique('username', 'uq_users_username');
            $table->unique('email', 'uq_users_email');
            $table->index('role', 'idx_users_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
