<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `exam_attempts` from backend/database/migrations/0006, with
 * attempt_number, the UNIQUE ordinal and the score CHECK from 0015 folded in.
 *
 * attempt_number + uq_exam_attempts_ordinal — the race-condition defence
 * ------------------------------------------------------------------
 * The original submit path did "SELECT COUNT(*) FROM exam_attempts" and then,
 * in a SEPARATE statement, an INSERT. That is a textbook TOCTOU: N concurrent
 * submissions all read the same count, all pass the allowed_attempts check,
 * and all insert — producing more attempts than the exam allows.
 *
 * The application-level fix is a transaction that row-locks the exam before
 * counting. THIS COLUMN AND ITS UNIQUE KEY ARE THE DEFENCE-IN-DEPTH HALF: the
 * ordinal is computed as COUNT(*)+1 inside that same transaction, so even if
 * the lock were bypassed (a future code path that forgets it, a replica, a
 * manual INSERT) two racing writers would compute the SAME attempt_number and
 * the second would be rejected by the database itself.
 *
 * Its leading (exam_id, student_id) prefix also serves the two hottest attempt
 * queries — count-attempts and find-attempt — which previously had to use the
 * single-column exam FK index and filter student_id afterwards.
 *
 * `score` is a PERCENTAGE (earned/total * 100, rounded to 2dp), so anything
 * outside 0..100 is corrupt data by definition — hence the 0..100 CHECK rather
 * than a mere non-negative one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('exam_id', 36);
            $table->char('student_id', 36);
            $table->integer('attempt_number')->default(1);
            $table->json('answers')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->dateTime('started_at')->useCurrent();
            $table->dateTime('submitted_at')->nullable();

            $table->unique(['exam_id', 'student_id', 'attempt_number'], 'uq_exam_attempts_ordinal');

            $table->foreign('exam_id', 'fk_exam_attempts_exam')
                ->references('id')->on('exams')
                ->cascadeOnDelete();

            $table->foreign('student_id', 'fk_exam_attempts_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE exam_attempts ADD CONSTRAINT chk_exam_attempts_score CHECK (score IS NULL OR (score >= 0 AND score <= 100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
