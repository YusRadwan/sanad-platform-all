<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Grade;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;

/**
 * "Is this option still in use?" — the counters behind every 409 on the flat
 * catalog endpoints, and the same by-name matching CatalogController::update()
 * cascades a rename through.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHY COUNT BY NAME AND NOT BY FOREIGN KEY (for teachers.* / students.*).  │
 * │                                                                          │
 * │ teachers.grades / teachers.subjects are JSON arrays of NAME STRINGS, and │
 * │ students.grade_level is a plain string column — neither is a foreign     │
 * │ key. The database cannot enforce either reference, so deleting a catalog │
 * │ row would silently leave those values pointing at something that no      │
 * │ longer exists — and the instructor course form matches grades/subjects   │
 * │ BY STRING, so the teacher would simply stop being able to create a       │
 * │ course. The 409 guard is the only integrity these references have.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class CatalogReferences
{
    /**
     * How many teachers list $name inside teachers.{$jsonColumn}.
     *
     * whereJsonContains compiles to MySQL's JSON_CONTAINS with the needle
     * bound as a JSON scalar, which is an EXACT element match — unlike a LIKE
     * over the serialised array, which would count "الفيزياء" as a hit for
     * "فيزياء". Same semantics as the legacy hand-written statement.
     *
     * $jsonColumn is never caller input: it comes from CatalogType, a backed
     * enum, and is 'grades' or 'subjects'.
     */
    public function teachersUsing(string $jsonColumn, string $name): int
    {
        return Teacher::query()
            ->whereNotNull($jsonColumn)
            ->whereJsonContains($jsonColumn, $name)
            ->count();
    }

    /**
     * How many LIVE courses point at this row through courses.{$fkColumn}.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ `deleted_at IS NULL` IS DELIBERATE AND MUST NOT BE DROPPED.          │
     * │                                                                      │
     * │ A SOFT-DELETED course's reference does NOT block the delete. The     │
     * │ course is already gone as far as every user-facing surface is        │
     * │ concerned, and the FK is ON DELETE SET NULL, so removing the grade   │
     * │ simply nulls the dead row's column — nothing observable breaks.      │
     * │ Counting trashed courses would instead make catalog entries          │
     * │ permanently undeletable, since a soft-deleted course is never purged.│
     * │ backend/'s countCoursesReferencing() has the same predicate.         │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * A query-builder call rather than an Eloquent one because there is no
     * Course model yet — courses are a later stage. The column name comes from
     * CatalogType, not from the request.
     */
    public function liveCoursesUsing(string $fkColumn, string $id): int
    {
        return DB::table('courses')
            ->where($fkColumn, $id)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * How many students carry $name in students.{$column} (currently only
     * ever 'grade_level' — see CatalogType::studentGradeColumn()).
     *
     * Exact string equality, not a JSON/array match: unlike teachers.grades,
     * a student holds exactly one grade level as a plain string, not a list.
     */
    public function studentsUsing(string $column, string $name): int
    {
        return Student::query()
            ->where($column, $name)
            ->count();
    }

    /**
     * How many grades still belong to a section — the guard behind deleting a
     * `grade_sections` row. Grades reference their section by FOREIGN KEY
     * (grades.section_id), unlike the by-name references above, so this is a
     * plain count rather than a JSON/string match.
     */
    public function gradesInSection(string $sectionId): int
    {
        return Grade::query()
            ->where('section_id', $sectionId)
            ->count();
    }
}
