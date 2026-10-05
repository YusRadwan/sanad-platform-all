<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `lesson_progress` from backend/database/migrations/0005.
 *
 * Watch history. UNIQUE(student_id, content_item_id) is not decoration: the
 * write path is an UPSERT keyed on exactly that pair, so the constraint is
 * what makes "record progress" idempotent under concurrent writes from two
 * tabs or a retried request.
 *
 * student_id -> students.user_id (not users.id), matching every other
 * student-scoped table in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('student_id', 36);
            $table->char('content_item_id', 36);
            $table->integer('progress_seconds')->default(0);
            $table->boolean('completed')->default(false);
            $table->dateTime('last_watched_at')->useCurrent();

            $table->unique(['student_id', 'content_item_id'], 'uq_lesson_progress');

            $table->foreign('student_id', 'fk_lesson_progress_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();

            $table->foreign('content_item_id', 'fk_lesson_progress_content_item')
                ->references('id')->on('content_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
