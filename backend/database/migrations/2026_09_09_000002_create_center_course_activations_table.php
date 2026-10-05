<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "تفعيل كورس سنتر" (explicit teacher request) — a per-student, FREE,
 * no-payment grant of access to ONE course, recorded as its own row rather
 * than a Subscription (no financial trace, per the request) and rather than
 * flipping the course's `visibility` (which would open it to EVERY center
 * student of that teacher, not just the ones who actually attended/were
 * named). App\Policies\Concerns\ResolvesCourseOwnership::canConsume() reads
 * this as a FOURTH, additive way into a course, alongside ownership,
 * subscription and center-visibility membership — it can only ever GRANT
 * access, never restrict it.
 *
 * `center_session_id` is nullable: a bulk "activate for this session's
 * attendees" grant names the session it came from (for audit/traceability);
 * a single-student activation by center code/phone has no session at all.
 *
 * UNIQUE(course_id, student_id): activating the same course for the same
 * student twice (e.g. once per session they attend) is idempotent, not an
 * error or a duplicate row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_course_activations', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('course_id', 36);
            $table->char('teacher_id', 36);
            $table->char('student_id', 36);
            $table->char('center_session_id', 36)->nullable();
            $table->char('activated_by', 36);
            $table->dateTime('activated_at')->useCurrent();

            $table->unique(['course_id', 'student_id'], 'uq_center_course_activations_course_student');
            $table->index('teacher_id', 'idx_center_course_activations_teacher');
            $table->index('center_session_id', 'idx_center_course_activations_session');

            $table->foreign('course_id', 'fk_center_course_activations_course')
                ->references('id')->on('courses')
                ->cascadeOnDelete();

            $table->foreign('teacher_id', 'fk_center_course_activations_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();

            $table->foreign('student_id', 'fk_center_course_activations_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();

            $table->foreign('center_session_id', 'fk_center_course_activations_session')
                ->references('id')->on('center_sessions')
                ->nullOnDelete();

            $table->foreign('activated_by', 'fk_center_course_activations_actor')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_course_activations');
    }
};
