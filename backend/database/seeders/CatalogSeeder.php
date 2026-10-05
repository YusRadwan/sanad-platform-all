<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Grade;
use App\Models\GradeSection;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * The three admin-managed option catalogs: grade_sections, grades
 * (scoped by section — open decision 10.12), and subjects.
 *
 * These are the platform's REAL default options — not sample data. Getting a
 * name wrong here is not cosmetic: teachers.grades and teachers.subjects
 * store grade/subject NAME STRINGS (and, since the curricula/countries system
 * was removed, students.grade_level does too), so a renamed catalog entry
 * silently orphans every teacher/student already assigned to it.
 *
 * SECTIONS is Egypt-only (إعدادي/ثانوي) and GRADES is grouped under its
 * matching section, each numbered 0-2 independently — this is also the exact
 * order a brand-new install shows in the public student signup wizard and
 * Admin/AddStudent.tsx, both of which now read these two catalogs directly
 * instead of a hard-coded frontend list. `firstOrCreate`'s second argument
 * only applies on actual creation, so re-running this seeder never resets an
 * `order` the admin has since customised via the UI.
 */
class CatalogSeeder extends Seeder
{
    /** @var array<string, array<int, string>> */
    private const SECTIONS = [
        'المرحلة الإعدادية' => [
            'الصف الأول الإعدادي',
            'الصف الثاني الإعدادي',
            'الصف الثالث الإعدادي',
        ],
        'المرحلة الثانوية' => [
            'الصف الأول الثانوي',
            'الصف الثاني الثانوي',
            'الصف الثالث الثانوي',
        ],
    ];

    /** @var array<int, string> */
    private const SUBJECTS = [
        'اللغة العربية',
        'الرياضيات',
        'الفيزياء',
        'الكيمياء',
        'اللغة الإنجليزية',
        'الأحياء',
    ];

    public function run(): void
    {
        $gradeCount = 0;

        foreach (array_values(self::SECTIONS) as $sectionIndex => $grades) {
            $sectionName = array_keys(self::SECTIONS)[$sectionIndex];
            $section = GradeSection::firstOrCreate(['name' => $sectionName], ['order' => $sectionIndex]);

            foreach ($grades as $index => $name) {
                Grade::firstOrCreate(['name' => $name], ['section_id' => $section->getKey(), 'order' => $index]);
                $gradeCount++;
            }
        }

        foreach (self::SUBJECTS as $name) {
            Subject::firstOrCreate(['name' => $name]);
        }

        $this->command?->info(sprintf(
            'Catalogs seeded: %d sections, %d grades, %d subjects.',
            count(self::SECTIONS),
            $gradeCount,
            count(self::SUBJECTS)
        ));
    }
}
