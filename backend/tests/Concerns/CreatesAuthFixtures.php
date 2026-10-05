<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Actions\ContentItem\ContentDetailPersisters;
use App\Actions\Revenue\RecordRevenueTransaction;
use App\Enums\BroadcastAudience;
use App\Enums\ContentItemType;
use App\Enums\EmployeeAccountType;
use App\Enums\QuestionType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\BroadcastNotification;
use App\Models\ContentItem;
use App\Models\Notification;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\EnrollmentCode;
use App\Models\Grade;
use App\Models\GradeSection;
use App\Models\Payout;
use App\Models\RevenueTransaction;
use App\Models\TeacherProfitSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fixture builders for the auth suite.
 *
 * There are no model factories in this project (Stage 0 shipped none, and the
 * schema's UUID/legacy-column shape makes hand-written builders clearer than
 * factories that would each need a custom definition anyway). These helpers are
 * the shared vocabulary instead.
 *
 * NOTE `password_hash` IS SET WITH forceFill: it is deliberately absent from
 * User::$fillable so it can never be mass-assigned from request input, which
 * means test fixtures have to be explicit about it too. That is the trait
 * working as intended, not an obstacle.
 */
trait CreatesAuthFixtures
{
    /** The password used by every fixture unless one is passed explicitly. */
    public const FIXTURE_PASSWORD = 'CorrectHorse42Battery';

    protected function makeUser(array $attributes = [], ?string $password = null): User
    {
        $user = new User();

        $user->forceFill(array_merge([
            'username' => 'user' . bin2hex(random_bytes(4)),
            'email' => 'user' . bin2hex(random_bytes(4)) . '@sanad.local',
            'password_hash' => Hash::make($password ?? self::FIXTURE_PASSWORD),
            'full_name' => 'مستخدم اختبار',
            'role' => UserRole::Student,
            'is_active' => true,
        ], $attributes))->save();

        return $user->refresh();
    }

    protected function makeInstructor(array $grades = [], array $subjects = []): User
    {
        $user = $this->makeUser(['role' => UserRole::Instructor]);

        Teacher::query()->create([
            'user_id' => $user->getKey(),
            'grades' => $grades,
            'subjects' => $subjects,
        ]);

        return $user;
    }

    protected function makeGrade(string $name): Grade
    {
        return Grade::query()->create(['name' => $name]);
    }

    /**
     * "إدارة الأدمنز" — an Employee/Amin sub-account owned by `$ownerUser`,
     * genuinely EITHER a teacher (decision 10.37) or an admin (decision
     * 10.40, "موظفو الأدمن منفصلون عن المدرّسين كلياً") — the caller decides
     * by which fixture it passes in (`makeInstructor()` or `makeAdmin()`).
     * Built through real Eloquent creates (not the HTTP endpoint) so a test
     * that only needs a ready-made fixture — not to exercise CreateEmployee
     * itself — can build one directly, same rationale as makeInstructor()
     * for `teachers`.
     *
     * `loginAs($employeeUser)` (the SAME existing helper, no employee-
     * specific variant needed) is what actually exercises
     * App\Actions\Auth\LoginUser's identity swap — the employee's `username`
     * IS their phone, and `loginAs()` already posts to the real
     * `/auth/login` route.
     *
     * @param list<string> $permissions
     * @return array{0: User, 1: Employee}
     */
    protected function makeEmployee(
        User $ownerUser,
        EmployeeAccountType $accountType = EmployeeAccountType::Employee,
        array $permissions = [],
        ?string $phone = null,
    ): array {
        $phone ??= '01' . random_int(100000000, 999999999);

        $employeeUser = $this->makeUser([
            'username' => $phone,
            'email' => $phone . '@sanad-employee.local',
            'role' => UserRole::Employee,
        ]);

        $employee = Employee::query()->create([
            'user_id' => $employeeUser->getKey(),
            'owner_id' => $ownerUser->getKey(),
            'account_type' => $accountType,
            'permissions' => $accountType === EmployeeAccountType::Amin ? null : $permissions,
            'is_active' => true,
        ]);

        return [$employeeUser, $employee];
    }

