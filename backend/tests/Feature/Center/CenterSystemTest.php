<?php

declare(strict_types=1);

namespace Tests\Feature\Center;

use App\Actions\Center\GenerateCenterCode;
use App\Enums\ContentItemType;
use App\Models\CenterAttendance;
use App\Models\CenterCode;
use App\Models\CenterGroup;
use App\Models\CenterSession;
use App\Models\Course;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Center System — covers the 21 scenarios named explicitly in the feature
 * request: code uniqueness, multi-teacher membership without course mixing,
 * cross-teacher isolation on groups/sessions/attendance/codes, course
 * visibility, the three attendance-marking methods, notes, session closing
 * counts, and report scoping.
 */
final class CenterSystemTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $teacherA;

    private User $teacherB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        [$this->teacherA] = $this->makeTeacherWithProfile([], ['full_name' => 'المعلّم أ']);
        [$this->teacherB] = $this->makeTeacherWithProfile([], ['full_name' => 'المعلّم ب']);
    }

    // ══ Group CRUD over real HTTP — catches signature-collision fatals that
    // ══ a direct-model fixture (makeCenterGroup()) cannot, since it never
    // ══ goes through the Form Request/Controller at all. ═════════════════

    public function test_an_instructor_can_create_update_and_delete_their_own_group_over_http(): void
    {
        $this->loginAs($this->teacherA);

        $created = $this->postJson('/api/v1/center/groups', [
            'center_name' => 'سنتر المستقبل',
            'grade_label' => 'الثالث الثانوي',
            'group_name' => 'مجموعة السبت',
            'billing_type' => 'حصة',
            'schedules' => [['day_of_week' => 'السبت', 'time' => '18:00']],
        ])->assertStatus(201)->json('data.group');

        $this->assertSame('سنتر المستقبل', $created['center_name']);
        $this->assertSame('السبت 6:00 مساءً', $created['schedule_text']);
        $this->assertSame([['day_of_week' => 'السبت', 'time' => '18:00']], $created['schedules']);
        $this->assertSame(1, CenterGroup::query()->where('teacher_id', $this->teacherA->getKey())->count());

        $this->patchJson("/api/v1/center/groups/{$created['id']}", [
            'group_name' => 'مجموعة الأحد',
            'schedules' => [
                ['day_of_week' => 'السبت', 'time' => '18:00'],
                ['day_of_week' => 'الثلاثاء', 'time' => '16:00'],
            ],
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.group.group_name', 'مجموعة الأحد')
            ->assertJsonPath('data.group.schedule_text', 'السبت 6:00 مساءً، الثلاثاء 4:00 مساءً');

        $this->deleteJson("/api/v1/center/groups/{$created['id']}")->assertStatus(200);
        $this->assertSame(0, CenterGroup::query()->count());
    }

    // ══ Deleting a group WITH a roster requires the teacher's own password ═

    public function test_deleting_a_group_with_students_requires_the_teachers_password(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);
        \Illuminate\Support\Facades\DB::table('center_group_students')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'center_group_id' => $group->getKey(),
            'student_id' => $student->getKey(),
            'joined_at' => now(),
        ]);

        $this->loginAs($this->teacherA);

        $this->deleteJson("/api/v1/center/groups/{$group->getKey()}")
            ->assertStatus(401);
        $this->assertSame(1, CenterGroup::query()->count());

        $this->deleteJson("/api/v1/center/groups/{$group->getKey()}", ['password' => 'wrong-password'])
            ->assertStatus(401);
        $this->assertSame(1, CenterGroup::query()->count());

        $this->deleteJson("/api/v1/center/groups/{$group->getKey()}", $this->reauth())
            ->assertStatus(200);
        $this->assertSame(0, CenterGroup::query()->count());
    }

    public function test_deleting_an_empty_group_needs_no_password(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);

        $this->loginAs($this->teacherA);
        $this->deleteJson("/api/v1/center/groups/{$group->getKey()}")->assertStatus(200);

        $this->assertSame(0, CenterGroup::query()->count());
    }

    // ══ Tests 1-3 — code generation and uniqueness ═══════════════════════

    public function test_codes_are_generated_uniquely_for_each_teacher(): void
    {
        $generate = app(GenerateCenterCode::class);

        $codeA = $generate(\App\Models\Teacher::find($this->teacherA->getKey()), null);
        $codeB = $generate(\App\Models\Teacher::find($this->teacherB->getKey()), null);

        $this->assertMatchesRegularExpression('/^[A-Z]\d{5}$/', $codeA->code);
        $this->assertMatchesRegularExpression('/^[A-Z]\d{5}$/', $codeB->code);
        $this->assertNotSame($codeA->code, $codeB->code);
        $this->assertSame($this->teacherA->getKey(), $codeA->teacher_id);
        $this->assertSame($this->teacherB->getKey(), $codeB->teacher_id);
    }

    public function test_only_admin_may_generate_or_view_center_codes(): void
    {
        $this->loginAs($this->teacherA);

        $this->postJson('/api/v1/admin/center-codes', ['teacher_id' => $this->teacherA->getKey()])
            ->assertStatus(403);
        $this->getJson('/api/v1/admin/center-codes')->assertStatus(403);
    }

    // ══ Tests 4-6 — student joins multiple teachers, courses never mix ═══

    public function test_a_student_joining_two_teachers_sees_each_teachers_courses_in_separate_entries(): void
    {
        $this->makeCourseFor($this->teacherA, [
            'title' => 'كورس A',
            'is_published' => true,
            'visibility' => Course::VISIBILITY_CENTER,
        ]);
        $this->makeCourseFor($this->teacherB, [
            'title' => 'كورس B',
            'is_published' => true,
            'visibility' => Course::VISIBILITY_CENTER,
        ]);

        $codeA = $this->makeCenterCode($this->teacherA);
        $codeB = $this->makeCenterCode($this->teacherB);

        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);

        $this->postJson('/api/v1/center/join', ['code' => $codeA->code])->assertStatus(200);
        $this->postJson('/api/v1/center/join', ['code' => $codeB->code])->assertStatus(200);

        $centers = $this->getJson('/api/v1/center/my-centers')->assertStatus(200)->json('data.centers');

        $this->assertCount(2, $centers);

        $byTeacher = collect($centers)->keyBy(fn ($c) => $c['teacher']['id']);

        $titlesA = collect($byTeacher[$this->teacherA->getKey()]['courses'])->pluck('title')->all();
        $titlesB = collect($byTeacher[$this->teacherB->getKey()]['courses'])->pluck('title')->all();

        $this->assertSame(['كورس A'], $titlesA);
        $this->assertSame(['كورس B'], $titlesB);
    }

    public function test_joining_the_same_teacher_twice_is_idempotent_not_an_error(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);

        $this->postJson('/api/v1/center/join', ['code' => $code->code])->assertStatus(200);
        $this->postJson('/api/v1/center/join', ['code' => $code->code])->assertStatus(200);

        $this->assertSame(
            1,
            \App\Models\CenterMembership::query()->where('student_id', $student->getKey())->count()
        );
    }

    public function test_a_code_already_linked_to_another_student_is_refused(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        [$firstUser] = $this->makeStudentWithCode();
        $this->loginAs($firstUser);
        $this->postJson('/api/v1/center/join', ['code' => $code->code])->assertStatus(200);

        [$secondUser] = $this->makeStudentWithCode();
        $this->loginAs($secondUser);
        $this->postJson('/api/v1/center/join', ['code' => $code->code])
            ->assertStatus(409)
            ->assertJsonPath('message', \App\Actions\Center\JoinCenter::CODE_ALREADY_LINKED);

        $this->assertSame(1, \App\Models\CenterMembership::query()->where('center_code_id', $code->getKey())->count());
    }

    public function test_unlinking_frees_the_code_for_a_different_student(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        [$firstUser, $firstStudent] = $this->makeStudentWithCode();
        $this->loginAs($firstUser);
        $this->postJson('/api/v1/center/join', ['code' => $code->code])->assertStatus(200);

        $membershipId = \App\Models\CenterMembership::query()
            ->where('center_code_id', $code->getKey())
            ->where('student_id', $firstStudent->getKey())
            ->value('id');

        $this->loginAsAdmin();
        $this->deleteJson("/api/v1/admin/center-memberships/{$membershipId}")->assertStatus(200);

        [$secondUser] = $this->makeStudentWithCode();
        $this->loginAs($secondUser);
        $this->postJson('/api/v1/center/join', ['code' => $code->code])->assertStatus(200);

        $this->assertSame(1, \App\Models\CenterMembership::query()->where('center_code_id', $code->getKey())->count());
    }

    public function test_an_invalid_code_is_rejected_clearly(): void
    {
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);

        $this->postJson('/api/v1/center/join', ['code' => 'Z99999'])->assertStatus(404);
    }

    // ══ Test 11-13 — course visibility ════════════════════════════════════

    public function test_a_platform_only_course_never_appears_in_center_listing(): void
    {
        $this->makeCourseFor($this->teacherA, [
            'title' => 'كورس منصة',
            'is_published' => true,
            'visibility' => Course::VISIBILITY_PLATFORM,
        ]);

        $code = $this->makeCenterCode($this->teacherA);
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);
        $this->postJson('/api/v1/center/join', ['code' => $code->code]);

        $centers = $this->getJson('/api/v1/center/my-centers')->json('data.centers');

        $this->assertSame([], $centers[0]['courses']);
    }

    public function test_a_both_visibility_course_appears_in_center_listing_and_public_catalogue(): void
    {
        $course = $this->makeCourseFor($this->teacherA, [
            'title' => 'كورس مزدوج',
            'is_published' => true,
            'visibility' => Course::VISIBILITY_BOTH,
        ]);

        $this->getJson('/api/v1/courses')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $course->getKey()]);

        $code = $this->makeCenterCode($this->teacherA);
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);
        $this->postJson('/api/v1/center/join', ['code' => $code->code]);

        $centers = $this->getJson('/api/v1/center/my-centers')->json('data.centers');

        $this->assertSame('كورس مزدوج', $centers[0]['courses'][0]['title']);
    }

    public function test_a_center_only_course_is_absent_from_the_public_catalogue(): void
    {
        $course = $this->makeCourseFor($this->teacherA, [
            'title' => 'كورس سنتر فقط',
            'is_published' => true,
            'visibility' => Course::VISIBILITY_CENTER,
        ]);

        $response = $this->getJson('/api/v1/courses')->assertStatus(200);

        $ids = collect($response->json('data.courses'))->pluck('id');
        $this->assertNotContains($course->getKey(), $ids);
    }

    /**
     * The detail page itself, not just the catalogue listing — a student
     * cannot reach a center-only course by guessing/visiting its URL unless
     * they actually joined THAT course's own teacher's center. Joining a
     * DIFFERENT teacher's center is not enough (Course::VISIBILITY_CENTER +
     * CenterMembership scoped to teacher_id — see ResolvesCourseOwnership::
     * hasCenterAccess()).
     */
    public function test_a_center_only_course_is_unreachable_without_that_teachers_own_membership(): void
    {
        $course = $this->makeCourseFor($this->teacherA, [
            'title' => 'كورس سنتر أ',
            'is_published' => true,
            'visibility' => Course::VISIBILITY_CENTER,
        ]);

        $codeB = $this->makeCenterCode($this->teacherB);
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);
        $this->postJson('/api/v1/center/join', ['code' => $codeB->code])->assertStatus(200);

        // Joined Teacher B's center, NOT Teacher A's — Teacher A's course stays 404.
        $this->getJson("/api/v1/courses/{$course->getKey()}")->assertStatus(404);

        $codeA = $this->makeCenterCode($this->teacherA);
        $this->postJson('/api/v1/center/join', ['code' => $codeA->code])->assertStatus(200);

        // Now a real member of Teacher A's center — the same course opens.
        $this->getJson("/api/v1/courses/{$course->getKey()}")->assertStatus(200);
    }

    // ══ Tests 7-8, 10 — group/session/code isolation ═════════════════════

    public function test_teacher_a_cannot_access_teacher_bs_group(): void
    {
        $group = $this->makeCenterGroup($this->teacherB);

        $this->loginAs($this->teacherA);

        $this->patchJson("/api/v1/center/groups/{$group->getKey()}", ['group_name' => 'مسروقة'])
            ->assertStatus(403);
        $this->deleteJson("/api/v1/center/groups/{$group->getKey()}")->assertStatus(403);
        $this->getJson("/api/v1/center/groups/{$group->getKey()}/sessions")->assertStatus(403);
    }

    public function test_teacher_a_cannot_access_teacher_bs_session(): void
    {
        $group = $this->makeCenterGroup($this->teacherB);
        $session = $this->makeCenterSession($this->teacherB, $group);

        $this->loginAs($this->teacherA);

        $this->getJson("/api/v1/center/sessions/{$session->getKey()}")->assertStatus(403);
        $this->postJson("/api/v1/center/sessions/{$session->getKey()}/attendance", ['identifier' => 'x'])
            ->assertStatus(403);
        $this->postJson("/api/v1/center/sessions/{$session->getKey()}/close")->assertStatus(403);
    }

    // ══ Test 9 — the crux: cannot mark a foreign student present ═════════

    public function test_teacher_a_cannot_mark_attendance_for_a_student_who_is_not_their_center_member(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);

        // This student belongs to Teacher B's center, not Teacher A's.
        [$foreignStudent, $profile] = $this->makeStudentWithCode();
        $codeB = $this->makeCenterCode($this->teacherB);
        $this->loginAs($foreignStudent);
        $this->postJson('/api/v1/center/join', ['code' => $codeB->code]);
        $this->post('/api/v1/auth/logout');

        $this->loginAs($this->teacherA);

        $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $profile->student_code]
        )->assertStatus(422);

        $this->assertSame(0, CenterAttendance::query()->count());
    }

    // ══ Test 14-16 — the three attendance methods + close counts ═════════

    public function test_attendance_can_be_marked_by_code_or_phone_and_close_computes_counts(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);

        [, $s1] = $this->joinedStudent($this->makeCenterCode($this->teacherA), ['phone' => '01011111111']);
        [, $s2] = $this->joinedStudent($this->makeCenterCode($this->teacherA), ['phone' => '01022222222']);
        [, $s3] = $this->joinedStudent($this->makeCenterCode($this->teacherA), ['phone' => '01033333333']);

        $this->loginAs($this->teacherA);

        // `center_group_students` (the "known roster" for absence counting)
        // is only populated by having actually attended at least once — see
        // App\Actions\Center\MarkAttendance's banner. An earlier session
        // establishes s3 as a known group member who will then be ABSENT
        // from the session under test.
        $priorSession = $this->makeCenterSession($this->teacherA, $group);
        $this->postJson(
            "/api/v1/center/sessions/{$priorSession->getKey()}/attendance",
            ['identifier' => $s3->student_code]
        )->assertStatus(200);
        $this->postJson("/api/v1/center/sessions/{$priorSession->getKey()}/close")->assertStatus(200);

        $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $s1->student_code]
        )->assertStatus(200);

        $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $s2->phone]
        )->assertStatus(200);

        $this->assertSame(2, CenterAttendance::query()->where('center_session_id', $session->getKey())->count());

        $close = $this->postJson("/api/v1/center/sessions/{$session->getKey()}/close")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(3, $close['total']);
        $this->assertSame(2, $close['present']);
        $this->assertSame(1, $close['absent']);

        $this->assertSame(
            CenterSession::STATUS_CLOSED,
            CenterSession::find($session->getKey())->status
        );
    }

    // ══ Attendance by CENTER code (1 letter + 5 digits) — what the card ══
    // ══ actually carries, per the teacher's explicit request.            ══

    public function test_attendance_can_be_marked_by_the_center_code_itself(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);

        $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $code->code]
        )->assertStatus(200);

        $this->assertDatabaseHas('center_attendances', [
            'center_session_id' => $session->getKey(),
            'student_id' => $student->getKey(),
        ]);
    }

    /**
     * A code can no longer be linked to two students through the normal
     * join flow (decision: "كود السنتر يتم ربطه مرة واحدة فقط بالطالب") —
     * JoinCenter itself now refuses a second student on the same code. This
     * defensive guard in MarkAttendance is therefore belt-and-braces for
     * data that predates that change (or a future bypass of JoinCenter),
     * which is why the two memberships are inserted directly here rather
     * than through POST /center/join.
     */
    public function test_a_center_code_shared_by_several_students_is_rejected_as_ambiguous(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);
        $code = $this->makeCenterCode($this->teacherA);
        [, $studentOne] = $this->makeStudentWithCode();
        [, $studentTwo] = $this->makeStudentWithCode();
        \App\Models\CenterMembership::query()->create([
            'student_id' => $studentOne->getKey(), 'teacher_id' => $this->teacherA->getKey(),
            'center_code_id' => $code->getKey(), 'joined_at' => now(),
        ]);
        \App\Models\CenterMembership::query()->create([
            'student_id' => $studentTwo->getKey(), 'teacher_id' => $this->teacherA->getKey(),
            'center_code_id' => $code->getKey(), 'joined_at' => now(),
        ]);

        $this->loginAs($this->teacherA);

        $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $code->code]
        )->assertStatus(422)->assertJsonPath(
            'data.errors.identifier.0',
            \App\Actions\Center\MarkAttendance::CODE_SHARED
        );

        $this->assertSame(0, CenterAttendance::query()->where('center_session_id', $session->getKey())->count());
    }

    public function test_another_teachers_center_code_never_resolves_here(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);
        $foreignCode = $this->makeCenterCode($this->teacherB);
        $this->joinedStudent($foreignCode);

        $this->loginAs($this->teacherA);

        $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $foreignCode->code]
        )->assertStatus(422)->assertJsonPath(
            'data.errors.identifier.0',
            \App\Actions\Center\MarkAttendance::NOT_FOUND
        );
    }

    // ══ "حصة تعويضية" — attendance in a DIFFERENT group than the student's ══
    // ══ home group requires confirmation and never moves the student.     ══

    public function test_attendance_in_a_different_group_requires_confirmation_first(): void
    {
        $groupA = $this->makeCenterGroup($this->teacherA);
        $groupB = $this->makeCenterGroup($this->teacherA);
        $sessionA = $this->makeCenterSession($this->teacherA, $groupA);
        $sessionB = $this->makeCenterSession($this->teacherA, $groupB);
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);

        // First-ever attendance establishes groupA as the student's home group.
        $this->postJson(
            "/api/v1/center/sessions/{$sessionA->getKey()}/attendance",
            ['identifier' => $code->code]
        )->assertStatus(200);

        // Checking into groupB's session, without confirming, is a 409 —
        // nothing is written.
        $this->postJson(
            "/api/v1/center/sessions/{$sessionB->getKey()}/attendance",
            ['identifier' => $code->code]
        )->assertStatus(409);

        $this->assertDatabaseMissing('center_attendances', [
            'center_session_id' => $sessionB->getKey(),
            'student_id' => $student->getKey(),
        ]);
        $this->assertDatabaseMissing('center_group_students', [
            'center_group_id' => $groupB->getKey(),
            'student_id' => $student->getKey(),
        ]);
    }

    public function test_confirming_a_different_group_check_in_marks_it_as_makeup_without_moving_the_student(): void
    {
        $groupA = $this->makeCenterGroup($this->teacherA);
        $groupB = $this->makeCenterGroup($this->teacherA);
        $sessionA = $this->makeCenterSession($this->teacherA, $groupA);
        $sessionB = $this->makeCenterSession($this->teacherA, $groupB);
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);

        $this->postJson(
            "/api/v1/center/sessions/{$sessionA->getKey()}/attendance",
            ['identifier' => $code->code]
        )->assertStatus(200);

        $this->postJson(
            "/api/v1/center/sessions/{$sessionB->getKey()}/attendance",
            ['identifier' => $code->code, 'confirm' => true]
        )->assertStatus(200);

        $this->assertDatabaseHas('center_attendances', [
            'center_session_id' => $sessionB->getKey(),
            'student_id' => $student->getKey(),
            'is_makeup' => 1,
        ]);

        // NEVER moved: groupB's roster still does not include this student,
        // so groupB's own total/absent counts stay exactly what the teacher
        // actually enrolled — the whole point of "compensatory", not "transfer".
        $this->assertDatabaseMissing('center_group_students', [
            'center_group_id' => $groupB->getKey(),
            'student_id' => $student->getKey(),
        ]);

        $sessionBDetail = $this->getJson("/api/v1/center/sessions/{$sessionB->getKey()}")
            ->assertStatus(200)->json('data.session');

        $this->assertSame(0, $sessionBDetail['total']);
        $this->assertSame(0, $sessionBDetail['present']);
        $this->assertSame(1, $sessionBDetail['makeup_present']);
        $this->assertTrue($sessionBDetail['attendances'][0]['is_makeup']);
    }

    public function test_a_second_attendance_in_the_students_own_home_group_needs_no_confirmation(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $session1 = $this->makeCenterSession($this->teacherA, $group);
        $session2 = $this->makeCenterSession($this->teacherA, $group);
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);

        $this->postJson(
            "/api/v1/center/sessions/{$session1->getKey()}/attendance",
            ['identifier' => $code->code]
        )->assertStatus(200);

        // Same (home) group's NEXT session — no confirmation needed even
        // though it is a different session.
        $this->postJson(
            "/api/v1/center/sessions/{$session2->getKey()}/attendance",
            ['identifier' => $code->code]
        )->assertStatus(200);

        $this->assertDatabaseHas('center_attendances', [
            'center_session_id' => $session2->getKey(),
            'student_id' => $student->getKey(),
            'is_makeup' => 0,
        ]);
    }

    // ══ Tests 17-18 — delete attendance, add/edit note ═══════════════════

    public function test_attendance_can_be_deleted_and_a_note_added_then_edited(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);

        $mark = $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $student->student_code]
        )->assertStatus(200)->json('data.session.attendances.0');

        $this->patchJson(
            "/api/v1/center/attendance/{$mark['attendance_id']}/note",
            ['note' => 'ملاحظة أولى']
        )->assertStatus(200);

        $this->assertSame('ملاحظة أولى', CenterAttendance::find($mark['attendance_id'])->note);

        $this->patchJson(
            "/api/v1/center/attendance/{$mark['attendance_id']}/note",
            ['note' => 'ملاحظة معدَّلة']
        )->assertStatus(200);

        $this->assertSame('ملاحظة معدَّلة', CenterAttendance::find($mark['attendance_id'])->note);

        $this->deleteJson("/api/v1/center/sessions/{$session->getKey()}/attendance/{$mark['attendance_id']}")
            ->assertStatus(200);

        $this->assertSame(0, CenterAttendance::query()->count());
    }

    // ══ Tests 20 — reports scoped to the caller's own center students ═══

    public function test_student_report_never_lists_another_teachers_center_student(): void
    {
        $codeA = $this->makeCenterCode($this->teacherA);
        $codeB = $this->makeCenterCode($this->teacherB);

        [, $ownStudent] = $this->joinedStudent($codeA);
        [, $foreignStudent] = $this->joinedStudent($codeB);

        $this->loginAs($this->teacherA);

        $rows = $this->getJson('/api/v1/center/reports/students')->assertStatus(200)->json('data.students');
        $codes = collect($rows)->pluck('student_code');

        $this->assertContains($ownStudent->student_code, $codes);
        $this->assertNotContains($foreignStudent->student_code, $codes);
    }

    // ══ Decision 10.26 — course/materials picker + video watch percentage ═

    public function test_center_courses_endpoint_only_returns_center_visible_courses(): void
    {
        $this->makeCourseFor($this->teacherA, ['title' => 'منصة فقط', 'visibility' => Course::VISIBILITY_PLATFORM]);
        $this->makeCourseFor($this->teacherA, ['title' => 'سنتر', 'visibility' => Course::VISIBILITY_CENTER]);
        $this->makeCourseFor($this->teacherA, ['title' => 'كلاهما', 'visibility' => Course::VISIBILITY_BOTH]);
        $this->makeCourseFor($this->teacherB, ['title' => 'كورس مدرس آخر', 'visibility' => Course::VISIBILITY_CENTER]);

        $this->loginAs($this->teacherA);

        $titles = collect(
            $this->getJson('/api/v1/center/courses')->assertStatus(200)->json('data.courses')
        )->pluck('title');

        $this->assertContains('سنتر', $titles);
        $this->assertContains('كلاهما', $titles);
        $this->assertNotContains('منصة فقط', $titles);
        $this->assertNotContains('كورس مدرس آخر', $titles);
    }

    public function test_materials_endpoint_rejects_a_platform_only_or_foreign_course(): void
    {
        $platformOnly = $this->makeCourseFor($this->teacherA, ['visibility' => Course::VISIBILITY_PLATFORM]);
        $foreign = $this->makeCourseFor($this->teacherB, ['visibility' => Course::VISIBILITY_CENTER]);

        $this->loginAs($this->teacherA);

        $this->getJson("/api/v1/center/courses/{$platformOnly->getKey()}/materials")->assertStatus(404);
        $this->getJson("/api/v1/center/courses/{$foreign->getKey()}/materials")->assertStatus(404);
    }

    public function test_attaching_exams_over_http_actually_links_them_and_video_watch_percentage_is_computed(): void
    {
        $course = $this->makeCourseFor($this->teacherA, ['visibility' => Course::VISIBILITY_CENTER]);
        $section = $this->makeSection($course);
        [, $exam] = $this->makeExam($section, [], ['title' => 'امتحان الوحدة الأولى']);
        $videoItem = $this->makeContentItemWithDetail(
            $section,
            ContentItemType::Video,
            ['video_url' => 'https://example.com/v.mp4', 'duration' => 600],
            ['title' => 'فيديو المقدمة']
        );

        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);

        // Half the video watched.
        LessonProgress::recordFor((string) $student->getKey(), (string) $videoItem->getKey(), 300, false);

        $this->loginAs($this->teacherA);

        $this->postJson("/api/v1/center/sessions/{$session->getKey()}/attendance", ['identifier' => $student->student_code])
            ->assertStatus(200);

        // The materials picker must offer both, scoped to this course.
        $materials = $this->getJson("/api/v1/center/courses/{$course->getKey()}/materials")
            ->assertStatus(200)->json('data');
        $this->assertCount(1, $materials['exams']);
        $this->assertCount(1, $materials['videos']);

        $this->postJson("/api/v1/center/sessions/{$session->getKey()}/exams", ['exam_ids' => [$exam->getKey()]])
            ->assertStatus(200);
        $this->postJson("/api/v1/center/sessions/{$session->getKey()}/videos", ['video_ids' => [(string) $videoItem->getKey()]])
            ->assertStatus(200);

        // Regression: attachExams/attachVideos must have actually inserted a
        // row, not silently failed on the pivot's own UUID primary key.
        $this->assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('center_session_exams')->where('center_session_id', $session->getKey())->count()
        );
        $this->assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('center_session_videos')->where('center_session_id', $session->getKey())->count()
        );

        $detail = $this->getJson("/api/v1/center/sessions/{$session->getKey()}")->assertStatus(200)->json('data.session');

        $this->assertCount(1, $detail['exams']);
        $this->assertCount(1, $detail['videos']);

        $row = collect($detail['attendances'])->firstWhere('student_id', (string) $student->getKey());
        $this->assertSame(50, $row['video_progress'][(string) $videoItem->getKey()]['percent']);
        $this->assertTrue($row['video_progress'][(string) $videoItem->getKey()]['watched']);
    }

    // ══ Decision 10.28 — student report's "كود الطالب" is the center code ═

    public function test_student_report_shows_and_filters_by_the_center_code_not_the_academic_code(): void
    {
        $codeA = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($codeA);

        $this->loginAs($this->teacherA);

        $row = collect(
            $this->getJson('/api/v1/center/reports/students')->assertStatus(200)->json('data.students')
        )->firstWhere('student_id', (string) $student->getKey());

        $this->assertSame($codeA->code, $row['center_code']);
        $this->assertNotSame($student->student_code, $row['center_code']);

        // Filtering by "student_code" now searches the CENTER code, not STU######.
        $byCenterCode = $this->getJson('/api/v1/center/reports/students?student_code=' . $codeA->code)
            ->assertStatus(200)->json('data.students');
        $this->assertCount(1, $byCenterCode);

        $byAcademicCode = $this->getJson('/api/v1/center/reports/students?student_code=' . $student->student_code)
            ->assertStatus(200)->json('data.students');
        $this->assertCount(0, $byAcademicCode);
    }

    public function test_student_report_filters_by_grade_level(): void
    {
        $codeA = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($codeA, ['grade_level' => 'الصف الثاني الثانوي']);

        $this->loginAs($this->teacherA);

        $matching = $this->getJson('/api/v1/center/reports/students?grade_level=' . urlencode('الصف الثاني الثانوي'))
            ->assertStatus(200)->json('data.students');
        $this->assertCount(1, $matching);

        $nonMatching = $this->getJson('/api/v1/center/reports/students?grade_level=' . urlencode('الصف الأول الثانوي'))
            ->assertStatus(200)->json('data.students');
        $this->assertCount(0, $nonMatching);
    }

    public function test_teacher_can_unlink_their_own_student_from_the_report(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);
        $row = collect(
            $this->getJson('/api/v1/center/reports/students')->assertStatus(200)->json('data.students')
        )->firstWhere('student_id', (string) $student->getKey());

        $this->deleteJson("/api/v1/center/memberships/{$row['membership_id']}")->assertStatus(200);

        $this->assertSame(
            0,
            \App\Models\CenterMembership::query()->where('student_id', $student->getKey())->count()
        );

        // The code is free again, per decision "كود السنتر... في حالة حذفه يمكن استخدامه مرة ثانية".
        [$otherUser] = $this->makeStudentWithCode();
        $this->loginAs($otherUser);
        $this->postJson('/api/v1/center/join', ['code' => $code->code])->assertStatus(200);
    }

    public function test_teacher_a_cannot_unlink_teacher_bs_student(): void
    {
        $codeB = $this->makeCenterCode($this->teacherB);
        [, $foreignStudent] = $this->joinedStudent($codeB);

        $this->loginAs($this->teacherB);
        $row = collect(
            $this->getJson('/api/v1/center/reports/students')->assertStatus(200)->json('data.students')
        )->firstWhere('student_id', (string) $foreignStudent->getKey());

        $this->loginAs($this->teacherA);
        $this->deleteJson("/api/v1/center/memberships/{$row['membership_id']}")->assertStatus(404);

        $this->assertSame(
            1,
            \App\Models\CenterMembership::query()->where('student_id', $foreignStudent->getKey())->count()
        );
    }

    // ══ Decision 10.27 — reopen a closed session, delete a session ═══════

    public function test_reopening_a_closed_session_allows_attendance_again(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);

        $this->postJson("/api/v1/center/sessions/{$session->getKey()}/close")->assertStatus(200);

        // Closed: marking attendance is refused.
        $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $student->student_code]
        )->assertStatus(422);

        $this->postJson("/api/v1/center/sessions/{$session->getKey()}/reopen")
            ->assertStatus(200)
            ->assertJsonPath('data.session.status', 'open');

        // Reopened: attendance works again.
        $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $student->student_code]
        )->assertStatus(200);
    }

    public function test_teacher_b_cannot_reopen_or_delete_teacher_as_session(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);

        $this->loginAs($this->teacherB);

        $this->postJson("/api/v1/center/sessions/{$session->getKey()}/reopen")->assertStatus(403);
        $this->deleteJson("/api/v1/center/sessions/{$session->getKey()}")->assertStatus(403);
    }

    public function test_deleting_a_session_cascades_its_attendance(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);

        $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $student->student_code]
        )->assertStatus(200);

        $this->deleteJson("/api/v1/center/sessions/{$session->getKey()}")->assertStatus(200);

        $this->assertSame(0, CenterSession::query()->count());
        $this->assertSame(0, CenterAttendance::query()->count());
    }

    // ══ Decision 10.25 — "فك الربط" ═══════════════════════════════════════

    public function test_unlinking_a_membership_lets_the_student_join_the_same_teacher_again(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        [$studentUser] = $this->joinedStudent($code);

        $membershipId = \App\Models\CenterMembership::query()
            ->where('teacher_id', $this->teacherA->getKey())
            ->where('student_id', $studentUser->getKey())
            ->value('id');

        $this->loginAs($this->makeAdmin());

        $this->deleteJson("/api/v1/admin/center-memberships/{$membershipId}")->assertStatus(200);
        $this->assertSame(0, \App\Models\CenterMembership::query()->count());

        $this->loginAs($studentUser);
        $this->postJson('/api/v1/center/join', ['code' => $code->code])->assertStatus(200);

        $this->assertSame(1, \App\Models\CenterMembership::query()->count());
    }

    public function test_the_code_details_table_carries_student_phone_and_membership_id(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code, ['phone' => '01099998888', 'parent_phone' => '01077776666']);

        $this->loginAs($this->makeAdmin());

        $row = $this->getJson('/api/v1/admin/center-codes/search?code=' . $code->code)
            ->assertStatus(200)
            ->json('data.codes.0');

        $this->assertCount(1, $row['students']);
        $this->assertSame('01099998888', $row['students'][0]['phone']);
        $this->assertSame('01077776666', $row['students'][0]['parent_phone']);
        $this->assertNotEmpty($row['students'][0]['membership_id']);
    }

    public function test_a_guest_and_a_student_cannot_reach_instructor_center_routes(): void
    {
        $this->getJson('/api/v1/center/groups')->assertStatus(401);

        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);
        $this->getJson('/api/v1/center/groups')->assertStatus(403);
    }

    // ══ "زر تفعيل نظام السنتر" — admin-only per-teacher gate ═══════════

    public function test_a_teacher_with_center_disabled_cannot_reach_any_center_route(): void
    {
        \App\Models\Teacher::query()->where('user_id', $this->teacherA->getKey())
            ->update(['center_enabled' => false]);

        $this->loginAs($this->teacherA);
        $this->getJson('/api/v1/center/groups')->assertStatus(403);
        $this->postJson('/api/v1/center/groups', [])->assertStatus(403);
    }

    public function test_disabling_then_reenabling_the_center_system_loses_no_data(): void
    {
        $group = $this->makeCenterGroup($this->teacherA);
        $code = $this->makeCenterCode($this->teacherA);
        [, $student] = $this->joinedStudent($code);
        \Illuminate\Support\Facades\DB::table('center_group_students')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'center_group_id' => $group->getKey(),
            'student_id' => $student->getKey(),
            'joined_at' => now(),
        ]);

        \App\Models\Teacher::query()->where('user_id', $this->teacherA->getKey())
            ->update(['center_enabled' => false]);

        $this->loginAs($this->teacherA);
        $this->getJson('/api/v1/center/groups')->assertStatus(403);

        // The group and its roster still exist untouched in the database —
        // the gate never deletes or hides a row, it only blocks the route.
        $this->assertSame(1, CenterGroup::query()->whereKey($group->getKey())->count());
        $this->assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('center_group_students')->where('center_group_id', $group->getKey())->count()
        );

        \App\Models\Teacher::query()->where('user_id', $this->teacherA->getKey())
            ->update(['center_enabled' => true]);

        // Re-login: the guard cached the User (and its lazy `teacher`
        // relation) from the earlier 403 request within this same test
        // process — a fresh login re-resolves both, exactly like a real
        // user's very next request would in production without needing to
        // log out first (a real request per-request-resolves the guard;
        // only this in-process test client persists it across calls).
        $this->loginAs($this->teacherA);

        $rows = $this->getJson('/api/v1/center/groups')->assertStatus(200)->json('data.groups');
        $this->assertCount(1, $rows);
        $this->assertSame($group->getKey(), $rows[0]['id']);
    }

    // ══ "تفعيل كورس سنتر" — free grant, no Subscription, two mutually ═══
    // ══ exclusive modes: a whole session's attendees, or one student.  ═══

    public function test_activating_for_a_session_grants_every_attendee_and_no_one_else(): void
    {
        $course = $this->makeCourseFor($this->teacherA, ['visibility' => Course::VISIBILITY_CENTER]);
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);
        [$attendeeUser, $attendee] = $this->joinedStudent($this->makeCenterCode($this->teacherA));
        [, $absentee] = $this->joinedStudent($this->makeCenterCode($this->teacherA));

        $this->loginAs($this->teacherA);
        $this->postJson(
            "/api/v1/center/sessions/{$session->getKey()}/attendance",
            ['identifier' => $attendee->student_code]
        )->assertStatus(200);

        $response = $this->postJson(
            "/api/v1/center/courses/{$course->getKey()}/activate",
            ['center_session_id' => $session->getKey()]
        )->assertStatus(200);

        $this->assertSame(1, $response->json('data.granted'));
        $this->assertSame(1, $response->json('data.total_attendees'));

        $this->assertDatabaseHas('center_course_activations', [
            'course_id' => $course->getKey(),
            'student_id' => $attendee->getKey(),
        ]);
        $this->assertDatabaseMissing('center_course_activations', [
            'course_id' => $course->getKey(),
            'student_id' => $absentee->getKey(),
        ]);

        $this->assertTrue(
            \Illuminate\Support\Facades\Gate::forUser($attendeeUser)->allows('consumeContent', $course)
        );
    }

    public function test_activating_for_one_student_by_center_code_grants_only_that_student(): void
    {
        $course = $this->makeCourseFor($this->teacherA, ['visibility' => Course::VISIBILITY_CENTER]);
        $code = $this->makeCenterCode($this->teacherA);
        [$studentUser, $student] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);
        $response = $this->postJson(
            "/api/v1/center/courses/{$course->getKey()}/activate",
            ['identifier' => $code->code]
        )->assertStatus(200);

        $this->assertSame('student', $response->json('data.mode'));
        $this->assertDatabaseHas('center_course_activations', [
            'course_id' => $course->getKey(),
            'student_id' => $student->getKey(),
            'center_session_id' => null,
        ]);
        $this->assertTrue(
            \Illuminate\Support\Facades\Gate::forUser($studentUser)->allows('consumeContent', $course)
        );
    }

    /**
     * Decision 10.34 — unlinking must revoke a course activation, not just
     * the membership row. Before UnlinkCenterMembership existed, both
     * unlink endpoints ran only `$membership->delete()`; the student
     * vanished from every listing built by iterating memberships, but a
     * course this teacher had already activated for them kept working —
     * the exact leak this test pins closed.
     */
    public function test_unlinking_revokes_a_previously_activated_course(): void
    {
        $course = $this->makeCourseFor($this->teacherA, ['visibility' => Course::VISIBILITY_CENTER]);
        $code = $this->makeCenterCode($this->teacherA);
        [$studentUser, $student] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);
        $this->postJson(
            "/api/v1/center/courses/{$course->getKey()}/activate",
            ['identifier' => $code->code]
        )->assertStatus(200);

        $this->assertTrue(
            \Illuminate\Support\Facades\Gate::forUser($studentUser)->allows('consumeContent', $course)
        );

        $membershipId = \App\Models\CenterMembership::query()
            ->where('teacher_id', $this->teacherA->getKey())
            ->where('student_id', $studentUser->getKey())
            ->value('id');

        $this->deleteJson("/api/v1/center/memberships/{$membershipId}")->assertStatus(200);

        $this->assertDatabaseMissing('center_course_activations', [
            'course_id' => $course->getKey(),
            'student_id' => $student->getKey(),
        ]);

        // Re-login: the guard would otherwise reuse the User it already
        // resolved for the earlier `allows()` check in this same test
        // process — see the identical note on
        // test_disabling_then_reenabling_the_center_system_loses_no_data.
        $this->loginAs($studentUser);
        $this->assertFalse(
            \Illuminate\Support\Facades\Gate::forUser($studentUser)->allows('consumeContent', $course)
        );
    }

    /**
     * Decision 10.35 — "the course must open exactly like a subscribed
     * one, with no financial record." An activation is worthless if the
     * student can never actually reach the course:
     *   - CoursePolicy::view() used to only check ownership/publication/
     *     hasCenterAccess() — an activated PLATFORM course (no visibility
     *     change) 404'd on the very first request, before consumeContent()
     *     was ever reached.
     *   - Course::scopeSubscribedBy() (my-courses / student dashboard) only
     *     ever read the `subscriptions` table, so the course never appeared
     *     in the student's own course list either — activation was
     *     invisible end to end, not just narrowly ungranted.
     * This test pins BOTH halves of the fix for BOTH activation modes.
     */
    public function test_activation_actually_opens_the_course_like_a_subscription(): void
    {
        // decision 10.39 restored the center/both-visibility restriction on
        // ActivateCenterCourseRequest::course() (a PLATFORM course can no
        // longer be the target — see test_activating_a_platform_only_course_
        // is_refused below) — this test now pins the same "opens like a
        // subscription" chain for the visibility the feature actually
        // supports going forward.
        $course = $this->makeCourseFor($this->teacherA, ['visibility' => Course::VISIBILITY_CENTER]);
        $code = $this->makeCenterCode($this->teacherA);
        [$studentUser] = $this->joinedStudent($code);

        $this->loginAs($this->teacherA);
        $this->postJson(
            "/api/v1/center/courses/{$course->getKey()}/activate",
            ['identifier' => $code->code]
        )->assertStatus(200);

        // The course page itself must open, not just the deeper
        // content-consumption gate.
        $this->assertTrue(
            \Illuminate\Support\Facades\Gate::forUser($studentUser)->allows('view', $course)
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Gate::forUser($studentUser)->allows('consumeContent', $course)
        );

        $this->loginAs($studentUser);
        $courseIds = collect(
            $this->getJson('/api/v1/courses/my-courses')->assertStatus(200)->json('data.courses')
        )->pluck('id');

        $this->assertTrue($courseIds->contains($course->getKey()));
    }

    /**
     * Decision 10.39 — the explicit reversal of decision 10.35: the user
     * confirmed, after being told this removes the "grant a paid platform
     * course for free" scenario, that the Center section must show/accept
     * ONLY center-assigned courses. A platform-only course can no longer be
     * named as the activation target at all, even by a direct id.
     */
    public function test_activating_a_platform_only_course_is_refused(): void
    {
        $course = $this->makeCourseFor($this->teacherA, ['visibility' => Course::VISIBILITY_PLATFORM, 'price' => 100]);
        $code = $this->makeCenterCode($this->teacherA);
        $this->joinedStudent($code);

        $this->loginAs($this->teacherA);
        $this->postJson(
            "/api/v1/center/courses/{$course->getKey()}/activate",
            ['identifier' => $code->code]
        )->assertStatus(404);

        $this->assertSame(0, \App\Models\CenterCourseActivation::query()->count());
    }

    public function test_a_student_never_granted_activation_still_cannot_consume_a_platform_course(): void
    {
        $course = $this->makeCourseFor($this->teacherA, ['visibility' => Course::VISIBILITY_PLATFORM, 'price' => 100]);
        $code = $this->makeCenterCode($this->teacherA);
        [$studentUser] = $this->joinedStudent($code);

        $this->assertFalse(
            \Illuminate\Support\Facades\Gate::forUser($studentUser)->allows('consumeContent', $course)
        );
    }

    public function test_activating_requires_exactly_one_of_session_or_identifier(): void
    {
        $course = $this->makeCourseFor($this->teacherA, ['visibility' => Course::VISIBILITY_CENTER]);
        $group = $this->makeCenterGroup($this->teacherA);
        $session = $this->makeCenterSession($this->teacherA, $group);

        $this->loginAs($this->teacherA);

        $this->postJson("/api/v1/center/courses/{$course->getKey()}/activate", [])
            ->assertStatus(422);

        $this->postJson(
            "/api/v1/center/courses/{$course->getKey()}/activate",
            ['center_session_id' => $session->getKey(), 'identifier' => 'anything']
        )->assertStatus(422);
    }

    public function test_activating_a_foreign_teachers_course_is_404(): void
    {
        $foreignCourse = $this->makeCourseFor($this->teacherB, ['visibility' => Course::VISIBILITY_CENTER]);

        $this->loginAs($this->teacherA);
        $this->postJson(
            "/api/v1/center/courses/{$foreignCourse->getKey()}/activate",
            ['identifier' => 'whatever']
        )->assertStatus(404);
    }

    public function test_activating_with_another_teachers_session_is_rejected(): void
    {
        $course = $this->makeCourseFor($this->teacherA, ['visibility' => Course::VISIBILITY_CENTER]);
        $foreignGroup = $this->makeCenterGroup($this->teacherB);
        $foreignSession = $this->makeCenterSession($this->teacherB, $foreignGroup);

        $this->loginAs($this->teacherA);
        $this->postJson(
            "/api/v1/center/courses/{$course->getKey()}/activate",
            ['center_session_id' => $foreignSession->getKey()]
        )->assertStatus(403);
    }

    // ══ fixtures ═══════════════════════════════════════════════════════

    private function makeCenterCode(User $teacher): CenterCode
    {
        return CenterCode::query()->create([
            'code' => \chr(random_int(65, 90)) . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT),
            'teacher_id' => $teacher->getKey(),
            'is_active' => true,
        ]);
    }

    private function makeCenterGroup(User $teacher): CenterGroup
    {
        return CenterGroup::query()->create([
            'teacher_id' => $teacher->getKey(),
            'center_name' => 'سنتر الاختبار',
            'grade_label' => 'الثالث الثانوي',
            'group_name' => 'مجموعة السبت',
            'schedule_text' => 'السبت 6:00 مساءً',
        ]);
    }

    private function makeCenterSession(User $teacher, CenterGroup $group): CenterSession
    {
        return CenterSession::query()->create([
            'teacher_id' => $teacher->getKey(),
            'center_group_id' => $group->getKey(),
            'session_date' => now()->toDateString(),
            'opened_at' => now(),
            'status' => CenterSession::STATUS_OPEN,
        ]);
    }

    /** @return array{0: User, 1: \App\Models\Student} */
    private function joinedStudent(CenterCode $code, array $profile = []): array
    {
        [$user, $student] = $this->makeStudentWithCode(null, $profile);

        $this->loginAs($user);
        $this->postJson('/api/v1/center/join', ['code' => $code->code])->assertStatus(200);
        $this->post('/api/v1/auth/logout');

        return [$user, $student];
    }
}
