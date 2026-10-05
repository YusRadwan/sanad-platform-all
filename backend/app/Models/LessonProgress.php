<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * lesson_progress — watch history, one row per (student, lesson).
 *
 * Columns (migration 2026_01_01_000018): id, student_id, content_item_id,
 * progress_seconds, completed, last_watched_at. No created_at/updated_at, so
 * `$timestamps = false`.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `student_id` IS A FOREIGN KEY INTO **students.user_id**, NOT users.id.   ║
 * ║                                                                          ║
 * ║ That one fact drives the whole non-student story on the /lessons routes: ║
 * ║ an admin and an instructor have no `students` row, so no row here can    ║
 * ║ legally exist for them and no INSERT can succeed. The application must   ║
 * ║ therefore decide — before touching this table — that a non-student's     ║
 * ║ write is a NO-OP rather than an error. See                               ║
 * ║ App\Actions\Lesson\SaveLessonProgress, which carries the argument and    ║
 * ║ the Gate::before escape-hatch note in full.                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
class LessonProgress extends BaseModel
{
    /** A lesson counts as completed once the student has watched this much of it. */
    public const COMPLETION_THRESHOLD_PERCENT = 90.0;

    protected $table = 'lesson_progress';

    public $timestamps = false;

    /**
     * DELIBERATELY EMPTY. Nothing here is ever mass-assigned.
     *
     * The single write path is recordFor() below, which builds its statement
     * by hand. `student_id` in particular must never be reachable from a
     * request body — it is the session identity, and a fillable spelling of it
     * is one `create($request->all())` away from letting a student write
     * another student's watch history.
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'progress_seconds' => 'integer',
            'completed' => 'boolean',
            'last_watched_at' => 'datetime',
        ];
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id', 'id');
    }

    /** The existing row for this pair, or null. */
    public static function findFor(string $studentId, string $contentItemId): ?self
    {
        return self::query()
            ->where('student_id', $studentId)
            ->where('content_item_id', $contentItemId)
            ->first();
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE MONOTONIC UPSERT. `GREATEST()` ON BOTH MUTABLE COLUMNS.          ║
     * ║                                                                      ║
     * ║   INSERT … ON DUPLICATE KEY UPDATE                                    ║
     * ║     progress_seconds = GREATEST(progress_seconds, VALUES(…)),        ║
     * ║     completed        = GREATEST(completed, VALUES(…)),                ║
     * ║     last_watched_at  = NOW()                                          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── WHY MONOTONIC, AND WHY IT IS A PRODUCT RULE NOT AN OPTIMISATION. ──┐
     * │ The player fires this from a throttled `timeupdate` handler, so the  │
     * │ server sees a stream of positions in whatever order the network      │
     * │ delivers them. Two consequences that GREATEST() and only GREATEST()  │
     * │ handles:                                                             │
     * │                                                                      │
     * │  1. A student who scrubs BACKWARDS near the end of a lesson must not │
     * │     lose their furthest-watched position.                            │
     * │  2. `completed` is STICKY. A student re-watching a finished lesson   │
     * │     from second zero posts 2% — and must not be un-completed. This   │
     * │     is visible in the product: the dashboard's completed-lesson      │
     * │     count and the course progress bar would both go backwards.       │
     * │                                                                      │
     * │ Pinned by LessonProgressTest::test_completion_is_sticky_and_progress │
     * │ _never_decreases.                                                     │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── WHY A RAW STATEMENT AND NOT Model::upsert(). ──────────────────────┐
     * │ Eloquent's upsert() compiles to `ON DUPLICATE KEY UPDATE col =       │
     * │ VALUES(col)` — a straight OVERWRITE. It has no expression form, so   │
     * │ the GREATEST() that makes the two rules above true cannot be         │
     * │ expressed through it. Using it would silently convert both rules     │
     * │ into last-write-wins.                                                │
     * │                                                                      │
     * │ The statement is fully parameter-bound; nothing is interpolated.     │
     * │ The conflict target is the schema's own UNIQUE KEY uq_lesson_progress │
     * │ (student_id, content_item_id), which is what makes a retried or      │
     * │ double-tab save idempotent rather than a duplicate row.               │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public static function recordFor(
        string $studentId,
        string $contentItemId,
        int $progressSeconds,
        bool $completed,
    ): void {
        DB::statement(
            'INSERT INTO lesson_progress
                (id, student_id, content_item_id, progress_seconds, completed, last_watched_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                progress_seconds = GREATEST(progress_seconds, VALUES(progress_seconds)),
                completed        = GREATEST(completed, VALUES(completed)),
                last_watched_at  = NOW()',
            [(string) Str::uuid7(), $studentId, $contentItemId, $progressSeconds, (int) $completed]
        );
    }
}
