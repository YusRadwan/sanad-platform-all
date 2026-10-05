<?php

declare(strict_types=1);

namespace App\Actions\Center;

use App\Exceptions\ConflictException;
use App\Models\CenterAttendance;
use App\Models\CenterCode;
use App\Models\CenterMembership;
use App\Models\CenterSession;
use App\Models\Student;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a student by ONE identifier — the center code (1 capital letter +
 * 5 digits) printed/scanned off the student's card, the platform student
 * code, or a phone number — and marks them present for an OPEN session.
 *
 * ┌── CENTER CODE IS TRIED FIRST — IT IS WHAT THE CARD ACTUALLY CARRIES. ────┐
 * │ The physical card a center student scans (barcode/QR, hardware reader   │
 * │ typing it as keystrokes — see CenterAttendance.tsx's unified input)      │
 * │ carries the CENTER code they joined with (decision 10.28: reports read   │
 * │ this column the same way), never their platform `STU######` code.       │
 * │ Falling through to `student_code`/`phone` keeps manual attendance-taking │
 * │ working exactly as before for a teacher who just knows those instead.    │
 * │                                                                          │
 * │ A center code is REUSABLE (JoinCenter.php) — normally issued one-per-    │
 * │ student, but nothing stops a whole class sharing one. Resolving it to    │
 * │ MORE THAN ONE of THIS teacher's students is genuinely ambiguous, so that │
 * │ case is rejected with a clear message instead of guessing which student  │
 * │ to mark present.                                                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── "حصة تعويضية" — A STUDENT'S HOME GROUP IS THEIR EARLIEST-JOINED ONE. ──┐
 * │ Explicit teacher request. A student's "home" group is whichever of this  │
 * │ teacher's groups their OLDEST `center_group_students` row names — set    │
 * │ once, on their very first attendance ever, and never moved afterward.    │
 * │ Checking into a DIFFERENT group's session is allowed (a student who      │
 * │ misses their own slot can make it up elsewhere), but ONLY after the      │
 * │ teacher explicitly confirms — see `confirmed` below — and even then the  │
 * │ student is NOT added to the new group's roster: the attendance row is    │
 * │ flagged `is_makeup = true` instead, so the new group's total/absence     │
 * │ counts (built from `center_group_students`) stay exactly the roster the  │
 * │ teacher actually enrolled, not whoever happened to sit in on a session.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE MEMBERSHIP CHECK BELOW IS THE ENTIRE CROSS-TEACHER ISOLATION         ║
 * ║ GUARANTEE FOR ATTENDANCE-TAKING.                                         ║
 * ║                                                                          ║
 * ║ Any student on the platform can be RESOLVED by code/phone (those columns ║
 * ║ are not scoped to one teacher) — resolution alone proves nothing. Only a ║
 * ║ `center_memberships` row naming THIS session's teacher proves the        ║
 * ║ student is actually this teacher's. Without this check, Teacher A could  ║
 * ║ mark Teacher B's student present by typing a phone number they happen to ║
 * ║ know — exactly the leak the spec's Test 9 exists to catch. The center-   ║
 * ║ code lookup is ALREADY scoped to this teacher's own codes, but the check ║
 * ║ still runs unconditionally afterward for one uniform guarantee.          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class MarkAttendance
{
    public const NOT_FOUND = 'لا يوجد طالب بهذا الكود/الهاتف';

    public const NOT_YOUR_STUDENT = 'هذا الطالب غير تابع لك';

    public const SESSION_CLOSED = 'هذه الحصة مغلقة، لا يمكن تسجيل حضور جديد';

    public const CODE_SHARED = 'هذا الكود مستخدم لأكثر من طالب، برجاء استخدام كود الطالب أو رقم الهاتف بدلاً منه';

    public function __invoke(CenterSession $session, string $identifier, bool $confirmed = false): CenterAttendance
    {
        if (!$session->isOpen()) {
            throw ValidationException::withMessages(['identifier' => [self::SESSION_CLOSED]]);
        }

        $identifier = trim($identifier);

        $student = $this->resolveByCenterCode($identifier, (string) $session->teacher_id)
            ?? Student::query()->where('student_code', $identifier)->first()
            ?? Student::query()->where('phone', $identifier)->first();

        if ($student === null) {
            throw ValidationException::withMessages(['identifier' => [self::NOT_FOUND]]);
        }

        $isMember = CenterMembership::query()
            ->where('student_id', $student->getKey())
            ->where('teacher_id', $session->teacher_id)
            ->exists();

        if (!$isMember) {
            throw ValidationException::withMessages(['identifier' => [self::NOT_YOUR_STUDENT]]);
        }

        $homeGroup = DB::table('center_group_students')
            ->join('center_groups', 'center_groups.id', '=', 'center_group_students.center_group_id')
            ->where('center_group_students.student_id', $student->getKey())
            ->where('center_groups.teacher_id', $session->teacher_id)
            ->orderBy('center_group_students.joined_at')
            ->select(['center_group_students.center_group_id', 'center_groups.center_name', 'center_groups.group_name'])
            ->first();

        $isMakeup = $homeGroup !== null && (string) $homeGroup->center_group_id !== (string) $session->center_group_id;

        if ($isMakeup && !$confirmed) {
            throw new ConflictException(sprintf(
                'هذا الطالب مسجَّل بالفعل في مجموعة "%s — %s"، هل تريد تسجيل حضوره في هذه الحصة كحصة تعويضية؟',
                $homeGroup->center_name,
                $homeGroup->group_name,
            ));
        }

        $attendance = DB::transaction(function () use ($session, $student, $isMakeup): CenterAttendance {
            /*
             * `center_group_students` has its own UUID `id` primary key
             * (matching every other table's convention) rather than a
             * composite one, so BelongsToMany::syncWithoutDetaching() cannot
             * be used here — it never populates a pivot's own surrogate key.
             * A plain upsert on the (group, student) unique index does the
             * same "insert once, never duplicate" job directly.
             *
             * SKIPPED ENTIRELY for a confirmed makeup check-in: the whole
             * point is that the student is NOT added to this group's roster.
             */
            if (!$isMakeup) {
                $alreadyMember = DB::table('center_group_students')
                    ->where('center_group_id', $session->center_group_id)
                    ->where('student_id', $student->getKey())
                    ->exists();

                if (!$alreadyMember) {
                    DB::table('center_group_students')->insert([
                        'id' => (string) Str::uuid7(),
                        'center_group_id' => $session->center_group_id,
                        'student_id' => $student->getKey(),
                        'joined_at' => now(),
                    ]);
                }
            }

            $existing = CenterAttendance::query()
                ->where('center_session_id', $session->getKey())
                ->where('student_id', $student->getKey())
                ->first();

            if ($existing !== null) {
                $existing->forceFill(['marked_at' => now(), 'is_makeup' => $isMakeup])->save();

                return $existing;
            }

            return CenterAttendance::query()->create([
                'center_session_id' => $session->getKey(),
                'center_group_id' => $session->center_group_id,
                'student_id' => $student->getKey(),
                'marked_at' => now(),
                'is_makeup' => $isMakeup,
            ]);
        });

        SecurityLog::criticalOperation('Center attendance marked', [
            'event' => 'center_attendance.marked',
            'session_id' => $session->getKey(),
            'teacher_id' => $session->teacher_id,
            'student_id' => $student->getKey(),
            'is_makeup' => $isMakeup,
        ]);

        return $attendance;
    }

    /**
     * Center codes are scoped to ONE teacher (`center_codes.teacher_id`), so
     * a code that belongs to a different teacher never resolves here at
     * all — it silently falls through to the student_code/phone attempts
     * instead of leaking who owns it.
     */
    private function resolveByCenterCode(string $identifier, string $teacherId): ?Student
    {
        $code = CenterCode::query()
            ->where('code', $identifier)
            ->where('teacher_id', $teacherId)
            ->first();

        if ($code === null) {
            return null;
        }

        $studentIds = CenterMembership::query()
            ->where('center_code_id', $code->getKey())
            ->pluck('student_id');

        if ($studentIds->count() > 1) {
            throw ValidationException::withMessages(['identifier' => [self::CODE_SHARED]]);
        }

        if ($studentIds->isEmpty()) {
            return null;
        }

        return Student::query()->find($studentIds->first());
    }
}
