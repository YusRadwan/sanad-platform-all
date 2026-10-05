<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * center_groups — a teacher's roster group at one physical center (e.g.
 * "سنتر المستقبل / الثالث الثانوي / مجموعة السبت / السبت 6:00 مساءً").
 *
 * Deliberately separate from `course_groups` (content organisation inside
 * ONE course) and `question_groups` (a teacher's question bank folder) — see
 * App\Models\CourseGroup's own docblock for why that name is reserved.
 *
 * `grade_label`/`center_name`/`group_name`/`schedule_text` are plain strings,
 * matching `students.grade_level`'s existing denormalised-string convention
 * rather than adding a new FK catalog for a field the spec treats as free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_groups', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('teacher_id', 36);
            $table->string('center_name', 190);
            $table->string('grade_label', 190);
            $table->string('group_name', 190);
            $table->string('schedule_text', 190);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();

            $table->index('teacher_id', 'idx_center_groups_teacher');

            $table->foreign('teacher_id', 'fk_center_groups_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_groups');
    }
};
