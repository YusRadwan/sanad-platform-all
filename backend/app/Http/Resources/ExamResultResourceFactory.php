<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ONE PLACE THE ANSWER-KEY REVEAL DECISION IS MADE.                   ║
 * ║ Sibling of App\Http\Resources\ExamResourceFactory, same discipline.     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `exams.show_answers` was written by the instructor form and read by NOTHING
 * until backend/ wired it into resultsFor(). This — plus
 * App\Policies\ExamPolicy::viewAnswerKey(), which holds the rule — is where it
 * is enforced in backend-v2. DO NOT LET IT REGRESS TO A HIDDEN BUTTON.
 *
 * ┌── TWO CONDITIONS, AND THEY ARE NOT THE SAME CONDITION. ──────────────────┐
 * │  1. MAY this caller see the key?   -> ExamPolicy::viewAnswerKey          │
 * │  2. IS there a key to see?         -> the exam HAS a bank at all         │
 * │                                                                          │
 * │ Both must hold for the revealed shape, and backend/ says so             │
 * │ (`if ($reveal && $exam['question_group_id'])`). But `show_answers` in    │
 * │ the payload reports condition 1 ALONE — so an exam with the reveal on    │
 * │ and no bank answers `show_answers: true` with no `correct_answers` key.  │
 * │ Collapsing the two would misreport the instructor's setting.             │
 * │                                                                          │
 * │ NOTE the second condition is "has a bank", NOT "the bank has rows": a    │
 * │ bank that exists but is empty yields `correct_answers: []`, which is     │
 * │ what backend/'s `$reveal && $question_group_id` produces. `$questions`   │
 * │ is therefore `?Collection` and NOT a possibly-empty Collection — null    │
 * │ and [] mean different things on the wire.                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── THE QUERY IS THE **CONTROLLER'S**, NOT THIS CLASS'S. ──────────────────┐
 * │ The bank is read by ExamController and handed in. Two reasons, and the   │
 * │ second is the load-bearing one:                                          │
 * │   * a Resource that issues queries is a Resource whose cost is invisible │
 * │     at the call site;                                                    │
 * │   * this file then contains no `'question_group_id'` literal at all,     │
 * │     which keeps ExamPayloadSplitTest's grep — "exactly two Resource      │
 * │     classes may name the bank id" — a blunt, greppable invariant rather  │
 * │     than one with an explained exception.                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `Gate::forUser($user)->allows(...)`, never `$user?->can(...)` — open decision
 * 3.7. Row 91 carries `auth` so `$user` is never null in practice, but the
 * correct idiom is written anyway so that a later widening of the route cannot
 * fail CLOSED and unnoticed.
 */
final class ExamResultResourceFactory
{
    /** @param Collection<int, Question>|null $questions the exam's bank, or null if it has none */
    public static function for(
        ?User $user,
        Exam $exam,
        ExamAttempt $attempt,
        ?Collection $questions,
    ): JsonResource {
        $reveal = $user !== null && Gate::forUser($user)->allows('viewAnswerKey', $exam);

        if ($reveal && $questions !== null) {
            return new RevealedExamResultResource($attempt, $exam, $questions);
        }

        return new ExamResultResource($attempt, $exam, $reveal);
    }
}
