<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `question_groups` from backend/database/migrations/0006, with the
 * `grade` column added by 0010 folded in.
 *
 * A teacher's reusable question bank folder. `grade` is a free-text VARCHAR
 * mirroring the grade filter shown in the question-bank UI — deliberately NOT
 * a foreign key to grades.id, because it is a display/filter label the teacher
 * types, exactly like teachers.grades stores grade NAME strings.
 *
 * teacher_id -> teachers.user_id: a question bank belongs to a teacher
 * profile, not to an arbitrary user account. This is the same ownership edge
 * as courses.teacher_id and will be one of the tenant-scoping roots later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_groups', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('teacher_id', 36);
            $table->string('title', 190);
            $table->string('grade', 100)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->foreign('teacher_id', 'fk_question_groups_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_groups');
    }
};
