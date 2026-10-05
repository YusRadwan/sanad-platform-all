<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `courses` from backend/database/migrations/0005, with the composite
 * index from 0012 and the two CHECK constraints from 0015 folded in.
 *
 * teacher_id -> teachers.user_id, NOT users.id
 * ------------------------------------------------------------------
 * This is load-bearing and easy to get wrong. A course belongs to a TEACHER
 * PROFILE, not to any user account. Pointing it at users.id would let a
 * student or an admin row own a course, and every ownership check in later
 * stages (and the future per-tenant scoping) keys off this edge. The FK target
 * is teachers.user_id, which is that table's primary key.
 *
 * grade_id / subject_id are ON DELETE SET NULL: they are catalog pointers, and
 * retiring a catalog entry must not delete a teacher's course.
 *
 * CHECK constraints (from 0015)
 * ------------------------------------------------------------------
 * `price` is the catalogue price, `discount_price` the reduced price actually
 * charged. A negative price, or a "discount" above the base price, is never a
 * legitimate state — the storefront feeds both straight through and derives
 * isFree from price <= 0.
 *
 * Laravel 11's Blueprint has no fluent CHECK API, so these go out as raw
 * ALTER TABLE. MySQL 8.4 (verified: SELECT VERSION() -> 8.4.3) enforces CHECK
 * for real; it is not the silently-ignored no-op that MySQL 5.7 parsed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->string('thumbnail_url', 255)->nullable();
            $table->char('teacher_id', 36);
            $table->char('grade_id', 36)->nullable();
            $table->char('subject_id', 36)->nullable();
            $table->boolean('is_published')->default(false);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable()->useCurrentOnUpdate();
            $table->dateTime('deleted_at')->nullable();

            $table->index('teacher_id', 'idx_courses_teacher');
            $table->index('is_published', 'idx_courses_published');
            // Covers the public catalogue's hottest query in index order:
            // WHERE is_published = 1 ORDER BY created_at DESC LIMIT/OFFSET.
            // The single-column idx_courses_published cannot satisfy the sort
            // and forces a filesort on every page.
            $table->index(['is_published', 'created_at'], 'idx_courses_published_created');

            $table->foreign('teacher_id', 'fk_courses_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();

            $table->foreign('grade_id', 'fk_courses_grade')
                ->references('id')->on('grades')
                ->nullOnDelete();

            $table->foreign('subject_id', 'fk_courses_subject')
                ->references('id')->on('subjects')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE courses ADD CONSTRAINT chk_courses_price_non_negative CHECK (price >= 0)');
        DB::statement('ALTER TABLE courses ADD CONSTRAINT chk_courses_discount_range CHECK (discount_price IS NULL OR (discount_price >= 0 AND discount_price <= price))');
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
