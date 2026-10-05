<?php

declare(strict_types=1);

namespace App\Actions\Center;

use App\Exceptions\NotFoundException;
use App\Models\CenterGroup;
use App\Models\CenterPaymentRecord;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * "تسجيل حالة الدفع" — sets (creates or updates, never duplicates) ONE
 * paid/unpaid status for one (student, group, payment_type, period). Pure
 * tracking, see CenterPaymentRecord's docblock — no amount ever touches this.
 *
 * The student must actually be on THIS group's roster
 * (`center_group_students`, the same table attendance uses to know who
 * belongs where) — resolving a student id alone proves nothing about which
 * teacher/group they belong to, exactly the same reasoning
 * App\Actions\Center\MarkAttendance's membership check documents.
 */
final class SetCenterPaymentStatus
{
    public const NOT_YOUR_STUDENT = 'هذا الطالب غير موجود في هذه المجموعة';

    public function __invoke(
        CenterGroup $group,
        User $actor,
        string $studentId,
        string $paymentType,
        string $period,
        string $status
    ): CenterPaymentRecord {
        $onRoster = DB::table('center_group_students')
            ->where('center_group_id', $group->getKey())
            ->where('student_id', $studentId)
            ->exists();

        if (!$onRoster) {
            throw new NotFoundException(self::NOT_YOUR_STUDENT);
        }

        $record = CenterPaymentRecord::query()->updateOrCreate(
            [
                'teacher_id' => $group->teacher_id,
                'student_id' => $studentId,
                'center_group_id' => $group->getKey(),
                'payment_type' => $paymentType,
                'period' => $period,
            ],
            [
                'status' => $status,
                'recorded_by' => $actor->getKey(),
            ]
        );

        SecurityLog::info('Center payment status recorded', [
            'event' => 'center_payment.status_set',
            'teacher_id' => $group->teacher_id,
            'student_id' => $studentId,
            'center_group_id' => $group->getKey(),
            'payment_type' => $paymentType,
            'period' => $period,
            'status' => $status,
        ]);

        return $record;
    }
}
