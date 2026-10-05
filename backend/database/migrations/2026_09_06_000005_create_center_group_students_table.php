<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * center_group_students — pivot recording which students have been seen in
 * which center group. Populated automatically by
 * App\Actions\Center\MarkAttendance the first time a student is marked
 * present in that group — there is no separate "assign student to group"
 * screen in the spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_group_students', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('center_group_id', 36);
            $table->char('student_id', 36);
            $table->dateTime('joined_at')->useCurrent();

            $table->unique(['center_group_id', 'student_id'], 'uq_center_group_students');
            $table->index('student_id', 'idx_center_group_students_student');

            $table->foreign('center_group_id', 'fk_center_group_students_group')
                ->references('id')->on('center_groups')
                ->cascadeOnDelete();

            $table->foreign('student_id', 'fk_center_group_students_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_group_students');
    }
};
