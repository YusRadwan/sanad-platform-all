<?php

declare(strict_types=1);

namespace App\Actions\Course;

use App\Http\Requests\Course\CourseWriteRequest;
use App\Models\Course;
use App\Support\CatalogNameResolver;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE FIELD-SEMANTICS TABLE, EXPRESSED AS CODE. ONE COPY, BOTH VERBS.     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 *  field           absent      ''                        value
 *  ──────────────  ─────────  ────────────────────────  ──────────────────
 *  title           untouched  422 upstream (min:3)      update
 *  thumbnail_url   untouched  **CLEAR**                 update
 *  description     untouched  **CLEAR**                 update
 *  discount_price  untouched  **IGNORED**, not cleared  update
 *  price           untouched  **IGNORED**, not cleared  update
 *  is_published    untouched  n/a — PRESENCE-based      update, false included
 *  grade/subject   untouched  untouched                 resolved to an id
 *
 * ┌── THE THREE LARAVEL PRIMITIVES, AND WHY EACH APPEARS WHERE IT DOES ──────┐
 * │                                                                          │
 * │  $request->has($f)      array_key_exists-equivalent. NOT fooled by '',   │
 * │                         [] or false. This is PRESENCE.                   │
 * │                                                                          │
 * │  $request->filled($f)   present AND non-empty. Treats absent and '' the  │
 * │                         same — used only where both mean "don't touch".  │
 * │                                                                          │
 * │  $request->input($f)    the RAW value, '' included. Used AS-IS for the   │
 * │                         clearable fields, never re-tested with filled(). │
 * │                                                                          │
 * │  ✗ NEVER $request->boolean($f) WITHOUT has() IN FRONT. boolean() maps an │
 * │    ABSENT key to false, so a body that never mentioned `is_published`    │
 * │    would silently UNPUBLISH the course. has() establishes presence;      │
 * │    boolean() then interprets. Same rule, same reason, as Stage 4's       │
 * │    UpdateTeacher and `isActive`.                                         │
 * │                                                                          │
 * │  The `has() && input() !== null` pair on the clearable fields reproduces │
 * │  backend/'s `array_filter(…, fn ($v) => $v !== null)`: a literal `null`  │
 * │  was dropped by that filter (untouched) while '' survived it (written).  │
 * │  has() alone would write the null and blank the column.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ WHY THE MONEY COLUMNS NEED NO SPECIAL CASE HERE — AND WHY THAT IS THE   ║
 * ║ POINT.                                                                   ║
 * ║                                                                          ║
 * ║ `price: ''` and `discount_price: ''` are already GONE by the time this   ║
 * ║ runs: CourseWriteRequest::prepareForValidation() removes blank money     ║
 * ║ keys from the request outright. So `has()` here means "a real number was ║
 * ║ sent", and the inverted convention costs exactly zero branches in this   ║
 * ║ file.                                                                    ║
 * ║                                                                          ║
 * ║ That placement is deliberate. Handling it here instead would mean the    ║
 * ║ Form Request's rules, App\Rules\DiscountNotAbovePrice's baseline and     ║
 * ║ this writer each had to remember the same exception independently —      ║
 * ║ three chances to disagree about what '' means, in three files, for the   ║
 * ║ single highest-risk field of the session. Removing the key once makes    ║
 * ║ all three agree by construction.                                         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ApplyCourseAttributes
{
    public function __construct(private readonly CatalogNameResolver $catalog)
    {
    }

    public function __invoke(Course $course, CourseWriteRequest $request): Course
    {
        // ── presence-based, value already validated as a real title ───────
        if ($request->has('title')) {
            $course->setAttribute('title', (string) $request->input('title'));
        }

        // ── '' CLEARS, null is "absent", absent is untouched ──────────────
        foreach (['description', 'thumbnail_url'] as $field) {
            if ($this->present($request, $field)) {
                $course->setAttribute($field, (string) $request->input($field));
            }
        }

        // ── blank keys were stripped upstream; has() means "a real number" ─
        foreach (['price', 'discount_price'] as $field) {
            if ($request->has($field)) {
                $course->setAttribute($field, $request->input($field));
            }
        }

        // ── has() BEFORE boolean(). `false` is a real instruction. ────────
        if ($request->has('is_published')) {
            $course->setAttribute('is_published', $request->boolean('is_published'));
        }

        // ── Center System — same presence-based treatment as is_published ──
        if ($request->has('visibility')) {
            $course->setAttribute('visibility', (string) $request->input('visibility'));
        }

        $this->applyCatalogIds($course, $request);

        return $course;
    }

    /**
     * NAME -> id for `grade_id` / `subject_id`.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ AN UNRESOLVED NAME LEAVES THE COLUMN **UNTOUCHED**. IT DOES NOT NULL  │
     * │ IT, AND IT DOES NOT 422.                                              │
     * │                                                                      │
     * │ Both halves are ported from the same two lines of                     │
     * │ backend/CourseService::update():                                      │
     * │                                                                      │
     * │   array_filter([                                                      │
     * │     'grade_id' => $this->resolveCatalogId('grades', $submittedGrade), │
     * │     …                                                                 │
     * │   ], fn ($v) => $v !== null)                                          │
     * │                                                                      │
     * │ resolveCatalogId() returns null for a BLANK name and for an UNKNOWN   │
     * │ one alike, and array_filter then drops the key entirely — so neither  │
     * │ case ever reaches the UPDATE. The "fail open" reasoning is quoted in  │
     * │ full on App\Support\CatalogNameResolver.                              │
     * │                                                                      │
     * │ Consequence worth stating out loud, because it looks like a bug and   │
     * │ is not: there is NO WAY through this endpoint to un-set a course's    │
     * │ grade once it has one. backend/ had none either. Session B/C must not │
     * │ "fix" it silently.                                                    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    private function applyCatalogIds(Course $course, CourseWriteRequest $request): void
    {
        if ($request->filled('grade')) {
            $gradeId = $this->catalog->gradeId((string) $request->input('grade'));

            if ($gradeId !== null) {
                $course->setAttribute('grade_id', $gradeId);
            }
        }

        if ($request->filled('subject')) {
            $subjectId = $this->catalog->subjectId((string) $request->input('subject'));

            if ($subjectId !== null) {
                $course->setAttribute('subject_id', $subjectId);
            }
        }
    }

    /**
     * "Sent, and not literally null" — the `''`-survives / `null`-is-dropped
     * reading of backend/'s array_filter. Identical helper, identical wording,
     * to App\Actions\Teacher\UpdateTeacher's.
     */
    private function present(CourseWriteRequest $request, string $field): bool
    {
        return $request->has($field) && $request->input($field) !== null;
    }
}
