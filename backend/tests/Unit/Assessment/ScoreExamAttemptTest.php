<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Actions\Exam\ScoreExamAttempt;
use App\Models\Question;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Every documented quirk of the PORTED grading algorithm, pinned.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THESE TESTS EXIST TO MAKE "IMPROVING" THE ALGORITHM A **DELIBERATE**    ║
 * ║ ACT. `exam_attempts.score` is a percentage already recorded against     ║
 * ║ historical rows; changing how the denominator is computed changes what  ║
 * ║ yesterday's 80% meant, silently and irreversibly.                        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * A PLAIN PHPUnit TestCase, not Tests\TestCase: ScoreExamAttempt is a pure
 * function and needs no application, no database and no container. The models
 * are hydrated in memory with forceFill and never saved — which is also a
 * proof in itself that the scorer performs no query.
 */
final class ScoreExamAttemptTest extends TestCase
{
    /** @param array<string, mixed> $attributes */
    private function question(string $id, ?string $correct, float $points = 1.0, array $attributes = []): Question
    {
        $question = new Question();

        $question->forceFill(array_merge([
            'id' => $id,
            'body' => 'س',
            'correct_answer' => $correct,
            'points' => $points,
        ], $attributes));

        return $question;
    }

    /** @param list<Question> $questions */
    private function score(array $questions, array $answers): ScoreExamAttempt
    {
        return ScoreExamAttempt::for(new Collection($questions), $answers);
    }

    // ── THE ARITHMETIC ────────────────────────────────────────────────────

    public function test_all_correct_is_one_hundred(): void
    {
        $result = $this->score(
            [$this->question('q1', 'a'), $this->question('q2', 'b')],
            [['question_id' => 'q1', 'answer' => 'a'], ['question_id' => 'q2', 'answer' => 'b']]
        );

        $this->assertSame(100.0, $result->score);
        $this->assertSame(2.0, $result->earnedPoints);
        $this->assertSame(2.0, $result->totalPoints);
    }

    public function test_all_wrong_is_zero(): void
    {
        $result = $this->score(
            [$this->question('q1', 'a'), $this->question('q2', 'b')],
            [['question_id' => 'q1', 'answer' => 'z'], ['question_id' => 'q2', 'answer' => 'z']]
        );

        $this->assertSame(0.0, $result->score);
        $this->assertSame(2.0, $result->totalPoints);
    }

    public function test_half_correct_is_fifty(): void
    {
        $result = $this->score(
            [$this->question('q1', 'a'), $this->question('q2', 'b')],
            [['question_id' => 'q1', 'answer' => 'a'], ['question_id' => 'q2', 'answer' => 'z']]
        );

        $this->assertSame(50.0, $result->score);
    }

    /** Weighting is by POINTS, not by question count. */
    public function test_points_weight_the_percentage(): void
    {
        $result = $this->score(
            [$this->question('q1', 'a', 3.0), $this->question('q2', 'b', 1.0)],
            [['question_id' => 'q1', 'answer' => 'a']]
        );

        $this->assertSame(75.0, $result->score);
    }

    /** Rounded to 2dp, which is what chk_exam_attempts_score is expressed for. */
    public function test_the_percentage_is_rounded_to_two_decimals(): void
    {
        $result = $this->score(
            [$this->question('q1', 'a'), $this->question('q2', 'b'), $this->question('q3', 'c')],
            [['question_id' => 'q1', 'answer' => 'a']]
        );

        $this->assertSame(33.33, $result->score);
    }

    // ── MATCHING ──────────────────────────────────────────────────────────

    public function test_the_match_is_case_insensitive(): void
    {
        $result = $this->score(
            [$this->question('q1', 'Paris')],
            [['question_id' => 'q1', 'answer' => 'pARIS']]
        );

        $this->assertSame(100.0, $result->score);
    }

    public function test_the_match_is_whitespace_trimmed(): void
    {
        $result = $this->score(
            [$this->question('q1', '  4 ')],
            [['question_id' => 'q1', 'answer' => "4\t"]]
        );

        $this->assertSame(100.0, $result->score);
    }

    /**
     * NO TYPE-AWARENESS. mcq / true_false / short_answer are all scored by the
     * same string comparison — backend/ has no branch on `questions.type` at
     * all, and adding one would silently change what a historical score meant.
     */
    public function test_every_question_type_is_scored_identically(): void
    {
        foreach (['mcq', 'true_false', 'short_answer'] as $type) {
            $result = $this->score(
                [$this->question('q1', 'TRUE', 1.0, ['type' => $type])],
                [['question_id' => 'q1', 'answer' => 'true']]
            );

            $this->assertSame(100.0, $result->score, "type {$type} must score like every other type");
        }
    }

