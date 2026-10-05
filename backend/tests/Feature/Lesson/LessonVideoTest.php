<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\ContentItemType;
use App\Enums\SubscriptionStatus;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/lessons/{id}/video — freeze-table row 27.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS IS THE ROUTE THAT HANDS OUT `videos.video_url` — THE PAID PRODUCT  ║
 * ║ ITSELF. IT IS THE **ONLY** WAY TO OBTAIN A STREAM URL WITHOUT BEING    ║
 * ║ COURSE STAFF, AND THAT IS WHY IT EXISTS: without it, knowing a          ║
 * ║ content-item UUID would be enough.                                      ║
 * ║                                                                          ║
 * ║ Unlike the four public reads of this session there is no reduced shape  ║
 * ║ to degrade to, so a refusal is the only correct answer for anyone who   ║
 * ║ fails the gate.                                                          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * FOUR WAYS IN, and the third is open decision 3.8's — the one that is easy to
 * lose and whose loss silently paywalls every course's free sample.
 */
final class LessonVideoTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Course $course;

    private Section $section;

    private ContentItem $paidLesson;

    private ContentItem $previewLesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        $this->admin = $this->makeAdmin();
        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course);

        $this->paidLesson = $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Video,
            [
                'video_url' => 'https://example.test/paid.m3u8',
                'thumbnail_url' => 'https://example.test/poster.jpg',
                'duration' => 1234,
                'provider' => 'youtube',
                'is_preview' => false,
            ],
            ['title' => 'درس مدفوع', 'description' => 'وصف'],
        );

        $this->previewLesson = $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Video,
            ['video_url' => 'https://example.test/preview.m3u8', 'is_preview' => true],
            ['title' => 'معاينة مجانية'],
        );
    }

    // ══ 1. THE 404-FOR-A-NON-VIDEO RULE ═══════════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A NON-VIDEO CONTENT ITEM IS **404 'Lesson not found'** — NOT 422 AND ║
     * ║ NOT 403.                                                              ║
     * ║                                                                      ║
     * ║ "Lesson" MEANS a content item of type video; there is no `lessons`   ║
     * ║ table. An id naming a note does not name a lesson, so the lesson     ║
     * ║ genuinely does not exist. And answering 422 'not a video' would be a ║
     * ║ TYPE ORACLE: probe an id, learn from the status what kind of thing   ║
     * ║ it is, without any authorization at all.                             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_every_non_video_type_is_404_lesson_not_found(): void
    {
        foreach ([ContentItemType::Note, ContentItemType::Link, ContentItemType::Exam,
            ContentItemType::File] as $type) {
            $item = $this->makeContentItem($this->section, ['type' => $type]);

            // Even for the OWNER, who is maximally entitled.
            $this->freshLogin($this->owner);

            $this->getJson("/api/v1/lessons/{$item->getKey()}/video")
                ->assertStatus(404)
                ->assertJsonPath('message', 'Lesson not found');
        }
    }

    /** …and an unknown id answers identically. No oracle either way. */
    public function test_an_unknown_id_and_a_non_video_id_are_indistinguishable(): void
    {
        $note = $this->makeContentItem($this->section, ['type' => ContentItemType::Note]);

        $this->freshLogin($this->owner);
        $noteResponse = $this->getJson("/api/v1/lessons/{$note->getKey()}/video");

        $this->freshLogin($this->owner);
        $unknown = $this->getJson('/api/v1/lessons/' . Str::uuid7() . '/video');

        $this->assertSame($unknown->status(), $noteResponse->status());
        $this->assertSame($unknown->json('message'), $noteResponse->json('message'));
    }

    /** The TYPE check runs BEFORE the access check — proven with a stranger. */
    public function test_the_type_check_precedes_the_access_check(): void
    {
        $note = $this->makeContentItem($this->section, ['type' => ContentItemType::Note]);

        $this->freshLogin($this->makeStudentUser());

        // A totally unentitled caller still gets 404, not 403 — so the status
        // code cannot be used to probe an id's type.
        $this->getJson("/api/v1/lessons/{$note->getKey()}/video")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Lesson not found');
    }

    // ══ 2. THE FOUR WAYS IN ═══════════════════════════════════════════════

    public function test_the_owner_and_an_admin_may_watch_a_paid_lesson(): void
    {
        foreach ([$this->owner, $this->admin] as $actor) {
            $this->freshLogin($actor);

            $video = $this->getJson("/api/v1/lessons/{$this->paidLesson->getKey()}/video")
                ->assertOk()
                ->json('data.video');

            $this->assertSame('https://example.test/paid.m3u8', $video['videoUrl']);
        }
    }

    public function test_a_subscribed_student_may_watch_a_paid_lesson(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course);

        $this->freshLogin($student);

        $this->getJson("/api/v1/lessons/{$this->paidLesson->getKey()}/video")
            ->assertOk()
            ->assertJsonPath('data.video.videoUrl', 'https://example.test/paid.m3u8');
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ OPEN DECISION 3.8, RESOLVED AND PINNED.                              ║
     * ║                                                                      ║
     * ║ `videos.is_preview = 1` opens a lesson to ANY AUTHENTICATED user —   ║
     * ║ an unsubscribed student, a student enrolled with a DIFFERENT         ║
     * ║ teacher, and a completely unrelated instructor. That is what "free   ║
     * ║ preview" means, and ResolvesCourseOwnership::canConsume() knows      ║
     * ║ nothing about it: the branch lives in ContentItemPolicy::watch()     ║
     * ║ because is_preview is a column on `videos`, not on `courses`.        ║
     * ║                                                                      ║
     * ║ Dropping the branch would 403 every free sample on the platform      ║
     * ║ while every other test in this file kept passing.                    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_preview_lesson_is_open_to_any_authenticated_user(): void
    {
        [$strangerInstructor] = $this->makeTeacherWithProfile();

        $elsewhere = $this->makeStudentUser();
        $this->makeSubscription($elsewhere, $this->makeCourseFor($strangerInstructor));

        foreach ([$this->makeStudentUser(), $elsewhere, $strangerInstructor] as $actor) {
            $this->freshLogin($actor);

            $this->getJson("/api/v1/lessons/{$this->previewLesson->getKey()}/video")
                ->assertOk()
                ->assertJsonPath('data.video.videoUrl', 'https://example.test/preview.m3u8')
                ->assertJsonPath('data.video.isPreview', true);
        }
    }

    /** …but a preview is NOT open to a GUEST. `auth` still applies. */
    public function test_a_preview_lesson_is_still_closed_to_a_guest(): void
    {
        $this->flushSession();
        $this->startNextRequest();

        $this->getJson("/api/v1/lessons/{$this->previewLesson->getKey()}/video")
            ->assertStatus(401);
    }

    // ══ 3. EVERYTHING ELSE IS 403 ═════════════════════════════════════════

    public function test_every_unentitled_actor_is_403_on_a_paid_lesson(): void
    {
        $expired = $this->makeStudentUser();
        $this->makeSubscription($expired, $this->course, expiresAt: now()->subMinute());

        $cancelled = $this->makeStudentUser();
        $this->makeSubscription($cancelled, $this->course, SubscriptionStatus::Cancelled);

        [$stranger] = $this->makeTeacherWithProfile();

        foreach ([$this->makeStudentUser(), $expired, $cancelled, $stranger] as $actor) {
            $this->freshLogin($actor);

            $response = $this->getJson("/api/v1/lessons/{$this->paidLesson->getKey()}/video")
                ->assertStatus(403)
                ->assertJsonPath('message', 'You do not have access to this lesson');

            $this->assertStringNotContainsString(
                'https://example.test/paid.m3u8',
                $response->getContent() ?: '',
                'A refusal leaked the stream URL in its body.'
            );
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE ROLE CHECK IN canConsume()'s SECOND BRANCH IS NOT DECORATION —   ║
     * ║ AND THIS TEST HAD TO BE BUILT AROUND A SCHEMA CONSTRAINT TO PROVE   ║
     * ║ IT, WHICH IS ITSELF WORTH RECORDING.                                 ║
     * ║                                                                      ║
     * ║ MEASURED, NOT ASSUMED: the first version of this test simply gave an ║
     * ║ instructor a `subscriptions` row and expected 403. It could not even ║
     * ║ be SET UP — `fk_subscriptions_student` references `students.user_id` ║
     * ║ and the instructor has no such row, so MySQL refused the INSERT with ║
     * ║ a 1452. The database is therefore the FIRST line of defence here.    ║
     * ║                                                                      ║
     * ║ The role check is the SECOND. The state it defends against is an     ║
     * ║ account that holds BOTH a `students` profile AND `role =             ║
     * ║ instructor` — reachable through a data migration or a mis-scoped     ║
     * ║ admin action, not through any route. Constructed deliberately here.  ║
     * ║ Without the role check, that one row would promote an instructor     ║
     * ║ into ANOTHER instructor's paid course.                                ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_an_instructor_who_also_holds_a_student_profile_is_still_refused(): void
    {
        [$stranger] = $this->makeTeacherWithProfile();

        // The anomalous state: a student profile on an instructor account.
        $this->attachStudentProfile($stranger);
        $this->makeSubscription($stranger, $this->course);

        $this->assertDatabaseHas('subscriptions', [
            'student_id' => $stranger->getKey(),
            'course_id' => $this->course->getKey(),
            'status' => 'active',
        ]);

        $this->freshLogin($stranger);

        $this->getJson("/api/v1/lessons/{$this->paidLesson->getKey()}/video")
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not have access to this lesson');
    }

    public function test_a_guest_is_401(): void
    {
        $this->flushSession();
        $this->startNextRequest();

        $this->getJson("/api/v1/lessons/{$this->paidLesson->getKey()}/video")->assertStatus(401);
    }

    // ══ 4. THE PAYLOAD SHAPE ══════════════════════════════════════════════

    /**
     * SEVEN camelCase KEYS — and only this route in the whole application
     * speaks camelCase for these fields. VideoPlayerPage.tsx reads
     * `videoData.videoUrl` / `.thumbnailUrl` / `.courseId`; renaming them to
     * match ContentItemResource's snake_case would break the player silently.
     */
    public function test_the_payload_is_the_seven_camel_case_keys_the_player_reads(): void
    {
        $this->freshLogin($this->owner);

        $video = $this->getJson("/api/v1/lessons/{$this->paidLesson->getKey()}/video")
            ->assertOk()
            ->json('data.video');

        $this->assertSame([
            'id', 'title', 'description', 'videoUrl', 'thumbnailUrl',
            'courseId', 'duration', 'provider', 'isPreview',
        ], array_keys($video));

        $this->assertSame($this->paidLesson->getKey(), $video['id']);
        $this->assertSame($this->course->getKey(), $video['courseId']);
        $this->assertSame(1234, $video['duration']);
        $this->assertSame('youtube', $video['provider']);
        $this->assertFalse($video['isPreview']);
    }

    /**
     * A video content item with NO `videos` row is a legal state (created
     * without a URL — see PersistVideoDetail). It must answer 200 with nulls
     * rather than 500, and it is NOT a preview.
     */
    public function test_a_video_item_without_a_detail_row_answers_nulls_not_a_500(): void
    {
        $bare = $this->makeContentItem($this->section, ['type' => ContentItemType::Video]);

        $this->freshLogin($this->owner);

        $video = $this->getJson("/api/v1/lessons/{$bare->getKey()}/video")
            ->assertOk()
            ->json('data.video');

        $this->assertNull($video['videoUrl']);
        $this->assertNull($video['duration']);
        $this->assertFalse($video['isPreview']);

        // …and a null relation is fail-CLOSED for a stranger, not a free pass.
        $this->freshLogin($this->makeStudentUser());
        $this->getJson("/api/v1/lessons/{$bare->getKey()}/video")->assertStatus(403);
    }

    /** A soft-deleted course's leftover lesson is 404, never 500. */
    public function test_a_lesson_under_a_soft_deleted_course_is_404_not_500(): void
    {
        $this->course->delete();

        foreach ([$this->owner, $this->admin, $this->makeStudentUser()] as $actor) {
            $this->freshLogin($actor);

            $this->getJson("/api/v1/lessons/{$this->paidLesson->getKey()}/video")
                ->assertStatus(404)
                ->assertJsonPath('message', 'Course not found');
        }
    }

    /**
     * ┌── A HIDDEN LESSON IS NOT SPECIAL ON THIS ROUTE, AND THAT IS PORTED. ─┐
     * │ backend/LessonService::assertCanAccess() never looks at is_hidden —  │
     * │ the hiding rule belongs to the LISTING and single-item READ routes.  │
     * │ Playback is governed by entitlement alone, so a subscriber who       │
     * │ somehow holds the id of a hidden lesson can play it. Documented      │
     * │ rather than tightened: changing it is a product decision, and the    │
     * │ id is not obtainable through any read route this session opens.      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_hidden_lesson_is_governed_by_entitlement_only_exactly_as_backend_does(): void
    {
        $hidden = $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Video,
            ['video_url' => 'https://example.test/hidden.m3u8'],
            ['is_hidden' => true],
        );

        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course);

        $this->freshLogin($student);
        $this->getJson("/api/v1/lessons/{$hidden->getKey()}/video")->assertOk();

        $this->freshLogin($this->makeStudentUser());
        $this->getJson("/api/v1/lessons/{$hidden->getKey()}/video")->assertStatus(403);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function freshLogin(User $user): void
    {
        $this->flushSession();
        $this->startNextRequest();
        $this->loginAs($user);
        $this->startNextRequest();
    }
}
