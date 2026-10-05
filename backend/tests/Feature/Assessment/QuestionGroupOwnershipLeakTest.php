<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Enums\ContentItemType;
use App\Models\Exam;
use App\Models\QuestionGroup;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ APPROVED DECISION #2 — THE UNOWNED-QUESTION-GROUP LEAK, PROVEN CLOSED.  ║
 * ║                                                                          ║
 * ║ THE ATTACK, END TO END, AS IT WORKED BEFORE THIS SESSION:                ║
 * ║   1. teacher B creates an exam under THEIR OWN course — legitimate;      ║
 * ║   2. they set `question_group_id` to a bank belonging to teacher A.      ║
 * ║      Nothing rejected it: `fk_exams_question_group` only requires the    ║
 * ║      row to EXIST, never that it be yours;                               ║
 * ║   3. they call GET /exams/{their own exam}/questions, which resolves the ║
 * ║      bank SERVER-SIDE and returns teacher A's questions — **including    ║
 * ║      `correct_answer`**, because the answer key is kept for course       ║
 * ║      STAFF and teacher B is legitimately staff on their own course.      ║
 * ║                                                                          ║
 * ║ A one-field write on an owned resource reading a foreign tenant's answer ║
 * ║ keys. Closed at the ONLY point the id is accepted:                        ║
 * ║ App\Rules\OwnedQuestionGroup, wired into                                 ║
 * ║ ValidatesContentDetail::detailRules()'s exam branch.                     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class QuestionGroupOwnershipLeakTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private const MESSAGE = 'مجموعة الأسئلة المحددة لا تخصك';

    private User $teacherA;

    private User $teacherB;

    private QuestionGroup $bankA;

    private QuestionGroup $bankB;

    private Section $sectionB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->teacherA] = $this->makeTeacherWithProfile();
        [$this->teacherB] = $this->makeTeacherWithProfile();

        $this->bankA = $this->makeQuestionGroup($this->teacherA, ['title' => 'بنك المعلّم أ']);
        $this->bankB = $this->makeQuestionGroup($this->teacherB, ['title' => 'بنك المعلّم ب']);

        // Teacher B's OWN course and section — the exam itself is legitimate.
        $this->sectionB = $this->makeSection($this->makeCourseFor($this->teacherB));
    }

    private function createUrl(): string
    {
        return "/api/v1/sections/{$this->sectionB->getKey()}/content-items";
    }

    // ══ 1. CREATE ════════════════════════════════════════════════════════

    public function test_a_teacher_cannot_attach_another_teachers_bank_on_create(): void
    {
        $this->loginAs($this->teacherB);

        $this->postJson($this->createUrl(), [
            'title' => 'امتحان',
            'type' => 'exam',
            'question_group_id' => $this->bankA->getKey(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.question_group_id.0', self::MESSAGE);

        // NOTHING was written — not the exam, not even the parent content item.
        $this->assertSame(0, Exam::query()->count());
        $this->assertSame(
            0,
            \App\Models\ContentItem::query()->where('section_id', $this->sectionB->getKey())->count()
        );
    }

    public function test_a_teacher_may_attach_their_own_bank(): void
    {
        $this->loginAs($this->teacherB);

        $this->postJson($this->createUrl(), [
            'title' => 'امتحان',
            'type' => 'exam',
            'question_group_id' => $this->bankB->getKey(),
        ])->assertStatus(201);

        $this->assertSame(
            $this->bankB->getKey(),
            Exam::query()->firstOrFail()->getAttribute('question_group_id')
        );
    }

    /** ADMINS ARE EXEMPT, matching every other ownership rule in the app. */
    public function test_an_admin_may_attach_any_bank(): void
    {
        $this->loginAsAdmin();

        $this->postJson($this->createUrl(), [
            'title' => 'امتحان إداري',
            'type' => 'exam',
            'question_group_id' => $this->bankA->getKey(),
        ])->assertStatus(201);

        $this->assertSame(
            $this->bankA->getKey(),
            Exam::query()->firstOrFail()->getAttribute('question_group_id')
        );
    }

    // ══ 2. UPDATE — THE ROUTE THE ATTACK WOULD ACTUALLY USE ══════════════

    /**
     * A teacher who already owns a perfectly legitimate exam must not be able
     * to RE-POINT it at a foreign bank. This is the likelier shape of the
     * attack than create: the content item already exists and passes every
     * ownership check.
     */
    public function test_a_teacher_cannot_repoint_an_owned_exam_at_a_foreign_bank(): void
    {
        $item = $this->makeContentItemWithDetail(
            $this->sectionB,
            ContentItemType::Exam,
            ['question_group_id' => $this->bankB->getKey()]
        );

        $this->loginAs($this->teacherB);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", [
            'question_group_id' => $this->bankA->getKey(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.question_group_id.0', self::MESSAGE);

        $this->assertSame(
            $this->bankB->getKey(),
            Exam::query()->firstOrFail()->getAttribute('question_group_id'),
            'The existing link must survive a rejected re-point.'
        );
    }

    public function test_a_teacher_may_repoint_at_their_own_other_bank(): void
    {
        $otherOwn = $this->makeQuestionGroup($this->teacherB);

        $item = $this->makeContentItemWithDetail(
            $this->sectionB,
            ContentItemType::Exam,
            ['question_group_id' => $this->bankB->getKey()]
        );

        $this->loginAs($this->teacherB);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", [
            'question_group_id' => $otherOwn->getKey(),
        ])->assertStatus(200);

        $this->assertSame(
            $otherOwn->getKey(),
            Exam::query()->firstOrFail()->getAttribute('question_group_id')
        );
    }

    /**
     * THE GROUP-SCOPED CREATE ROUTE IS COVERED TOO. It is a different Form
     * Request (StoreGroupContentItemRequest) sharing the same trait, so a rule
     * added to only one of the two doors would leave the other open.
     */
    public function test_the_group_scoped_create_route_is_guarded_as_well(): void
    {
        $group = $this->makeCourseGroup($this->makeCourseFor($this->teacherB));

        $this->loginAs($this->teacherB);

        $this->postJson("/api/v1/groups/{$group->getKey()}/content-items", [
            'title' => 'امتحان',
            'type' => 'exam',
            'question_group_id' => $this->bankA->getKey(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.question_group_id.0', self::MESSAGE);

        $this->assertSame(0, Exam::query()->count());
    }

    // ══ 3. THE EDGE VALUES ═══════════════════════════════════════════════

    /** null and absent both mean "no link" / "leave it" and must pass. */
    public function test_a_null_or_absent_bank_id_is_accepted(): void
    {
        $this->loginAs($this->teacherB);

        $this->postJson($this->createUrl(), ['title' => 'ا', 'type' => 'exam'])
            ->assertStatus(201);
        $this->postJson($this->createUrl(), ['title' => 'ب', 'type' => 'exam', 'question_group_id' => null])
            ->assertStatus(201);

        $this->assertSame(2, Exam::query()->whereNull('question_group_id')->count());
    }

    /**
     * `''` IS A 422, NOT A 500 — and this only works because the rule is
     * IMPLICIT.
     *
     * Open decision 5.10's mechanism: Laravel skips every non-implicit rule for
     * a blank string, and `api/v1/content-items/*` preserves `''` through
     * ConvertEmptyStringsToNull, so an ordinary rule would let '' reach a
     * CHAR(36) FK — SQLSTATE 1452, a 500.
     */
    public function test_an_empty_bank_id_is_a_422_and_not_a_foreign_key_500(): void
    {
        $item = $this->makeContentItemWithDetail(
            $this->sectionB,
            ContentItemType::Exam,
            ['question_group_id' => $this->bankB->getKey()]
        );

        $this->loginAs($this->teacherB);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", ['question_group_id' => ''])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.question_group_id.0', self::MESSAGE);
    }

    /**
     * A NON-EXISTENT ID GETS THE **SAME** MESSAGE AS A FOREIGN ONE.
     *
     * Deliberate: distinguishing "not yours" from "no such bank" would be a
     * positive oracle over other teachers' bank ids. The side effect is that a
     * bad id is 422 rather than backend/'s raw FK 500 — an improvement, and no
     * previously-successful request changes.
     */
    public function test_an_unknown_bank_id_gets_the_same_message_as_a_foreign_one(): void
    {
        $this->loginAs($this->teacherB);

        $this->postJson($this->createUrl(), [
            'title' => 'امتحان',
            'type' => 'exam',
            'question_group_id' => '00000000-0000-7000-8000-000000000000',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.question_group_id.0', self::MESSAGE);
    }

    /**
     * THE RULE IS SCOPED TO THE EXAM BRANCH AND DOES NOT LEAK ONTO OTHER
     * TYPES. A note carrying a junk `question_group_id` still succeeds — the
     * key is ignored, never persisted, never validated, exactly as
     * ValidatesContentDetail's banner requires.
     */
    public function test_a_note_carrying_a_foreign_bank_id_is_untouched_by_the_rule(): void
    {
        $this->loginAs($this->teacherB);

        $this->postJson($this->createUrl(), [
            'title' => 'مذكرة',
            'type' => 'note',
            'content_text' => 'نصّ',
            'question_group_id' => $this->bankA->getKey(),
        ])->assertStatus(201);

        $this->assertSame(0, Exam::query()->count());
    }
}
