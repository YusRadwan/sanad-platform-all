<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `grades` from backend/database/migrations/0005, as amended by 0016
 * (unique name) and 0019 (curriculum scoping).
 *
 * FINAL STATE ONLY. backend/ got here in three steps — create flat, add
 * UNIQUE(name), then drop that index and replace it with
 * UNIQUE(name, curriculum_id) — because each step had live data behind it.
 * Only the end state is reproduced here; `uq_grades_name` never exists.
 *
 * WHY THE UNIQUE IS COMPOSITE
 * Once grades are scoped per country, a global unique on `name` is wrong:
 * 'الصف الأول الثانوي' is a real grade in the Egyptian curriculum AND in the
 * Jordanian one, and a global index would let whichever curriculum was
 * configured first permanently claim the name. A name may repeat ACROSS
 * curricula, never WITHIN one.
 *
 * WHY curriculum_id IS NULLABLE
 * A flat, pre-curriculum catalog is still read by the teacher-creation form
 * (AddTeacher.tsx) via a query that selects every row. Those legacy rows carry
 * curriculum_id = NULL, and MySQL treats NULL as distinct in a unique index,
 * so (name, NULL) is never rejected. That does mean the flat list loses its
 * DATABASE-level dedup — it is enforced at the service layer instead, by an
 * explicit by-name lookup before insert.
 *
 * ON DELETE RESTRICT (not SET NULL): silently detaching a country's grades
 * when the country is deleted would leave them indistinguishable from legacy
 * flat grades. The service layer turns this into an explicit 409 with an
 * Arabic message before the constraint ever fires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name', 100);
            $table->char('curriculum_id', 36)->nullable();

            $table->unique(['name', 'curriculum_id'], 'uq_grades_name_curriculum');

            $table->foreign('curriculum_id', 'fk_grades_curriculum')
                ->references('id')->on('curricula')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
