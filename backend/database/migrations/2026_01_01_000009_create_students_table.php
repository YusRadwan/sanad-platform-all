<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of backend/database/migrations/0003_create_students_table.sql, with
 * 0020 (curriculum_id) and 0022 (educational_stage becomes nullable) folded
 * into the final state.
 *
 * 1:1 extension of `users` where role='student'.
 *
 * Arabic literal option strings from the signup form ('ذكر'/'أنثى',
 * 'المرحلة الإعدادية'/'المرحلة الثانوية', 'عام'/'بكالوريا'/'أزهري') are stored
 * as plain VARCHAR, NOT as an ENUM: they are UI-facing labels the frontend may
 * localize or change independently of the backend contract. Same reasoning for
 * `governorate`, which stores a NAME STRING copy rather than a governorates.id
 * — that is why deleting a governorate is guarded by a name-match count
 * instead of a foreign key.
 *
 * educational_stage is NULLABLE (0022): the public 3-step signup wizard no
 * longer asks for "المستوى الدراسي"/"نوع التعليم" at all, because the
 * curriculum picked in its first step already scopes the grade list. The
 * admin-side creation form is unchanged and still sends a real value, so only
 * self-registrations leave it NULL.
 *
 * curriculum_id is NULLABLE with ON DELETE SET NULL: deleting a country must
 * never be blocked by, nor cascade into, real student accounts. The student
 * keeps their grade_level and phone as plain stored values and simply loses
 * the pointer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->char('user_id', 36)->primary();
            $table->string('student_code', 20);
            $table->char('curriculum_id', 36)->nullable();
            $table->string('phone', 20);
            $table->string('parent_phone', 20);
            $table->string('governorate', 100);
            $table->string('gender', 20)->nullable();
            $table->string('educational_stage', 100)->nullable();
            $table->string('education_type', 100)->nullable();
            $table->string('grade_level', 100);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable()->useCurrentOnUpdate();

            $table->unique('student_code', 'uq_students_code');

            $table->foreign('user_id', 'fk_students_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->foreign('curriculum_id', 'fk_students_curriculum')
                ->references('id')->on('curricula')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
