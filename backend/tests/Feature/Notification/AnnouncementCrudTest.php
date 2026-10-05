<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Announcements — freeze-table rows 109-112, and the home of approved
 * decisions 3.2 (platform announcements) and 4 (PATCH rejects a blank title).
 */
final class AnnouncementCrudTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    // ── LISTING ───────────────────────────────────────────────────────────

    public function test_an_instructor_sees_only_their_own_announcements(): void
    {
        $mine = $this->makeInstructor();
        $theirs = $this->makeInstructor();

        $a = $this->makeAnnouncement($mine, ['title' => 'لي']);
        $this->makeAnnouncement($theirs, ['title' => 'لغيري']);
        $this->makeAnnouncement(null, ['title' => 'منصّة']);

        $this->actingAs($mine);

        $titles = array_column(
            $this->getJson('/api/v1/announcements')->assertOk()->json('data.announcements'),
            'title'
        );

        // NOTE the PLATFORM announcement is absent too: `teacher_id = <id>`
        // excludes NULL in SQL, which is correct — an admin's platform notice
        // is not one of this instructor's drafts.
        $this->assertSame(['لي'], $titles);
        $this->assertSame($a->getKey(), $this->getJson('/api/v1/announcements')->json('data.announcements.0.id'));
    }

    public function test_an_admin_sees_every_announcement_including_platform_ones(): void
    {
        $one = $this->makeInstructor();
        $two = $this->makeInstructor();

        $this->makeAnnouncement($one, ['title' => 'أ']);
        $this->makeAnnouncement($two, ['title' => 'ب']);
        $this->makeAnnouncement(null, ['title' => 'منصّة']);

        $this->actingAs($this->makeAdmin());

        $titles = array_column(
            $this->getJson('/api/v1/announcements')->assertOk()->json('data.announcements'),
            'title'
        );

        sort($titles);
        $this->assertSame(['أ', 'ب', 'منصّة'], $titles);
    }

    public function test_a_student_and_a_guest_cannot_reach_the_announcement_surface(): void
    {
        $announcement = $this->makeAnnouncement($this->makeInstructor());

        $this->actingAs($this->makeStudentUser());
        $this->getJson('/api/v1/announcements')->assertStatus(403);
        $this->postJson('/api/v1/announcements', ['title' => 'x', 'content' => 'y'])->assertStatus(403);
        $this->patchJson("/api/v1/announcements/{$announcement->getKey()}", ['title' => 'z'])->assertStatus(403);
        $this->deleteJson("/api/v1/announcements/{$announcement->getKey()}")->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/announcements')->assertStatus(401);
    }

    // ── CREATE ────────────────────────────────────────────────────────────

    public function test_an_instructor_creates_an_announcement_owned_by_themselves(): void
    {
        $instructor = $this->makeInstructor();

        $this->actingAs($instructor);

        $response = $this->postJson('/api/v1/announcements', [
            'title' => 'عنوان',
            'content' => 'محتوى',
            'linkUrl' => 'https://example.test/x',
            'startDate' => '2026-08-01T10:00',
            'endDate' => '2026-09-01T10:00',
            'isPublished' => false,
        ])->assertStatus(201);

        $response->assertJsonPath('message', 'تم إنشاء الإشعار بنجاح');

        $row = Announcement::query()->firstOrFail();

        $this->assertSame((string) $instructor->getKey(), (string) $row->getAttribute('teacher_id'));
        $this->assertNull($row->getAttribute('course_id'));
        $this->assertSame('محتوى', $row->getAttribute('body'));
        $this->assertFalse((bool) $row->getAttribute('is_published'));

        $response->assertJsonPath('data.announcement.content', 'محتوى')
            ->assertJsonPath('data.announcement.linkUrl', 'https://example.test/x')
            ->assertJsonPath('data.announcement.startDate', '2026-08-01T10:00:00Z')
            ->assertJsonPath('data.announcement.endDate', '2026-09-01T10:00:00Z')
            ->assertJsonPath('data.announcement.isPublished', false);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ APPROVED DECISION 3.2. THE EXACT REQUEST THAT **500s** IN backend/    ║
     * ║ TODAY.                                                                ║
     * ║                                                                      ║
     * ║ backend/ writes `teacher_id => $userId` unconditionally, and          ║
     * ║ `announcements.teacher_id` is a FK into `teachers.user_id` where an    ║
     * ║ admin has no row. Here it is a 201 with teacher_id NULL — a PLATFORM  ║
     * ║ announcement.                                                         ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_an_admin_creating_an_announcement_writes_a_null_teacher_id_and_does_not_500(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin);

        $this->postJson('/api/v1/announcements', [
            'title' => 'إعلان المنصّة',
            'content' => 'محتوى',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.announcement.teacher_id', null);

        $row = Announcement::query()->firstOrFail();

        $this->assertNull(
            $row->getAttribute('teacher_id'),
            'An admin author must produce a PLATFORM announcement, not an FK violation.'
        );
        $this->assertNotSame((string) $admin->getKey(), (string) $row->getAttribute('teacher_id'));
    }

    public function test_a_blank_or_whitespace_title_is_422_on_create(): void
    {
        $this->actingAs($this->makeInstructor());

        foreach (['', '   '] as $title) {
            $this->postJson('/api/v1/announcements', ['title' => $title, 'content' => 'محتوى'])
                ->assertStatus(422)
                ->assertJsonPath('data.errors.title.0', 'العنوان والمحتوى مطلوبان');
        }

        $this->postJson('/api/v1/announcements', ['title' => 'عنوان', 'content' => ''])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.content.0', 'العنوان والمحتوى مطلوبان');

        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_a_dangerous_link_url_is_rejected(): void
    {
        $this->actingAs($this->makeInstructor());

        $this->postJson('/api/v1/announcements', [
            'title' => 'عنوان',
            'content' => 'محتوى',
            'linkUrl' => 'javascript:alert(1)',
        ])->assertStatus(422);

        $this->assertSame(0, Announcement::query()->count());
    }

    // ── COURSE OWNERSHIP IS A SEPARATE CHECK ──────────────────────────────

    public function test_attaching_an_unknown_course_is_404(): void
    {
        $this->actingAs($this->makeInstructor());

        $this->postJson('/api/v1/announcements', [
            'title' => 'عنوان',
            'content' => 'محتوى',
            'courseId' => '0195c1a2-3b4c-7d8e-9f00-112233445566',
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');

        $this->assertSame(0, Announcement::query()->count());
    }

    /**
     * The course check is CoursePolicy::manage, NOT AnnouncementPolicy — two
     * different owners, two different questions, two different messages.
     */
    public function test_attaching_someone_elses_course_is_403_with_the_course_message(): void
    {
        $owner = $this->makeInstructor();
        $course = $this->makeCourseFor($owner);

        $this->actingAs($this->makeInstructor());

        $this->postJson('/api/v1/announcements', [
            'title' => 'عنوان',
            'content' => 'محتوى',
            'course_id' => $course->getKey(),
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not own this course');

        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_an_admin_may_attach_any_course_via_the_gate_bypass(): void
    {
        $course = $this->makeCourseFor($this->makeInstructor());

        $this->actingAs($this->makeAdmin());

        $this->postJson('/api/v1/announcements', [
            'title' => 'عنوان',
            'content' => 'محتوى',
            'courseId' => $course->getKey(),
        ])->assertStatus(201);

        $row = Announcement::query()->firstOrFail();

        $this->assertSame((string) $course->getKey(), (string) $row->getAttribute('course_id'));
        $this->assertNull($row->getAttribute('teacher_id'));
    }

    // ── UPDATE ────────────────────────────────────────────────────────────

    public function test_an_owner_updates_their_own_announcement(): void
    {
        $instructor = $this->makeInstructor();
        $announcement = $this->makeAnnouncement($instructor, ['title' => 'قديم']);

        $this->actingAs($instructor);

        $this->patchJson("/api/v1/announcements/{$announcement->getKey()}", ['title' => 'جديد'])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث الإشعار بنجاح')
            ->assertJsonPath('data.announcement.title', 'جديد');

        $this->assertSame('جديد', $announcement->fresh()->getAttribute('title'));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ APPROVED DECISION 4 — THE ONE DELIBERATE IMPROVEMENT OVER backend/.   ║
     * ║                                                                      ║
     * ║ backend/ 422s `POST {title:""}` and SILENTLY WRITES `PATCH            ║
     * ║ {title:""}`. Both are 422 now.                                        ║
     * ║                                                                      ║
     * ║ AND THE OLD TITLE MUST STILL BE THERE AFTERWARDS — asserted against   ║
     * ║ the DATABASE, because a 422 response is also what a rejected-but-     ║
     * ║ already-written request would produce if the order were wrong.        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_patching_a_blank_title_is_422_and_leaves_the_old_title(): void
    {
        $instructor = $this->makeInstructor();
        $announcement = $this->makeAnnouncement($instructor, ['title' => 'الأصلي']);

        $this->actingAs($instructor);

        foreach (['', '   '] as $blank) {
            $this->patchJson("/api/v1/announcements/{$announcement->getKey()}", ['title' => $blank])
                ->assertStatus(422)
                ->assertJsonPath('data.errors.title.0', 'العنوان والمحتوى مطلوبان');
        }

        $this->assertSame('الأصلي', $announcement->fresh()->getAttribute('title'));
    }

    public function test_patching_a_blank_content_is_422_too(): void
    {
        $instructor = $this->makeInstructor();
        $announcement = $this->makeAnnouncement($instructor, ['body' => 'الأصلي']);

        $this->actingAs($instructor);

        $this->patchJson("/api/v1/announcements/{$announcement->getKey()}", ['content' => ''])
            ->assertStatus(422);

        $this->assertSame('الأصلي', $announcement->fresh()->getAttribute('body'));
    }

    /** Absent fields are UNTOUCHED — the array_key_exists contract. */
    public function test_omitted_fields_are_left_alone(): void
    {
        $instructor = $this->makeInstructor();
        $announcement = $this->makeAnnouncement($instructor, [
            'title' => 'ع',
            'body' => 'م',
            'link_url' => 'https://example.test/keep',
            'start_date' => '2026-01-01 09:00:00',
            'is_published' => true,
        ]);

        $this->actingAs($instructor);

        $this->patchJson("/api/v1/announcements/{$announcement->getKey()}", ['title' => 'ع2'])->assertOk();

        $fresh = $announcement->fresh();

        $this->assertSame('ع2', $fresh->getAttribute('title'));
        $this->assertSame('م', $fresh->getAttribute('body'));
        $this->assertSame('https://example.test/keep', $fresh->getAttribute('link_url'));
        $this->assertSame('2026-01-01 09:00:00', $fresh->getAttribute('start_date')->format('Y-m-d H:i:s'));
        $this->assertTrue((bool) $fresh->getAttribute('is_published'));
    }

    /** …but a PRESENT falsy value IS written. '' clears the link. */
    public function test_a_blank_link_url_clears_it_and_a_false_is_published_unpublishes(): void
    {
        $instructor = $this->makeInstructor();
        $announcement = $this->makeAnnouncement($instructor, [
            'link_url' => 'https://example.test/old',
            'is_published' => true,
        ]);

        $this->actingAs($instructor);

        $this->patchJson("/api/v1/announcements/{$announcement->getKey()}", [
            'linkUrl' => '',
            'isPublished' => false,
        ])->assertOk();

        $fresh = $announcement->fresh();

        $this->assertNull($fresh->getAttribute('link_url'));
        $this->assertFalse((bool) $fresh->getAttribute('is_published'));
    }

    // ── OWNERSHIP: AnnouncementPolicy::manage ─────────────────────────────

    public function test_a_stranger_cannot_patch_or_delete_another_teachers_announcement(): void
    {
        $owner = $this->makeInstructor();
        $announcement = $this->makeAnnouncement($owner, ['title' => 'محفوظ']);

        $this->actingAs($this->makeInstructor());

        $this->patchJson("/api/v1/announcements/{$announcement->getKey()}", ['title' => 'مخترق'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Not your announcement');

        $this->deleteJson("/api/v1/announcements/{$announcement->getKey()}")->assertStatus(403);

        $this->assertSame('محفوظ', $announcement->fresh()->getAttribute('title'));
    }

    /**
     * A PLATFORM announcement (teacher_id NULL) is admin-only — decision 3.2's
     * null branch, exercised from both sides.
     */
    public function test_a_platform_announcement_is_manageable_by_an_admin_and_403_for_an_instructor(): void
    {
        $platform = $this->makeAnnouncement(null, ['title' => 'منصّة']);

        $this->actingAs($this->makeInstructor());

        $this->patchJson("/api/v1/announcements/{$platform->getKey()}", ['title' => 'مخترق'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Not your announcement');
        $this->deleteJson("/api/v1/announcements/{$platform->getKey()}")->assertStatus(403);

        $this->assertSame('منصّة', $platform->fresh()->getAttribute('title'));

        $this->actingAs($this->makeAdmin());

        $this->patchJson("/api/v1/announcements/{$platform->getKey()}", ['title' => 'محدَّث'])->assertOk();
        $this->deleteJson("/api/v1/announcements/{$platform->getKey()}")->assertOk();

        $this->assertNull($platform->fresh());
    }

    /**
     * The policy itself, through the Gate — so the null branch is proven to be
     * a `false` FROM THE POLICY and not merely the HTTP outcome of some other
     * guard upstream.
     */
    public function test_the_policy_returns_false_for_a_platform_announcement_for_every_non_admin(): void
    {
        $platform = $this->makeAnnouncement(null);
        $owned = $this->makeAnnouncement($owner = $this->makeInstructor());

        $this->assertFalse(Gate::forUser($owner)->allows('manage', $platform));
        $this->assertFalse(Gate::forUser($this->makeStudentUser())->allows('manage', $platform));

        // …and the ordinary ownership comparison still works.
        $this->assertTrue(Gate::forUser($owner)->allows('manage', $owned));
        $this->assertFalse(Gate::forUser($this->makeInstructor())->allows('manage', $owned));

        // An admin passes via Gate::before, never entering the body.
        $this->assertTrue(Gate::forUser($this->makeAdmin())->allows('manage', $platform));
    }

    public function test_an_admin_may_edit_any_teachers_announcement(): void
    {
        $announcement = $this->makeAnnouncement($this->makeInstructor(), ['title' => 'قديم']);

        $this->actingAs($this->makeAdmin());

        $this->patchJson("/api/v1/announcements/{$announcement->getKey()}", ['title' => 'إشراف'])->assertOk();

        $this->assertSame('إشراف', $announcement->fresh()->getAttribute('title'));
    }

    // ── DELETE, AND THE 404-BEFORE-403 LADDER ─────────────────────────────

    public function test_an_owner_deletes_their_own_announcement(): void
    {
        $instructor = $this->makeInstructor();
        $announcement = $this->makeAnnouncement($instructor);

        $this->actingAs($instructor);

        $this->deleteJson("/api/v1/announcements/{$announcement->getKey()}")
            ->assertOk()
            ->assertJsonPath('message', 'تم حذف الإشعار بنجاح');

        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_an_unknown_id_is_404_before_any_ownership_check(): void
    {
        $this->actingAs($this->makeInstructor());

        foreach (['patchJson', 'deleteJson'] as $verb) {
            $this->{$verb}('/api/v1/announcements/0195c1a2-3b4c-7d8e-9f00-112233445566', ['title' => 'x'])
                ->assertStatus(404)
                ->assertJsonPath('message', 'Announcement not found');
        }
    }

    // ── PAYLOAD SHAPE ─────────────────────────────────────────────────────

    /**
     * The exact ten keys backend/'s present() emits, in a deliberately mixed
     * snake_case/camelCase — and the `…Z` suffix that misrepresents a local
     * time as UTC, preserved rather than fixed (open decision 8.6).
     */
    public function test_the_payload_shape_matches_the_legacy_presenter_exactly(): void
    {
        $instructor = $this->makeInstructor();
        $this->makeAnnouncement($instructor, [
            'body' => 'المحتوى',
            'link_url' => 'https://example.test/a',
            'start_date' => '2026-03-04 05:06:07',
            'end_date' => null,
        ]);

        $this->actingAs($instructor);

        $row = $this->getJson('/api/v1/announcements')->assertOk()->json('data.announcements.0');

        $this->assertSame([
            'id', 'course_id', 'teacher_id', 'title', 'content',
            'linkUrl', 'startDate', 'endDate', 'isPublished', 'createdAt',
        ], array_keys($row));

        $this->assertSame('المحتوى', $row['content']);
        $this->assertArrayNotHasKey('body', $row);

        // The pseudo-UTC suffix, asserted VERBATIM so a well-meaning "fix"
        // fails loudly instead of shifting every displayed schedule.
        $this->assertSame('2026-03-04T05:06:07Z', $row['startDate']);
        $this->assertNull($row['endDate']);

        // …while createdAt is the RAW 'Y-m-d H:i:s' with no T and no Z. Two
        // date shapes in one payload, both backend/'s.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['createdAt']);
    }

    /** An unparseable date is a SILENT null here (and a 422 on broadcasts). */
    public function test_an_unparseable_date_is_silently_null(): void
    {
        $this->actingAs($this->makeInstructor());

        $this->postJson('/api/v1/announcements', [
            'title' => 'عنوان',
            'content' => 'محتوى',
            'startDate' => 'not-a-date-at-all',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.announcement.startDate', null);
    }
}
