<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of backend/database/migrations/0021_create_governorates_table.sql.
 *
 * WHY curriculum_id IS NOT NULL (unlike grades.curriculum_id)
 * grades.curriculum_id had to stay nullable because a flat, pre-curriculum
 * catalog already existed and is still read. Governorates have no such
 * legacy: the table was net-new, so a governorate belonging to no country
 * would be meaningless. NOT NULL from day one.
 *
 * ON DELETE CASCADE (also unlike grades, which is RESTRICT)
 * A grade is pointed AT by other tables — courses.grade_id is a real foreign
 * key and teachers.grades lists grade names — so deleting grades with their
 * country would orphan live rows. A governorate row is referenced by nothing:
 * it is a pure option list, and students.governorate stores a NAME STRING
 * copy, not an id. Removing a country's option list together with the country
 * is therefore lossless, so CASCADE is correct and a curriculum delete is not
 * blocked by its governorates.
 *
 * Deleting a SINGLE governorate is still guarded at the service layer with a
 * 409 when any students.governorate matches its name — a by-NAME referential
 * check, because the reference is by string, not by foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governorates', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name', 100);
            $table->char('curriculum_id', 36);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['name', 'curriculum_id'], 'uq_governorates_name_curriculum');

            $table->foreign('curriculum_id', 'fk_governorates_curriculum')
                ->references('id')->on('curricula')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governorates');
    }
};
