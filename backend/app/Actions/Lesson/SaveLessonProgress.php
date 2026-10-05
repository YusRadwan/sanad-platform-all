<?php

declare(strict_types=1);

namespace App\Actions\Lesson;

use App\Enums\UserRole;
use App\Models\ContentItem;
use App\Models\LessonProgress;
use App\Models\User;

/**
 * POST /api/v1/lessons/{id}/progress — freeze-table row 29.
 *
 * Port of backend/LessonService::saveProgress() (~lines 88-117).
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ ESCAPE HATCH #3 FOR `Gate::before`. SAME CLASS OF ISSUE AS OPEN          ║
 * ║ DECISION 3.1's ExamPolicy::submit AND AssignmentPolicy::submit.          ║
 * ║ SAME PLACEMENT RULE, AND THE PLACEMENT IS THE WHOLE POINT.               ║
 * ║                                                                          ║
 * ║ THE MECHANISM, IN ORDER:                                                 ║
 * ║  1. `lesson_progress.student_id` is a FOREIGN KEY into                   ║
 * ║     `students.user_id`.                                                  ║
 * ║  2. An admin and an instructor have no `students` row. They cannot have  ║
 * ║     one — the role is the thing that decides whether the profile exists. ║
 * ║  3. `Gate::before` returns true for an admin BEFORE any policy body is   ║
 * ║     entered, so `watch` cannot filter them out; and the OWNING           ║
 * ║     INSTRUCTOR passes `watch` legitimately, on the merits, previewing    ║
 * ║     their own lesson.                                                    ║
 * ║  4. Both therefore arrive here fully authorised — and an INSERT for      ║
 * ║     either one dies on the FK: a raw 500 where the contract owes 200.    ║
 * ║                                                                          ║
 * ║ THE REFUSAL MUST THEREFORE LIVE IN THIS LAYER — the Action that performs ║
 * ║ the write — because it is the only layer the Gate bypass does not cover. ║
 * ║ It is FORBIDDEN to put it:                                               ║
 * ║   * inside ContentItemPolicy::watch()  — never runs for an admin;        ║
 * ║   * as a role exception inside Gate::before — that puts a second         ║
 * ║     authorization rule in the one place reserved for exactly one.        ║
 * ║                                                                          ║
 * ║ WHERE THIS ONE DIFFERS FROM 3.1, AND IT IS A REAL DIFFERENCE:            ║
 * ║ ExamPolicy::submit and AssignmentPolicy::submit owe a **403** with a     ║
 * ║ literal message ('Only an enrolled student may submit an exam attempt'). ║
 * ║ This route owes a **silent 200 no-op**. backend/ says why: the player    ║
 * ║ fires progress saves fire-and-forget from a throttled timeupdate         ║
 * ║ handler, and an instructor reviewing their own lesson would otherwise    ║
 * ║ collect a stream of 403s in the console for doing nothing wrong. The     ║
 * ║ zeroed record is returned instead — the same shape a student who has     ║
 * ║ never opened the lesson gets.                                            ║
 * ║                                                                          ║
 * ║ Pinned by LessonProgressTest::test_a_non_student_post_writes_absolutely_ ║
 * ║ nothing, which asserts 200 AND checks `lesson_progress` directly to      ║
 * ║ prove the table was not touched — because a test that only reads the     ║
 * ║ response cannot tell a no-op from a successful write.                    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class SaveLessonProgress
{
    /**
     * @return LessonProgress|null  the persisted row, or null for the zeroed
     *                              shape (non-student — see the banner)
     */
    public function __invoke(User $user, ContentItem $lesson, array $data): ?LessonProgress
    {
        // ESCAPE HATCH. Read the class banner before touching this branch.
        if ($user->role !== UserRole::Student) {
            return null;
        }

        LessonProgress::recordFor(
            (string) $user->getKey(),
            (string) $lesson->getKey(),
            self::position($data),
            self::percentage($data) >= LessonProgress::COMPLETION_THRESHOLD_PERCENT
        );

        return LessonProgress::findFor((string) $user->getKey(), (string) $lesson->getKey());
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ OUT-OF-RANGE VALUES ARE **CLAMPED, NOT REJECTED**. NO 422 EXISTS ON  ║
     * ║ THIS ROUTE, AND ADDING ONE WOULD BE A CONTRACT CHANGE.               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     *   watched_percentage: 150  -> 100   (NOT a 422)
     *   watched_percentage: -5   ->   0
     *   last_position:      -5   ->   0
     *
     * WHY CLAMP. The numbers come from client-side `<video>` element
     * arithmetic — `currentTime / duration * 100` — over a duration the
     * browser may still be probing. A seek during a metadata load, a stream
     * whose reported duration is 0, or a lesson the student finishes while the
     * tab is throttled all produce legitimately absurd values from an
     * honest client. Rejecting them would drop real progress on the floor for
     * a class of user whose only mistake is a slow network, and it would do so
     * through a fire-and-forget request whose failure nobody sees.
     *
     * The payload is nonetheless UNTRUSTED — a hostile client can post
     * anything — which is why the bound exists at all rather than the value
     * being written through. Clamping satisfies both: no request fails, and
     * nothing outside [0, 100] / [0, ∞) ever reaches the database.
     *
     * BOTH SPELLINGS OF BOTH FIELDS ARE ACCEPTED (`lastPosition` /
     * `last_position`, `watchedPercentage` / `watched_percentage`), camelCase
     * first, exactly as backend/'s `?? ` chain ordered them. VideoPlayerPage
     * sends camelCase; the snake_case fallbacks were already there and are
     * ported rather than dropped.
     *
     * ┌── `''` NEEDS NO ConvertEmptyStringsToNull EXEMPTION HERE (4.1). ─────┐
     * │ Checked, not assumed. Both fields are NUMERIC, so there is no        │
     * │ clear-vs-absent semantic to protect: under the global middleware ''  │
     * │ becomes null, `?? 0` makes it 0, and the write is 0. In backend/,    │
     * │ '' arrived intact and `(int) round((float) '')` is likewise 0. The   │
     * │ two are identical on the wire, so `api/v1/lessons/*` is deliberately │
     * │ NOT added to AppServiceProvider::preserveEmptyStringsOnClearable-    │
     * │ Writes(). Pinned by test_an_empty_string_progress_payload_behaves_   │
     * │ exactly_as_a_zero.                                                    │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @param  array<string, mixed>  $data
     */
    private static function position(array $data): int
    {
        $raw = $data['lastPosition'] ?? $data['last_position'] ?? 0;

        return max(0, (int) round((float) $raw));
    }

    /** @param array<string, mixed> $data */
    private static function percentage(array $data): float
    {
        $raw = $data['watchedPercentage'] ?? $data['watched_percentage'] ?? 0;

        return max(0.0, min(100.0, (float) $raw));
    }
}
