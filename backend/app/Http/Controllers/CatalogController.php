<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CatalogType;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Requests\Catalog\ReorderCatalogRequest;
use App\Http\Requests\Catalog\StoreCatalogItemRequest;
use App\Http\Requests\Catalog\UpdateCatalogItemRequest;
use App\Http\Resources\CatalogItem;
use App\Http\Responses\ApiResponse;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\CatalogReferences;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET/POST/PATCH(rename)/PATCH(reorder)/DELETE for the THREE admin-managed
 * option catalogs (grades / subjects / grade_sections) behind
 * AddTeacher.tsx's "التصنيفات" step. Backs
 * frontend/src/services/catalogService.ts.
 *
 * Subjects and sections are flat admin-managed lists. Grades are the one
 * catalog SCOPED by another (grade_sections, via grades.section_id — open
 * decision 10.12): creating or reordering a grade requires a `section_id`
 * (see StoreCatalogItemRequest::sectionId() / ReorderCatalogRequest::sectionId()),
 * and a grade's manual `order` is independent PER SECTION, not global.
 *
 * ┌── READS ARE PUBLIC. DO NOT ADD `auth` BACK. ──────────────────────────────┐
 * │ The public, logged-out student-signup wizard (SignupPage.tsx) and        │
 * │ Admin/AddStudent.tsx both render the `grades` catalog directly now       │
 * │ (since the platform's curricula/countries system was removed) — the same │
 * │ "public if a logged-out surface renders it" rule already applied to      │
 * │ GET /settings and the old GET /curricula. The payload is display-only    │
 * │ (catalog names), nothing sensitive.                                      │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ RENAME EXISTS NOW (update(), PATCH), AND THE ORPHANING RISK IS SOLVED —  │
 * │ NOT IGNORED. READ THIS BEFORE TOUCHING update().                         │
 * │                                                                          │
 * │ The historical objection was real: teachers.grades / teachers.subjects   │
 * │ are JSON arrays of catalog NAME STRINGS, not foreign keys, so updating   │
 * │ only the catalog row's `name` column would leave every teacher carrying  │
 * │ the OLD string pointing at a name that no longer exists in the picker.   │
 * │ Renaming was therefore omitted, and the admin UI's pencil control        │
 * │ removed, rather than shipped broken.                                     │
 * │                                                                          │
 * │ update() closes that hole by CASCADING inside a single DB::transaction:  │
 * │ the catalog row's rename and the rewrite of every teacher JSON array     │
 * │ carrying the old name commit together or not at all. There is no window  │
 * │ in which the catalog says one thing and a teacher row says another.      │
 * │                                                                          │
 * │ WHAT IS CASCADED   teachers.grades / teachers.subjects — the two         │
 * │                    by-NAME references in the schema. The exact array     │
 * │                    ELEMENT equal to the old name is replaced; every      │
 * │                    other element on that teacher is left byte-identical. │
 * │                                                                          │
 * │ WHAT NEEDS NO CASCADE, AND WHY IT IS NOT AN OVERSIGHT                    │
 * │   courses        reference catalog rows by FOREIGN KEY (courses.grade_id │
 * │                  / courses.subject_id — see destroy()'s guards, which    │
 * │                  count by FK precisely because that is how the reference │
 * │                  is stored). A course's displayed grade/subject name     │
 * │                  comes from a live lookup against the catalog row, so a  │
 * │                  rename is reflected automatically with ZERO writes to   │
 * │                  any `courses` row.                                     │
 * │ students.governorate is also a by-name copy, stored as free text, and    │
 * │ students.grade_level (grades only — see CatalogType::studentGradeColumn) │
 * │ is now cascaded exactly like teachers.grades, for the same reason.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class CatalogController extends Controller
{
    public function __construct(private readonly CatalogReferences $references)
    {
    }

    /**
     * GET /api/v1/catalogs/{type} -> {items: [{id, name}]}
     *
     * fromRoute() is the FIRST statement, so an unknown {type} is a 404 with
     * 'نوع القائمة غير معروف' before any query is built — the same ordering
     * backend/ guaranteed by resolving {type} ahead of the repository call.
     *
     * Ordering is `$catalog->query()`'s own concern now (grades by their
     * admin-managed `order`, subjects alphabetically) — not fixed here.
     */
    public function index(string $type): JsonResponse
    {
        $catalog = CatalogType::fromRoute($type);

        return ApiResponse::success([
            'items' => CatalogItem::collection($catalog->query()->get()),
        ]);
    }

    /**
     * POST /api/v1/catalogs/{type} -> 201 {item: {id, name}}
     */
    public function store(StoreCatalogItemRequest $request, string $type): JsonResponse
    {
        $catalog = CatalogType::fromRoute($type);

        $item = $catalog->query()->getModel()->newInstance();
        $item->forceFill($catalog->creationAttributes($request->sectionId()) + ['name' => $request->name()]);
        $item->save();

        return ApiResponse::created(
            ['item' => new CatalogItem($item)],
            'تمت الإضافة بنجاح'
        );
    }

    /**
     * PATCH /api/v1/catalogs/{type}/{id} -> {item: {id, name}}
     *
     * The SAFE rename. See the class docblock for why this is allowed to exist
     * at all; this comment covers only the mechanics. The row is looked up
     * through the plain query, so an unknown id is a 404.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE CASCADE AND THE RENAME SHARE ONE TRANSACTION. DO NOT SPLIT THEM. │
     * │                                                                      │
     * │ A rename committed without its cascade leaves teachers pointing at a │
     * │ name that is no longer in the catalog — the precise failure this     │
     * │ endpoint was withheld for. Rolling back together is the whole        │
     * │ safety property, so the DB::transaction wraps BOTH writes.           │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE CASCADE IS A READ-MODIFY-WRITE IN PHP, NOT A RAW JSON_REPLACE.   │
     * │                                                                      │
     * │ Matching "the array ELEMENT exactly equal to the old name" and       │
     * │ swapping only that element is fragile through MySQL's JSON path      │
     * │ functions (the path of a matching element is not known up front, and │
     * │ a JSON_REPLACE over the whole document is a blunt overwrite). The    │
     * │ model's `array` cast already gives us the PHP array; rebuilding it   │
     * │ with a === comparison per element is exact by construction, and      │
     * │ leaves every non-matching element byte-identical. `whereJsonContains`│
     * │ (same technique destroy()'s teachersUsing guard uses) narrows the    │
     * │ candidate set first, so this loop only ever touches real matches.    │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * A no-op rename (new name === old name) is allowed through by the request
     * rule's whereKeyNot and simply cascades over zero changed elements.
     */
    public function update(UpdateCatalogItemRequest $request, string $type, string $id): JsonResponse
    {
        $catalog = CatalogType::fromRoute($type);

        $item = $catalog->query()->whereKey($id)->first()
            ?? throw new NotFoundException('العنصر غير موجود');

        $oldName = (string) $item->getAttribute('name');
        $newName = $request->name();
        $jsonColumn = $catalog->teacherJsonColumn();
        $studentColumn = $catalog->studentGradeColumn();

        // A catalog type with no by-name reference anywhere in the schema
        // (teacherJsonColumn() === null) has nothing to cascade or make
        // atomic. Both current catalog types (grades, subjects) have a
        // column, so this branch is defensive rather than reachable today.
        if ($jsonColumn === null && $studentColumn === null) {
            $item->forceFill(['name' => $newName])->save();
            $cascaded = 0;
            $studentsTouched = 0;
        } else {
            [$cascaded, $studentsTouched] = DB::transaction(function () use ($item, $jsonColumn, $studentColumn, $oldName, $newName): array {
                $item->forceFill(['name' => $newName])->save();

                $touched = 0;

                if ($jsonColumn !== null) {
                    $teachers = Teacher::query()
                        ->whereNotNull($jsonColumn)
                        ->whereJsonContains($jsonColumn, $oldName)
                        ->get();

                    foreach ($teachers as $teacher) {
                        /** @var array<int, mixed> $values */
                        $values = (array) ($teacher->getAttribute($jsonColumn) ?? []);

                        $replaced = array_map(
                            static fn (mixed $value): mixed => $value === $oldName ? $newName : $value,
                            $values
                        );

                        if ($replaced === $values) {
                            continue;
                        }

                        $teacher->setAttribute($jsonColumn, array_values($replaced));
                        $teacher->save();
                        $touched++;
                    }
                }

                // students.grade_level is a plain string column (not a JSON
                // array like teachers.grades), so the cascade is a single
                // exact-match UPDATE — no read-modify-write needed.
                $studentsTouched = $studentColumn !== null
                    ? Student::query()->where($studentColumn, $oldName)->update([$studentColumn => $newName])
                    : 0;

                return [$touched, $studentsTouched];
            });
        }

        SecurityLog::criticalOperation('catalog.renamed', [
            'catalog_type' => $catalog->value,
            'catalog_id' => (string) $item->getKey(),
            'old_name' => $oldName,
            'new_name' => $newName,
            'teachers_cascaded' => $cascaded,
            'students_cascaded' => $studentsTouched,
        ]);

        return ApiResponse::success(
            ['item' => new CatalogItem($item)],
            'تم التعديل بنجاح'
        );
    }

    /**
     * DELETE /api/v1/catalogs/{type}/{id} -> {} with an Arabic message.
     *
     * FOUR GUARDS, in this order — teachers, then courses, then students,
     * then (sections only) child grades — so the message an admin sees for a
     * multiply-referenced entry is deterministic. Only `grades` reaches the
     * third guard; only `grade_sections` reaches the fourth.
     */
    public function destroy(string $type, string $id): JsonResponse
    {
        $catalog = CatalogType::fromRoute($type);

        $item = $catalog->query()->whereKey($id)->first()
            ?? throw new NotFoundException('العنصر غير موجود');

        $name = (string) $item->getAttribute('name');

        if (($jsonColumn = $catalog->teacherJsonColumn()) !== null) {
            $teachers = $this->references->teachersUsing($jsonColumn, $name);

            if ($teachers > 0) {
                throw new ConflictException(
                    "لا يمكن الحذف: هذا الخيار مستخدم حالياً لدى {$teachers} مدرس/مدرسين"
                );
            }
        }

        if (($foreignKey = $catalog->courseForeignKey()) !== null) {
            $courses = $this->references->liveCoursesUsing($foreignKey, $id);

            if ($courses > 0) {
                throw new ConflictException(
                    "لا يمكن الحذف: هذا الخيار مرتبط بـ {$courses} كورس/كورسات"
                );
            }
        }

        if (($studentColumn = $catalog->studentGradeColumn()) !== null) {
            $students = $this->references->studentsUsing($studentColumn, $name);

            if ($students > 0) {
                throw new ConflictException(
                    "لا يمكن الحذف: هذا الخيار مستخدم حالياً لدى {$students} طالب/طلاب"
                );
            }
        }

        if ($catalog === CatalogType::Sections) {
            $grades = $this->references->gradesInSection($id);

            if ($grades > 0) {
                throw new ConflictException(
                    "لا يمكن الحذف: هذا القسم يحتوي على {$grades} صف/صفوف دراسية"
                );
            }
        }

        $item->delete();

        return ApiResponse::success(null, 'تم الحذف بنجاح');
    }

    /**
     * PATCH /api/v1/catalogs/{type}/reorder -> {items: [{id, name}]}
     *
     * Admin-only. Body: {order: [id1, id2, ...]} — the CURRENT rows in their
     * NEW display order, as a complete list. `order` is reassigned to each
     * id's position (0-based) inside one transaction, so a request that only
     * lists some rows or lists an unknown id is rejected outright by
     * ReorderCatalogRequest before any write happens (validated as an exact
     * permutation of the catalog's current ids) — there is no partial-reorder
     * concept to reason about.
     *
     * 404s (via fromRoute()) for an unknown {type}; a type that exists but
     * does not support ordering (subjects) is rejected by
     * ReorderCatalogRequest instead, since that is a validation concern about
     * the request body's applicability, not a routing one.
     */
    public function reorder(ReorderCatalogRequest $request, string $type): JsonResponse
    {
        $catalog = CatalogType::fromRoute($type);

        DB::transaction(function () use ($catalog, $request): void {
            foreach ($request->orderedIds() as $index => $id) {
                $catalog->query()->whereKey($id)->update(['order' => $index]);
            }
        });

        SecurityLog::criticalOperation('catalog.reordered', [
            'catalog_type' => $catalog->value,
            'section_id' => $request->sectionId(),
            'count' => count($request->orderedIds()),
        ]);

        return ApiResponse::success([
            'items' => CatalogItem::collection($catalog->query()->get()),
        ], 'تم إعادة الترتيب بنجاح');
    }
}
