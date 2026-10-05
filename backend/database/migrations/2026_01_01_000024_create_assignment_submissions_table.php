<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `assignment_submissions` from backend/database/migrations/0006, with
 * answer_text/feedback from 0007 and the score CHECK from 0015 folded in.
 *
 * A submission carries EITHER an uploaded file OR typed text (or both), which
 * is why file_path and answer_text are both nullable.
 *
 * CHECK score >= 0 only — the "<= max_score" half CANNOT be expressed here,
 * because a MySQL CHECK constraint may not reference another table. That half
 * stays enforced in the grading service. This is a deliberate, documented
 * asymmetry, not an oversight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('assignment_id', 36);
            $table->char('student_id', 36);
            $table->string('file_path', 255)->nullable();
            $table->text('answer_text')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->dateTime('submitted_at')->useCurrent();
            $table->dateTime('graded_at')->nullable();

            $table->foreign('assignment_id', 'fk_assignment_submissions_assignment')
                ->references('id')->on('assignments')
                ->cascadeOnDelete();

            $table->foreign('student_id', 'fk_assignment_submissions_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE assignment_submissions ADD CONSTRAINT chk_assignment_submissions_score CHECK (score IS NULL OR score >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
    }
};
