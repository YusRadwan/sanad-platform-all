<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * center_memberships — the ONLY place "this student belongs to this
 * teacher's center" is recorded. Every isolation check in the Center System
 * (attendance, reports, course access) ultimately tests a row here.
 *
 * UNIQUE(student_id, teacher_id): a student joins one teacher's center once,
 * no matter how many codes of that teacher's they type in. This is what lets
 * App\Actions\Center\JoinCenter be idempotent instead of erroring on a
 * repeat join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_memberships', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('student_id', 36);
            $table->char('teacher_id', 36);
            $table->char('center_code_id', 36)->nullable();
            $table->dateTime('joined_at')->useCurrent();

            $table->unique(['student_id', 'teacher_id'], 'uq_center_memberships_student_teacher');
            $table->index('teacher_id', 'idx_center_memberships_teacher');

            $table->foreign('student_id', 'fk_center_memberships_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();

            $table->foreign('teacher_id', 'fk_center_memberships_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();

            $table->foreign('center_code_id', 'fk_center_memberships_code')
                ->references('id')->on('center_codes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_memberships');
    }
};
