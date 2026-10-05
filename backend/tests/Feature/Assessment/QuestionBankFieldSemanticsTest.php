<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `''` MEANS **THREE DIFFERENT THINGS** ACROSS THE TWO WRITE ROUTES OF    ║
 * ║ ONE FEATURE, AND EVERY ONE OF THEM IS READ OFF backend/ RATHER THAN     ║
 * ║ INFERRED FROM A SIBLING STAGE.                                          ║
 * ║                                                                          ║
 * ║   PATCH /question-groups/{id}                                           ║
 * ║     title  `!empty()`           '' KEEPS the old value                  ║
 * ║     grade  `array_key_exists()` '' is WRITTEN, null CLEARS              ║
 * ║                                                                          ║
 * ║   PATCH /questions/{id}                                                 ║
 * ║     body, type, points          `isset()`  — null LEAVES IT             ║
 * ║     options, image_url,         `array_key_exists()` — null CLEARS      ║
 * ║     correct_answer                                                       ║
 * ║                                                                          ║
 * ║ Every prior stage found a DIFFERENT split (decision 5.1: column type;   ║
 * ║ decision 5.8: per detail-table). This one is driven by the IDIOM the    ║
 * ║ legacy service happened to use — `title` and `grade` are both VARCHARs  ║
 * ║ and still disagree. Measured, not assumed.                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * None of this is even EXPRESSIBLE unless `''` survives Laravel's global
 * ConvertEmptyStringsToNull, which is why `api/v1/question-groups*` and
 * `api/v1/questions/*` are on
 * AppServiceProvider::preserveEmptyStringsOnClearableWrites()'s allow-list —
 * open decision 4.1's standing obligation. Remove either pattern and several
 * tests here fail with a 200, which is exactly the silent failure 4.1 warns of.
 */
final class QuestionBankFieldSemanticsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $teacher;

    private QuestionGroup $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->teacher] = $this->makeTeacherWithProfile();
        $this->bank = $this->makeQuestionGroup($this->teacher, [
            'title' => 'العنوان الأصلي',
            'grade' => 'الصف الأول',
        ]);

        $this->loginAs($this->teacher);
    }

    private function patchBank(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->patchJson("/api/v1/question-groups/{$this->bank->getKey()}", $body);
    }

    // ══ THE GROUP ENDPOINT'S INVERTED PAIR ═══════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE HEADLINE TEST. BOTH FIELDS, BOTH `''`, ONE REQUEST.              ║
     * ║                                                                      ║
     * ║ Sending them separately would let an implementation that got ONE of   ║
     * ║ them right pass while the other silently did the opposite; sending    ║
     * ║ them together makes the asymmetry itself the thing under test.        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_inverted_convention_holds_for_both_fields_in_one_request(): void
    {
        $this->patchBank(['title' => '', 'grade' => ''])
            ->assertStatus(200)
            // `!empty('')` is false -> the OLD title is re-written.
            ->assertJsonPath('data.group.title', 'العنوان الأصلي')
            // array_key_exists('grade') is true -> '' lands in the column.
            ->assertJsonPath('data.group.grade', '');

        $this->bank->refresh();
        $this->assertSame('العنوان الأصلي', $this->bank->getAttribute('title'));
        $this->assertSame('', $this->bank->getAttribute('grade'));
    }

    /** An explicit null CLEARS the grade — the other half of array_key_exists. */
    public function test_a_null_grade_clears_the_column(): void
    {
        $this->patchBank(['grade' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.group.grade', null);

        $this->assertNull($this->bank->refresh()->getAttribute('grade'));
    }

    /** An ABSENT grade leaves it alone — absence is not null. */
    public function test_an_absent_grade_is_untouched(): void
    {
        $this->patchBank(['title' => 'عنوان جديد'])
            ->assertStatus(200)
            ->assertJsonPath('data.group.title', 'عنوان جديد')
            ->assertJsonPath('data.group.grade', 'الصف الأول');
    }

    /** A null title is "leave it", exactly like '' — `!empty(null)` is false too. */
    public function test_a_null_title_keeps_the_old_one(): void
    {
        $this->patchBank(['title' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.group.title', 'العنوان الأصلي');
    }

    /**
     * ┌── THE `'0'` QUIRK, PORTED LITERALLY. ────────────────────────────────┐
     * │ `!empty('0')` is FALSE in PHP, so a bank cannot be renamed to "0"    │
     * │ through this endpoint. It is odd, it is real, and it is recorded in  │
     * │ docs/open-decisions.md rather than silently "fixed" — a fix would be │
     * │ a behaviour change on a live authoring form that nobody asked for.   │
     * │                                                                      │
     * │ This test exists so that changing the idiom is a DECISION.           │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_title_of_zero_is_treated_as_empty_exactly_as_backend_does(): void
    {
        $this->patchBank(['title' => '0'])
            ->assertStatus(200)
            ->assertJsonPath('data.group.title', 'العنوان الأصلي');
    }

    /** `grade: '0'` DOES land — array_key_exists has no truthiness opinion. */
    public function test_a_grade_of_zero_is_written(): void
    {
        $this->patchBank(['grade' => '0'])
            ->assertStatus(200)
            ->assertJsonPath('data.group.grade', '0');
    }

    // ══ THE QUESTION ENDPOINT'S OTHER SPLIT ══════════════════════════════

    private function makeMine(array $attributes = []): Question
    {
        return $this->makeQuestion($this->bank, $attributes);
    }

    /**
     * `isset()` fields: null LEAVES the column. `array_key_exists()` fields:
     * null CLEARS it. Both halves in one request, for the same reason as above.
     */
    public function test_the_two_question_idioms_hold_in_one_request(): void
    {
        $question = $this->makeMine([
            'body' => 'النصّ الأصلي',
            'correct_answer' => 'أ',
            'options' => ['أ', 'ب'],
            'image_url' => 'uploads/images/x.png',
            'points' => 4,
        ]);

        $this->patchJson("/api/v1/questions/{$question->getKey()}", [
            // isset() -> untouched
            'body' => null,
            'points' => null,
            // array_key_exists() -> cleared
            'correct_answer' => null,
            'options' => null,
            'image_url' => null,
        ])->assertStatus(200);

        $question->refresh();

        $this->assertSame('النصّ الأصلي', $question->getAttribute('body'));
        $this->assertSame(4.0, (float) $question->getAttribute('points'));
        $this->assertNull($question->getAttribute('correct_answer'));
        $this->assertNull($question->getAttribute('options'));
        $this->assertNull($question->getAttribute('image_url'));
    }

    /** `body: ''` IS written — TEXT, and isset('') is true. */
    public function test_an_empty_body_is_written(): void
    {
        $question = $this->makeMine(['body' => 'قديم']);

        $this->patchJson("/api/v1/questions/{$question->getKey()}", ['body' => ''])
            ->assertStatus(200)
            ->assertJsonPath('data.question.body', '');

        $this->assertSame('', $question->refresh()->getAttribute('body'));
    }

    /** `correct_answer: ''` IS written — the erase-by-null twin. */
    public function test_an_empty_correct_answer_is_written_not_nulled(): void
    {
        $question = $this->makeMine(['correct_answer' => 'أ']);

        $this->patchJson("/api/v1/questions/{$question->getKey()}", ['correct_answer' => ''])
            ->assertStatus(200)
            ->assertJsonPath('data.question.correct_answer', '');

        $this->assertSame('', $question->refresh()->getAttribute('correct_answer'));
    }

    /**
     * `points: ''` IS IGNORED, AND IT IS A 200 RATHER THAN A 1366.
     *
     * Open decision 5.10's mechanism, one endpoint over: Laravel's
     * presentOrRuleIsImplicit() SKIPS `numeric` for a blank string, so the rule
     * alone does not protect a DECIMAL column. The key is REMOVED in
     * prepareForValidation() instead, so the validator, the writer and the
     * column agree by construction.
     */
    public function test_an_empty_points_is_ignored_and_not_a_1366(): void
    {
        $question = $this->makeMine(['points' => 5]);

        $this->patchJson("/api/v1/questions/{$question->getKey()}", ['points' => ''])
            ->assertStatus(200);

        $this->assertSame(5.0, (float) $question->refresh()->getAttribute('points'));
    }

    /**
     * `correctAnswer` (camelCase) IS accepted on CREATE and IGNORED on UPDATE.
     * The asymmetry is backend/'s: createQuestion() has the `??` alias chain,
     * QuestionRepository::update()'s field ladder does not. Ported, not levelled.
     */
    public function test_the_camel_case_alias_works_on_create_and_not_on_update(): void
    {
        $created = $this->postJson("/api/v1/question-groups/{$this->bank->getKey()}/questions", [
            'body' => 'س',
            'correctAnswer' => 'من-الصيغة-الجملية',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.question.correct_answer', 'من-الصيغة-الجملية');

        $id = $created->json('data.question.id');

        $this->patchJson("/api/v1/questions/{$id}", ['correctAnswer' => 'محاولة-تعديل'])
            ->assertStatus(200)
            ->assertJsonPath('data.question.correct_answer', 'من-الصيغة-الجملية');
    }

    /** snake_case wins when both spellings arrive — the `??` chain's order. */
    public function test_snake_case_wins_when_both_spellings_are_sent(): void
    {
        $this->postJson("/api/v1/question-groups/{$this->bank->getKey()}/questions", [
            'body' => 'س',
            'correct_answer' => 'snake',
            'correctAnswer' => 'camel',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.question.correct_answer', 'snake');
    }

    /** The three write-time defaults, from backend/'s `??` chains. */
    public function test_the_creation_defaults_are_ported(): void
    {
        $this->postJson("/api/v1/question-groups/{$this->bank->getKey()}/questions", ['body' => 'س'])
            ->assertStatus(201)
            ->assertJsonPath('data.question.type', 'mcq')
            ->assertJsonPath('data.question.points', fn ($v): bool => (float) $v === 1.0)
            ->assertJsonPath('data.question.options', null)
            ->assertJsonPath('data.question.correct_answer', null);
    }

    /** `body` is REQUIRED with the contract string, and '' counts as missing. */
    public function test_an_empty_body_is_rejected_on_create(): void
    {
        $this->postJson("/api/v1/question-groups/{$this->bank->getKey()}/questions", ['body' => ''])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.body.0', 'نص السؤال مطلوب');
    }

    /**
     * The `options` JSON round-trip preserves Arabic literally, which is the
     * whole reason backend/ passed JSON_UNESCAPED_UNICODE to json_encode.
     */
    public function test_arabic_options_round_trip_without_escaping(): void
    {
        $created = $this->postJson("/api/v1/question-groups/{$this->bank->getKey()}/questions", [
            'body' => 'س',
            'options' => ['خيار أوّل', 'خيار ثانٍ'],
        ])->assertStatus(201);

        $this->assertSame(['خيار أوّل', 'خيار ثانٍ'], $created->json('data.question.options'));
        $this->assertStringContainsString('خيار أوّل', $created->getContent() ?: '');
    }
}
