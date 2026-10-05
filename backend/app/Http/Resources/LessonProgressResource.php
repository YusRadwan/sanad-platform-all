<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Port of backend/LessonService::presentProgress(). Freeze-table rows 28-29.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE RESPONSE KEY IS `last_position_seconds`. THE COLUMN IS              ║
 * ║ `progress_seconds`. THE MISMATCH IS THE CONTRACT — DO NOT "FIX" IT.     ║
 * ║                                                                          ║
 * ║ VideoPlayerPage.tsx reads `progress.last_position_seconds` to seek the  ║
 * ║ <video> element back to where the student stopped. Renaming the key to  ║
 * ║ match the column would make every returning student restart every       ║
 * ║ lesson from zero, silently, with a 200 response and no error anywhere.  ║
 * ║ backend/LessonService says so at the mapping line; this is the port.    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THE NULL RESOURCE IS A FIRST-CLASS, EXPECTED INPUT. ───────────────────┐
 * │ `LessonProgressResource::forRow(null)` is the ZEROED record, and it is   │
 * │ returned in two entirely legitimate situations:                          │
 * │                                                                          │
 * │  1. a student who has never opened this lesson — no row yet;             │
 * │  2. an ADMIN or the OWNING INSTRUCTOR previewing their own lesson.       │
 * │     `lesson_progress.student_id` is an FK into `students.user_id`, so    │
 * │     they have no row and never can. backend/ answers a synthetic zeroed  │
 * │     record rather than an error, because VideoPlayerPage loads video +   │
 * │     progress together and an error would blank the whole page for the    │
 * │     instructor reviewing their own material.                             │
 * │                                                                          │
 * │ Both answer the same shape on purpose: the client cannot tell "not       │
 * │ started" from "not applicable", and does not need to.                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `last_watched_at` is 'Y-m-d H:i:s' for the same reason every other datetime
 * in this codebase is — backend/ shipped the raw MySQL string.
 *
 * @mixin LessonProgress
 */
final class LessonProgressResource extends JsonResource
{
    /**
     * ALWAYS construct through this, never `new LessonProgressResource($row)`.
     *
     * JsonResource::__construct accepts null and then serialises to `null`
     * wholesale — which would ship `progress: null` where the contract owes
     * `progress: {last_position_seconds: 0, completed: false,
     * last_watched_at: null}`. This factory keeps the zeroed shape the only
     * representable answer for a missing row.
     */
    public static function forRow(?LessonProgress $row): self
    {
        return new self($row);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        if (!$row instanceof LessonProgress) {
            return [
                'last_position_seconds' => 0,
                'completed' => false,
                'last_watched_at' => null,
            ];
        }

        return [
            // The column is progress_seconds. See the class banner.
            'last_position_seconds' => (int) $row->getAttribute('progress_seconds'),
            'completed' => (bool) $row->getAttribute('completed'),
            'last_watched_at' => $row->getAttribute('last_watched_at')?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * JsonResource short-circuits a null resource to `null` before toArray()
     * is ever reached. Overriding resolve() is what routes the null case into
     * toArray() above, so the zeroed shape is produced by ONE code path rather
     * than two that could drift.
     */
    public function resolve($request = null): array
    {
        return $this->toArray($request ?? request());
    }
}
