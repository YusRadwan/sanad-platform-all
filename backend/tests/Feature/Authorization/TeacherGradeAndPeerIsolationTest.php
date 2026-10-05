<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Grade;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Regression cover for two isolation properties that were previously proven
 * only on the CREATE path, or only on the collection-level admin routes.
 *
 * ┌── 1. GRADE / SUBJECT ISOLATION ON **UPDATE**, NOT JUST CREATE ───────────┐
 * │ App\Rules\AssignedToTeacher is attached in                               │
 * │ CourseWriteRequest::sharedRules(), so both StoreCourseRequest and        │
 * │ UpdateCourseRequest inherit it. CourseCrudTest pins the POST side        │
 * │ (test_a_grade_not_assigned_to_the_teacher_is_422_…); nothing pinned the  │
 * │ PUT side, which is the path an instructor would actually use to RE-TAG   │
 * │ an already-created course into a grade that is not theirs. If a future   │
 * │ edit moved the two `new AssignedToTeacher(...)` lines out of             │
 * │ sharedRules() and into StoreCourseRequest::rules(), every existing test  │
 * │ would still pass and the whole rule would be bypassable with one PUT.    │
 * │ These tests are the tripwire for that.                                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── 2. THE ADMIN TEACHER ROUTES, PER-ID ─────────────────────────────────┐
 * │ TeacherCrudTest::test_a_non_admin_cannot_reach_the_admin_teacher_routes │
 * │ covers the two COLLECTION routes (GET/POST /admin/teachers). The four   │
 * │ per-id routes are the ones that would actually hand one instructor      │
 * │ another instructor's private profile (email, phone, assigned lists), so │
 * │ they get their own assertions here.                                     │
 * │                                                                          │
 * │ The expected status is **403 from `role:admin`**, not 404: the routes   │
 * │ are registered for everyone and it is the middleware that refuses. A    │
 * │ 404 would mean the route table changed shape, which is a different — and │
 * │ far more fragile — way to be safe.                                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class TeacherGradeAndPeerIsolationTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $instructor;

    private Grade $grade;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->instructor, $this->grade, $this->subject] = $this->makeInstructorWithCatalog();
    }

    // ── 1. grade / subject isolation on the UPDATE path ───────────────────

    public function test_an_instructor_cannot_retag_their_course_into_an_unassigned_grade(): void
    {
        $this->makeFlatGrade('الصف الثالث الثانوي');
        $course = $this->makeCourseFor($this->instructor, ['title' => 'كورس قابل للتعديل']);
        $this->loginAs($this->instructor);

        $this->putJson("/api/v1/courses/{$course->getKey()}", [
            'grade_name' => 'الصف الثالث الثانوي',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.grade.0', 'غير مسموح لك بإضافة كورس لصف غير مسند إليك');

        $this->assertNull($course->refresh()->getAttribute('grade_id'));
    }

    /** The SHORT alias must fail identically — both keys collapse to `grade`. */
    public function test_the_short_grade_alias_is_refused_on_update_too(): void
    {
        $this->makeFlatGrade('الصف الثالث الثانوي');
        $course = $this->makeCourseFor($this->instructor);
        $this->loginAs($this->instructor);

        $this->putJson("/api/v1/courses/{$course->getKey()}", [
            'grade' => 'الصف الثالث الثانوي',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.grade.0', 'غير مسموح لك بإضافة كورس لصف غير مسند إليك');
    }

    public function test_an_instructor_cannot_retag_their_course_into_an_unassigned_subject(): void
    {
        $this->makeSubject('كيمياء');
        $course = $this->makeCourseFor($this->instructor);
        $this->loginAs($this->instructor);

        $this->putJson("/api/v1/courses/{$course->getKey()}", [
            'subject_name' => 'كيمياء',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.subject.0', 'غير مسموح لك بإضافة كورس لمادة غير مسندة إليك');

        $this->assertNull($course->refresh()->getAttribute('subject_id'));
    }

    /** The positive half: an ASSIGNED grade updates and resolves to its row. */
    public function test_an_assigned_grade_and_subject_update_normally(): void
    {
        $course = $this->makeCourseFor($this->instructor);
        $this->loginAs($this->instructor);

        $this->putJson("/api/v1/courses/{$course->getKey()}", [
            'grade_name' => 'الصف الأول الثانوي',
            'subject_name' => 'رياضيات',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.grade_id', $this->grade->getKey())
            ->assertJsonPath('data.subject_id', $this->subject->getKey());
    }

    /**
     * The admin exemption survives on the update path as well — the rule is
     * not ATTACHED for an admin (CourseWriteRequest::resolveActingTeacher()
     * returns null), so any catalogued grade is writable.
     */
    public function test_an_admin_is_not_constrained_when_updating_another_teachers_course(): void
    {
        $foreign = $this->makeFlatGrade('صفّ لا يملكه أحد');
        $course = $this->makeCourseFor($this->instructor);
        $this->loginAsAdmin();

        $this->putJson("/api/v1/courses/{$course->getKey()}", [
            'grade_name' => 'صفّ لا يملكه أحد',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.grade_id', $foreign->getKey());
    }

    /**
     * A grade that belongs to ANOTHER instructor's assigned list is not
     * special — it is refused for exactly the same reason an uncatalogued one
     * is. This is the "teacher A borrows teacher B's specialisation" case that
     * drives the public catalogue's filtering.
     */
    public function test_another_teachers_assigned_grade_is_still_refused(): void
    {
        $this->makeFlatGrade('الصف الثاني الإعدادي');
        $this->makeTeacherWithProfile([
            'grades' => ['الصف الثاني الإعدادي'],
            'subjects' => ['رياضيات'],
        ]);

        $this->loginAs($this->instructor);

        $this->postJson('/api/v1/courses', [
            'title' => 'صفّ زميلي',
            'grade_name' => 'الصف الثاني الإعدادي',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.grade.0', 'غير مسموح لك بإضافة كورس لصف غير مسند إليك');
    }

    // ── 2. teacher-to-teacher isolation on the per-id admin routes ────────

    public function test_an_instructor_gets_403_on_every_per_id_admin_teacher_route(): void
    {
        [$other] = $this->makeTeacherWithProfile();
        $id = $other->getKey();

        $this->loginAs($this->instructor);

        $this->getJson("/api/v1/admin/teachers/{$id}")->assertStatus(403);
        $this->patchJson("/api/v1/admin/teachers/{$id}", ['fullName' => 'مخترق'])->assertStatus(403);
        $this->putJson("/api/v1/admin/teachers/{$id}", ['isActive' => false])->assertStatus(403);
        $this->deleteJson("/api/v1/admin/teachers/{$id}", $this->reauth())->assertStatus(403);
        $this->getJson("/api/v1/admin/teachers/{$id}/courses")->assertStatus(403);

        // …and nothing was written by the two mutating attempts.
        $this->assertTrue((bool) $other->refresh()->getAttribute('is_active'));
    }

    /**
     * The instructor self-service statistics route reads the SESSION and
     * nothing else, so naming another teacher cannot redirect it. Pinned in
     * both shapes a caller might try.
     */
    public function test_statistics_ignores_any_supplied_teacher_identifier(): void
    {
        [$other] = $this->makeTeacherWithProfile();
        $this->makeCourseFor($other, ['title' => 'كورس زميل', 'is_published' => true]);

        $this->loginAs($this->instructor);

        $baseline = $this->getJson('/api/v1/teachers/statistics')
            ->assertStatus(200)
            ->json('data');

        $probed = $this->getJson('/api/v1/teachers/statistics?teacher_id=' . $other->getKey())
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($baseline, $probed);
    }
}
