<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Exceptions\NotFoundException;
use App\Models\Grade;
use App\Models\GradeSection;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;

/**
 * The `{type}` segment of /api/v1/catalogs/{type} — the THREE admin-managed
 * option catalogs behind AddTeacher.tsx's "التصنيفات" step. Matches
 * frontend/src/services/catalogService.ts's `CatalogType` union
 * ('grades' | 'subjects' | 'grade_sections') exactly.
 *
 * `grade_sections` (open decision 10.12) is the first catalog SCOPED BY
 * another: every Grade row carries a NOT NULL `section_id`. Grades' `order`
 * is scoped per section too — see creationAttributes() and
 * ReorderCatalogRequest, which both take an optional section id for this
 * reason. Sections themselves are flat and unscoped, exactly like subjects.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THIS ENUM REPLACES backend/'s CatalogService::TYPES ARRAY, AND IT IS THE │
 * │ THING THAT MAKES THE ENDPOINT SAFE.                                      │
 * │                                                                          │
 * │ The legacy design parameterised a repository by TABLE NAME and           │
 * │ interpolated that name straight into SQL — safe only because a literal   │
 * │ allow-list was consulted first, and one careless refactor away from an   │
 * │ injection. Here the route segment resolves to an Eloquent MODEL CLASS,   │
 * │ never to a string that reaches a query builder. There is no table name   │
 * │ to smuggle.                                                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * fromRoute() is called as the FIRST statement of every catalog controller
 * action, so an unknown type is a 404 before any database work happens — the
 * ordering backend/ also guaranteed by resolving {type} ahead of the
 * repository call.
 *
 * Rename (PATCH) exists — see CatalogController::update() — and cascades the
 * new name into every by-name reference (teachers.grades/.subjects,
 * students.grade_level) inside one transaction, so it never orphans a row
 * the old absence-of-rename design was protecting against.
 */
enum CatalogType: string
{
    use HasValues;

    case Grades = 'grades';
    case Subjects = 'subjects';
    case Sections = 'grade_sections';

    /**
     * Resolves the raw route segment, or 404s with the legacy Arabic message.
     *
     * A backed-enum route TYPE-HINT would have resolved this implicitly, but
     * Laravel answers an unmatched case with a BackedEnumCaseNotFoundException
     * whose message names the enum class — genericised by our Handler to
     * 'Not found'. The contract is 'نوع القائمة غير معروف', so the lookup is
     * explicit.
     */
    public static function fromRoute(string $type): self
    {
        return self::tryFrom($type)
            ?? throw new NotFoundException('نوع القائمة غير معروف');
    }

    /**
     * A query already narrowed to the rows THIS endpoint owns, in this
     * catalog's DISPLAY order. Grades carry a real, admin-managed `order`
     * column (see supportsOrdering()); subjects have none and stay
     * alphabetical, matching every other catalog before ordering existed.
     */
    public function query(): Builder
    {
        return match ($this) {
            self::Grades => Grade::query()->orderBy('order'),
            self::Subjects => Subject::query()->orderBy('name'),
            self::Sections => GradeSection::query()->orderBy('order'),
        };
    }

    /**
     * Attributes every row created through this catalog carries beyond `name`.
     *
     * Grades: a newly added grade always lands at the END of ITS SECTION's
     * ordered list, never re-sorted alphabetically into the middle of it —
     * `(max ?? -1) + 1`, scoped to `$sectionId`, so the very first grade in a
     * brand-new section still gets 0. `$sectionId` is ignored by the other
     * two cases (Subjects has no ordering; Sections is itself the top level).
     *
     * @return array<string, mixed>
     */
    public function creationAttributes(?string $sectionId = null): array
    {
        return match ($this) {
            self::Grades => [
                'section_id' => $sectionId,
                'order' => (Grade::query()->where('section_id', $sectionId)->max('order') ?? -1) + 1,
            ],
            self::Subjects => [],
            self::Sections => ['order' => (GradeSection::query()->max('order') ?? -1) + 1],
        };
    }

    /**
     * The teachers.* JSON column that stores this catalog's entries BY NAME,
     * or null when nothing references the catalog that way.
     */
    public function teacherJsonColumn(): ?string
    {
        return match ($this) {
            self::Grades => 'grades',
            self::Subjects => 'subjects',
            self::Sections => null,
        };
    }

    /** The courses.* foreign key pointing at this catalog's rows, if any. */
    public function courseForeignKey(): ?string
    {
        return match ($this) {
            self::Grades => 'grade_id',
            self::Subjects => 'subject_id',
            self::Sections => null,
        };
    }

    /**
     * The students.* column that stores this catalog's entries BY NAME, or
     * null when nothing references the catalog that way.
     *
     * Only `grades` qualifies: since the platform's curricula/countries
     * system was removed, students.grade_level is a free-text column the
     * public signup wizard and Admin/AddStudent.tsx populate directly from
     * THIS catalog's names — the same "stored by name, not by id" pattern
     * teachers.grades already used, now shared by a second table. Subjects
     * and sections are never collected from a student at all — a section is
     * a pure client-side filtering layer, never persisted anywhere.
     */
    public function studentGradeColumn(): ?string
    {
        return match ($this) {
            self::Grades => 'grade_level',
            self::Subjects, self::Sections => null,
        };
    }

    /**
     * Whether rows in this catalog carry a manually-managed `order` and can
     * be reordered via PATCH /catalogs/{type}/reorder. Grades and sections
     * do; subjects have no `order` column and stay alphabetical.
     */
    public function supportsOrdering(): bool
    {
        return match ($this) {
            self::Grades, self::Sections => true,
            self::Subjects => false,
        };
    }
}
