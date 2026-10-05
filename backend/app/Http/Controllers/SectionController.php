<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Section\DeleteSectionRequest;
use App\Http\Requests\Section\ReorderSectionsRequest;
use App\Http\Requests\Section\StoreSectionRequest;
use App\Http\Requests\Section\UpdateSectionRequest;
use App\Http\Resources\SectionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Section;
use App\Support\CourseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sections — freeze-table rows 15-19.
 *
 * Every refusal (404 section, 404 course, 403 owner) is raised by the Form
 * Requests before these methods are entered; see App\Support\CourseAccess for
 * the four literal strings and App\Http\Requests\Section\* for the ladders.
 */
final class SectionController extends Controller
{
    /**
     * GET /api/v1/courses/{courseId}/sections — PUBLIC. Row 15.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ DECISION 3.6 CASCADES TO THIS ROUTE. A DRAFT COURSE'S SYLLABUS IS    ║
     * ║ 404 FOR ANYONE BUT ITS OWNER AND AN ADMIN.                           ║
     * ║                                                                      ║
     * ║ Hiding the course row at GET /courses/{id} while leaving its section  ║
     * ║ titles readable here would hide nothing: the syllabus outline is the  ║
     * ║ substance of an unreleased course. backend/SectionService::           ║
     * ║ listByCourse() checks only that the course EXISTS, so this is the     ║
     * ║ same approved tightening as row 11, applied where it actually bites.  ║
     * ║                                                                      ║
     * ║ Session C inherits the identical obligation for /courses/{id}/videos  ║
     * ║ and the content-item family. The PRINCIPLE starts here.               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * PUBLIC, and `authorize()` is forbidden on it — this is a DEGRADE row.
     * assertVisible() answers 404 rather than 403 for exactly that reason.
     *
     * Ordering comes from Course::sections() (`order_index ASC, id ASC`), so
     * it is stated once on the relation rather than at each call site.
     */
    public function index(Request $request, string $courseId): JsonResponse
    {
        $course = CourseAccess::findOrFail($courseId);

        CourseAccess::assertVisible($request->user(), $course);

        return ApiResponse::success(
            SectionResource::collection($course->sections()->get())
        );
    }

    /**
     * POST /api/v1/courses/{courseId}/sections -> 201. Row 16.
     *
     * ┌── THE `order_index` RACE IS LEFT AS-IS — APPROVED DECISION #4. ──────┐
     * │ nextOrderIndex() is `SELECT COALESCE(MAX(order_index), -1) + 1` and  │
     * │ is NOT locked, so two concurrent creates can be handed the same       │
     * │ value. The consequence is cosmetic — two sections tie for a display   │
     * │ position — and the tie is broken deterministically by the `id`        │
     * │ secondary sort on Course::sections(), so the list does not shuffle    │
     * │ between requests. Adding SELECT … FOR UPDATE would put row-level      │
     * │ locking on the hottest write path of the course editor to fix a       │
     * │ display-order collision. backend/ has the same race; it stays.        │
     * │                                                                      │
     * │ The MAX() runs INSIDE the transaction below, which does not make it   │
     * │ race-free (REPEATABLE READ does not prevent two transactions reading  │
     * │ the same MAX) and is not claimed to.                                  │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function store(StoreSectionRequest $request): JsonResponse
    {
        $course = $request->course();

        $section = DB::transaction(fn (): Section => Section::query()->create([
            'course_id' => $course->getKey(),
            'title' => (string) $request->input('title'),
            'order_index' => $this->nextOrderIndex($course->getKey()),
        ]));

        return ApiResponse::created(new SectionResource($section), 'تم إنشاء القسم بنجاح');
    }

    /** PUT /api/v1/sections/{id}. Row 17. */
    public function update(UpdateSectionRequest $request): JsonResponse
    {
        $section = $request->section();

        // has() && !== null: absent and null both mean "leave it"; '' is
        // written, because `sections.title` is a VARCHAR and backend/'s
        // array_filter genuinely wrote it. See UpdateSectionRequest.
        if ($request->has('title') && $request->input('title') !== null) {
            $section->setAttribute('title', (string) $request->input('title'));
            $section->save();
        }

        return ApiResponse::success(new SectionResource($section), 'تم تحديث القسم بنجاح');
    }

    /**
     * PATCH /api/v1/courses/{courseId}/sections/reorder. Row 18.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE `course_id` PREDICATE IS THE TENANT GUARD, NOT THE `id`.         ║
     * ║                                                                      ║
     * ║   UPDATE sections SET order_index = ?                                 ║
     * ║   WHERE id = ? AND course_id = ?                                      ║
     * ║                                                                      ║
     * ║ Both columns, every time. With `id` alone, a caller who owns course A ║
     * ║ could pass a section id from course B and silently renumber another   ║
     * ║ teacher's syllabus — the ownership check on the COURSE would have     ║
     * ║ passed, because the course they named is genuinely theirs.            ║
     * ║                                                                      ║
     * ║ And the foreign id must be IGNORED, not rejected: the scoped UPDATE   ║
     * ║ matches zero rows and the request still answers 200. See              ║
     * ║ ReorderSectionsRequest for why that tolerance is the ported contract. ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * DB::transaction per approved decision #3 — and here it earns its keep
     * immediately: this is a genuine multi-statement write, and a failure
     * halfway through backend/'s unwrapped loop left the syllabus in an order
     * that was neither the old one nor the new one.
     */
    public function reorder(ReorderSectionsRequest $request): JsonResponse
    {
        $course = $request->course();
        $orderedIds = $request->orderedIds();

        DB::transaction(function () use ($course, $orderedIds): void {
            foreach ($orderedIds as $index => $sectionId) {
                Section::query()
                    ->where('id', $sectionId)
                    ->where('course_id', $course->getKey())
                    ->update(['order_index' => $index]);
            }
        });

        return ApiResponse::success(null, 'تم إعادة ترتيب الأقسام بنجاح');
    }

    /**
     * DELETE /api/v1/sections/{id} — HARD delete, NOT audited. Row 19.
     * Both properties are ported; see DeleteSectionRequest.
     */
    public function destroy(DeleteSectionRequest $request): JsonResponse
    {
        $request->section()->delete();

        return ApiResponse::success(null, 'تم حذف القسم بنجاح');
    }

    /**
     * `COALESCE(MAX(order_index), -1) + 1`, verbatim from
     * backend/SectionRepository::nextOrderIndex().
     *
     * The COALESCE default of -1 (not 0) is what makes the FIRST section land
     * on index 0 rather than 1 — the frontend renders positions from this
     * value directly.
     */
    private function nextOrderIndex(string $courseId): int
    {
        $max = Section::query()->where('course_id', $courseId)->max('order_index');

        // MAX() over an empty set is SQL NULL, which COALESCE(-1) + 1 turned
        // into 0. Reproduced explicitly rather than with a (int) cast on null,
        // which would give 0 for an empty course AND 1 for a course whose only
        // section sits at index 0 — the same number for two different states.
        return $max === null ? 0 : ((int) $max) + 1;
    }
}
