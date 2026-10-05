<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\Course;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Rows 90 and 92 over HTTP — the paper, and recording an attempt.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS FILE DOES **NOT** PROVE THE LOCKING. IT CANNOT.                     ║
 * ║                                                                          ║
 * ║ Laravel's test client runs in-process and single-threaded, so two        ║
 * ║ "concurrent" submissions here are simply sequential and would pass       ║
 * ║ against a completely unlocked implementation. The race proof is          ║
 * ║ tests/Concurrency/exam_attempt_race.php — 8 real OS processes, each with ║
 * ║ its own database connection, plus an unlocked CONTROL that must BREACH   ║
 * ║ the cap. Anything in this file about attempt counts is a SEQUENTIAL      ║
 * ║ claim only, and is labelled as such.                                     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ExamAttemptTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private Course $course;

    private Section $section;

    private QuestionGroup $bank;

    private Exam $exam;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course);
        $this->bank = $this->makeQuestionGroup($this->owner);

        $this->makeQuestion($this->bank, ['body' => '٢+٢', 'correct_answer' => '4', 'points' => 1]);
        $this->makeQuestion($this->bank, ['body' => 'عاصمة فرنسا', 'correct_answer' => 'Paris', 'points' => 1]);

        [, $this->exam] = $this->makeExam($this->section, [
            'question_group_id' => $this->bank->getKey(),
            'pass_score' => 50,
            'allowed_attempts' => 2,
        ]);

        $this->student = $this->makeStudentUser();
        $this->makeSubscription($this->student, $this->course);
    }

    /** @return list<array{question_id: string, answer: string}> */
    private function answers(string $first = '4', string $second = 'Paris'): array
    {
        $ids = Question::query()->where('question_group_id', $this->bank->getKey())->orderBy('id')->pluck('id')->all();

        return [
            ['question_id' => (string) $ids[0], 'answer' => $first],
            ['question_id' => (string) $ids[1], 'answer' => $second],
        ];
    }

    private function submit(array $answers): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/exams/{$this->exam->getKey()}/submit", ['answers' => $answers]);
    }

    // ══ ROW 90 — THE PAPER ═══════════════════════════════════════════════

    public function test_a_subscribed_student_gets_the_paper_without_the_answer_key(): void
    {
        $this->loginAs($this->student);

        $response = $this->getJson("/api/v1/exams/{$this->exam->getKey()}/questions")->assertStatus(200);

        $questions = $response->json('data.questions');
        $this->assertCount(2, $questions);

        // The raw JSON, not a falsy check: an absent key and a null one are
        // different contracts and only the string test can tell them apart.
        $this->assertStringNotContainsString('correct_answer', $response->getContent());
        $this->assertStringNotContainsString('question_group_id', $response->getContent());
    }

    /**
     * COURSE STAFF DO GET THE KEY on row 90 — this route is the instructor's
     * read-only preview and a preview that hides the answers is useless.
     * `show_answers` is irrelevant here; it gates the RESULTS reveal.
     */
    public function test_the_owner_gets_the_paper_with_the_answer_key(): void
    {
        $this->loginAs($this->owner);

        $response = $this->getJson("/api/v1/exams/{$this->exam->getKey()}/questions")->assertStatus(200);

        $this->assertStringContainsString('correct_answer', $response->getContent());
        $this->assertSame('4', $response->json('data.questions.0.correct_answer'));
    }

    /** …and the toggle being OFF does not turn the staff preview into a blind one. */
    public function test_show_answers_off_does_not_hide_the_key_from_staff_on_the_paper(): void
    {
        [, $exam] = $this->makeExam($this->section, [
            'question_group_id' => $this->bank->getKey(),
            'show_answers' => false,
        ]);

        $this->loginAs($this->owner);

        $this->getJson("/api/v1/exams/{$exam->getKey()}/questions")
            ->assertStatus(200)
            ->assertJsonPath('data.questions.0.correct_answer', '4');
    }

    public function test_a_foreign_instructor_and_an_unsubscribed_student_get_the_two_ported_strings(): void
    {
        [$other] = $this->makeTeacherWithProfile();
        $this->loginAs($other);
        $this->getJson("/api/v1/exams/{$this->exam->getKey()}/questions")
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not own this exam');

        $this->startNextRequest();
        $stranger = $this->makeStudentUser();
        $this->loginAs($stranger);
        $this->getJson("/api/v1/exams/{$this->exam->getKey()}/questions")
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are not enrolled in this course');
    }

    /** A detached exam (`question_group_id` NULL) is a normal state, not a 404. */
    public function test_an_exam_with_no_bank_returns_an_empty_paper(): void
    {
        [, $exam] = $this->makeExam($this->section);

        $this->loginAs($this->student);

        $this->getJson("/api/v1/exams/{$exam->getKey()}/questions")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.questions');
    }

    // ══ ROW 92 — SUBMIT ══════════════════════════════════════════════════

    public function test_a_student_submits_and_the_row_is_written_with_the_computed_score(): void
    {
        $this->loginAs($this->student);

        $response = $this->submit($this->answers())
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم تسليم الامتحان بنجاح')
            ->assertJsonPath('data.passed', true);

        // Numeric comparisons go through an explicit float cast: PHP's
        // json_encode renders the float 100.0 as `100`, so assertJsonPath
        // against 100.0 would be comparing a float to a decoded int. The VALUE
        // is the contract, not its JSON spelling — and that spelling is
        // byte-identical to what backend/ shipped, which is the point.
        $this->assertSame(100.0, (float) $response->json('data.score'));
        $this->assertSame(2.0, (float) $response->json('data.earned_points'));
        $this->assertSame(2.0, (float) $response->json('data.total_points'));

        $row = DB::table('exam_attempts')->where('id', $response->json('data.attempt_id'))->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->attempt_number);
        $this->assertSame('100.00', (string) $row->score);
        $this->assertSame((string) $this->student->getKey(), (string) $row->student_id);
        $this->assertNotNull($row->submitted_at);
    }

    public function test_the_ordinal_increments_across_sequential_attempts(): void
    {
        $this->loginAs($this->student);

        $this->submit($this->answers())->assertStatus(200);
        $this->submit($this->answers('wrong', 'wrong'))->assertStatus(200);

        $this->assertSame(
            [1, 2],
            DB::table('exam_attempts')->orderBy('attempt_number')->pluck('attempt_number')->map(intval(...))->all()
        );
    }

    /**
     * SEQUENTIAL claim only — see the class banner. The genuine race proof is
     * tests/Concurrency/exam_attempt_race.php.
     */
    public function test_the_cap_rejects_the_third_sequential_attempt_with_the_contract_string(): void
    {
        $this->loginAs($this->student);

        $this->submit($this->answers())->assertStatus(200);
        $this->submit($this->answers())->assertStatus(200);

        $this->submit($this->answers())
            ->assertStatus(422)
            ->assertJsonPath(
                'data.errors.attempts.0',
                'لقد استنفدت عدد المحاولات المسموح بها لهذا الامتحان'
            );

        $this->assertSame(2, DB::table('exam_attempts')->count());
    }

    /** NULL / 0 allowed_attempts means UNLIMITED, matching backend/ exactly. */
    public function test_a_null_cap_means_unlimited(): void
    {
        [, $exam] = $this->makeExam($this->section, ['question_group_id' => $this->bank->getKey()]);

        $this->loginAs($this->student);

        foreach (range(1, 3) as $ignored) {
            $this->postJson("/api/v1/exams/{$exam->getKey()}/submit", ['answers' => []])->assertStatus(200);
        }

        $this->assertSame(3, DB::table('exam_attempts')->where('exam_id', $exam->getKey())->count());
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ MEASURED, NOT ASSUMED: `allowed_attempts = 0` IS **UNREACHABLE** —    ║
     * ║ `chk_exams_scores` requires `allowed_attempts IS NULL OR >= 1`.       ║
     * ║                                                                      ║
     * ║ So the Action's `(int) $allowed <= 0 -> unlimited` branch is DEAD     ║
     * ║ CODE against this schema, and it is kept anyway for two reasons: it   ║
     * ║ is backend/'s literal guard (ported, not invented), and the           ║
     * ║ alternative reading — "0 means zero attempts allowed" — would turn a  ║
     * ║ future migration that relaxes the CHECK into a silent lock-out of     ║
     * ║ every student. NULL is the only expressible "unlimited".              ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_zero_cap_cannot_even_be_stored(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->makeExam($this->section, [
            'question_group_id' => $this->bank->getKey(),
            'allowed_attempts' => 0,
        ]);
    }

    // ══ THE Gate::before ESCAPE HATCH ════════════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ AN ADMIN AND THE OWNING INSTRUCTOR GET **403 WITH THE LITERAL        ║
     * ║ STRING** — NOT A 500 FK VIOLATION, AND NOT A SILENT 201.             ║
     * ║                                                                      ║
     * ║ Both pass course access legitimately (the admin via `Gate::before`,   ║
     * ║ the owner on the merits), so both reach the write layer fully         ║
     * ║ authorised. `exam_attempts.student_id` is an FK into                  ║
     * ║ `students.user_id` and neither has such a row, so without the         ║
     * ║ Action's first statement this is a raw 500.                           ║
     * ║                                                                      ║
     * ║ THE TABLE IS COUNTED TOO: a 403 response and an unwritten table are   ║
     * ║ two different claims, and only the second rules out a partial write.  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_an_admin_is_403_on_submit_with_the_contract_string_and_writes_nothing(): void
    {
        $this->loginAs($this->makeAdmin());

        $this->submit($this->answers())
            ->assertStatus(403)
            ->assertJsonPath('message', 'Only an enrolled student may submit an exam attempt');

        $this->assertSame(0, DB::table('exam_attempts')->count());
    }

    public function test_the_owning_instructor_is_403_on_submit_and_writes_nothing(): void
    {
        $this->loginAs($this->owner);

        $this->submit($this->answers())
            ->assertStatus(403)
            ->assertJsonPath('message', 'Only an enrolled student may submit an exam attempt');

        $this->assertSame(0, DB::table('exam_attempts')->count());
    }

    /**
     * ORDER MATTERS: an UNSUBSCRIBED student is refused by COURSE ACCESS, not
     * by the student check, and gets the other string. The two gates are
     * distinct questions and the messages prove they are asked separately.
     */
    public function test_an_unsubscribed_student_is_refused_by_course_access_first(): void
    {
        $this->loginAs($this->makeStudentUser());

        $this->submit($this->answers())
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are not enrolled in this course');
    }

    // ══ SCORING, END TO END ══════════════════════════════════════════════

    public function test_the_null_key_denominator_quirk_survives_over_http(): void
    {
        $bank = $this->makeQuestionGroup($this->owner);
        $good = $this->makeQuestion($bank, ['correct_answer' => 'a', 'points' => 1]);
        $this->makeQuestion($bank, ['correct_answer' => null, 'points' => 1]);

        [, $exam] = $this->makeExam($this->section, ['question_group_id' => $bank->getKey()]);

        $this->loginAs($this->student);

        // A PERFECT paper — every answerable question right — still scores 50,
        // because the blank key's points stayed in the denominator.
        $perfect = $this->postJson("/api/v1/exams/{$exam->getKey()}/submit", [
            'answers' => [['question_id' => (string) $good->getKey(), 'answer' => 'a']],
        ])->assertStatus(200);

        $this->assertSame(50.0, (float) $perfect->json('data.score'));
        $this->assertSame(2.0, (float) $perfect->json('data.total_points'));
    }

    public function test_an_exam_with_no_questions_scores_zero_over_http(): void
    {
        [, $exam] = $this->makeExam($this->section);

        $this->loginAs($this->student);

        $response = $this->postJson("/api/v1/exams/{$exam->getKey()}/submit", ['answers' => []])
            ->assertStatus(200);

        $this->assertSame(0.0, (float) $response->json('data.score'));
        $this->assertSame(0.0, (float) $response->json('data.total_points'));
    }

    /**
     * `passed` IS TRI-STATE. No `pass_score` means the exam has not been passed
     * OR failed, and a client rendering "راسب" for false must not render it for
     * "no mark set".
     */
    public function test_passed_is_null_when_the_exam_sets_no_pass_score(): void
    {
        [, $exam] = $this->makeExam($this->section, ['question_group_id' => $this->bank->getKey()]);

        $this->loginAs($this->student);

        $this->postJson("/api/v1/exams/{$exam->getKey()}/submit", ['answers' => []])
            ->assertStatus(200)
            ->assertJsonPath('data.passed', null);
    }

    /**
     * A NON-ARRAY `answers` IS NOT A 422 — ported. backend/ foreach'es it,
     * warns, and scores nothing; the outcome is a zero, not a rejection.
     */
    public function test_a_non_array_answers_payload_scores_zero_rather_than_422(): void
    {
        $this->loginAs($this->student);

        $response = $this->postJson("/api/v1/exams/{$this->exam->getKey()}/submit", ['answers' => 'nonsense'])
            ->assertStatus(200);

        $this->assertSame(0.0, (float) $response->json('data.score'));
    }

    public function test_the_answers_payload_is_stored_verbatim_including_arabic(): void
    {
        $this->loginAs($this->student);

        $answers = [['question_id' => 'x', 'answer' => 'إجابة عربية']];

        $id = $this->postJson("/api/v1/exams/{$this->exam->getKey()}/submit", ['answers' => $answers])
            ->assertStatus(200)
            ->json('data.attempt_id');

        $stored = (string) DB::table('exam_attempts')->where('id', $id)->value('answers');

        // assertEquals, not assertSame: MySQL's JSON column type NORMALISES
        // object key order (shortest key first), so the round trip is
        // value-identical but not byte-identical. The values are the contract.
        $this->assertEquals($answers, json_decode($stored, true));
        $this->assertSame('إجابة عربية', json_decode($stored, true)[0]['answer']);
    }

    // ══ THE DATABASE BACKSTOP ════════════════════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ LAYER 3, TESTED WITHOUT THE APPLICATION: a duplicate ordinal is       ║
     * ║ rejected by `uq_exam_attempts_ordinal` ITSELF.                        ║
     * ║                                                                      ║
     * ║ Raw SQL on purpose — this asserts a property of the SCHEMA, which is  ║
     * ║ the one layer a future code path that forgets the row lock cannot     ║
     * ║ bypass. `assignment_submissions` has no equivalent (open decision     ║
     * ║ 6.11), which is exactly why this test exists on only one family.      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_duplicate_attempt_ordinal_is_rejected_by_the_database_alone(): void
    {
        $row = [
            'exam_id' => $this->exam->getKey(),
            'student_id' => $this->student->getKey(),
            'attempt_number' => 1,
            'answers' => '[]',
            'score' => 10,
            'started_at' => now(),
            'submitted_at' => now(),
        ];

        DB::table('exam_attempts')->insert($row + ['id' => (string) \Illuminate\Support\Str::uuid7()]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('exam_attempts')->insert($row + ['id' => (string) \Illuminate\Support\Str::uuid7()]);
    }

    /** …and the CHECK constraint pins the percentage range independently. */
    public function test_a_score_outside_zero_to_one_hundred_is_rejected_by_the_check_constraint(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('exam_attempts')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'exam_id' => $this->exam->getKey(),
            'student_id' => $this->student->getKey(),
            'attempt_number' => 9,
            'answers' => '[]',
            'score' => 101,
            'started_at' => now(),
            'submitted_at' => now(),
        ]);
    }
}
