<?php

declare(strict_types=1);

use App\Models\Grade;
use App\Models\GradeSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scopes every grade to a section (open decision 10.12). `section_id` is
 * added nullable first so the backfill below can run, then tightened to
 * NOT NULL — the same two-step shape `order` used in migration
 * 2026_08_19_031630, which taught the lesson this migration exists to
 * avoid repeating: never leave two independently-numbered groups sharing
 * one flat 0..n order space. Here, `order` is renumbered PER SECTION
 * (0..2 within each) as part of the same backfill, not left as the prior
 * global 0..5.
 *
 * The two default sections and the "إعدادي"/"ثانوي" name-substring split
 * mirror the exact heuristic `deriveEducationalStage()` used client-side
 * before this migration — the last place that inference lived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->char('section_id', 36)->nullable()->after('name');
        });

        $prep = GradeSection::create(['name' => 'المرحلة الإعدادية', 'order' => 0]);
        $secondary = GradeSection::create(['name' => 'المرحلة الثانوية', 'order' => 1]);

        $prepIndex = 0;
        $secondaryIndex = 0;

        foreach (Grade::query()->orderBy('order')->get() as $grade) {
            $name = (string) $grade->getAttribute('name');

            if (mb_strpos($name, 'إعدادي') !== false) {
                $grade->forceFill(['section_id' => $prep->getKey(), 'order' => $prepIndex])->save();
                $prepIndex++;
            } elseif (mb_strpos($name, 'ثانوي') !== false) {
                $grade->forceFill(['section_id' => $secondary->getKey(), 'order' => $secondaryIndex])->save();
                $secondaryIndex++;
            } else {
                // No pre-existing grade should reach this branch (the seeded
                // catalog is exactly the six prep/secondary names), but a
                // manually-added row with neither substring falls back to
                // the secondary section rather than failing the migration.
                $grade->forceFill(['section_id' => $secondary->getKey(), 'order' => $secondaryIndex])->save();
                $secondaryIndex++;
            }
        }

        // A raw MODIFY, not ->change(), to avoid a doctrine/dbal dependency
        // this project does not otherwise need.
        DB::statement('ALTER TABLE grades MODIFY section_id CHAR(36) NOT NULL');

        Schema::table('grades', function (Blueprint $table) {
            $table->foreign('section_id', 'fk_grades_section')
                ->references('id')->on('grade_sections')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropForeign('fk_grades_section');
            $table->dropColumn('section_id');
        });
    }
};