    // ── STAGE 2 FIXTURES ──────────────────────────────────────────────────

    protected function makeAdmin(): User
    {
        return $this->makeUser(['role' => UserRole::Admin]);
    }

    /** A section in the grade_sections catalog. */
    protected function makeGradeSection(string $name): GradeSection
    {
        return GradeSection::query()->create(['name' => $name]);
    }

    /**
     * A grade in the catalog. `$sectionId` defaults to a single shared
     * fixture section (created once, reused across calls in the same test)
     * so every pre-existing `makeFlatGrade('name')` call site keeps working
     * unchanged now that grades.section_id is NOT NULL (open decision
     * 10.12) — only tests that care about section scoping need to pass one.
     */
    protected function makeFlatGrade(string $name, ?string $sectionId = null): Grade
    {
        $sectionId ??= GradeSection::query()->firstOrCreate(['name' => 'قسم اختباري افتراضي'])->getKey();

        return Grade::query()->create(['name' => $name, 'section_id' => $sectionId]);
    }

    /** A student profile row carrying a free-text governorate and grade-level NAME. */
    protected function makeStudent(string $governorate, string $gradeLevel = 'الصف الأول الثانوي'): Student
    {
        $user = $this->makeUser(['role' => UserRole::Student]);

        return Student::query()->create([
            'user_id' => $user->getKey(),
            'student_code' => 'S' . bin2hex(random_bytes(4)),
            'phone' => '01012345678',
            'parent_phone' => '01087654321',
            'governorate' => $governorate,
            'gender' => 'male',
            'education_type' => 'general',
            'grade_level' => $gradeLevel,
        ]);
    }

