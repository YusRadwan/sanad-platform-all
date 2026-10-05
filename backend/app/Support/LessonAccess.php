<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ContentItemType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The refusal shapes of the /lessons family — freeze-table rows 27-29.
 *
 * Port of backend/LessonService::requireVideoItem() (~132-139) and
 * ::assertCanAccess() (~142-172). CourseAccess and ContentItemAccess are the
 * same idea for their own route families; this one exists because the lesson
 * routes have their own two literal strings and their own 404-for-wrong-TYPE
 * rule, which neither sibling expresses.
 */
final class LessonAccess
{
    /** backend/'s literal strings. Both are part of the API contract. */
    public const LESSON_NOT_FOUND = 'Lesson not found';

    public const NO_ACCESS = 'You do not have access to this lesson';

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ "LESSON" IS NOT A TABLE. IT IS A `content_items` ROW WHOSE `type`    ║
     * ║ IS `video`. THERE IS NO `lessons` TABLE ANYWHERE IN THIS SCHEMA.     ║
     * ║                                                                      ║
     * ║ VideoPlayerPage.tsx receives the content-item id as `:videoId` and   ║
     * ║ passes it straight through, which is why the URL vocabulary and the  ║
     * ║ storage vocabulary disagree. Renaming either would break a live      ║
     * ║ route.                                                               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── A NON-VIDEO ID IS **404**, NOT 422 AND NOT 403. ───────────────────┐
     * │ backend/requireVideoItem() collapses "no such row" and "that row is  │
     * │ a note" into ONE refusal: `!$item || $item['type'] !== 'video'` ->   │
     * │ NotFoundException('Lesson not found'). That is not laziness, it is   │
     * │ the correct answer twice over:                                       │
     * │                                                                      │
     * │  * Semantically, "lesson" MEANS a video item. An id that names a     │
     * │    note does not name a lesson, so the lesson genuinely does not     │
     * │    exist. 422 would be claiming the request is malformed when it is  │
     * │    perfectly well-formed and simply about something else.            │
     * │                                                                      │
     * │  * Defensively, 422 'not a video' would be a TYPE ORACLE: probe an   │
     * │    id, learn from the status code whether it names an exam or a      │
     * │    video, all without authorization. 404 answers identically for a   │
     * │    wrong guess and for a real note.                                  │
     * │                                                                      │
     * │ Note the ORDER: the type check runs BEFORE any access check, so it   │
     * │ discloses nothing about entitlement either.                          │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public static function findVideoOrFail(?string $id): ContentItem
    {
        $item = ContentItem::query()->whereKey((string) $id)->first();

        if ($item === null || $item->getAttribute('type') !== ContentItemType::Video) {
            throw new NotFoundException(self::LESSON_NOT_FOUND);
        }

        return $item;
    }

    /**
     * The playback gate — 403 'You do not have access to this lesson'.
     *
     * FAIL-CLOSED: anything that cannot be positively proven is rejected. The
     * four ways through are enumerated on ContentItemPolicy::watch(), which is
     * the authority; this method only turns its verdict into the exact status
     * and literal string the freeze table pins.
     *
     * `Gate::denies` and NOT a hand-written comparison, so Stage 3's admin
     * bypass stays in play — backend/assertCanAccess()'s first branch after the
     * empty-identity guard is `if ($role === 'admin') return;`, and that branch
     * is now Gate::before's, expressed once for the whole application.
     *
     * `Gate::denies` and NOT `authorize()`: authorize() raises
     * AuthorizationException, which App\Exceptions\Handler flattens to the
     * generic 'Forbidden' — the right status with the wrong body. Only an
     * exception implementing App\Exceptions\PublicMessage survives, and
     * ForbiddenException is that. Same argument as CourseAccess.
     *
     * THE ORPHANED-COURSE RUNG comes first, as in ContentItemAccess: a
     * soft-deleted course leaves its content items in place, and backend/
     * answered 404 'Course not found' for that state rather than letting the
     * ownership comparison decide.
     */
    public static function assertWatchable(User $user, ContentItem $lesson): void
    {
        if ($lesson->course === null) {
            throw new NotFoundException(CourseAccess::COURSE_NOT_FOUND);
        }

        if (Gate::denies('watch', $lesson)) {
            throw new ForbiddenException(self::NO_ACCESS);
        }
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
