<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * center_sessions — one class meeting ("حصة") of one center group.
 *
 * `teacher_id` is a deliberate denormalised copy of `center_groups.teacher_id`
 * so every ownership filter on this table can stay a direct column
 * comparison, matching the `scopeOwnedBy` convention used everywhere else
 * (Course, CourseGroup) rather than a `whereHas('group', ...)` on the hot
 * attendance-taking path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_sessions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('teacher_id', 36);
            $table->char('center_group_id', 36);
            $table->date('session_date');
            $table->dateTime('opened_at')->useCurrent();
            $table->dateTime('closed_at')->nullable();
            $table->string('status', 10)->default('open');

            $table->index('teacher_id', 'idx_center_sessions_teacher');
            $table->index('center_group_id', 'idx_center_sessions_group');
            $table->index('status', 'idx_center_sessions_status');

            $table->foreign('teacher_id', 'fk_center_sessions_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();

            $table->foreign('center_group_id', 'fk_center_sessions_group')
                ->references('id')->on('center_groups')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_sessions');
    }
};
