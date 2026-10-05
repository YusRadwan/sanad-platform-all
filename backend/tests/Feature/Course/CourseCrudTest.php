<?php

declare(strict_types=1);

namespace Tests\Feature\Course;

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\Grade;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Course create / update / delete, the two list scopes, the pricing rule and
 * the grade-subject assignment rule — freeze-table rows 8, 9, 11-14, 31, 41.
 *
 * The field-semantics matrix has its own file (CourseFieldSemanticsTest) and
 * decision 3.6's visibility matrix has another (CourseVisibilityTest). What is
 * left here is everything else the sixteen routes promise.
 */
final class CourseCrudTest extends TestCase
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

    // ── create ────────────────────────────────────────────────────────────

    public function test_an_instructor_creates_a_course_under_their_own_id(): void
    {
        $this->loginAs($this->instructor);

        $this->postJson('/api/v1/courses', ['title' => 'كورس التفاضل والتكامل'])
            ->assertStatus(201)
            ->assertJsonPath('message', 'تم إنشاء الكورس بنجاح')
            ->assertJsonPath('data.title', 'كورس التفاضل والتكامل')
            ->assertJsonPath('data.teacher_id', $this->instructor->getKey())
            // Defaults from the schema, surfaced through the presenter.
            ->assertJsonPath('data.price', 0)
            ->assertJsonPath('data.is_published', false)
            ->assertJsonPath('data.isFree', true);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ AN INSTRUCTOR CANNOT FILE A COURSE UNDER ANOTHER TEACHER'S NAME.     ║
     * ║ The posted `teacher_id` has no code path that could observe it —      ║
     * ║ CreateCourse::ownerFor() reads the request only on the admin branch.  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_posted_teacher_id_is_ignored_for_an_instructor(): void
    {
        [$victim] = $this->makeTeacherWithProfile();
        $this->loginAs($this->instructor);

        $this->postJson('/api/v1/courses', [
            'title' => 'محاولة انتحال',
            'teacher_id' => $victim->getKey(),
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.teacher_id', $this->instructor->getKey());

        $this->assertSame(0, Course::query()->where('teacher_id', $victim->getKey())->count());
    }

    /** An admin creating on an instructor's behalf is a real console flow. */
    public function test_an_admin_may_name_the_owning_teacher(): void
    {
        $this->loginAsAdmin();

        $this->postJson('/api/v1/courses', [
            'title' => 'كورس أنشأه المدير',
            'teacher_id' => $this->instructor->getKey(),
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.teacher_id', $this->instructor->getKey());
    }

    public function test_a_course_without_a_title_is_422_not_500(): void
    {
        $this->loginAs($this->instructor);

        $this->postJson('/api/v1/courses', [])->assertStatus(422);
    }

    /** A student may not reach the write routes at all — `role:` refuses first. */
    public function test_a_student_cannot_create_a_course(): void
    {
        $this->loginAs($this->makeStudentUser());

        $this->postJson('/api/v1/courses', ['title' => 'ليس من حقي'])->assertStatus(403);
    }

    public function test_a_guest_cannot_create_a_course(): void
    {
        $this->postJson('/api/v1/courses', ['title' => 'زائر مجهول'])->assertStatus(401);
    }

    // ── grade / subject resolution and enforcement ────────────────────────

    public function test_an_assigned_grade_and_subject_resolve_to_catalogue_ids(): void
    {
        $this->loginAs($this->instructor);

        $response = $this->postJson('/api/v1/courses', [
            'title' => 'كورس مصنَّف',
            'grade_name' => 'الصف الأول الثانوي',
            'subject_name' => 'رياضيات',
        ])->assertStatus(201);

        // Against the catalogue ROW, not against the name: comparing names
        // would pass even if resolution silently did nothing.
        $response->assertJsonPath('data.grade_id', $this->grade->getKey());
        $response->assertJsonPath('data.subject_id', $this->subject->getKey());
        $response->assertJsonPath('data.grade_name', 'الصف الأول الثانوي');
        $response->assertJsonPath('data.subject', 'رياضيات');
    }

    /**
     * THE LEGACY ALIASES. backend/'s submittedGrade() reads
     * `$data['grade_name'] ?? $data['grade']`, so the short keys must work
     * identically — CourseManagement.tsx posts them.
     */
    public function test_the_short_grade_and_subject_aliases_are_accepted(): void
    {
        $this->loginAs($this->instructor);

        $this->postJson('/api/v1/courses', [
            'title' => 'كورس بالمفاتيح القصيرة',
            'grade' => 'الصف الأول الثانوي',
            'subject' => 'رياضيات',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.grade_id', $this->grade->getKey())
            ->assertJsonPath('data.subject_id', $this->subject->getKey());
    }

    public function test_a_grade_not_assigned_to_the_teacher_is_422_with_the_exact_message(): void
    {
        $this->makeFlatGrade('الصف الثالث الثانوي');
        $this->loginAs($this->instructor);

        $this->postJson('/api/v1/courses', [
            'title' => 'صفّ ليس لي',
            'grade_name' => 'الصف الثالث الثانوي',
        ])
            ->assertStatus(422)
            // The error KEY is `grade`, not `grade_name` — backend/ throws
            // ValidationException(['grade' => [...]]) and the course form reads
            // that key.
            ->assertJsonPath('data.errors.grade.0', 'غير مسموح لك بإضافة كورس لصف غير مسند إليك');
    }

    public function test_a_subject_not_assigned_to_the_teacher_is_422_with_the_exact_message(): void
    {
        $this->makeSubject('كيمياء');
        $this->loginAs($this->instructor);

        $this->postJson('/api/v1/courses', [
            'title' => 'مادة ليست لي',
            'subject_name' => 'كيمياء',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.subject.0', 'غير مسموح لك بإضافة كورس لمادة غير مسندة إليك');
    }

    /** Admins are exempt wholesale — backend/ returns before the check. */
    public function test_an_admin_is_exempt_from_the_assignment_rule(): void
    {
        $foreign = $this->makeFlatGrade('صفّ لا يملكه أحد');
        $this->loginAsAdmin();

        $this->postJson('/api/v1/courses', [
            'title' => 'المدير فوق القاعدة',
            'teacher_id' => $this->instructor->getKey(),
            'grade_name' => 'صفّ لا يملكه أحد',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.grade_id', $foreign->getKey());
    }

    /**
     * An instructor whose profile has NO assigned grades must still be able to
     * save a course that names none. Absence is not a violation.
     */
    public function test_an_empty_grade_is_allowed_for_an_unassigned_teacher(): void
    {
        [$bare] = $this->makeTeacherWithProfile(['grades' => [], 'subjects' => []]);
        $this->loginAs($bare);

        $this->postJson('/api/v1/courses', [
            'title' => 'كورس بلا تصنيف',
            'grade_name' => '',
            'subject_name' => '',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.grade_id', null);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ AN UNKNOWN NAME **FAILS OPEN**: null, not a 422.                     ║
     * ║                                                                      ║
     * ║ backend/resolveCatalogId()'s own comment: "An unknown name yields    ║
     * ║ null, i.e. exactly the previous behaviour, so this can never turn a  ║
     * ║ previously-accepted write into a failure." The name IS assigned to   ║
     * ║ the teacher here — it simply has no catalogue row, which is the real ║
     * ║ state of every course created before migration 0016.                 ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_an_assigned_but_uncatalogued_name_resolves_to_null_rather_than_erroring(): void
    {
        [$teacher] = $this->makeTeacherWithProfile(['grades' => ['صفّ بلا صفّ في الكتالوج']]);
        $this->loginAs($teacher);

        $this->postJson('/api/v1/courses', [
            'title' => 'اسم غير معروف للكتالوج',
            'grade_name' => 'صفّ بلا صفّ في الكتالوج',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.grade_id', null);
    }

    /**
     * The missing-profile case is a **403** with backend/'s Arabic message —
     * a different status from the 422 an unassigned grade produces, and the
     * frontend branches on the difference.
     */
    public function test_an_instructor_without_a_teacher_profile_gets_403(): void
    {
        // A user with the instructor ROLE but no `teachers` row — the
        // corrupted-account state backend/ guards against.
        $orphan = $this->makeUser(['role' => \App\Enums\UserRole::Instructor]);
        $this->loginAs($orphan);

        $this->postJson('/api/v1/courses', ['title' => 'حساب بلا ملف مدرس'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'لا يوجد ملف مدرس لهذا الحساب');
    }

    // ── pricing ───────────────────────────────────────────────────────────

    public function test_a_discount_above_the_submitted_price_is_422(): void
    {
        $this->loginAs($this->instructor);

        $this->postJson('/api/v1/courses', [
            'title' => 'تسعير خاطئ',
            'price' => 100,
            'discount_price' => 150,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.discount_price.0', 'سعر الخصم يجب ألا يزيد عن السعر الأصلي');
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE `lte:price` TRAP. A PARTIAL UPDATE CARRYING ONLY               ║
     * ║ `discount_price` MUST BE COMPARED AGAINST THE **STORED** PRICE.      ║
     * ║                                                                      ║
     * ║ There is no `price` in this body for Laravel's built-in `lte:price`  ║
     * ║ to read, so the built-in rule would resolve the other field to null  ║
     * ║ and let 900 through against a stored 500 — which MySQL then rejects  ║
     * ║ at chk_courses_discount_range as a 500. App\Rules\                    ║
     * ║ DiscountNotAbovePrice injects the stored value instead.               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_partial_update_compares_the_discount_against_the_stored_price(): void
    {
        $course = $this->makeCourseFor($this->instructor, ['price' => 500, 'discount_price' => null]);
        $this->loginAs($this->instructor);

        $this->putJson("/api/v1/courses/{$course->getKey()}", ['discount_price' => 900])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.discount_price.0', 'سعر الخصم يجب ألا يزيد عن السعر الأصلي');

        $this->assertNull($course->refresh()->getAttribute('discount_price'));
    }

    /** The same partial shape, below the stored baseline, must succeed. */
    public function test_the_same_partial_update_below_the_stored_price_succeeds(): void
    {
        $course = $this->makeCourseFor($this->instructor, ['price' => 500, 'discount_price' => null]);
        $this->loginAs($this->instructor);

        $this->putJson("/api/v1/courses/{$course->getKey()}", ['discount_price' => 400])
            ->assertStatus(200);

        $this->assertSame('400.00', $course->refresh()->getAttributes()['discount_price']);
    }

    /** A `price` in the SAME request wins over the stored baseline. */
    public function test_raising_the_price_in_the_same_request_admits_a_bigger_discount(): void
    {
        $course = $this->makeCourseFor($this->instructor, ['price' => 500]);
        $this->loginAs($this->instructor);

        $this->putJson("/api/v1/courses/{$course->getKey()}", [
            'price' => 1000,
            'discount_price' => 900,
        ])->assertStatus(200);

        $this->assertSame('900.00', $course->refresh()->getAttributes()['discount_price']);
    }

    /**
     * On CREATE the baseline is the column default 0, which turns backend/'s
     * constraint-violation 500 into a 422. Decision 5.2.
     */
    public function test_a_discount_on_a_priceless_create_is_422_not_a_constraint_500(): void
    {
        $this->loginAs($this->instructor);

        $this->postJson('/api/v1/courses', [
            'title' => 'خصم بلا سعر',
            'discount_price' => 50,
        ])->assertStatus(422);
    }

    public function test_a_negative_discount_is_rejected(): void
    {
        $this->loginAs($this->instructor);

        $this->postJson('/api/v1/courses', [
            'title' => 'خصم سالب',
            'price' => 100,
            'discount_price' => -5,
        ])->assertStatus(422);
    }

    // ── update / delete ownership ─────────────────────────────────────────

    public function test_another_teacher_cannot_update_the_course(): void
    {
        $course = $this->makeCourseFor($this->instructor, ['title' => 'ملكي أنا']);
        [$intruder] = $this->makeTeacherWithProfile();
        $this->loginAs($intruder);

        $this->putJson("/api/v1/courses/{$course->getKey()}", ['title' => 'استوليت عليه'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not own this course');

        $this->assertSame('ملكي أنا', $course->refresh()->getAttribute('title'));
    }

    public function test_another_teacher_cannot_delete_the_course(): void
    {
        $course = $this->makeCourseFor($this->instructor);
        [$intruder] = $this->makeTeacherWithProfile();
        $this->loginAs($intruder);

        $this->deleteJson("/api/v1/courses/{$course->getKey()}")->assertStatus(403);

        $this->assertNull($course->refresh()->getAttribute('deleted_at'));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ OWNERSHIP IS REFUSED BEFORE VALIDATION EVER RUNS.                    ║
     * ║                                                                      ║
     * ║ A body that is BOTH unauthorised and malformed must answer 403, not  ║
     * ║ 422. Otherwise a foreign instructor learns things about a course      ║
     * ║ they cannot see — including its stored price, through the             ║
     * ║ DiscountNotAbovePrice baseline. backend/ calls assertOwnsCourse()     ║
     * ║ first; UpdateCourseRequest::authorize() is where Laravel lets us do    ║
     * ║ the same.                                                             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_ownership_is_refused_before_validation_ever_runs(): void
    {
        $course = $this->makeCourseFor($this->instructor, ['price' => 500]);
        [$intruder] = $this->makeTeacherWithProfile();
        $this->loginAs($intruder);

        $this->putJson("/api/v1/courses/{$course->getKey()}", [
            'title' => 'x',              // fails min:3
            'discount_price' => 99999,   // would fail the pricing rule
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not own this course');
    }

    public function test_an_admin_may_update_any_teachers_course(): void
    {
        $course = $this->makeCourseFor($this->instructor);
        $this->loginAsAdmin();

        $this->putJson("/api/v1/courses/{$course->getKey()}", ['title' => 'تعديل إداري'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم تحديث الكورس بنجاح');
    }

    public function test_the_delete_is_soft_and_leaves_sections_and_groups_in_place(): void
    {
        $course = $this->makeCourseFor($this->instructor);
        $section = $this->makeSection($course);
        $group = $this->makeCourseGroup($course);

        $this->loginAs($this->instructor);

        $this->deleteJson("/api/v1/courses/{$course->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم حذف الكورس بنجاح');

        $this->assertDatabaseHas('courses', ['id' => $course->getKey()]);
        $this->assertNotNull(Course::withTrashed()->find($course->getKey())->getAttribute('deleted_at'));

        // DELIBERATELY NOT CASCADED — backend/ leaves both in place and Stage 5
        // preserves it rather than making a reversible delete irreversible.
        $this->assertDatabaseHas('sections', ['id' => $section->getKey()]);
        $this->assertDatabaseHas('course_groups', ['id' => $group->getKey()]);
    }

    public function test_an_unknown_course_id_is_404_with_the_contract_message(): void
    {
        $this->loginAs($this->instructor);

        $this->putJson('/api/v1/courses/00000000-0000-0000-0000-000000000000', ['title' => 'لا شيء'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');
    }

    // ── my-courses, the three branches ────────────────────────────────────

    public function test_a_student_sees_only_actively_subscribed_courses(): void
    {
        $subscribed = $this->makeCourseFor($this->instructor, ['is_published' => true]);
        $expired = $this->makeCourseFor($this->instructor, ['is_published' => true]);
        $this->makeCourseFor($this->instructor, ['is_published' => true]); // never enrolled

        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $subscribed);
        $this->makeSubscription($student, $expired, SubscriptionStatus::Active, now()->subDay());

        $this->loginAs($student);

        $ids = collect($this->getJson('/api/v1/courses/my-courses')->json('data.courses'))
            ->pluck('id')->all();

        $this->assertSame([$subscribed->getKey()], $ids);
    }

    public function test_an_instructor_sees_only_their_own_courses(): void
    {
        $mine = $this->makeCourseFor($this->instructor);
        [$other] = $this->makeTeacherWithProfile();
        $theirs = $this->makeCourseFor($other, ['is_published' => true]);

        $this->loginAs($this->instructor);

        $ids = collect($this->getJson('/api/v1/courses/my-courses')->json('data.courses'))
            ->pluck('id')->all();

        $this->assertContains($mine->getKey(), $ids);
        $this->assertNotContains($theirs->getKey(), $ids);
    }

    public function test_an_admin_sees_every_course_including_drafts(): void
    {
        $draft = $this->makeCourseFor($this->instructor, ['is_published' => false]);
        [$other] = $this->makeTeacherWithProfile();
        $theirs = $this->makeCourseFor($other, ['is_published' => false]);

        $this->loginAsAdmin();

        $ids = collect($this->getJson('/api/v1/courses/my-courses')->json('data.courses'))
            ->pluck('id')->all();

        $this->assertContains($draft->getKey(), $ids);
        $this->assertContains($theirs->getKey(), $ids);
    }

    /**
     * The legacy alias must answer with the SAME data as /courses/my-courses.
     * Registered twice on purpose; served from one handler so they cannot
     * disagree.
     */
    public function test_the_codes_student_courses_alias_returns_identical_data(): void
    {
        $course = $this->makeCourseFor($this->instructor, ['is_published' => true]);
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $course);

        $this->loginAs($student);

        $alias = $this->getJson('/api/v1/codes/student-courses')->assertStatus(200)->json('data');
        $canonical = $this->getJson('/api/v1/courses/my-courses')->assertStatus(200)->json('data');

        $this->assertSame($canonical, $alias);
    }

    /** The alias is student-only, unlike /courses/my-courses. */
    public function test_the_codes_alias_refuses_an_instructor(): void
    {
        $this->loginAs($this->instructor);

        $this->getJson('/api/v1/codes/student-courses')->assertStatus(403);
    }

    // ── admin/teachers/{id}/courses ───────────────────────────────────────

    public function test_the_admin_teacher_courses_route_is_scoped_to_that_teacher(): void
    {
        $mine = $this->makeCourseFor($this->instructor);
        [$other] = $this->makeTeacherWithProfile();
        $theirs = $this->makeCourseFor($other);

        $this->loginAsAdmin();

        $ids = collect(
            $this->getJson("/api/v1/admin/teachers/{$this->instructor->getKey()}/courses")->json('data.courses')
        )->pluck('id')->all();

        $this->assertSame([$mine->getKey()], $ids);
        $this->assertNotContains($theirs->getKey(), $ids);
    }

    public function test_an_instructor_cannot_reach_the_admin_teacher_courses_route(): void
    {
        $this->loginAs($this->instructor);

        $this->getJson("/api/v1/admin/teachers/{$this->instructor->getKey()}/courses")
            ->assertStatus(403);
    }

    /** An unknown teacher id is an empty list, never a 404 — the picker must render. */
    public function test_an_unknown_teacher_id_yields_an_empty_list(): void
    {
        $this->loginAsAdmin();

        $this->getJson('/api/v1/admin/teachers/00000000-0000-0000-0000-000000000000/courses')
            ->assertStatus(200)
            ->assertJsonPath('data.courses', []);
    }

    // ── the public catalogue ──────────────────────────────────────────────

    public function test_the_catalogue_is_paginated_with_a_default_of_twelve(): void
    {
        $this->getJson('/api/v1/courses')
            ->assertStatus(200)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.per_page', 12);
    }

    public function test_per_page_is_clamped_to_one_hundred(): void
    {
        $this->getJson('/api/v1/courses?per_page=1000000')
            ->assertStatus(200)
            ->assertJsonPath('data.per_page', 100);
    }

    /** The presenter's duplicated keys are a contract; all of them ship. */
    public function test_the_presenter_emits_both_naming_conventions(): void
    {
        $course = $this->makeCourseFor($this->instructor, [
            'is_published' => true,
            'thumbnail_url' => 'https://cdn.example/c.png',
            'price' => 100,
            'discount_price' => 80,
            'grade_id' => $this->grade->getKey(),
            'subject_id' => $this->subject->getKey(),
        ]);

        $this->getJson("/api/v1/courses/{$course->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.thumbnail_url', 'https://cdn.example/c.png')
            ->assertJsonPath('data.image', 'https://cdn.example/c.png')
            ->assertJsonPath('data.discount_price', 80)
            ->assertJsonPath('data.discountPrice', 80)
            ->assertJsonPath('data.grade_name', 'الصف الأول الثانوي')
            ->assertJsonPath('data.grade', 'الصف الأول الثانوي')
            ->assertJsonPath('data.subject_name', 'رياضيات')
            ->assertJsonPath('data.subject', 'رياضيات')
            ->assertJsonPath('data.teacher.name', $this->instructor->getAttribute('full_name'))
            ->assertJsonPath('data.teacher_name', $this->instructor->getAttribute('full_name'))
            ->assertJsonPath('data.isFree', false);
    }

    /**
     * `discount_price: null` must stay null and never become 0.0 — to the
     * storefront, 0.0 means "100% off".
     */
    public function test_a_null_discount_is_not_flattened_to_zero(): void
    {
        $course = $this->makeCourseFor($this->instructor, [
            'is_published' => true,
            'price' => 100,
            'discount_price' => null,
        ]);

        $this->getJson("/api/v1/courses/{$course->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.discount_price', null)
            ->assertJsonPath('data.discountPrice', null);
    }
}
