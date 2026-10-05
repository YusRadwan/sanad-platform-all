<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `announcements` from backend/database/migrations/0006, with the
 * publishing/scheduling columns from 0008 folded in.
 *
 * Both parents are nullable, and they mean different things:
 *   course_id NULL   -> a teacher-wide announcement, not tied to one course
 *   teacher_id NULL  -> authored by an admin, or the author was removed
 * course_id CASCADEs (an announcement about a deleted course is meaningless)
 * while teacher_id SET NULLs (the announcement itself outlives its author).
 *
 * is_published defaults to 1, matching the old ALTER's default: an
 * announcement created through the UI is live unless explicitly withheld.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('course_id', 36)->nullable();
            $table->char('teacher_id', 36)->nullable();
            $table->string('title', 190);
            $table->text('body');
            $table->string('link_url', 255)->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->boolean('is_published')->default(true);
            $table->dateTime('created_at')->useCurrent();

            $table->foreign('course_id', 'fk_announcements_course')
                ->references('id')->on('courses')
                ->cascadeOnDelete();

            $table->foreign('teacher_id', 'fk_announcements_teacher')
                ->references('user_id')->on('teachers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
