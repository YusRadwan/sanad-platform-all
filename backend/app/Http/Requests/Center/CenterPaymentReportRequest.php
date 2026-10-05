<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Exceptions\NotFoundException;
use App\Http\Requests\ApiFormRequest;
use App\Models\CenterMembership;
use App\Models\Student;
use App\Models\User;

/**
 * GET /center/payments/students/{studentId}/report — "تقرير الطالب" (full
 * payment-tracking history, one student, this teacher only).
 *
 * `authorize()` IS the isolation boundary here (section 12's hard
 * requirement): the student must have an actual `center_memberships` row
 * with the calling teacher, or this 404s — same "resolution alone proves
 * nothing, only a membership row does" reasoning MarkAttendance documents.
 * A 404 (not 403) so a foreign student id cannot even be confirmed to exist.
 */
final class CenterPaymentReportRequest extends ApiFormRequest
{
    public const NOT_YOUR_STUDENT = 'الطالب غير موجود';

    private ?Student $student = null;

    public function authorize(): bool
    {
        /** @var User $teacher */
        $teacher = $this->user();

        $studentId = (string) $this->route('studentId');

        $isMember = CenterMembership::query()
            ->where('student_id', $studentId)
            ->where('teacher_id', $teacher->getKey())
            ->exists();

        if (!$isMember) {
            throw new NotFoundException(self::NOT_YOUR_STUDENT);
        }

        $this->student = Student::query()->whereKey($studentId)->with('user')->first()
            ?? throw new NotFoundException(self::NOT_YOUR_STUDENT);

        return true;
    }

    public function student(): Student
    {
        return $this->student ??= throw new \LogicException('authorize() must run before student()');
    }

    /** No body/query params — everything comes from the {studentId} route segment resolved in authorize(). */
    public function rules(): array
    {
        return [];
    }
}
