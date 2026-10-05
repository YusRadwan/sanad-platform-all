<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\QuestionGroup;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Row 91 — `GET /exams/{examId}/results`.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ TWO INDEPENDENT THINGS ARE PINNED HERE, AND CONFLATING THEM IS THE      ║
 * ║ CLASSIC BUG ON THIS ROUTE:                                              ║
 * ║                                                                          ║
 * ║  1. WHOSE results may you ask for — `?student_id=`, 403 for a student   ║
 * ║     naming a classmate, allowed for course staff.                        ║
 * ║  2. WHETHER the answer key ships — `exams.show_answers`, whose ONLY      ║
 * ║     enforcement point in the whole application is                        ║
 * ║     App\Policies\ExamPolicy::viewAnswerKey().                            ║
 * ║                                                                          ║
 * ║ EVERY `correct_answers` ASSERTION IS MADE AGAINST THE **RAW JSON**, not  ║
 * ║ against a falsy json() lookup: the contract is that the key is ABSENT,   ║
 * ║ and `json('data.result.correct_answers')` returns null for both "absent" ║
 * ║ and "present but null". Only the string check can tell them apart.       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ExamResultsTest extends TestCase
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
        $this->makeQuestion($this->bank, ['correct_answer' => '4', 'points' => 1]);

        [, $this->exam] = $this->makeExam($this->section, [
            'question_group_id' => $this->bank->getKey(),
            'pass_score' => 60,
        ]);

        $this->student = $this->makeStudentUser();
        $this->makeSubscription($this->student, $this->course);
    }

    private function attempt(Exam $exam, User $student, float $score = 100.0, int $ordinal = 1): ExamAttempt
    {
        $attempt = new ExamAttempt();

        $attempt->forceFill([
            'id' => (string) Str::uuid7(),
            'exam_id' => $exam->getKey(),
            'student_id' => $student->getKey(),
            'attempt_number' => $ordinal,
            'answers' => [['question_id' => 'q', 'answer' => '4']],
            'score' => $score,
            'started_at' => now(),
            'submitted_at' => now(),
        ])->save();

        return $attempt;
    }

    private function results(?string $studentId = null): \Illuminate\Testing\TestResponse
    {
        $url = "/api/v1/exams/{$this->exam->getKey()}/results";

        return $this->getJson($studentId === null ? $url : $url . '?student_id=' . $studentId);
    }

    // ══ WHOSE RESULTS ════════════════════════════════════════════════════

    public function test_a_student_reads_their_own_results(): void
    {
        $attempt = $this->attempt($this->exam, $this->student);

        $this->loginAs($this->student);

        $this->results()
            ->assertStatus(200)
            ->assertJsonPath('data.result.attempt_id', $attempt->getKey())
            ->assertJsonPath('data.result.attempt_number', 1)
            ->assertJsonPath('data.result.passed', true);
    }

    /** Passing YOUR OWN id explicitly is not a refusal — backend/ guards on inequality. */
    public function test_a_student_may_name_their_own_id_explicitly(): void
    {
        $this->attempt($this->exam, $this->student);

        $this->loginAs($this->student);

        $this->results((string) $this->student->getKey())->assertStatus(200);
    }

    /** An EMPTY `?student_id=` means "me" too, matching backend/'s `!== ''`. */
    public function test_an_empty_student_id_parameter_means_me(): void
    {
        $this->attempt($this->exam, $this->student);

        $this->loginAs($this->student);

        $this->getJson("/api/v1/exams/{$this->exam->getKey()}/results?student_id=")
            ->assertStatus(200);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE CLASSMATE-SWAP. This is the entire reason                        ║
     * ║ ExamPolicy::viewResultsOf takes a THIRD parameter — without it the   ║
     * ║ question degenerates into "may this user see results?", which is     ║
     * ║ true for every enrolled student.                                     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_student_may_not_read_a_classmates_results(): void
    {
        $classmate = $this->makeStudentUser();
        $this->makeSubscription($classmate, $this->course);
        $this->attempt($this->exam, $classmate);

        $this->attempt($this->exam, $this->student);
        $this->loginAs($this->student);

        $this->results((string) $classmate->getKey())
            ->assertStatus(403)
            ->assertJsonPath('message', 'You may only view your own results');
    }

    public function test_the_owner_and_an_admin_may_read_any_students_results(): void
    {
        $this->attempt($this->exam, $this->student);

        $this->loginAs($this->owner);
        $this->results((string) $this->student->getKey())->assertStatus(200);

        $this->startNextRequest();
        $this->loginAs($this->makeAdmin());
        $this->results((string) $this->student->getKey())->assertStatus(200);
    }

    /** A FOREIGN instructor is stopped one rung earlier, by course access. */
    public function test_a_foreign_instructor_is_refused_by_course_access(): void
    {
        $this->attempt($this->exam, $this->student);

        [$other] = $this->makeTeacherWithProfile();
        $this->loginAs($other);

        $this->results((string) $this->student->getKey())
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not own this exam');
    }

    /**
     * NO ATTEMPT -> 404, AND STAFF GET IT TOO.
     *
     * The existence check is deliberately AFTER the authorization one, which is
     * why ExamPolicy::viewResultsOf does not read `exam_attempts`: merging them
     * would make the status depend on data the caller is not yet cleared for.
     */
    public function test_no_attempt_is_404_with_the_contract_string(): void
    {
        $this->loginAs($this->student);

        $this->results()
            ->assertStatus(404)
            ->assertJsonPath('message', 'No attempt found for this exam');

        $this->startNextRequest();
        $this->loginAs($this->owner);

        $this->results((string) $this->student->getKey())
            ->assertStatus(404)
            ->assertJsonPath('message', 'No attempt found for this exam');
    }

    // ══ THE ANSWER-KEY REVEAL ════════════════════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `show_answers = 0` + STUDENT -> NO `correct_answers` KEY AT ALL.     ║
     * ║ Asserted on the RAW BODY, because absent and null are different      ║
     * ║ contracts and json() cannot distinguish them.                        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_reveal_off_hides_the_key_from_the_student_entirely(): void
    {
        [, $exam] = $this->makeExam($this->section, [
            'question_group_id' => $this->bank->getKey(),
            'show_answers' => false,
        ]);
        $this->attempt($exam, $this->student);

        $this->loginAs($this->student);

        $response = $this->getJson("/api/v1/exams/{$exam->getKey()}/results")->assertStatus(200);

        $this->assertStringNotContainsString('correct_answers', $response->getContent());
        $this->assertStringNotContainsString('correct_answer', $response->getContent());
        $this->assertFalse($response->json('data.result.show_answers'));
    }

    public function test_reveal_off_still_shows_the_key_to_course_staff(): void
    {
        [, $exam] = $this->makeExam($this->section, [
            'question_group_id' => $this->bank->getKey(),
            'show_answers' => false,
        ]);
        $this->attempt($exam, $this->student);

        $this->loginAs($this->owner);

        $response = $this->getJson(
            "/api/v1/exams/{$exam->getKey()}/results?student_id={$this->student->getKey()}"
        )->assertStatus(200);

        $this->assertStringContainsString('correct_answers', $response->getContent());
        $this->assertTrue($response->json('data.result.show_answers'));
        $this->assertSame('4', $response->json('data.result.correct_answers.0.correct_answer'));
    }

    /** …and an ADMIN gets it via Gate::before, which agrees with the rule here. */
    public function test_reveal_off_still_shows_the_key_to_an_admin(): void
    {
        [, $exam] = $this->makeExam($this->section, [
            'question_group_id' => $this->bank->getKey(),
            'show_answers' => false,
        ]);
        $this->attempt($exam, $this->student);

        $this->loginAs($this->makeAdmin());

        $this->getJson("/api/v1/exams/{$exam->getKey()}/results?student_id={$this->student->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.result.show_answers', true)
            ->assertJsonPath('data.result.correct_answers.0.correct_answer', '4');
    }

    public function test_reveal_on_shows_the_key_to_the_student(): void
    {
        $this->attempt($this->exam, $this->student);

        $this->loginAs($this->student);

        $response = $this->results()->assertStatus(200);

        $this->assertStringContainsString('correct_answers', $response->getContent());
        $this->assertTrue($response->json('data.result.show_answers'));
        $this->assertSame('4', $response->json('data.result.correct_answers.0.correct_answer'));
    }

    /**
     * THE TWO CONDITIONS ARE NOT ONE CONDITION: reveal ON with NO BANK answers
     * `show_answers: true` AND OMITS `correct_answers`, which is exactly what
     * backend/'s `$reveal && $exam['question_group_id']` produces.
     */
    public function test_reveal_on_with_no_bank_reports_true_and_still_omits_the_key(): void
    {
        [, $exam] = $this->makeExam($this->section);
        $this->attempt($exam, $this->student);

        $this->loginAs($this->student);

        $response = $this->getJson("/api/v1/exams/{$exam->getKey()}/results")->assertStatus(200);

        $this->assertTrue($response->json('data.result.show_answers'));
        $this->assertStringNotContainsString('correct_answers', $response->getContent());
    }

    /** The answer key never carries the bank id — approved decision #3. */
    public function test_the_revealed_key_does_not_leak_the_question_group_id(): void
    {
        $this->attempt($this->exam, $this->student);

        $this->loginAs($this->student);

        $body = $this->results()->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('question_group_id', $body);
        $this->assertStringNotContainsString((string) $this->bank->getKey(), $body);
    }

    // ══ PAYLOAD SHAPE ════════════════════════════════════════════════════

    public function test_the_result_carries_every_ported_key(): void
    {
        $this->attempt($this->exam, $this->student, 55.0);

        $this->loginAs($this->student);

        $result = $this->results()->assertStatus(200)->json('data.result');

        $this->assertSame(
            [
                'answers', 'attempt_id', 'attempt_number', 'correct_answers',
                'pass_score', 'passed', 'score', 'show_answers', 'started_at', 'submitted_at',
            ],
            collect(array_keys($result))->sort()->values()->all()
        );

        // Not passed: 55 < 60. `passed` is a real boolean, never a 0/1.
        $this->assertFalse($result['passed']);
        $this->assertSame(60.0, (float) $result['pass_score']);
        $this->assertSame(55.0, (float) $result['score']);

        // The clocks are the raw MySQL DATETIME shape, not ISO-8601.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result['started_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result['submitted_at']);
    }

    /** `passed` is TRI-STATE — null when the exam sets no pass mark. */
    public function test_passed_and_pass_score_are_null_when_the_exam_sets_no_mark(): void
    {
        [, $exam] = $this->makeExam($this->section, ['question_group_id' => $this->bank->getKey()]);
        $this->attempt($exam, $this->student);

        $this->loginAs($this->student);

        $this->getJson("/api/v1/exams/{$exam->getKey()}/results")
            ->assertStatus(200)
            ->assertJsonPath('data.result.pass_score', null)
            ->assertJsonPath('data.result.passed', null);
    }

    /** The LATEST attempt is reported, not the first. */
    public function test_the_latest_attempt_is_the_one_reported(): void
    {
        $first = $this->attempt($this->exam, $this->student, 10.0, 1);
        $first->forceFill(['started_at' => now()->subHour(), 'submitted_at' => now()->subHour()])->save();

        $latest = $this->attempt($this->exam, $this->student, 90.0, 2);

        $this->loginAs($this->student);

        $this->results()
            ->assertStatus(200)
            ->assertJsonPath('data.result.attempt_id', $latest->getKey())
            ->assertJsonPath('data.result.attempt_number', 2);
    }

    /** `answers` is a decoded array, never the raw JSON string. */
    public function test_the_stored_answers_come_back_decoded(): void
    {
        $this->attempt($this->exam, $this->student);

        $this->loginAs($this->student);

        $answers = $this->results()->assertStatus(200)->json('data.result.answers');

        $this->assertIsArray($answers);
        $this->assertSame('4', $answers[0]['answer']);
    }
}
