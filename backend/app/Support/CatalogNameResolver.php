<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Grade;
use App\Models\Subject;

/**
 * Resolves a grade/subject NAME to its catalogue row id, so `courses.grade_id`
 * and `courses.subject_id` are actually populated.
 *
 * Port of backend/app/Services/CourseService::resolveCatalogId() +
 * CatalogService::idForName() + CatalogRepository::findByName().
 *
 * Both columns exist with real foreign keys (migration 000011) but nothing ever
 * wrote them: the course forms only ever submitted free-text names, so every
 * course had grade_id = subject_id = NULL and the joined `grade_name` the
 * frontend reads was always null too. Now that the names come from a persisted
 * catalogue the lookup is a single indexed read.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ IT FAILS **OPEN**, AND THAT IS THE PORTED CONTRACT — NOT AN OVERSIGHT.   ║
 * ║                                                                          ║
 * ║ backend/'s own words, preserved because the reasoning is the behaviour:  ║
 * ║                                                                          ║
 * ║   "An unknown name yields null, i.e. exactly the previous behaviour, so  ║
 * ║    this can never turn a previously-accepted write into a failure."      ║
 * ║                                                                          ║
 * ║ An unrecognised grade name is therefore **NOT** a validation error. It   ║
 * ║ resolves to null, the course saves, and `grade_id` stays NULL exactly as ║
 * ║ it did for every course created before the catalogue existed. Turning    ║
 * ║ this into a 422 would reject saves that work in production today —       ║
 * ║ every course whose grade string predates migration 0016, and every       ║
 * ║ teacher whose assigned list contains a name an admin has since deleted   ║
 * ║ from the catalogue.                                                      ║
 * ║                                                                          ║
 * ║ NOTE THE DIVISION OF LABOUR: App\Rules\AssignedToTeacher decides whether ║
 * ║ the instructor is ALLOWED to use the name (422 if not). This class only  ║
 * ║ decides whether the name is KNOWN. A name can be allowed and unknown at  ║
 * ║ once — that is the fail-open case above — and the two questions must not ║
 * ║ be merged.                                                               ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THE SEARCH USES `WHERE name = :name LIMIT 1`. ──────────────────────────┐
 * │ APPROVED DECISION #2 OF STAGE 5.                                         │
 * │                                                                          │
 * │ `grades.name` is UNIQUE, so this always resolves to at most one row.     │
 * │ backend/'s query is ported verbatim.                                     │
 * │                                                                          │
 * │ ONE DELIBERATE ADDITION: `orderBy('id')`. backend/'s LIMIT 1 with no     │
 * │ ORDER BY lets MySQL return a DIFFERENT row between two identical calls   │
 * │ (index choice, page layout, replica). That is not "first match", it is   │
 * │ "any match", and it makes the same save non-reproducible. Ordering by    │
 * │ the primary key makes "the first match" an actual, testable promise      │
 * │ without narrowing or widening the result set by a single row.            │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class CatalogNameResolver
{
    /** @return string|null the grades row id, or null when unknown/blank */
    public function gradeId(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return Grade::query()->where('name', $name)->orderBy('id')->value('id');
    }

    /** @return string|null the subjects row id, or null when unknown/blank */
    public function subjectId(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return Subject::query()->where('name', $name)->orderBy('id')->value('id');
    }
}
