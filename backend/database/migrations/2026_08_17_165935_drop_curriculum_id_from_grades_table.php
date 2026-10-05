<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the curricula/countries removal (see the governorates-drop
 * migration for context). `grades` no longer doubles as a curriculum-scoped
 * list — it is once again a single flat catalog, so the FK and the column go,
 * and the old composite unique(name, curriculum_id) is replaced with a plain
 * unique(name) to match App\Models\Grade after this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropForeign('fk_grades_curriculum');
            $table->dropUnique('uq_grades_name_curriculum');
            $table->dropColumn('curriculum_id');

            $table->unique('name', 'uq_grades_name');
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique('uq_grades_name');
            $table->char('curriculum_id', 36)->nullable();

            $table->unique(['name', 'curriculum_id'], 'uq_grades_name_curriculum');

            $table->foreign('curriculum_id', 'fk_grades_curriculum')
                ->references('id')->on('curricula')
                ->restrictOnDelete();
        });
    }
};
