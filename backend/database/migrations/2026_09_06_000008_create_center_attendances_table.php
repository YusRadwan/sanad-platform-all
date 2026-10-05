<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * center_attendances — one student's presence row for one session.
 *
 * UNIQUE(center_session_id, student_id): re-scanning the same student's QR
 * twice in one session updates, never duplicates. `note` lives directly on
 * this row (per-session note); the student report surfaces the most recent
 * non-empty note across a student's attendance rows for that teacher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_attendances', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('center_session_id', 36);
            $table->char('center_group_id', 36);
            $table->char('student_id', 36);
            $table->dateTime('marked_at')->useCurrent();
            $table->text('note')->nullable();

            $table->unique(['center_session_id', 'student_id'], 'uq_center_attendances_session_student');
            $table->index('student_id', 'idx_center_attendances_student');
            $table->index('center_group_id', 'idx_center_attendances_group');

            $table->foreign('center_session_id', 'fk_center_attendances_session')
                ->references('id')->on('center_sessions')
                ->cascadeOnDelete();

            $table->foreign('center_group_id', 'fk_center_attendances_group')
                ->references('id')->on('center_groups')
                ->cascadeOnDelete();

            $table->foreign('student_id', 'fk_center_attendances_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_attendances');
    }
};
