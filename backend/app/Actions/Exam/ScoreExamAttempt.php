<?php

declare(strict_types=1);

namespace App\Actions\Exam;

use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE GRADING ALGORITHM, PORTED **VERBATIM** FROM                         ║
 * ║ backend/ExamService::submit() lines ~136-153. IT IS DELIBERATELY NOT    ║
 * ║ IMPROVED, AND EVERY ONE OF ITS QUIRKS IS PINNED BY A TEST.              ║
 * ║                                                                          ║
 * ║ "Improving" grading logic is not a refactor: `exam_attempts.score` is a  ║
 * ║ PERCENTAGE already recorded against thousands of historical rows. Change ║
 * ║ how the denominator is computed and yesterday's 80% and tomorrow's 80%   ║
 * ║ stop meaning the same thing, silently, with no migration that could      ║
 * ║ restate the old rows because the raw answers are all that survive.       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── A PURE FUNCTION. NO DATABASE WRITE, NO CLOCK, NO REQUEST. ─────────────┐
 * │ That is a LOCKING requirement, not a style preference. This runs BEFORE  │
 * │ App\Actions\Exam\SubmitExamAttempt opens its transaction, so the `exams` │
 * │ row is held for exactly the lock+count+insert and not for one instant    │
 * │ longer. backend/'s own comment makes the same argument. Moving any of    │
 * │ this inside the critical section lengthens the window during which every │
 * │ other student submitting the SAME exam is blocked.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE FOUR PORTED QUIRKS — all real current behaviour, none of them bugs to
 * fix in a rewrite:
 *
 *  1. COMPARISON IS PLAIN, CASE-INSENSITIVE, TRIMMED STRING EQUALITY, and it
 *     is applied identically to `mcq`, `true_false` and `short_answer`. There
 *     is no type-awareness and no partial credit anywhere in backend/.
 *
 *  2. A QUESTION WITH `correct_answer = null` SCORES ZERO FOR EVERYONE — but
 *     its `points` STILL COUNT TOWARD THE DENOMINATOR, which LOWERS every
 *     student's percentage. The `!== null` guard sits on the earning branch
 *     only; the totalling loop has no such guard. Preserved exactly.
 *
 *  3. AN ANSWER FOR A `question_id` THAT IS NOT IN THIS EXAM'S BANK IS
 *     SILENTLY IGNORED — `continue`, never an error. A client that posts a
 *     stale paper gets a score, not a 422.
 *
 *  4. BOTH KEY SPELLINGS ARE ACCEPTED on every entry:
 *       question_id | questionId      and      answer | value
 *     snake_case first in both pairs, exactly as backend/'s `??` chain ordered
 *     them.
 *
 * ┌── `total_points === 0` IS AN EXPLICIT GUARD, NOT A HOPE ABOUT FLOATS. ───┐
 * │ An exam with no question group, an empty bank, or a bank whose every     │
 * │ question is worth 0 all reach the division. `0.0 / 0.0` is NAN in PHP    │
 * │ (not a DivisionByZeroError — that is integer intdiv/%), NAN would pass   │
 * │ straight through round(), and inserting NAN into DECIMAL(6,2) is either  │
 * │ a 1264 or a silent 0.00 depending on strict mode. The ternary below is   │
 * │ what makes the answer 0.0 by decision rather than by MySQL's mood.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The result is a percentage rounded to 2dp, which is what
 * `chk_exam_attempts_score` (0..100) is expressed against. It cannot exceed
 * 100 by construction — `earned` only ever accumulates points already counted
 * into `total` — but the CHECK is the belt to that braces.
 */
final class ScoreExamAttempt
{
    private function __construct(
        public readonly float $score,
        public readonly float $earnedPoints,
        public readonly float $totalPoints,
    ) {
    }

    /**
     * @param  Collection<int, Question>  $questions  this exam's bank, already read
     * @param  array<int|string, mixed>   $answers    the submitted payload, untrusted
     */
    public static function for(Collection $questions, array $answers): self
    {
        /** @var array<string, Question> $byId */
        $byId = [];
        $totalPoints = 0.0;

        foreach ($questions as $question) {
            $byId[(string) $question->getKey()] = $question;
            $totalPoints += (float) $question->getAttribute('points');
        }

        $earned = 0.0;

        foreach ($answers as $answer) {
            // backend/ indexes straight into $answer. A non-array entry there
            // is a PHP warning and a garbage read rather than a rejection, so
            // skipping is the only difference — and it cannot change the
            // outcome of any request that previously succeeded.
            if (!is_array($answer)) {
                continue;
            }

            $questionId = $answer['question_id'] ?? $answer['questionId'] ?? null;

            // QUIRK 3 — an id this exam does not know is not an error.
            if ($questionId === null || !is_scalar($questionId) || !isset($byId[(string) $questionId])) {
                continue;
            }

            $question = $byId[(string) $questionId];
            $correct = $question->getAttribute('correct_answer');

            // QUIRK 2 — a null key earns nothing, yet its points were already
            // added to $totalPoints above.
            if ($correct === null) {
                continue;
            }

            $given = $answer['answer'] ?? $answer['value'] ?? '';
            $given = is_scalar($given) ? (string) $given : '';

            // QUIRK 1 — trim(mb_strtolower(...)) on both sides. mb_strtolower
            // first, then trim, in backend/'s own order.
            if (trim(mb_strtolower($given)) === trim(mb_strtolower((string) $correct))) {
                $earned += (float) $question->getAttribute('points');
            }
        }

        return new self(
            $totalPoints > 0 ? round(($earned / $totalPoints) * 100, 2) : 0.0,
            $earned,
            $totalPoints,
        );
    }
}
