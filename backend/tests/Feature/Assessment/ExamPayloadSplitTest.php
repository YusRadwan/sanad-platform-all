<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Enums\SubscriptionStatus;
use App\Http\Resources\EnrolledExamResource;
use App\Http\Resources\PublicExamResource;
use App\Http\Resources\StaffExamResource;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\Exam;
use App\Models\QuestionGroup;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE THREE-WAY EXAM PAYLOAD SPLIT — THE ARCHITECTURAL CENTREPIECE OF     ║
 * ║ STAGE 6 / SESSION A.                                                    ║
 * ║                                                                          ║
 * ║   PublicExamResource     guest / unsubscribed / expired / cancelled /   ║
 * ║                          a FOREIGN instructor                            ║
 * ║   EnrolledExamResource   a subscribed student — WITHOUT                  ║
 * ║                          `question_group_id` (approved decision #3)     ║
 * ║   StaffExamResource      the owner and admins — WITH it, and it is the   ║
 * ║                          only class in the application that has it      ║
 * ║                                                                          ║
 * ║ AND THE CROSS-ROUTE CLAIM: the same exam, for the same actor, has the   ║
 * ║ same key set through ALL FOUR read paths — including                     ║
 * ║ ContentItemResource's `detail`, which was a SECOND DOOR with a          ║
 * ║ different shape until this session closed it.                            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ExamPayloadSplitTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** The exact key sets. Written out so a drift is a diff, not a surprise. */
    private const PUBLIC_KEYS = ['id', 'content_item_id', 'title', 'course_id', 'duration_minutes'];

    private const ENROLLED_KEYS = [
        'id', 'content_item_id', 'title', 'course_id', 'duration_minutes',
        'pass_score', 'total_score', 'allowed_attempts',
        'show_answers', 'randomize_questions', 'lock_content',
    ];

    private const STAFF_KEYS = [
        'id', 'content_item_id', 'title', 'course_id', 'duration_minutes',
        'pass_score', 'total_score', 'allowed_attempts',
        'show_answers', 'randomize_questions', 'lock_content',
        'question_group_id',
    ];

    private User $owner;

    private User $foreignInstructor;

    private Course $course;

    private Section $section;

    private ContentItem $item;

    private Exam $exam;

    private QuestionGroup $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        [$this->foreignInstructor] = $this->makeTeacherWithProfile();

        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course);
        $this->bank = $this->makeQuestionGroup($this->owner);

        [$this->item, $this->exam] = $this->makeExam($this->section, [
            'question_group_id' => $this->bank->getKey(),
            'duration_minutes' => 45,
            'pass_score' => 60,
            'total_score' => 100,
            'allowed_attempts' => 2,
            'show_answers' => false,
            'randomize_questions' => true,
            'lock_content' => true,
        ], ['title' => 'امتحان الوحدة الأولى']);
    }

    // ══ 1. THE ACTOR MATRIX, OVER GET /exams/{id} ════════════════════════

    public function test_a_guest_gets_the_public_shape(): void
    {
        $body = $this->getJson("/api/v1/exams/{$this->exam->getKey()}")
            ->assertStatus(200)
            ->json('data.exam');

        $this->assertSame(self::PUBLIC_KEYS, array_keys($body));
        $this->assertArrayNotHasKey('question_group_id', $body);
        $this->assertSame('امتحان الوحدة الأولى', $body['title']);
        $this->assertSame($this->course->getKey(), $body['course_id']);
        $this->assertSame(45, $body['duration_minutes']);
    }

    /**
     * FOUR ACTORS WHO ARE ALL "NOT ENROLLED" FOR FOUR DIFFERENT REASONS, and
     * every one of them must land on the same reduced shape. An expired or
     * cancelled subscription is the interesting pair: the ROW exists, so an
     * implementation that checked for a subscription's presence rather than
     * calling Subscription::scopeActive() would hand them the paid shape.
     */
    public function test_every_unentitled_actor_gets_the_public_shape(): void
    {
        $unsubscribed = $this->makeStudentUser();

        $expired = $this->makeStudentUser();
        $this->makeSubscription($expired, $this->course, SubscriptionStatus::Active, now()->subDay());

        $cancelled = $this->makeStudentUser();
        $this->makeSubscription($cancelled, $this->course, SubscriptionStatus::Cancelled);

        foreach ([$unsubscribed, $expired, $cancelled, $this->foreignInstructor] as $actor) {
            $this->loginAs($actor);
            $this->startNextRequest();

            $body = $this->getJson("/api/v1/exams/{$this->exam->getKey()}")
                ->assertStatus(200)
                ->json('data.exam');

            $this->assertSame(
                self::PUBLIC_KEYS,
                array_keys($body),
                "Actor {$actor->getKey()} must receive the PUBLIC exam shape."
            );
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ APPROVED DECISION #3, PROVEN: A SUBSCRIBED STUDENT GETS THE GRADING  ║
     * ║ CONFIG AND **NOT** `question_group_id`.                              ║
     * ║                                                                      ║
     * ║ The bank id is a tenant identifier with no student-side use — the    ║
     * ║ paper is served by GET /exams/{id}/questions, which resolves the     ║
     * ║ bank server-side. Emitting it would turn                              ║
     * ║ GET /question-groups/{id}/questions from a guess into a targeted      ║
     * ║ request.                                                              ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_subscribed_student_gets_the_enrolled_shape_without_the_bank_id(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course);

        $this->loginAs($student);

        $body = $this->getJson("/api/v1/exams/{$this->exam->getKey()}")
            ->assertStatus(200)
            ->json('data.exam');

        $this->assertSame(self::ENROLLED_KEYS, array_keys($body));
        $this->assertArrayNotHasKey('question_group_id', $body);

        // …and the raw bytes carry the id nowhere either.
        $raw = $this->getJson("/api/v1/exams/{$this->exam->getKey()}")->getContent() ?: '';
        $this->assertStringNotContainsString((string) $this->bank->getKey(), $raw);

        // The grading config IS there, with the ported types.
        $this->assertSame(60.0, (float) $body['pass_score']);
        $this->assertSame(100.0, (float) $body['total_score']);
        $this->assertSame(2, $body['allowed_attempts']);
        $this->assertFalse($body['show_answers']);
        $this->assertTrue($body['randomize_questions']);
        $this->assertTrue($body['lock_content']);
    }

    public function test_the_owner_and_an_admin_get_the_staff_shape_with_the_bank_id(): void
    {
        foreach ([$this->owner, $this->makeAdmin()] as $actor) {
            $this->loginAs($actor);
            $this->startNextRequest();

            $body = $this->getJson("/api/v1/exams/{$this->exam->getKey()}")
                ->assertStatus(200)
                ->json('data.exam');

            $this->assertSame(self::STAFF_KEYS, array_keys($body));
            $this->assertSame($this->bank->getKey(), $body['question_group_id']);
        }
    }

    // ══ 2. CROSS-ROUTE CONSISTENCY — THE BACKDOOR, CLOSED ════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE SAME EXAM, THE SAME ACTOR, FOUR ROUTES, ONE KEY SET.             ║
     * ║                                                                      ║
     * ║ Two of the four reach the exam through ContentItemResource's         ║
     * ║ `detail` key, which used to write the `exams` columns out by hand —  ║
     * ║ `question_group_id` included. So before this session a SUBSCRIBED    ║
     * ║ STUDENT received, through /content-items/{id}, exactly the field     ║
     * ║ decision #3 removes from /exams/{id}. Two doors to one row with two  ║
     * ║ shapes is the shape of every payload leak.                            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_same_exam_has_the_same_key_set_through_all_four_read_paths(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course);

        $actors = [
            'subscriber' => [$student, self::ENROLLED_KEYS],
            'owner' => [$this->owner, self::STAFF_KEYS],
            'admin' => [$this->makeAdmin(), self::STAFF_KEYS],
        ];

        foreach ($actors as $label => [$actor, $expected]) {
            $this->loginAs($actor);
            $this->startNextRequest();

            $shapes = [
                'GET /exams/{id}' => $this->getJson("/api/v1/exams/{$this->exam->getKey()}")
                    ->assertStatus(200)->json('data.exam'),
                'GET /courses/{c}/exams' => $this->getJson("/api/v1/courses/{$this->course->getKey()}/exams")
                    ->assertStatus(200)->json('data.exams.0'),
                'GET /content-items/{id}' => $this->getJson("/api/v1/content-items/{$this->item->getKey()}")
                    ->assertStatus(200)->json('data.detail'),
                'GET /sections/{s}/content-items' => $this->getJson(
                    "/api/v1/sections/{$this->section->getKey()}/content-items"
                )->assertStatus(200)->json('data.0.detail'),
            ];

            foreach ($shapes as $route => $shape) {
                $this->assertIsArray($shape, "{$label} got no exam payload from {$route}.");
                $this->assertSame(
                    $expected,
                    array_keys($shape),
                    "{$label} got a DIFFERENT key set from {$route} than from the other read paths — "
                    . 'that divergence is the backdoor Stage 6 / Session A closed.'
                );
            }

            // Values agree too, not just keys.
            $this->assertSame(
                array_values($shapes['GET /exams/{id}']),
                array_values($shapes['GET /content-items/{id}'])
            );
        }
    }

    /**
     * FOR AN UNENTITLED ACTOR THE CONTENT-ITEM ROUTES CARRY **NO `detail` KEY
     * AT ALL** — which is a stronger guarantee than "the same reduced shape".
     *
     * PublicContentItemResource has no code path that can reach any detail
     * table other than `videos`, so there is nothing for the factory to be
     * asked about. The two exam routes still answer the reduced shape, so the
     * public storefront keeps working.
     */
    public function test_an_unentitled_actor_gets_no_detail_key_from_the_content_item_routes(): void
    {
        $item = $this->getJson("/api/v1/content-items/{$this->item->getKey()}")
            ->assertStatus(200)->json('data');

        $this->assertArrayNotHasKey('detail', $item);

        $list = $this->getJson("/api/v1/sections/{$this->section->getKey()}/content-items")
            ->assertStatus(200)->json('data.0');

        $this->assertArrayNotHasKey('detail', $list);

        // …while the exam routes degrade rather than disappear.
        $this->assertSame(
            self::PUBLIC_KEYS,
            array_keys((array) $this->getJson("/api/v1/exams/{$this->exam->getKey()}")->json('data.exam'))
        );
    }

    // ══ 3. THE STRUCTURAL PROPERTIES OF THE THREE CLASSES ════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ NONE OF THE THREE EXTENDS ANOTHER. That is the whole security        ║
     * ║ argument: inheritance is the mechanism by which a field added to a    ║
     * ║ parent appears in a child with no diff on the file anybody reviews.  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_none_of_the_three_exam_resources_extends_another(): void
    {
        foreach ([PublicExamResource::class, EnrolledExamResource::class, StaffExamResource::class] as $class) {
            $this->assertSame(
                JsonResource::class,
                get_parent_class($class),
                "{$class} must extend JsonResource directly — never a sibling, never a shared base."
            );
        }
    }

    /**
     * `question_group_id` IS WRITTEN BY EXACTLY **TWO** RESOURCE CLASSES, AND
     * BOTH ARE STAFF-ONLY.
     *
     * StaffExamResource is the field on `exams`; StaffQuestionResource is the
     * field on `questions` (a question's own parent). They are different
     * columns but the SAME disclosure — the bank's UUID — so decision #3's
     * rule is applied to both: the student-facing twins
     * (EnrolledExamResource, QuestionResource) drop it. QuestionResource's
     * banner records that this is a deliberate departure from
     * backend/presentQuestion(), safe because Session B ships its only route.
     *
     * Grep-based, so it stays true as classes are added rather than being a
     * fact about the four that exist today. Block comments are stripped first
     * so the banners that DISCUSS the field do not count as emitters.
     */
    public function test_only_the_two_staff_resources_can_emit_the_question_group_id(): void
    {
        $emitters = [];

        foreach (glob(app_path('Http/Resources/*.php')) ?: [] as $file) {
            $code = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));

            if (str_contains($code, "'question_group_id'")) {
                $emitters[] = basename($file);
            }
        }

        sort($emitters);

        $this->assertSame(['StaffExamResource.php', 'StaffQuestionResource.php'], $emitters);
    }

    /**
     * The public class is STRUCTURALLY INCAPABLE of the grading fields — not
     * "does not currently emit them". Serialised against a fully populated
     * exam, and every forbidden key AND its VALUE must be absent.
     */
    public function test_the_public_resource_cannot_express_a_single_grading_field(): void
    {
        $json = (string) json_encode(
            (new PublicExamResource($this->exam->load('contentItem.course')))->toArray(request()),
            JSON_UNESCAPED_UNICODE
        );

        foreach ([
            'question_group_id', 'pass_score', 'total_score', 'allowed_attempts',
            'show_answers', 'randomize_questions', 'lock_content',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }

        $this->assertStringNotContainsString((string) $this->bank->getKey(), $json);
        // The control: the fixture really is populated.
        $this->assertStringContainsString('امتحان الوحدة الأولى', $json);
    }
}
