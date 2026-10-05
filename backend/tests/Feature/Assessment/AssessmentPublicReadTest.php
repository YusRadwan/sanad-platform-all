<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\Course;
use App\Models\Exam;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ APPROVED DECISION #1'S MIDDLE GROUND, PROVEN ON THE WIRE.               ║
 * ║                                                                          ║
 * ║ The two read routes STAY public. What changed is:                        ║
 * ║  (a) hidden exams are FILTERED from the listing for                      ║
 * ║      anyone who is not the course owner or an admin — backend/ does no   ║
 * ║      filtering at all, so this closes a real gap;                        ║
 * ║  (b) decision 3.6's DRAFT-COURSE CASCADE applies to both.                ║
 * ║                                                                          ║
 * ║ And one thing deliberately did NOT change: a hidden exam is still        ║
 * ║ reachable by id. That is measured against backend/, not inferred from    ║
 * ║ Stage 5's content-item rule — see the test that pins it.                 ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class AssessmentPublicReadTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $foreignInstructor;

    private Course $published;

    private Section $publishedSection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        [$this->foreignInstructor] = $this->makeTeacherWithProfile();

        $this->published = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->publishedSection = $this->makeSection($this->published);
    }

    // ══ 1. NO `auth` ANYWHERE — THE ROUTES ARE GENUINELY PUBLIC ══════════

    /**
     * READ FROM THE ROUTER, NOT FROM A STATUS CODE. A 200 could also mean the
     * middleware ran and happened to pass; the route definition cannot.
     */
    public function test_the_read_routes_carry_no_auth_no_active_and_no_role_gate(): void
    {
        foreach ([
            'courses.exams.index',
            'exams.show',
        ] as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

            foreach (['auth', 'active'] as $forbidden) {
                $this->assertNotContains(
                    $forbidden,
                    $middleware,
                    "Route {$name} must stay public — approved decision #1. Adding `{$forbidden}` "
                    . 'is an outage of the logged-out course page, not a tightening.'
                );
            }

            foreach ($middleware as $entry) {
                $this->assertStringStartsNotWith('role:', (string) $entry, "Route {$name} must carry no role gate.");
            }
        }
    }

    public function test_a_guest_gets_200_on_both_reads(): void
    {
        [, $exam] = $this->makeExam($this->publishedSection);

        $this->getJson("/api/v1/courses/{$this->published->getKey()}/exams")->assertStatus(200);
        $this->getJson("/api/v1/exams/{$exam->getKey()}")->assertStatus(200);
    }

    // ══ 2. THE HIDDEN-ITEM FILTER — THE GAP backend/ LEFT OPEN ═══════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE SUBSCRIBED STUDENT IS THE ACTOR THAT MATTERS HERE.               ║
     * ║ `viewHidden` is OWNER-ONLY while `consumeContent` — asked on the same ║
     * ║ course in the same request — says yes for them. An implementation     ║
     * ║ that reused one boolean for both would hand a paying student the      ║
     * ║ instructor's unreleased exam list.                                    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_hidden_exam_is_filtered_for_everyone_but_the_owner_and_an_admin(): void
    {
        $this->makeExam($this->publishedSection, [], ['title' => 'ظاهر']);
        $this->makeExam($this->publishedSection, [], ['title' => 'مخفي', 'is_hidden' => true]);

        $url = "/api/v1/courses/{$this->published->getKey()}/exams";

        // Guest.
        $this->assertSame(1, count($this->getJson($url)->assertStatus(200)->json('data.exams')));

        // A fully paid-up subscriber — filtered too.
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->published);
        $this->loginAs($student);
        $this->assertSame(1, count($this->getJson($url)->assertStatus(200)->json('data.exams')));

        // A foreign instructor — filtered.
        $this->loginAs($this->foreignInstructor);
        $this->startNextRequest();
        $this->assertSame(1, count($this->getJson($url)->assertStatus(200)->json('data.exams')));

        // The owner and an admin see BOTH.
        foreach ([$this->owner, $this->makeAdmin()] as $staff) {
            $this->loginAs($staff);
            $this->startNextRequest();
            $this->assertSame(2, count($this->getJson($url)->assertStatus(200)->json('data.exams')));
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ …BUT A HIDDEN EXAM IS STILL REACHABLE **BY ID** — 200, NOT 404.      ║
     * ║                                                                      ║
     * ║ THIS IS NOT THE ASYMMETRY STAGE 5 ESTABLISHED FOR CONTENT ITEMS      ║
     * ║ (list -> filtered, single -> 404), AND IT WAS NOT ASSUMED BY         ║
     * ║ ANALOGY. backend/ExamService::find() calls ExamRepository::findById() ║
     * ║ whose SQL is `SELECT * FROM exams WHERE id = :id LIMIT 1` — it never  ║
     * ║ joins `content_items` and therefore cannot see `is_hidden`. Row 89    ║
     * ║ has never 404'd on a hidden exam for anybody.                         ║
     * ║                                                                      ║
     * ║ Ported deliberately. Tightening it has a real victim: a student       ║
     * ║ holding the id of an exam the instructor hid mid-term to stop NEW     ║
     * ║ starts would begin getting 404s. Recorded in docs/open-decisions.md;  ║
     * ║ this test exists so that changing it is a decision.                   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_hidden_exam_is_filtered_from_the_listing_but_still_reachable_by_id(): void
    {
        [, $hidden] = $this->makeExam($this->publishedSection, [], ['is_hidden' => true]);

        $this->assertSame(
            0,
            count($this->getJson("/api/v1/courses/{$this->published->getKey()}/exams")->json('data.exams'))
        );

        $this->getJson("/api/v1/exams/{$hidden->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.exam.id', $hidden->getKey());
    }

    // ══ 3. DECISION 3.6's DRAFT-COURSE CASCADE ═══════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A DRAFT COURSE'S EXAMS ARE 404 FOR A NON-OWNER — LISTING **AND**     ║
     * ║ SINGLE FETCH — EXACTLY LIKE THE COURSE ROW AND ITS SYLLABUS.         ║
     * ║                                                                      ║
     * ║ 404 and never 403: a 403 on a guessed id is a POSITIVE ORACLE that   ║
     * ║ confirms the id names a real unreleased course, which lets a          ║
     * ║ competitor enumerate an instructor's pipeline one id at a time.       ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_draft_courses_assessments_are_404_for_a_non_owner(): void
    {
        $draft = $this->makeCourseFor($this->owner, ['is_published' => false]);
        $section = $this->makeSection($draft);

        [, $exam] = $this->makeExam($section);

        $urls = [
            "/api/v1/courses/{$draft->getKey()}/exams" => 'Course not found',
            "/api/v1/exams/{$exam->getKey()}" => 'Exam not found',
        ];

        // Guest.
        foreach ($urls as $url => $message) {
            $this->getJson($url)->assertStatus(404)->assertJsonPath('message', $message);
        }

        // A foreign instructor, and a student who is even SUBSCRIBED to it.
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $draft);

        foreach ([$this->foreignInstructor, $student] as $actor) {
            $this->loginAs($actor);
            $this->startNextRequest();

            foreach ($urls as $url => $message) {
                $this->getJson($url)->assertStatus(404)->assertJsonPath('message', $message);
            }
        }
    }

    public function test_a_draft_courses_assessments_are_200_for_the_owner_and_an_admin(): void
    {
        $draft = $this->makeCourseFor($this->owner, ['is_published' => false]);
        $section = $this->makeSection($draft);

        [, $exam] = $this->makeExam($section);

        foreach ([$this->owner, $this->makeAdmin()] as $actor) {
            $this->loginAs($actor);
            $this->startNextRequest();

            $this->getJson("/api/v1/courses/{$draft->getKey()}/exams")->assertStatus(200);
            $this->getJson("/api/v1/exams/{$exam->getKey()}")->assertStatus(200);
        }
    }

    // ══ 4. NOT-FOUND AND ORPHAN LADDERS ══════════════════════════════════

    public function test_unknown_ids_carry_the_contract_strings(): void
    {
        $missing = '00000000-0000-7000-8000-000000000000';

        $this->getJson("/api/v1/exams/{$missing}")
            ->assertStatus(404)->assertJsonPath('message', 'Exam not found');
        $this->getJson("/api/v1/courses/{$missing}/exams")
            ->assertStatus(404)->assertJsonPath('message', 'Course not found');
    }

    /**
     * A SOFT-DELETED COURSE LEAVES ITS EXAM ROWS BEHIND, and the orphan must be
     * a clean 404 'Course not found' — for EVERY caller, admins included.
     *
     * ┌── THE ADMIN IS THE ACTOR THAT MATTERS, AND THIS WAS MEASURED. ───────┐
     * │ The policy's null-course branch fails closed, so a guest and an      │
     * │ instructor were already 404. But `Gate::before` returns true for an  │
     * │ ADMIN **before the policy is entered**, so the admin got a 200 with  │
     * │ the full payload of a resource whose course no longer exists — two   │
     * │ answers for one orphan, the privileged one being the wrong one. The  │
     * │ rung in App\Support\AssessmentAccess::assertParentCourseExists()     │
     * │ runs AHEAD of the Gate for exactly that reason.                      │
     * │                                                                      │
     * │ It is also the same message and the same behaviour that              │
     * │ ContentItemController::show() already gives an orphaned content item.│
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_an_exam_under_a_soft_deleted_course_is_404_not_500(): void
    {
        [, $exam] = $this->makeExam($this->publishedSection);

        $this->published->delete();

        // Even for the owner and an admin — an orphan belongs to nobody.
        foreach ([null, $this->owner, $this->makeAdmin()] as $actor) {
            if ($actor !== null) {
                $this->loginAs($actor);
                $this->startNextRequest();
            }

            $this->getJson("/api/v1/exams/{$exam->getKey()}")
                ->assertStatus(404)->assertJsonPath('message', 'Course not found');
        }
    }

    /** Listings are ordered by the parent item's `order_index`, as backend/ was. */
    public function test_the_listings_follow_the_content_item_order(): void
    {
        $this->makeExam($this->publishedSection, [], ['title' => 'ثالث', 'order_index' => 2]);
        $this->makeExam($this->publishedSection, [], ['title' => 'أوّل', 'order_index' => 0]);
        $this->makeExam($this->publishedSection, [], ['title' => 'ثانٍ', 'order_index' => 1]);

        $titles = array_column(
            $this->getJson("/api/v1/courses/{$this->published->getKey()}/exams")
                ->assertStatus(200)->json('data.exams'),
            'title'
        );

        $this->assertSame(['أوّل', 'ثانٍ', 'ثالث'], $titles);
    }

    /** An empty listing is an empty LIST, not `{}` — the envelope contract. */
    public function test_an_empty_listing_is_an_empty_array(): void
    {
        $this->getJson("/api/v1/courses/{$this->published->getKey()}/exams")
            ->assertStatus(200)
            ->assertJsonPath('data.exams', [])
            ->assertJsonCount(0, 'data.exams');
    }

    /**
     * An exam hanging off a COURSE GROUP appears in the course listing too —
     * `Exam::scopeForCourse()` filters on the denormalised
     * `content_items.course_id` with no section predicate, exactly as
     * backend/ExamRepository::listByCourse() did.
     */
    public function test_an_exam_under_a_group_appears_in_the_course_listing(): void
    {
        $group = $this->makeCourseGroup($this->published);
        $item = $this->makeGroupContentItem($group, ['type' => \App\Enums\ContentItemType::Exam]);
        \App\Actions\ContentItem\ContentDetailPersisters::apply($item, ['duration_minutes' => 20]);

        $this->assertSame(1, Exam::query()->count());
        $this->assertSame(
            1,
            count($this->getJson("/api/v1/courses/{$this->published->getKey()}/exams")->json('data.exams'))
        );
    }
}