    // ── THE FOUR PORTED QUIRKS ────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ QUIRK 2 — A `correct_answer = null` QUESTION AWARDS ZERO TO EVERYONE  ║
     * ║ **AND STILL COUNTS TOWARD THE DENOMINATOR**, LOWERING EVERY SCORE.   ║
     * ║                                                                      ║
     * ║ This is real current behaviour, not a bug being ported by accident:   ║
     * ║ the `!== null` guard sits on the EARNING branch, and the totalling    ║
     * ║ loop has no guard at all. An instructor who leaves a key blank caps   ║
     * ║ the whole class below 100.                                            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_null_key_scores_nothing_yet_still_raises_the_denominator(): void
    {
        $result = $this->score(
            [$this->question('q1', 'a'), $this->question('q2', null)],
            [['question_id' => 'q1', 'answer' => 'a'], ['question_id' => 'q2', 'answer' => 'anything']]
        );

        $this->assertSame(2.0, $result->totalPoints, 'the null-key question still counts in the total');
        $this->assertSame(1.0, $result->earnedPoints);
        $this->assertSame(50.0, $result->score, 'a perfect paper is capped at 50 by one blank key');
    }

    /** QUIRK 3 — an answer for a foreign question id is silently ignored. */
    public function test_an_answer_for_a_foreign_question_id_is_ignored_not_rejected(): void
    {
        $result = $this->score(
            [$this->question('q1', 'a')],
            [
                ['question_id' => 'not-in-this-exam', 'answer' => 'a'],
                ['question_id' => 'q1', 'answer' => 'a'],
            ]
        );

        $this->assertSame(100.0, $result->score);
        $this->assertSame(1.0, $result->totalPoints, 'the foreign id adds nothing to the denominator');
    }

    /** QUIRK 4 — both key spellings, in both pairs. */
    public function test_both_key_alias_pairs_are_accepted(): void
    {
        $snake = $this->score(
            [$this->question('q1', 'a')],
            [['question_id' => 'q1', 'answer' => 'a']]
        );
        $camel = $this->score(
            [$this->question('q1', 'a')],
            [['questionId' => 'q1', 'value' => 'a']]
        );

        $this->assertSame(100.0, $snake->score);
        $this->assertSame(100.0, $camel->score);
    }

    public function test_snake_case_wins_when_both_spellings_are_present(): void
    {
        $result = $this->score(
            [$this->question('q1', 'a')],
            [['question_id' => 'q1', 'questionId' => 'other', 'answer' => 'a', 'value' => 'z']]
        );

        $this->assertSame(100.0, $result->score);
    }

    // ── THE DIVISION GUARD ────────────────────────────────────────────────

    /**
     * ZERO QUESTIONS -> 0.00, NOT NAN AND NOT AN ERROR.
     *
     * `0.0 / 0.0` is NAN in PHP (DivisionByZeroError is the INTEGER path), NAN
     * survives round(), and inserting it into DECIMAL(6,2) is a 1264 or a
     * silent 0.00 depending on strict mode. The explicit ternary is what makes
     * this an answer rather than a coincidence.
     */
    public function test_an_exam_with_no_questions_scores_zero_with_no_division_error(): void
    {
        $result = $this->score([], [['question_id' => 'q1', 'answer' => 'a']]);

        $this->assertSame(0.0, $result->score);
        $this->assertFalse(is_nan($result->score));
        $this->assertSame(0.0, $result->totalPoints);
    }

    /** …and the same for questions that all carry zero points. */
    public function test_questions_worth_zero_points_score_zero_with_no_division_error(): void
    {
        $result = $this->score(
            [$this->question('q1', 'a', 0.0)],
            [['question_id' => 'q1', 'answer' => 'a']]
        );

        $this->assertSame(0.0, $result->score);
        $this->assertFalse(is_nan($result->score));
    }

    public function test_an_empty_answers_payload_scores_zero(): void
    {
        $result = $this->score([$this->question('q1', 'a')], []);

        $this->assertSame(0.0, $result->score);
        $this->assertSame(1.0, $result->totalPoints);
    }

    /** A malformed entry cannot crash the scorer or earn a mark. */
    public function test_malformed_answer_entries_are_skipped(): void
    {
        $result = $this->score(
            [$this->question('q1', 'a')],
            ['a string', 42, null, ['no_id_at_all' => 'x'], ['question_id' => ['array'], 'answer' => 'a']]
        );

        $this->assertSame(0.0, $result->score);
    }
}