    /**
     * A course row pointing at a grade and/or a subject.
     *
     * Inserted through the query builder because there is NO Course model yet
     * — courses are a later stage — and Stage 2 only needs the row to exist so
     * the delete guards have something to count. $deleted drives the
     * soft-delete nuance: a trashed course must NOT block a catalog delete.
     */
    protected function makeCourse(User $instructor, array $attributes = [], bool $deleted = false): string
    {
        $id = (string) Str::uuid7();

        DB::table('courses')->insert(array_merge([
            'id' => $id,
            'title' => 'كورس اختبار ' . bin2hex(random_bytes(3)),
            'price' => 0,
            'teacher_id' => $instructor->getKey(),
            'is_published' => 0,
            'created_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ], $attributes));

        return $id;
    }

    // ── STAGE 3 FIXTURES (authorization) ──────────────────────────────────

    /**
     * A real App\Models\Course, as opposed to makeCourse()'s bare row id.
     *
     * makeCourse() above predates the Course model and is KEPT AS IS: Stage 2's
     * catalog-delete-guard tests only ever need "a row exists with this
     * grade_id", and rewriting them to hydrate a model would change what those
     * tests exercise. This is its Stage 3 sibling, not its replacement.
     *
     * `teacher_id` is set from the instructor USER's key because
     * courses.teacher_id -> teachers.user_id and that column IS the user id —
     * see App\Models\Course's docblock. Pass a user made by makeInstructor(),
     * never a bare makeUser(): without the `teachers` row the FK rejects the
     * insert, which is the schema correctly refusing to let a non-teacher own a
     * course.
     */
    protected function makeCourseFor(User $instructor, array $attributes = []): Course
    {
        return Course::query()->create(array_merge([
            'title' => 'كورس ' . bin2hex(random_bytes(3)),
            'price' => 0,
            'teacher_id' => $instructor->getKey(),
            'is_published' => false,
        ], $attributes));
    }

    /**
     * A student USER that also has the `students` profile row — which is what
     * subscriptions.student_id's FK actually points at.
     *
     * Distinct from makeStudent(), which returns the PROFILE and is what the
     * Stage 2 governorate tests want. Authorization tests reason about the
     * User, so this one returns that.
     */
    protected function makeStudentUser(): User
    {
        $user = $this->makeUser(['role' => UserRole::Student]);

        $this->attachStudentProfile($user);

        return $user;
    }

    /**
     * The `students` row for an EXISTING user.
     *
     * Split out of makeStudentUser() because one authorization test
     * deliberately gives an INSTRUCTOR account a student profile — the
     * data-corruption state that proves canConsume()'s role check is not
     * redundant with the subscription FK.
     *
     * `governorate` and `grade_level` are NOT NULL with no default (migration
     * 2026_01_01_000009), so both must be supplied or the insert is a 1364.
     */
    protected function attachStudentProfile(User $user): Student
    {
        return Student::query()->create([
            'user_id' => $user->getKey(),
            'student_code' => 'S' . bin2hex(random_bytes(4)),
            'phone' => '01012345678',
            'parent_phone' => '01087654321',
            'governorate' => 'القاهرة',
            'gender' => 'male',
            'education_type' => 'general',
            'grade_level' => 'الصف الأول الثانوي',
        ]);
    }

    /**
     * An enrollment row.
     *
     * `$expiresAt` is passed through verbatim so a test can say "NULL = never
     * expires" and "a timestamp in the past" without this helper deciding
     * anything on its own — the point of the subscription tests is exactly what
     * Subscription::scopeActive() does with those two cases.
     */
    protected function makeSubscription(
        User $student,
        Course $course,
        SubscriptionStatus $status = SubscriptionStatus::Active,
        ?DateTimeInterface $expiresAt = null,
    ): Subscription {
        return Subscription::query()->create([
            'student_id' => $student->getKey(),
            'course_id' => $course->getKey(),
            'status' => $status,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);
    }

    // ── STAGE 4 FIXTURES (teachers / students / users) ────────────────────

    /**
     * An instructor USER whose `teachers` profile carries real column values —
     * as opposed to makeInstructor(), which sets only grades/subjects.
     *
     * Kept separate rather than folded into makeInstructor(): the Stage 1-3
     * tests that call that helper assert on a profile with everything else
     * NULL, and widening it would change what those tests exercise.
     *
     * @return array{0: User, 1: Teacher}
     */
    protected function makeTeacherWithProfile(array $profile = [], array $user = []): array
    {
        $account = $this->makeUser(array_merge(['role' => UserRole::Instructor], $user));

        $teacher = Teacher::query()->create(array_merge([
            'user_id' => $account->getKey(),
            'phone' => '01011112222',
            'specialization' => 'الرياضيات',
            'bio' => 'نبذة',
            'subjects' => ['رياضيات'],
            'grades' => ['الصف الأول الثانوي'],
            'banner_url' => 'https://cdn.example/banner.png',
            'profile_photo_url' => 'https://cdn.example/photo.png',
        ], $profile));

        return [$account, $teacher];
    }

    /**
     * A student profile whose `student_code` MATCHES THE PRODUCTION FORMAT —
     * 'STU' + exactly six digits.
     *
     * attachStudentProfile() above deliberately keeps its short 'S'+hex code:
     * the Stage 2/3 tests that use it never look a student up BY code, and the
     * value only has to be unique. Stage 4's by-code lookup does, and testing
     * it against a fixture that could not exist in production would prove
     * nothing — App\Support\StudentCode's whole argument is about what
     * generateStudentCode() can emit.
     *
     * @return array{0: User, 1: Student}
     */
    protected function makeStudentWithCode(?string $code = null, array $profile = []): array
    {
        $account = $this->makeUser(['role' => UserRole::Student]);

        $student = Student::query()->create(array_merge([
            'user_id' => $account->getKey(),
            'student_code' => $code ?? 'STU' . random_int(100000, 999999),
            'phone' => '01012345678',
            'parent_phone' => '01087654321',
            'governorate' => 'القاهرة',
            'gender' => 'male',
            'education_type' => 'general',
            'educational_stage' => 'الثانوية',
            'grade_level' => 'الصف الأول الثانوي',
        ], $profile));

        return [$account, $student];
    }

    // ── STAGE 5 / SESSION A FIXTURES (courses / sections / groups) ────────

    /**
     * A section under a course. `order_index` defaults to 0 rather than being
     * computed: a test that cares about ordering passes it explicitly, and one
     * that does not must not silently depend on insertion order.
     */
    protected function makeSection(Course $course, array $attributes = []): Section
    {
        return Section::query()->create(array_merge([
            'course_id' => $course->getKey(),
            'title' => 'قسم ' . bin2hex(random_bytes(3)),
            'order_index' => 0,
        ], $attributes));
    }

    protected function makeCourseGroup(Course $course, array $attributes = []): CourseGroup
    {
        return CourseGroup::query()->create(array_merge([
            'course_id' => $course->getKey(),
            'title' => 'مجموعة ' . bin2hex(random_bytes(3)),
            'is_hidden' => false,
            'order_index' => 0,
        ], $attributes));
    }

    /** A flat subject catalog entry. */
    protected function makeSubject(string $name): Subject
    {
        return Subject::query()->create(['name' => $name]);
    }

    /**
     * An instructor whose `teachers` profile is assigned exactly one grade and
     * one subject, WITH matching catalogue rows so the name→id resolution has
     * something to find.
     *
     * Returns the account plus the two catalogue rows, because the assertions
     * that matter are "did `grade_id` end up pointing at THIS row" — comparing
     * against a name would pass even if resolution silently did nothing.
     *
     * @return array{0: User, 1: Grade, 2: Subject}
     */
    protected function makeInstructorWithCatalog(
        string $grade = 'الصف الأول الثانوي',
        string $subject = 'رياضيات',
    ): array {
        $gradeRow = $this->makeFlatGrade($grade);
        $subjectRow = $this->makeSubject($subject);

        [$account] = $this->makeTeacherWithProfile([
            'grades' => [$grade],
            'subjects' => [$subject],
        ]);

        return [$account, $gradeRow, $subjectRow];
    }

    /**
     * Log in as an admin and return them. Convenience for the 14 admin CRUD
     * routes, every one of which needs a real session (not actingAs) whenever
     * the test also calls startNextRequest().
     */
    protected function loginAsAdmin(): User
    {
        $admin = $this->makeAdmin();
        $this->loginAs($admin);

        return $admin;
    }

    /**
     * The body every Stage 4 DELETE must carry — the ACTING admin's own
     * password. Decision #1 extends this from the teacher delete to all three.
     *
     * @return array{password: string}
     */
    protected function reauth(?string $password = null): array
    {
        return ['password' => $password ?? self::FIXTURE_PASSWORD];
    }

    /**
     * Laravel's VerifyCsrfToken SHORT-CIRCUITS ITSELF UNDER TESTS
     * (`$this->runningUnitTests()` is just `$this['env'] === 'testing'`), so a
     * mutating request in a test normally sails through with no token — which
     * would make any CSRF assertion vacuously green. Flipping the container's
     * env for the duration of one request is what makes the middleware
     * genuinely run. Same helper as Stage 0's CsrfContractTest.
     */
    protected function withCsrfEnforcementOn(callable $fn): mixed
    {
        $original = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            return $fn();
        } finally {
            $this->app['env'] = $original;
        }
    }

    /** Most tests are not about throttling and must not trip over its budget. */
    protected function disableRateLimiting(): void
    {
        config(['ratelimit.enabled' => false]);
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ REQUIRED BEFORE ANY "AND NOW THE **NEXT** REQUEST…" ASSERTION.       │
     * │                                                                      │
     * │ In production every request is a fresh PHP process, so the session   │
     * │ guard is constructed from scratch and re-reads the user from the     │
     * │ database — which is the entire mechanism behind "a mid-session       │
     * │ deactivation takes effect immediately".                              │
     * │                                                                      │
     * │ Under `php artisan test` the application container is reused for     │
     * │ every request in one test method, so `Auth::guard('web')` is the     │
     * │ SAME instance and returns its cached `$user` property without        │
     * │ touching the database. A test that skips this helper therefore       │
     * │ asserts the harness's caching, not the application's behaviour —     │
     * │ measured, not assumed: these tests returned 200 until it existed.    │
     * │                                                                      │
     * │ forgetGuards() drops the resolved guards. The SESSION is untouched,  │
     * │ so the next call rebuilds the guard and re-hydrates the user from    │
     * │ the session id — exactly what a new process does.                    │
     * │                                                                      │
     * │ NOTE this only works after a REAL login. `actingAs()` sets the user  │
     * │ on the guard object without writing the session, so forgetting the   │
     * │ guards would log the user out outright and prove nothing. Use        │
     * │ loginAs() for these tests.                                           │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    protected function startNextRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * A REAL login through the endpoint, so the auth state lives in the session
     * and survives startNextRequest().
     */
    protected function loginAs(User $user, ?string $password = null): void
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => $password ?? self::FIXTURE_PASSWORD,
        ])->assertStatus(200);
    }

    // ── STAGE 5 / SESSION B FIXTURES (content items) ──────────────────────

    /**
     * A content item under a SECTION, built through the same factory the
     * controller uses.
     *
     * Deliberately NOT a raw `ContentItem::create([...])` or a DB::table()
     * insert: the XOR invariant (`(section_id IS NULL) <> (group_id IS NULL)`)
     * is enforced by those two factory methods being the only doors, and a
     * fixture that went round them would be able to build states the
     * application cannot — which is precisely the class of test that passes
     * while production breaks.
     */
    protected function makeContentItem(Section $section, array $attributes = []): ContentItem
    {
        return ContentItem::createUnderSection($section, array_merge([
            'title' => 'عنصر ' . bin2hex(random_bytes(3)),
            'type' => ContentItemType::Note,
            'is_hidden' => false,
        ], $attributes));
    }

    /** The group-scoped twin. See makeContentItem() for why it uses the factory. */
    protected function makeGroupContentItem(CourseGroup $group, array $attributes = []): ContentItem
    {
        return ContentItem::createUnderGroup($group, array_merge([
            'title' => 'عنصر ' . bin2hex(random_bytes(3)),
            'type' => ContentItemType::Note,
            'is_hidden' => false,
        ], $attributes));
    }

    /**
     * A content item WITH its detail row, written through the real persisters.
     *
     * Same argument as above one level down: the conditional-creation rule for
     * notes and the full-replace rule for videos live in
     * App\Actions\ContentItem\*, and a fixture that inserted detail rows by
     * hand would quietly diverge from them.
     */
    protected function makeContentItemWithDetail(
        Section $section,
        ContentItemType $type,
        array $detail = [],
        array $attributes = [],
    ): ContentItem {
        $item = $this->makeContentItem($section, array_merge(['type' => $type], $attributes));

        ContentDetailPersisters::apply($item, $detail);

        return $item->refresh();
    }

    // ── STAGE 6 / SESSION A FIXTURES (assessment) ─────────────────────────

    /**
     * A question bank owned by an instructor.
     *
     * `teacher_id` is set with setAttribute because App\Models\QuestionGroup
     * deliberately omits it from $fillable — the tenant column is never
     * mass-assignable, and a fixture that went round that would be able to
     * build states the application cannot.
     */
    protected function makeQuestionGroup(User $instructor, array $attributes = []): QuestionGroup
    {
        $group = new QuestionGroup();

        $group->fill(array_merge([
            'title' => 'بنك ' . bin2hex(random_bytes(3)),
            'grade' => null,
        ], $attributes));
        $group->setAttribute('teacher_id', $instructor->getKey());
        $group->save();

        return $group->refresh();
    }

    /** A question inside a bank. Same non-fillable-parent rule as above. */
    protected function makeQuestion(QuestionGroup $group, array $attributes = []): Question
    {
        $question = new Question();

        $question->fill(array_merge([
            'body' => 'سؤال ' . bin2hex(random_bytes(3)),
            'type' => QuestionType::Mcq->value,
            'options' => ['أ', 'ب', 'ج'],
            'image_url' => null,
            'correct_answer' => 'أ',
            'points' => 1,
        ], $attributes));
        $question->setAttribute('question_group_id', $group->getKey());
        $question->save();

        return $question->refresh();
    }

    /**
     * An exam content item plus its `exams` row, written through the REAL
     * persister — same argument as makeContentItemWithDetail(): the
     * partial-update and default rules live in
     * App\Actions\ContentItem\PersistExamDetail, and a fixture that inserted
     * the row by hand would quietly diverge from them.
     *
     * @return array{0: ContentItem, 1: Exam}
     */
    protected function makeExam(Section $section, array $detail = [], array $attributes = []): array
    {
        $item = $this->makeContentItemWithDetail(
            $section,
            ContentItemType::Exam,
            $detail,
            $attributes
        );

        return [$item, Exam::query()->where('content_item_id', $item->getKey())->firstOrFail()];
    }

    // ── STAGE 7 / SESSION A FIXTURES (enrolment, codes, revenue) ──────────

    /**
     * An enrollment code row, inserted DIRECTLY rather than through
     * App\Actions\Enrollment\GenerateEnrollmentCode.
     *
     * That is the opposite of the rule makeContentItem() follows, and the
     * reason is that the generator DRAWS ITS OWN RANDOM CODE — a test that
     * needs to redeem a specific string, or to pre-seed a collision, cannot
     * express that through it. The generator's own behaviour is covered
     * separately by EnrollmentCodeGenerationTest, which calls it for real.
     *
     * `code` defaults to a random 8-digit draw in the same range the generator
     * uses, so a fixture code is always a code the application could have
     * produced.
     */
    protected function makeEnrollmentCode(Course $course, array $attributes = []): EnrollmentCode
    {
        // `created_at` is deliberately absent from EnrollmentCode::$fillable
        // (an API request must never backdate a code), so a caller passing it
        // here — to control admin-listing order in a test — has it silently
        // dropped by mass assignment: `create()` ignores non-fillable keys
        // rather than raising, and Eloquent then stamps the real "now" over
        // it. Peel it out and set it via forceFill(), which mass-assignment
        // guarding doesn't apply to.
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $code = EnrollmentCode::query()->create(array_merge([
            'code' => (string) random_int(10000000, 99999999),
            'course_id' => $course->getKey(),
            'created_by' => null,
            'is_used' => false,
        ], $attributes));

        if ($createdAt !== null) {
            $code->forceFill(['created_at' => $createdAt])->save();
        }

        return $code->refresh();
    }

    /**
     * A teacher's revenue share.
     *
     * `$percentage` is passed as a string-safe scalar and stored in a
     * DECIMAL(5,2) column; tests that care about 0 must pass `0` and get a
     * real row, which is the case that distinguishes "0%" from "no row" in
     * App\Actions\Revenue\RecordRevenueTransaction::percentageFor().
     */
    protected function makeProfitSetting(User $teacher, float|int|string $percentage): TeacherProfitSetting
    {
        return TeacherProfitSetting::query()->create([
            'teacher_id' => $teacher->getKey(),
            'profit_percentage' => $percentage,
        ]);
    }

    /**
     * A ledger row written through the REAL Action, so every test that reads
     * `teacher_earnings` is reading a number the application computed rather
     * than one the fixture asserted into place.
     */
    /**
     * A payout row — money that has already left the platform.
     *
     * Written straight to the model rather than through the HTTP route on
     * purpose: the tests that BUILD a balance need several payouts as SETUP,
     * and routing each through POST /revenue/payouts would make every balance
     * test also a test of that endpoint's validation. PayoutTest exercises the
     * route itself.
     */
    protected function makePayout(User $teacher, float $amount, ?User $createdBy = null): Payout
    {
        return Payout::query()->create([
            'teacher_id' => $teacher->getKey(),
            'amount' => $amount,
            'payment_method' => Payout::METHOD_MANUAL,
            'notes' => null,
            'created_by' => $createdBy?->getKey(),
        ]);
    }

    protected function recordRevenue(
        User $student,
        Course $course,
        float $gross,
        string $paymentMethod = RevenueTransaction::METHOD_MANUAL_ADMIN,
        ?Subscription $subscription = null,
    ): RevenueTransaction {
        return app(RecordRevenueTransaction::class)([
            'subscription_id' => $subscription?->getKey(),
            'student_id' => $student->getKey(),
            'course_id' => $course->getKey(),
            'teacher_id' => $course->teacher_id,
            'gross_amount' => $gross,
            'payment_method' => $paymentMethod,
        ]);
    }
    // ── STAGE 8 FIXTURES (notifications, broadcasts, announcements) ───────

    /**
     * A PLAIN notification — `broadcast_id` NULL, therefore always visible.
     *
     * Built with setAttribute + save rather than create([...]) because
     * Notification declares `$fillable = []` on purpose: `user_id` must never
     * be mass-assignable from a request, and a fixture that went round that
     * would be able to build states the application cannot.
     */
    protected function makeNotification(User $user, array $attributes = []): Notification
    {
        $notification = new Notification();

        $notification->forceFill(array_merge([
            'user_id' => $user->getKey(),
            'title' => 'إشعار اختبار',
            'body' => 'نص الإشعار',
            'link' => null,
            'broadcast_id' => null,
            'is_read' => false,
        ], $attributes))->save();

        return $notification->refresh();
    }

    /**
     * A broadcast row WITHOUT any delivery — the state the toggle/visibility
     * tests need when they want to control the fan-out themselves.
     *
     * Defaults put the window wide open (started an hour ago, no end) so a
     * test that says nothing about scheduling gets a VISIBLE broadcast; each
     * visibility test then overrides exactly the one attribute it is about.
     */
    protected function makeBroadcast(User $creator, array $attributes = []): BroadcastNotification
    {
        $broadcast = new BroadcastNotification();

        $broadcast->forceFill(array_merge([
            'title' => 'تنبيه بثي',
            'body' => 'نص التنبيه',
            'link' => null,
            'audience' => BroadcastAudience::AllTeachers,
            'target_user_ids' => null,
            'start_at' => now()->subHour(),
            'end_at' => null,
            'is_active' => true,
            'created_by' => $creator->getKey(),
        ], $attributes))->save();

        return $broadcast->refresh();
    }

    /**
     * A broadcast plus its delivered rows, through the REAL delivery Action —
     * not a hand-rolled insert. The fan-out's own guarantees (one shared
     * timestamp, is_read false, the body column carrying the message) are then
     * exercised by every test that uses this, instead of being re-typed here
     * where they could quietly diverge.
     *
     * @param  list<User>  $recipients
     */
    protected function deliverBroadcastTo(User $creator, array $recipients, array $attributes = []): BroadcastNotification
    {
        $broadcast = $this->makeBroadcast($creator, $attributes);

        app(\App\Actions\Notification\DeliverBroadcast::class)(
            $broadcast,
            array_map(static fn (User $u): string => (string) $u->getKey(), $recipients)
        );

        return $broadcast;
    }

    /**
     * An announcement owned by $teacher, or a PLATFORM announcement when
     * $teacher is null (teacher_id NULL — approved decision 3.2).
     *
     * The null case is a first-class parameter and not an afterthought,
     * because "manageable by admins only" is a behaviour several tests assert
     * and it exists only in that state.
     */
    protected function makeAnnouncement(?User $teacher, array $attributes = []): Announcement
    {
        $announcement = new Announcement();

        $announcement->forceFill(array_merge([
            'course_id' => null,
            'teacher_id' => $teacher?->getKey(),
            'title' => 'عنوان الإعلان',
            'body' => 'محتوى الإعلان',
            'link_url' => null,
            'start_date' => null,
            'end_date' => null,
            'is_published' => true,
        ], $attributes))->save();

        return $announcement->refresh();
    }
}