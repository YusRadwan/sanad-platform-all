<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * exams — the configuration half of a `content_items.type = 'exam'` row.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ GRADUATED FROM App\Models\Concerns\PlaceholderModel IN STAGE 6 / A.     ║
 * ║                                                                          ║
 * ║ Stage 5 / Session B was allowed ONE narrow exception to "a placeholder   ║
 * ║ declares no behaviour" — contentItem() — because it had to write `exams` ║
 * ║ rows three stages early (open decision 5.11). That exemption, its        ║
 * ║ ALLOWED_PLACEHOLDER_METHODS entry in PolicySkeletonTest, and the marker  ║
 * ║ itself are all retired here, together, as 5.11 required.                 ║
 * ║                                                                          ║
 * ║ STILL NOT HERE, AND STILL DELIBERATE: `$fillable`. Every column is       ║
 * ║ written by App\Actions\ContentItem\PersistExamDetail with setAttribute,  ║
 * ║ precisely so no request key can reach a column nobody has audited. The   ║
 * ║ graduation is about the OWNERSHIP CHAIN and the read surface, not about  ║
 * ║ opening a mass-assignment door that was closed on purpose.               ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Columns (migration 2026_01_01_000021): id, content_item_id,
 * question_group_id, duration_minutes, pass_score, total_score,
 * allowed_attempts, randomize_questions, lock_content, show_answers. No
 * timestamps.
 *
 * ┌── SESSION A IS THE **READ** HALF OF STAGE 6. THE WRITE HALF IS 6B. ──────┐
 * │ Nothing on this model — and no route in the application — touches        │
 * │ `exam_attempts`. That table has no model, no policy body, no controller  │
 * │ and no route in this session, which is what makes "a student cannot      │
 * │ cheat the attempt cap" true BY CONSTRUCTION rather than by care: the     │
 * │ write path does not exist yet. Session B builds it, with the row lock    │
 * │ and the UNIQUE(exam_id, student_id, attempt_number) ordinal that         │
 * │ backend/ExamRepository already documents.                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class Exam extends BaseModel
{
    protected $table = 'exams';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'randomize_questions' => 'boolean',
            'lock_content' => 'boolean',
            'show_answers' => 'boolean',
        ];
    }

    /**
     * The parent content item — and, through its DENORMALISED `course_id`, the
     * tenant boundary every ability on App\Policies\ExamPolicy resolves.
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id', 'id');
    }

    /**
     * The linked question bank, or null.
     *
     * NULLABLE BY DESIGN: an exam may be authored before its questions exist,
     * and `fk_exams_question_group` is ON DELETE SET NULL, so deleting a bank
     * detaches every exam pointing at it rather than refusing the delete. That
     * cascade is the reason open item 6.3 (a 409-style delete guard) is
     * documented and NOT implemented in this session — see docs/open-decisions.
     */
    public function questionGroup(): BelongsTo
    {
        return $this->belongsTo(QuestionGroup::class, 'question_group_id', 'id');
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `$exam->course` — THE OWNERSHIP HOP, RESOLVED ONCE.                  ║
     * ║                                                                      ║
     * ║ It is an ATTRIBUTE ACCESSOR, not a relation, and it cannot be one:   ║
     * ║ the chain is exam -> content_items.id -> content_items.course_id ->  ║
     * ║ courses.id, i.e. a belongsTo THROUGH a belongsTo. Eloquent has       ║
     * ║ hasOneThrough (far table points BACK at the intermediate) but no     ║
     * ║ belongsToThrough, and the schema is frozen, so no column can be      ║
     * ║ added to make one fit.                                                ║
     * ║                                                                      ║
     * ║ Writing it as an Attribute rather than a plain `courseOf()` method   ║
     * ║ is what keeps `$exam->course` readable at the four call sites in     ║
     * ║ ExamPolicy. A plain method NAMED `course()` would be worse than      ║
     * ║ either: Eloquent's isRelation() checks `method_exists`, so           ║
     * ║ `$exam->course` would be routed through getRelationshipFromMethod()  ║
     * ║ and throw a LogicException for not returning a Relation.             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── NULL IS A REAL, EXPECTED ANSWER, AND EVERY CALLER OWES IT A BRANCH.┐
     * │ Deleting a course writes `courses.deleted_at` and DELIBERATELY leaves│
     * │ its sections, content items and detail rows in place                 │
     * │ (backend/CourseService::delete() says so). Course's SoftDeletes      │
     * │ global scope then makes this null while the exam row survives. Every │
     * │ ability on ExamPolicy branches on it and FAILS CLOSED; the           │
     * │ controllers turn the same fact into a 404 one rung earlier.          │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @return Attribute<?Course, never>
     */
    protected function course(): Attribute
    {
        return Attribute::get(fn (): ?Course => $this->contentItem?->course);
    }

    /**
     * Every exam under one course, in the syllabus order the course page shows.
     *
     * ┌── THE ORDERING IS A CORRELATED SUBQUERY, NOT A JOIN. ────────────────┐
     * │ backend/ExamRepository::listByCourse() is                            │
     * │   SELECT e.*, ci.title, ci.course_id … ORDER BY ci.order_index ASC   │
     * │ A join here would work but would put `content_items` columns into    │
     * │ the model's attribute bag, where they would shadow nothing today and │
     * │ silently shadow an `exams` column the day the two tables share a     │
     * │ name. The subquery orders by exactly the same value and leaves the   │
     * │ hydrated model clean; `with('contentItem.course')` supplies title    │
     * │ and course_id to the Resources properly, as relations.               │
     * │                                                                      │
     * │ `id` is the tie-break — a UUIDv7, i.e. creation order — because      │
     * │ order_index is not unique within a course and an unstable list order │
     * │ is a flaky test waiting to happen.                                   │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function scopeForCourse(Builder $query, string $courseId): Builder
    {
        return $query
            ->whereHas('contentItem', fn (Builder $q): Builder => $q->where('course_id', $courseId))
            ->orderBy(
                ContentItem::query()
                    ->select('order_index')
                    ->whereColumn('content_items.id', 'exams.content_item_id')
            )
            ->orderBy('id');
    }

    /**
     * Drop the rows whose content item is hidden.
     *
     * ROW-20 SEMANTICS, NOT ROW-22: this is applied ONLY when the caller is
     * neither the course owner nor an admin, so an instructor still sees the
     * exam they staged for next term. See App\Http\Controllers\ExamController
     * ::indexByCourse() for why the decision is made once per request rather
     * than once per row.
     */
    public function scopeVisibleOnly(Builder $query): Builder
    {
        return $query->whereHas('contentItem', fn (Builder $q): Builder => $q->where('is_hidden', false));
    }
}
