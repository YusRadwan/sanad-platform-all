<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\QuestionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Port of backend/QuestionBankService::presentGroup().
 *
 * ┌── THERE IS NO PUBLIC TWIN, AND NONE IS NEEDED. ──────────────────────────┐
 * │ All four group routes (freeze-table rows 80-83) are `auth` +             │
 * │ `role:admin,instructor` AND ownership-scoped on top, so every caller     │
 * │ that reaches this class either owns the row or is an admin. That is the  │
 * │ same argument Stage 5 / Session B made for shipping only                 │
 * │ ContentItemResource — and unlike that case, it does not expire: rows     │
 * │ 80-83 are admin/instructor in the FINAL contract too, exactly like row   │
 * │ 78. There is no storefront surface for a question bank.                  │
 * │                                                                          │
 * │ `teacher_id` therefore ships, as backend/ shipped it: to its owner it is │
 * │ their own id, and to an admin it is the column they filter the console   │
 * │ by. It is emitted for NOBODY else, because nobody else can reach a       │
 * │ handler that builds this class.                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `created_at` is 'Y-m-d H:i:s' — the raw MySQL DATETIME string backend/ shipped
 * — not ISO-8601. Same ported decision, same reason, as TeacherResource and
 * ContentItemResource: Laravel's default cast serialisation would emit
 * '2026-01-01T10:00:00.000000Z' and drag the value through a UTC conversion the
 * legacy payload never applied.
 *
 * `grade` is a free-text VARCHAR, NOT a foreign key to `grades` (migration
 * 000019 says so explicitly — it mirrors the label the teacher types in the
 * bank's filter, exactly like `teachers.grades` stores grade NAME strings). It
 * therefore ships as whatever string is stored, `''` included; see
 * App\Http\Requests\QuestionGroup\UpdateQuestionGroupRequest for the
 * empty-string semantics that make `''` reachable at all.
 *
 * @mixin QuestionGroup
 */
final class QuestionGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var QuestionGroup $group */
        $group = $this->resource;

        return [
            'id' => $group->getKey(),
            'teacher_id' => $group->getAttribute('teacher_id'),
            'title' => $group->getAttribute('title'),
            'grade' => $group->getAttribute('grade'),
            'created_at' => $group->getAttribute('created_at')?->format('Y-m-d H:i:s'),
        ];
    }
}
