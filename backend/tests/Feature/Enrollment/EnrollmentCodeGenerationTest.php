<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Actions\Enrollment\GenerateEnrollmentCode;
use App\Models\EnrollmentCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * POST /api/v1/admin/enrollment-codes — freeze-table row 57.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE COLLISION PATH IS THE POINT OF THIS FILE.                            ║
 * ║                                                                          ║
 * ║ A happy-path test proves nothing about the retry loop: on a 90-million   ║
 * ║ value space the loop's `catch` branch will never be entered by chance,   ║
 * ║ so an implementation that rethrew on collision — or that looped forever  ║
 * ║ — would pass every ordinary test in this file.                           ║
 * ║                                                                          ║
 * ║ THE COLLISION IS THEREFORE FORCED, NOT WAITED FOR. There are two of      ║
 * ║ them below and they force it in two different ways:                      ║
 * ║                                                                          ║
 * ║  1. by SATURATION — a MySQL BEFORE INSERT trigger that rewrites the      ║
 * ║     first N drawn codes to one already-taken value, so the loop is       ║
 * ║     provably driven through its catch branch N times and then succeeds   ║
 * ║     with a DIFFERENT code. This is the only construction that observes   ║
 * ║     the retry rather than inferring it, because the RNG cannot be        ║
 * ║     injected (random_int is a language function, deliberately).          ║
 * ║                                                                          ║
 * ║  2. by EXHAUSTION — the same trigger left permanently on, so all ten     ║
 * ║     attempts collide and the RuntimeException with the Arabic message    ║
 * ║     is reached. Without this, MAX_ATTEMPTS could be 1 or 10_000 and no   ║
 * ║     test would notice.                                                   ║
 * ║                                                                          ║
 * ║ And the third case is the one a broad `catch (QueryException)` breaks:   ║
 * ║ a NON-collision 23000 must propagate untouched, not be mistaken for a    ║
 * ║ collision and reported as "could not generate a unique code".            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class EnrollmentCodeGenerationTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private const TRIGGER = 'trg_force_code_collision';

    protected function tearDown(): void
    {
        /*
         * DROPPED EXPLICITLY. RefreshDatabase rolls the TRANSACTION back and
         * DDL is not transactional in MySQL — a CREATE TRIGGER commits
         * implicitly. Left behind, this trigger would rewrite the code of
         * every INSERT in every subsequent test in the suite.
         */
        DB::unprepared('DROP TRIGGER IF EXISTS ' . self::TRIGGER);

        parent::tearDown();
    }

    public function test_an_admin_generates_an_eight_digit_code_bound_to_the_course(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['title' => 'كورس الجبر']);

        $this->loginAsAdmin();

        $response = $this->postJson('/api/v1/admin/enrollment-codes', [
            'course_id' => (string) $course->getKey(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'تم توليد الكود بنجاح')
            ->assertJsonCount(1, 'data.codes')
            ->assertJsonPath('data.codes.0.course_id', (string) $course->getKey())
            ->assertJsonPath('data.codes.0.course_title', 'كورس الجبر')
            ->assertJsonPath('data.codes.0.is_used', false);

        $code = (string) $response->json('data.codes.0.code');

        // CHAR(8), no leading zero — the range random_int is given guarantees
        // both, and the column can only round-trip what fits.
        self::assertMatchesRegularExpression('/^[1-9]\d{7}$/', $code);
        self::assertDatabaseHas('enrollment_codes', [
            'code' => $code,
            'course_id' => $course->getKey(),
            'is_used' => 0,
            'used_by_student_id' => null,
        ]);
    }

    /** Bulk generation (open decision 10.15) — `count` produces that many DISTINCT codes, all bound to the course. */
    public function test_count_generates_that_many_distinct_codes(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);

        $this->loginAsAdmin();

        $response = $this->postJson('/api/v1/admin/enrollment-codes', [
            'course_id' => (string) $course->getKey(),
            'count' => 5,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'تم توليد 5 كود بنجاح')
            ->assertJsonCount(5, 'data.codes');

        $codes = collect($response->json('data.codes'))->pluck('code');

        self::assertSame(5, $codes->unique()->count(), 'All five generated codes must be distinct.');
        self::assertSame(5, EnrollmentCode::query()->where('course_id', $course->getKey())->count());
    }

    /** A count over the cap is rejected before any code is generated. */
    public function test_a_count_over_the_cap_is_422(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);

        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/enrollment-codes', [
            'course_id' => (string) $course->getKey(),
            'count' => 501,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.count.0', 'عدد الأكواد يجب ألا يزيد عن 500');

        self::assertSame(0, EnrollmentCode::query()->count());
    }

    public function test_the_generated_code_records_the_admin_who_made_it(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);

        $admin = $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/enrollment-codes', [
            'course_id' => (string) $course->getKey(),
        ])->assertStatus(201);

        self::assertDatabaseHas('enrollment_codes', [
            'course_id' => $course->getKey(),
            'created_by' => $admin->getKey(),
        ]);
    }

    /** The camelCase spelling backend/'s controller also accepted. */
    public function test_the_camel_case_course_id_is_accepted_too(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);

        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/enrollment-codes', [
            'courseId' => (string) $course->getKey(),
        ])->assertStatus(201);
    }

    public function test_a_missing_course_id_is_422_with_the_contract_string(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/enrollment-codes', [])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.course_id.0', 'برجاء اختيار الكورس');
    }

    /**
     * 404, NOT 422 — freeze-table row 57. An `exists:` rule would have been
     * the obvious implementation and would answer the wrong status.
     */
    public function test_an_unknown_course_is_404_not_422(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/enrollment-codes', [
            'course_id' => (string) \Illuminate\Support\Str::uuid7(),
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'الدورة غير موجودة');
    }

    public function test_a_soft_deleted_course_cannot_be_given_new_codes(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $course->delete();

        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/enrollment-codes', [
            'course_id' => (string) $course->getKey(),
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'الدورة غير موجودة');

        self::assertSame(0, EnrollmentCode::query()->count());
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE FORCED-COLLISION TEST. The retry branch is EXERCISED, not        ║
     * ║ assumed.                                                             ║
     * ║                                                                      ║
     * ║ `random_int` cannot be mocked — it is a language function, and that  ║
     * ║ is deliberate, since a swappable RNG on a bearer token is a          ║
     * ║ liability. So the collision is forced at the DATABASE instead: a     ║
     * ║ BEFORE INSERT trigger rewrites the first two drawn codes to a value  ║
     * ║ that already exists, which makes uq_enrollment_codes_code reject     ║
     * ║ them exactly as a natural collision would.                           ║
     * ║                                                                      ║
     * ║ The observation that proves the loop ran: the row that finally       ║
     * ║ lands carries a code DIFFERENT from the seeded one, and the total    ║
     * ║ row count is 2 (the seed plus one) rather than 1. If the catch       ║
     * ║ branch rethrew, the call would raise a QueryException and no second  ║
     * ║ row would exist.                                                     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_collision_is_retried_and_succeeds_with_a_different_code(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $admin = $this->makeAdmin();

        $taken = $this->makeEnrollmentCode($course, ['code' => '19999999']);

        // Two forced collisions, then the trigger lets the third draw through.
        $this->forceCollisions('19999999', limit: 2);

        $generated = app(GenerateEnrollmentCode::class)($course, $admin);

        self::assertNotSame('19999999', $generated->code);
        self::assertMatchesRegularExpression('/^[1-9]\d{7}$/', (string) $generated->code);

        // The seeded code plus exactly one new row: the retries did not leave
        // partial inserts behind, and none of them silently succeeded.
        self::assertSame(2, EnrollmentCode::query()->count());

        // THREE attempts: two rejected by uq_enrollment_codes_code, the third
        // accepted. This is the assertion that proves the `catch` branch ran
        // rather than the happy path having been lucky.
        self::assertSame(3, $this->attempts());
        self::assertTrue($taken->fresh()?->exists);
    }

    /**
     * MAX_ATTEMPTS IS A REAL CEILING. With the trigger left on permanently
     * every draw collides, so the loop must terminate — and must do so with
     * the contract's Arabic message rather than by looping forever or by
     * leaking the raw QueryException.
     */
    public function test_exhausting_every_attempt_raises_the_arabic_failure_message(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $admin = $this->makeAdmin();

        $this->makeEnrollmentCode($course, ['code' => '19999999']);
        $this->forceCollisions('19999999', limit: 1000);

        try {
            app(GenerateEnrollmentCode::class)($course, $admin);
            self::fail('Generation should have given up after MAX_ATTEMPTS.');
        } catch (\RuntimeException $e) {
            self::assertSame(GenerateEnrollmentCode::EXHAUSTED_MESSAGE, $e->getMessage());
        }

        // Ten attempts, no more and no fewer — the constant is pinned by
        // observation rather than by reading it back out of the class.
        self::assertSame(10, $this->attempts());
        self::assertSame(1, EnrollmentCode::query()->count());
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A NON-COLLISION INTEGRITY ERROR MUST PROPAGATE **UNCHANGED**.        ║
     * ║                                                                      ║
     * ║ `fk_enrollment_codes_creator` is also a 23000. A broad               ║
     * ║ `catch (QueryException)` would retry it ten times and then report    ║
     * ║ 'تعذر توليد كود فريد…' — a confident, completely wrong diagnosis of  ║
     * ║ "the admin who is generating this code does not exist".              ║
     * ║                                                                      ║
     * ║ The fixture builds exactly that: a User model that was never saved,  ║
     * ║ so `created_by` names a uuid with no `users` row.                    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_non_collision_integrity_error_propagates_unchanged(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);

        $ghost = new User();
        $ghost->forceFill(['id' => (string) \Illuminate\Support\Str::uuid7()]);

        try {
            app(GenerateEnrollmentCode::class)($course, $ghost);
            self::fail('An FK violation must not be swallowed by the collision loop.');
        } catch (QueryException $e) {
            self::assertSame('23000', $e->errorInfo[0]);
            self::assertStringContainsString('fk_enrollment_codes_creator', $e->getMessage());
            self::assertStringNotContainsString('تعذر توليد', $e->getMessage());
        }

        self::assertSame(0, EnrollmentCode::query()->count());
    }

    /**
     * Installs a BEFORE INSERT trigger that rewrites the first `$limit` drawn
     * codes to `$collideWith`, counting each rewrite in `collision_probe`.
     *
     * A TRIGGER RATHER THAN A MOCK because the failure being reproduced is the
     * DATABASE rejecting a duplicate — the very thing a PHP-level fake would
     * have to simulate, and therefore could get wrong in the same direction as
     * the code under test.
     */
    private function forceCollisions(string $collideWith, int $limit): void
    {
        /*
         * ┌──────────────────────────────────────────────────────────────────┐
         * │ THE ATTEMPT COUNTER IS A MYSQL **SESSION VARIABLE**, NOT A ROW.  │
         * │                                                                  │
         * │ Measured, not assumed: the first version of this helper kept the │
         * │ counter in a table and the trigger UPDATEd it. It always read 0. │
         * │ A failed INSERT rolls the whole statement back INCLUDING its     │
         * │ trigger's side effects, so every increment vanished together     │
         * │ with the duplicate it was counting — the one write that can      │
         * │ never survive is the write that records the failure.             │
         * │                                                                  │
         * │ A user variable (`@…`) belongs to the CONNECTION, not to the     │
         * │ transaction, so it survives both the statement rollback and      │
         * │ RefreshDatabase's outer rollback. Laravel reuses one PDO         │
         * │ connection for the whole test, which is what makes it readable   │
         * │ afterwards.                                                      │
         * └──────────────────────────────────────────────────────────────────┘
         */
        DB::statement('SET @sanad_collision_attempts = 0');

        DB::unprepared(\sprintf(
            'CREATE TRIGGER %s BEFORE INSERT ON enrollment_codes FOR EACH ROW
             BEGIN
                 SET @sanad_collision_attempts = IFNULL(@sanad_collision_attempts, 0) + 1;
                 IF @sanad_collision_attempts <= %d THEN
                     SET NEW.code = %s;
                 END IF;
             END',
            self::TRIGGER,
            $limit,
            DB::getPdo()->quote($collideWith),
        ));
    }

    /** How many INSERTs the generator actually attempted. */
    private function attempts(): int
    {
        return (int) DB::selectOne('SELECT @sanad_collision_attempts AS n')->n;
    }
}
