<?php

declare(strict_types=1);

namespace App\Actions\Enrollment;

use App\Models\Course;
use App\Models\EnrollmentCode;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Generates ONE globally unique 8-digit enrollment code bound to one course.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ INSERT-THEN-CATCH, **NOT** SELECT-THEN-INSERT.                           ║
 * ║                                                                          ║
 * ║ The tempting shape is:                                                   ║
 * ║     do { $code = random(); } while (EnrollmentCode::where('code',…)      ║
 * ║                                     ->exists());                         ║
 * ║     EnrollmentCode::create([...]);                                       ║
 * ║                                                                          ║
 * ║ It is wrong, and not marginally: two admins clicking "generate" in the   ║
 * ║ same instant can both read "free" for the SAME value before either       ║
 * ║ inserts. The check window and the write are separate statements, so no   ║
 * ║ amount of re-checking closes it. It also costs an extra round-trip on    ║
 * ║ the happy path, which is ~99.999% of calls.                              ║
 * ║                                                                          ║
 * ║ UNIQUE KEY uq_enrollment_codes_code IS THE AUTHORITY. This loop only     ║
 * ║ decides how to REACT to a collision the database has already refused;    ║
 * ║ it never assumes collisions are impossible. Same argument backend/       ║
 * ║ EnrollmentCodeRepository::createForCourse() makes, and the index name    ║
 * ║ it string-matches is preserved byte for byte — verified live against     ║
 * ║ sanad_platform_v2 before this file was written.                          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THE CATCH IS NARROW ON PURPOSE. ───────────────────────────────────────┐
 * │ Only SQLSTATE 23000 whose message names `uq_enrollment_codes_code` is    │
 * │ retried. EVERYTHING ELSE RETHROWS IMMEDIATELY AND UNCHANGED — including  │
 * │ a 23000 from `fk_enrollment_codes_course` (a course that vanished        │
 * │ between the existence check and the insert) and from                     │
 * │ `fk_enrollment_codes_creator`. A broad `catch (QueryException)` would    │
 * │ retry those ten times and then report "could not generate a unique       │
 * │ code", which is a false diagnosis of a completely different fault.       │
 * │                                                                          │
 * │ Pinned by EnrollmentCodeGenerationTest::                                 │
 * │ test_a_non_collision_integrity_error_propagates_unchanged.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `random_int`, NOT `rand()`/`mt_rand()`. It is the CSPRNG-backed generator.
 * An enrollment code is a BEARER TOKEN for paid content: anyone who types it
 * gets the course. A predictable sequence lets an attacker enumerate valid
 * unredeemed codes. The range 10000000..99999999 is chosen so every draw is
 * exactly 8 digits with no leading zero, which means the CHAR(8) column always
 * round-trips to precisely the string the admin was shown — a code generated
 * as 01234567 would be a different string from the 1234567 a careless
 * numeric-typed client would send back.
 */
final class GenerateEnrollmentCode
{
    /**
     * How many draws before giving up.
     *
     * With 90 million values in the space, ten consecutive collisions is not a
     * plausible outcome of chance — it means the table is saturated or
     * something is badly wrong — so the ceiling exists to turn an infinite
     * loop into a reportable error, not because it is expected to be reached.
     */
    private const MAX_ATTEMPTS = 10;

    /** The index whose violation — and ONLY whose violation — is retried. */
    private const CODE_UNIQUE_INDEX = 'uq_enrollment_codes_code';

    /** SQLSTATE class for an integrity constraint violation. */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    public const EXHAUSTED_MESSAGE = 'تعذر توليد كود فريد بعد عدة محاولات، برجاء المحاولة مرة أخرى';

    public function __invoke(Course $course, ?User $admin): EnrollmentCode
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $code = EnrollmentCode::query()->create([
                    'code' => (string) random_int(10000000, 99999999),
                    'course_id' => $course->getKey(),
                    'created_by' => $admin?->getKey(),
                    'is_used' => false,
                ]);

                /*
                 * `enrollment_codes.created_at` is DEFAULT CURRENT_TIMESTAMP
                 * and is never written from PHP, so the in-memory model has no
                 * value for it until the row is read back. The creation
                 * response ships `created_at`, and null there would be a wrong
                 * answer rather than an absent one.
                 */
                $code->refresh();

                SecurityLog::criticalOperation('Enrollment code generated', [
                    'event' => 'enrollment_code.created',
                    'code_id' => $code->getKey(),
                    'course_id' => $course->getKey(),
                    'created_by' => $admin?->getKey(),
                    'attempts' => $attempt,
                ]);

                return $code;
            } catch (QueryException $e) {
                if (!$this->isCodeCollision($e)) {
                    throw $e;
                }

                // A collision, and nothing else. Draw again.
            }
        }

        throw new RuntimeException(self::EXHAUSTED_MESSAGE);
    }

    /**
     * `errorInfo[0]` is the driver's SQLSTATE and is the reliable half of the
     * test; the index name has to be matched in the message text because
     * MySQL does not expose "which constraint" as a structured field. Both
     * conditions must hold — the SQLSTATE alone would also match every FK
     * failure on this table.
     */
    private function isCodeCollision(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === self::SQLSTATE_INTEGRITY_VIOLATION
            && str_contains($e->getMessage(), self::CODE_UNIQUE_INDEX);
    }
}
