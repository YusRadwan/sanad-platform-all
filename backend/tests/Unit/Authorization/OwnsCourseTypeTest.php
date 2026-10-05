<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Models\Course;
use App\Models\User;
use App\Policies\Concerns\ResolvesCourseOwnership;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * ResolvesCourseOwnership::ownsCourse() — THE TYPE MATRIX.
 *
 * A genuine unit test: no database, no application container. It hydrates
 * models by hand precisely so it can put a PHP int, a string and a Stringable
 * into an id attribute — which is the whole point. A DB-backed test could only
 * ever produce the CHAR(36) string case and would prove nothing about the
 * casts.
 *
 * Why this matters (see the method's docblock for the long version):
 *   * `===` between an int and a string is ALWAYS false -> a real owner is
 *     silently denied.
 *   * `==` would "fix" that and open a loose-comparison bypass instead.
 * The only safe form is `===` on two EXPLICITLY cast strings, on BOTH sides.
 */
final class OwnsCourseTypeTest extends TestCase
{
    /** The trait under test, exposed through a public shim. */
    private function subject(): object
    {
        return new class {
            use ResolvesCourseOwnership {
                ownsCourse as public callOwnsCourse;
            }
        };
    }

    private function course(mixed $teacherId): Course
    {
        $course = new Course();
        $course->teacher_id = $teacherId;

        return $course;
    }

    private function user(mixed $id): User
    {
        $user = new User();
        $user->id = $id;

        return $user;
    }

    private function stringable(string $value): Stringable
    {
        return new class ($value) implements Stringable {
            public function __construct(private string $value)
            {
            }

            public function __toString(): string
            {
                return $this->value;
            }
        };
    }

    private const UUID = '0195c1a2-3b4c-7d8e-9f00-112233445566';

    // ── MATCHING IDS: every type combination must say TRUE ────────────────

    public function test_string_id_on_both_sides_matches(): void
    {
        $this->assertTrue($this->subject()->callOwnsCourse(
            $this->user(self::UUID),
            $this->course(self::UUID),
        ));
    }

    public function test_int_course_id_against_string_user_id_matches(): void
    {
        $this->assertTrue($this->subject()->callOwnsCourse(
            $this->user('42'),
            $this->course(42),
        ));
    }

    public function test_int_user_id_against_string_course_id_matches(): void
    {
        $this->assertTrue($this->subject()->callOwnsCourse(
            $this->user(42),
            $this->course('42'),
        ));
    }

    public function test_int_on_both_sides_matches(): void
    {
        $this->assertTrue($this->subject()->callOwnsCourse(
            $this->user(42),
            $this->course(42),
        ));
    }

    public function test_stringable_course_id_against_string_user_id_matches(): void
    {
        $this->assertTrue($this->subject()->callOwnsCourse(
            $this->user(self::UUID),
            $this->course($this->stringable(self::UUID)),
        ));
    }

    public function test_stringable_user_id_against_string_course_id_matches(): void
    {
        $this->assertTrue($this->subject()->callOwnsCourse(
            $this->user($this->stringable(self::UUID)),
            $this->course(self::UUID),
        ));
    }

    public function test_stringable_on_both_sides_matches(): void
    {
        $this->assertTrue($this->subject()->callOwnsCourse(
            $this->user($this->stringable(self::UUID)),
            $this->course($this->stringable(self::UUID)),
        ));
    }

    // ── DIFFERENT IDS: must say FALSE, whatever the types ─────────────────

    public function test_two_different_uuids_do_not_match(): void
    {
        $this->assertFalse($this->subject()->callOwnsCourse(
            $this->user(self::UUID),
            $this->course('0195c1a2-3b4c-7d8e-9f00-ffffffffffff'),
        ));
    }

    public function test_different_numeric_ids_do_not_match(): void
    {
        $this->assertFalse($this->subject()->callOwnsCourse(
            $this->user(42),
            $this->course('43'),
        ));
    }

    // ── THE LOOSE-COMPARISON TRAPS ────────────────────────────────────────

    /**
     * The classic `==` bypass: PHP 7 evaluated `0 == 'any-string'` as TRUE.
     * PHP 8 fixed that particular case, but `'0' == 0`, `'42abc' == 42` and
     * friends still hold under `==`. `(string) 0 === (string) '0195…'` is
     * simply 'value mismatch' and cannot be talked into agreeing.
     */
    public function test_a_zero_user_id_does_not_match_a_uuid(): void
    {
        $this->assertFalse($this->subject()->callOwnsCourse(
            $this->user(0),
            $this->course(self::UUID),
        ));
    }

    /** `'42abc' == 42` is TRUE under loose comparison. It must not be here. */
    public function test_a_numeric_prefix_does_not_match_the_number(): void
    {
        $this->assertFalse($this->subject()->callOwnsCourse(
            $this->user(42),
            $this->course('42abc'),
        ));
    }

    /**
     * Two ids that are numerically equal but textually different — '042' and
     * '42' — are DIFFERENT ROWS in a CHAR(36) column. `==` would call them
     * equal; string comparison correctly does not.
     */
    public function test_leading_zeros_make_a_different_id(): void
    {
        $this->assertFalse($this->subject()->callOwnsCourse(
            $this->user('42'),
            $this->course('042'),
        ));
    }

    /**
     * A course with NO owner set: `(string) null` is '', and no real user id is
     * ever ''. Fails closed rather than matching a user whose id is somehow
     * also empty-ish.
     */
    public function test_a_null_teacher_id_never_matches_a_real_user(): void
    {
        $this->assertFalse($this->subject()->callOwnsCourse(
            $this->user(self::UUID),
            $this->course(null),
        ));
    }

    /** Case matters: UUIDs are stored lower-case and are not compared loosely. */
    public function test_case_differences_do_not_match(): void
    {
        $this->assertFalse($this->subject()->callOwnsCourse(
            $this->user(strtoupper(self::UUID)),
            $this->course(self::UUID),
        ));
    }
}
