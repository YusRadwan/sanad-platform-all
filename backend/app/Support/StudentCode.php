<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The `students.student_code` format, in one place.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ STAGE 4 DECISION #4 — SUFFIX/LIKE MATCHING IS REPLACED BY               │
 * │ NORMALISE-THEN-EXACT-MATCH. THIS IS A DELIBERATE BEHAVIOUR CHANGE.      │
 * │                                                                          │
 * │ WHAT backend/ DID. StudentRepository::findDetailsByStudentCode() ran     │
 * │   WHERE s.student_code LIKE CONCAT('%', :code)                           │
 * │ so that the admin could type either '510078' or 'STU510078'. Two         │
 * │ consequences, both bad:                                                  │
 * │  (a) a LIKE with a leading wildcard cannot use uq_students_code — every  │
 * │      lookup is a full table scan of a UNIQUE-indexed column;             │
 * │  (b) '510078' would also match a hypothetical 'STUX510078' or            │
 * │      'OLD510078'. The original comment acknowledged this and narrowed    │
 * │      the wildcard from '%code%' to '%code' — narrower, still not exact.  │
 * │                                                                          │
 * │ WHAT THIS DOES. normalise() folds both accepted input shapes onto the    │
 * │ ONE stored spelling, and the query is then a plain equality on the       │
 * │ unique column — index seek, one possible row, no wildcard semantics.     │
 * │ The admin's input flexibility is unchanged; only the matching rule is.   │
 * │                                                                          │
 * │ WHY IT IS PROVABLY SAFE, NOT MERELY UNFALSIFIED (verified, not assumed): │
 * │  1. `students` has exactly ONE insert path in each codebase —            │
 * │     backend/StudentRepository::create() and, in backend-v2,              │
 * │     App\Actions\Auth\RegisterStudent. Both take `student_code` from      │
 * │     ONE producer.                                                        │
 * │  2. That producer is generateStudentCode(), identical in both:           │
 * │         'STU' . random_int(100000, 999999)                               │
 * │     random_int's inclusive range 100000-999999 is exactly the six-digit  │
 * │     decimals with no leading zero, so the output is 'STU' + EXACTLY six  │
 * │     digits, always. There is no other shape it can produce.              │
 * │  3. NOTHING updates the column afterwards: StudentService::update()      │
 * │     writes only phone/parent_phone/governorate/grade_level/              │
 * │     educational_stage, and no migration backfills it.                    │
 * │  4. The live legacy database was checked and contains ZERO students —    │
 * │     a pre-launch platform, so there is not even historical data that     │
 * │     could contradict points 1-3.                                         │
 * │ Points 1-3 are the real argument; point 4 only removes the migration     │
 * │ question. A future code path that writes a differently-shaped code       │
 * │ would break this, which is why the format lives here as a named          │
 * │ constant rather than as a regex copied into a query.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class StudentCode
{
    /** The stored prefix. */
    public const PREFIX = 'STU';

    /** How many digits follow the prefix. */
    public const DIGITS = 6;

    /**
     * Accepted INPUT shapes, after trimming and upper-casing: the bare six
     * digits, or the whole stored code. Both are what backend/ accepted, and
     * what the ManageSubscriptions filter already accepted before it.
     */
    private const INPUT_PATTERN = '/^(?:' . self::PREFIX . ')?\d{' . self::DIGITS . '}$/';

    /**
     * The exact string an admin sees when their input is not a code shape.
     * Wording follows backend/StudentService::findByStudentCode()'s message.
     */
    public const INVALID_MESSAGE = 'كود الطالب يجب أن يكون 6 أرقام أو بالصيغة الكاملة STU متبوعة بـ 6 أرقام';

    /** TRUE if $code is one of the two accepted input shapes. */
    public static function isWellFormed(mixed $code): bool
    {
        if (!is_string($code)) {
            return false;
        }

        return preg_match(self::INPUT_PATTERN, self::fold($code)) === 1;
    }

    /**
     * Folds an accepted input shape onto the single STORED spelling.
     *
     *   ' stu510078 ' -> 'STU510078'
     *   '510078'      -> 'STU510078'
     *
     * Callers MUST have checked isWellFormed() first (the Form Request does);
     * this method does not validate, so that the validation failure carries a
     * 422 and its own Arabic message instead of a silently-mangled query.
     */
    public static function normalise(string $code): string
    {
        $folded = self::fold($code);

        return str_starts_with($folded, self::PREFIX) ? $folded : self::PREFIX . $folded;
    }

    /**
     * mb_strtoupper, not strtoupper: the input is admin-typed and a locale
     * where 'i' upper-cases oddly must not turn a valid code into an invalid
     * one. The prefix is ASCII, so this is belt-and-braces — but it costs
     * nothing and removes a locale dependency from a security-adjacent lookup.
     */
    private static function fold(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
