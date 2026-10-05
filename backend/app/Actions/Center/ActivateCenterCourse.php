<?php

declare(strict_types=1);

namespace App\Actions\Center;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\CenterAttendance;
use App\Models\CenterCode;
use App\Models\CenterCourseActivation;
use App\Models\CenterMembership;
use App\Models\CenterSession;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\CenterAccess;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "تفعيل كورس سنتر" — a free, no-payment grant of one course, in ONE of two
 * modes (never both, see ActivateCenterCourseRequest):
 *
 *   1) SESSION MODE ($sessionId): every student who has a `center_attendances`
 *      row for that session (present OR makeup — they showed up either way)
 *      is granted the course. Idempotent per student: re-activating for a
 *      later session they also attended adds nothing new for them.
 *
 *   2) STUDENT MODE ($identifier): ONE student, resolved the exact same way
 *      MarkAttendance resolves a check-in — center code first (what the
 *      card carries), then the platform student code, then phone — scoped
 *      to this teacher, with the same "code shared by several students"
 *      ambiguity guard. No session or roster membership required at all.
 *
 * Neither mode touches `courses.visibility` or creates a `Subscription` —
 * see CenterCourseActivation's own docblock for why a dedicated table
 * instead of either of those.
 */
final class ActivateCenterCourse
{
    public const SESSION_NOT_FOUND = CenterAccess::SESSION_NOT_FOUND;

    public const SESSION_NOT_OWNER = 'هذه الحصة لا تخص هذا الكورس';

    public const STUDENT_NOT_FOUND = 'لا يوجد طالب بهذا الكود/الهاتف';

    public const NOT_YOUR_STUDENT = 'هذا الطالب غير تابع لك';

    public const CODE_SHARED = 'هذا الكود مستخدم لأكثر من طالب، برجاء استخدام كود الطالب أو رقم الهاتف بدلاً منه';

    /** @return array{mode: 'session', granted: int, total_attendees: int}|array{mode: 'student', student_name: ?string} */
    public function __invoke(Course $course, User $actor, ?string $sessionId, ?string $identifier): array
    {
        return $sessionId !== null
            ? $this->activateForSession($course, $actor, $sessionId)
            : $this->activateForStudent($course, $actor, (string) $identifier);
    }

    /** @return array{mode: 'session', granted: int, total_attendees: int} */
    private function activateForSession(Course $course, User $actor, string $sessionId): array
    {
        $session = CenterSession::query()->whereKey($sessionId)->first()
            ?? throw new NotFoundException(self::SESSION_NOT_FOUND);

        if ((string) $session->teacher_id !== (string) $course->teacher_id) {
            throw new ForbiddenException(self::SESSION_NOT_OWNER);
        }

        $studentIds = CenterAttendance::query()
            ->where('center_session_id', $session->getKey())
            ->pluck('student_id');

        $granted = 0;

        DB::transaction(function () use ($course, $actor, $session, $studentIds, &$granted): void {
            foreach ($studentIds as $studentId) {
                $alreadyGranted = CenterCourseActivation::query()
                    ->where('course_id', $course->getKey())
                    ->where('student_id', $studentId)
                    ->exists();

                if ($alreadyGranted) {
                    continue;
                }

                CenterCourseActivation::query()->create([
                    'id' => (string) Str::uuid7(),
                    'course_id' => $course->getKey(),
                    'teacher_id' => $course->teacher_id,
                    'student_id' => $studentId,
                    'center_session_id' => $session->getKey(),
                    'activated_by' => $actor->getKey(),
                    'activated_at' => now(),
                ]);
                $granted++;
            }
        });

        SecurityLog::criticalOperation('Center course activated for session attendees', [
            'event' => 'center_course.activated_for_session',
            'course_id' => $course->getKey(),
            'session_id' => $session->getKey(),
            'teacher_id' => $course->teacher_id,
            'granted' => $granted,
            'total_attendees' => $studentIds->count(),
        ]);

        return ['mode' => 'session', 'granted' => $granted, 'total_attendees' => $studentIds->count()];
    }

    /** @return array{mode: 'student', student_name: ?string} */
    private function activateForStudent(Course $course, User $actor, string $identifier): array
    {
        $identifier = trim($identifier);
        $teacherId = (string) $course->teacher_id;

        $student = $this->resolveByCenterCode($identifier, $teacherId)
            ?? Student::query()->where('student_code', $identifier)->first()
            ?? Student::query()->where('phone', $identifier)->first();

        if ($student === null) {
            throw ValidationException::withMessages(['identifier' => [self::STUDENT_NOT_FOUND]]);
        }

        $isMember = CenterMembership::query()
            ->where('student_id', $student->getKey())
            ->where('teacher_id', $teacherId)
            ->exists();

        if (!$isMember) {
            throw ValidationException::withMessages(['identifier' => [self::NOT_YOUR_STUDENT]]);
        }

        $activation = CenterCourseActivation::query()
            ->where('course_id', $course->getKey())
            ->where('student_id', $student->getKey())
            ->first();

        if ($activation === null) {
            CenterCourseActivation::query()->create([
                'id' => (string) Str::uuid7(),
                'course_id' => $course->getKey(),
                'teacher_id' => $teacherId,
                'student_id' => $student->getKey(),
                'center_session_id' => null,
                'activated_by' => $actor->getKey(),
                'activated_at' => now(),
            ]);
        }

        SecurityLog::criticalOperation('Center course activated for one student', [
            'event' => 'center_course.activated_for_student',
            'course_id' => $course->getKey(),
            'teacher_id' => $teacherId,
            'student_id' => $student->getKey(),
        ]);

        return ['mode' => 'student', 'student_name' => $student->user?->full_name];
    }

    /**
     * Same resolution App\Actions\Center\MarkAttendance uses for its own
     * `identifier` field — kept as its own copy rather than a shared helper
     * so this brand-new, less-tested Action can never accidentally change
     * MarkAttendance's already-proven behaviour by editing "the same" code.
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
