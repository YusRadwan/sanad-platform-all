<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Http\Resources\QuestionResource;
use App\Http\Resources\StaffQuestionResource;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * The question bank — freeze-table rows 80-87, over HTTP.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE CROSS-TEACHER ISOLATION HALF IS THE POINT OF THIS FILE.             ║
 * ║                                                                          ║
 * ║ A question bank is the ONE resource in this application whose ownership  ║
 * ║ does not route through a Course, so none of the tenant-isolation proof   ║
 * ║ built in Stages 3-5 covers it. Every mutating route is exercised by a    ║
 * ║ FOREIGN instructor here, and the list route is checked for ZERO ROWS     ║
 * ║ rather than for a status code — a 200 carrying someone else's banks      ║
 * ║ would pass any status assertion.                                         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class QuestionBankCrudTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $teacherA;

    private User $teacherB;

    private QuestionGroup $bankA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->teacherA] = $this->makeTeacherWithProfile();
        [$this->teacherB] = $this->makeTeacherWithProfile();

        $this->bankA = $this->makeQuestionGroup($this->teacherA, [
            'title' => 'بنك المعلّم أ',
            'grade' => 'الصف الأول الثانوي',
        ]);
    }

    // ══ 1. THE HAPPY PATH, WITH THE LITERAL ARABIC STRINGS ═══════════════

    public function test_an_instructor_creates_a_bank_with_the_contract_message(): void
    {
        $this->loginAs($this->teacherB);

        $this->postJson('/api/v1/question-groups', [
            'title' => 'بنك جديد',
            'grade' => 'الصف الثالث الثانوي',
        ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'تم إنشاء مجموعة الأسئلة بنجاح')
            ->assertJsonPath('data.group.title', 'بنك جديد')
            ->assertJsonPath('data.group.grade', 'الصف الثالث الثانوي')
            // The owner comes from the SESSION, never from the body.
            ->assertJsonPath('data.group.teacher_id', $this->teacherB->getKey());
    }

    /**
     * `teacher_id` IS NOT MASS-ASSIGNABLE, AND THAT IS PROVEN BY SENDING IT.
     *
     * A caller filing a bank under another teacher's id would then read it back
     * through their OWN listing — the list scope trusts exactly this column.
     */
    public function test_a_teacher_id_in_the_body_is_ignored(): void
    {
        $this->loginAs($this->teacherB);

        $this->postJson('/api/v1/question-groups', [
            'title' => 'محاولة انتحال',
            'teacher_id' => $this->teacherA->getKey(),
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.group.teacher_id', $this->teacherB->getKey());

        $this->assertSame(
            0,
            QuestionGroup::query()
                ->where('teacher_id', $this->teacherA->getKey())
                ->where('title', 'محاولة انتحال')
                ->count()
        );
    }

    public function test_a_title_is_required_with_the_contract_message(): void
    {
        $this->loginAs($this->teacherA);

        $this->postJson('/api/v1/question-groups', ['title' => ''])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.title.0', 'العنوان مطلوب');
    }

    public function test_the_full_question_lifecycle(): void
    {
        $this->loginAs($this->teacherA);

        $created = $this->postJson("/api/v1/question-groups/{$this->bankA->getKey()}/questions", [
            'body' => 'ما ناتج ٢ + ٢؟',
            'type' => 'mcq',
            'options' => ['٣', '٤', '٥'],
            'correct_answer' => '٤',
            'points' => 2.5,
        ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'تم إضافة السؤال بنجاح')
            ->assertJsonPath('data.question.options', ['٣', '٤', '٥'])
            ->assertJsonPath('data.question.correct_answer', '٤')
            ->assertJsonPath('data.question.points', fn ($v): bool => (float) $v === 2.5);

        $id = $created->json('data.question.id');

        $this->patchJson("/api/v1/questions/{$id}", ['body' => 'ما ناتج ٣ + ٣؟'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم تحديث السؤال بنجاح')
            ->assertJsonPath('data.question.body', 'ما ناتج ٣ + ٣؟')
            // Unsent fields survive — the `isset()`/array_key_exists split.
            ->assertJsonPath('data.question.correct_answer', '٤');

        $this->deleteJson("/api/v1/questions/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم حذف السؤال بنجاح');

        $this->assertSame(0, Question::query()->count());
    }

    /** The GROUP verbs use the GENERIC strings; the QUESTION verbs do not. */
    public function test_the_group_verbs_carry_the_generic_messages(): void
    {
        $this->loginAs($this->teacherA);

        $this->patchJson("/api/v1/question-groups/{$this->bankA->getKey()}", ['title' => 'اسم جديد'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم التحديث بنجاح');

        $this->deleteJson("/api/v1/question-groups/{$this->bankA->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم الحذف بنجاح');
    }

    /** Deleting a bank takes its questions with it — fk_questions_group CASCADE. */
    public function test_deleting_a_bank_cascades_to_its_questions(): void
    {
        $this->makeQuestion($this->bankA);
        $this->makeQuestion($this->bankA);

        $this->loginAs($this->teacherA);

        $this->deleteJson("/api/v1/question-groups/{$this->bankA->getKey()}")->assertStatus(200);

        $this->assertSame(0, Question::query()->count());
    }

    // ══ 2. CROSS-TEACHER ISOLATION — EVERY ROUTE ═════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ZERO ROWS, NOT A STATUS CODE. The list route answers 200 for every   ║
     * ║ instructor; the only thing that can be wrong about it is WHICH rows  ║
     * ║ it carries, so that is what is asserted.                              ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_foreign_instructor_sees_zero_rows_on_the_listing(): void
    {
        $this->makeQuestionGroup($this->teacherA);
        $this->makeQuestionGroup($this->teacherB);

        $this->loginAs($this->teacherB);

        $response = $this->getJson('/api/v1/question-groups')->assertStatus(200);

        $this->assertCount(1, $response->json('data.groups'));

        foreach ($response->json('data.groups') as $group) {
            $this->assertSame($this->teacherB->getKey(), $group['teacher_id']);
        }
    }

    public function test_an_admin_sees_every_teachers_banks(): void
    {
        // setUp() already made ONE bank for teacher A.
        $this->makeQuestionGroup($this->teacherB);

        $this->loginAsAdmin();

        $this->getJson('/api/v1/question-groups')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.groups');
    }

    /**
     * ALL FIVE MUTATING/READING ROUTES THAT NAME A ROW, AS THE WRONG TEACHER.
     *
     * Written as one table rather than five methods so that adding a route to
     * this family without adding it here is visibly an omission.
     */
    public function test_every_row_scoped_route_refuses_a_foreign_instructor(): void
    {
        $question = $this->makeQuestion($this->bankA);
        $groupId = $this->bankA->getKey();
        $questionId = $question->getKey();

        $this->loginAs($this->teacherB);

        $calls = [
            ['patchJson', "/api/v1/question-groups/{$groupId}", ['title' => 'سرقة']],
            ['deleteJson', "/api/v1/question-groups/{$groupId}", []],
            ['getJson', "/api/v1/question-groups/{$groupId}/questions", []],
            ['postJson', "/api/v1/question-groups/{$groupId}/questions", ['body' => 'س']],
            ['patchJson', "/api/v1/questions/{$questionId}", ['body' => 'س']],
            ['deleteJson', "/api/v1/questions/{$questionId}", []],
        ];

        foreach ($calls as [$verb, $url, $body]) {
            $response = $verb === 'getJson' || $verb === 'deleteJson'
                ? $this->{$verb}($url)
                : $this->{$verb}($url, $body);

            $response
                ->assertStatus(403)
                ->assertJsonPath('message', 'You do not own this question group');
        }

        // …and nothing was written or removed by any of the six.
        $this->assertSame(1, Question::query()->count());
        $this->assertNotNull(QuestionGroup::query()->find($groupId));
        $this->assertSame('بنك المعلّم أ', QuestionGroup::query()->find($groupId)?->getAttribute('title'));
    }

    /** An admin passes all six — Gate::before, one rule, one place. */
    public function test_an_admin_may_manage_another_teachers_bank(): void
    {
        $question = $this->makeQuestion($this->bankA);

        $this->loginAsAdmin();

        $this->patchJson("/api/v1/question-groups/{$this->bankA->getKey()}", ['title' => 'تعديل إداري'])
            ->assertStatus(200);
        $this->getJson("/api/v1/question-groups/{$this->bankA->getKey()}/questions")
            ->assertStatus(200);
        $this->patchJson("/api/v1/questions/{$question->getKey()}", ['body' => 'تعديل إداري'])
            ->assertStatus(200);
    }

    /** An unknown id is 404 with backend/'s literal string, never 403. */
    public function test_unknown_ids_are_404_with_the_contract_strings(): void
    {
        $this->loginAs($this->teacherA);

        $missing = '00000000-0000-7000-8000-000000000000';

        $this->patchJson("/api/v1/question-groups/{$missing}", ['title' => 'x'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Question group not found');

        $this->patchJson("/api/v1/questions/{$missing}", ['body' => 'x'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Question not found');
    }

    /**
     * A question cannot be MOVED between banks by a body key. The ownership
     * check passed against the OLD parent; accepting the column would let the
     * row land somewhere it was never authorised for.
     */
    public function test_a_question_cannot_be_moved_into_another_bank(): void
    {
        $bankB = $this->makeQuestionGroup($this->teacherB);
        $question = $this->makeQuestion($this->bankA);

        $this->loginAs($this->teacherA);

        $this->patchJson("/api/v1/questions/{$question->getKey()}", [
            'body' => 'نصّ',
            'question_group_id' => $bankB->getKey(),
        ])->assertStatus(200);

        $this->assertSame(
            $this->bankA->getKey(),
            $question->refresh()->getAttribute('question_group_id')
        );
    }

    // ══ 3. THE TWO QUESTION RESOURCES ════════════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ QuestionResource HAS NEITHER THE ANSWER KEY NOR THE BANK ID;         ║
     * ║ StaffQuestionResource HAS BOTH.                                      ║
     * ║                                                                      ║
     * ║ The answer key is obvious. `question_group_id` is the same field, and ║
     * ║ the same argument, as approved decision #3 on the exam payload: the   ║
     * ║ bank's UUID is a tenant identifier with no student-side use, and      ║
     * ║ emitting it here would re-open one route over exactly the leak        ║
     * ║ decision #3 closes. That is a deliberate departure from               ║
     * ║ backend/presentQuestion(), safe because Session B ships the only      ║
     * ║ route that serves this class — see QuestionResource's banner and      ║
     * ║ docs/open-decisions.md item 6.4.                                      ║
     * ║                                                                      ║
     * ║ Both classes are built in Session A and consumed by Session B (row    ║
     * ║ 90), so both are proven here rather than left to the session that     ║
     * ║ will be busy with the concurrency-critical attempt path.              ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_two_question_resources_differ_by_exactly_the_two_staff_fields(): void
    {
        $question = $this->makeQuestion($this->bankA, [
            'correct_answer' => 'THE-ANSWER-KEY',
            'options' => ['أ', 'ب'],
            'points' => 3,
        ]);

        $student = (new QuestionResource($question))->toArray(request());
        $staff = (new StaffQuestionResource($question))->toArray(request());

        $this->assertArrayNotHasKey('correct_answer', $student);
        $this->assertArrayNotHasKey('question_group_id', $student);
        $this->assertSame('THE-ANSWER-KEY', $staff['correct_answer']);
        $this->assertSame($this->bankA->getKey(), $staff['question_group_id']);

        $this->assertSame(
            ['question_group_id', 'correct_answer'],
            array_values(array_diff(array_keys($staff), array_keys($student))),
            'The two shapes must differ by EXACTLY the two staff fields and nothing else.'
        );

        // Shared shape: options decoded to an array, points a float.
        foreach ([$student, $staff] as $shape) {
            $this->assertSame(['أ', 'ب'], $shape['options']);
            $this->assertSame(3.0, $shape['points']);
            $this->assertSame('mcq', $shape['type']);
        }
    }

    /**
     * NEITHER CLASS EXTENDS THE OTHER. Inheritance is exactly the mechanism by
     * which a field added to a parent appears in a child with no diff on the
     * file anybody reviews — and on this family the field is the answer key.
     */
    public function test_the_question_resources_do_not_inherit_from_each_other(): void
    {
        $this->assertSame(
            \Illuminate\Http\Resources\Json\JsonResource::class,
            get_parent_class(StaffQuestionResource::class)
        );
        $this->assertSame(
            \Illuminate\Http\Resources\Json\JsonResource::class,
            get_parent_class(QuestionResource::class)
        );
    }

    /**
     * `correct_answer` IS WRITTEN BY EXACTLY ONE RESOURCE CLASS IN THE WHOLE
     * APPLICATION. Asserted by grep so it stays true as classes are added.
     */
    public function test_only_one_resource_class_can_emit_the_answer_key(): void
    {
        $emitters = [];

        foreach (glob(app_path('Http/Resources/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            // Strip block comments so the banners that DISCUSS the key do not
            // count as emitters.
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $source);

            if (str_contains($code, "'correct_answer'")) {
                $emitters[] = basename($file);
            }
        }

        sort($emitters);

        /*
         * ╔══════════════════════════════════════════════════════════════════╗
         * ║ TWO SINCE STAGE 6 / SESSION B, NOT ONE. THE SECOND IS DELIBERATE ║
         * ║ AND IS THE ONLY ADDITION THIS INVARIANT HAS EVER TAKEN.          ║
         * ║                                                                  ║
         * ║  StaffQuestionResource     the paper, for course staff (row 90)  ║
         * ║  RevealedExamResultResource the answer key on a student's OWN    ║
         * ║                            result (row 91), and ONLY when        ║
         * ║                            App\Policies\ExamPolicy::viewAnswerKey║
         * ║                            says yes — staff always, a student    ║
         * ║                            only when `exams.show_answers = 1`.   ║
         * ║                                                                  ║
         * ║ The SIBLING class ExamResultResource is what makes the second    ║
         * ║ entry safe: it serves the not-revealed case and has no code path ║
         * ║ capable of reading the column, so the reveal is a choice of      ║
         * ║ CLASS rather than a conditional inside one.                      ║
         * ║                                                                  ║
         * ║ A THIRD entry appearing here is a leak until proven otherwise.   ║
         * ╚══════════════════════════════════════════════════════════════════╝
         */
        $this->assertSame(
            ['RevealedExamResultResource.php', 'StaffQuestionResource.php'],
            $emitters
        );
    }
}
