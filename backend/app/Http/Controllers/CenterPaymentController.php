<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Center\SetCenterPaymentStatus;
use App\Http\Requests\Center\CenterPaymentReportRequest;
use App\Http\Requests\Center\CenterPaymentStudentsRequest;
use App\Http\Requests\Center\SetCenterPaymentStatusRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CenterMembership;
use App\Models\CenterPaymentRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * "متابعة مدفوعات السنتر" — explicit teacher request. A PURE paid/unpaid
 * tracking log for center students, scoped to one teacher's own groups —
 * see App\Models\CenterPaymentRecord's docblock for the "never a financial
 * record" boundary this whole feature must never cross.
 */
final class CenterPaymentController extends Controller
{
    /**
     * GET /center/payments/students — the chosen group's roster (exactly
     * `center_group_students`, the same roster attendance/absence counts
     * read — never a broader "all this teacher's students" list) with each
     * student's status for one (payment_type, period). A student with no
     * matching CenterPaymentRecord row shows "unpaid" — recording only
     * happens on an explicit teacher action, never implicitly.
     */
    public function students(CenterPaymentStudentsRequest $request): JsonResponse
    {
        $group = $request->group();

        $studentIds = DB::table('center_group_students')
            ->where('center_group_id', $group->getKey())
            ->pluck('student_id')
            ->map(fn ($id) => (string) $id);

        if ($studentIds->isEmpty()) {
            return ApiResponse::success(['students' => []]);
        }

        $memberships = CenterMembership::query()
            ->whereIn('student_id', $studentIds)
            ->where('teacher_id', $group->teacher_id)
            ->with(['student.user', 'code'])
            ->get()
            ->keyBy(fn (CenterMembership $m) => (string) $m->student_id);

        $q = $request->q();

        $statuses = CenterPaymentRecord::query()
            ->where('teacher_id', $group->teacher_id)
            ->where('center_group_id', $group->getKey())
            ->where('payment_type', $request->paymentType())
            ->where('period', $request->period())
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy(fn (CenterPaymentRecord $r) => (string) $r->student_id);

        $rows = $studentIds
            ->map(function (string $studentId) use ($memberships, $statuses): ?array {
                $membership = $memberships->get($studentId);

                if ($membership === null) {
                    // Roster row survives an admin "فك الربط" that removed the
                    // membership; without a live membership there is no center
                    // code / teacher-scoped identity left to show — skip it
                    // rather than render a half-populated row.
                    return null;
                }

                return [
                    'student_id' => $studentId,
                    'name' => $membership->student?->user?->full_name,
                    'student_code' => $membership->student?->student_code,
                    'center_code' => $membership->code?->code,
                    'phone' => $membership->student?->phone,
                    'status' => $statuses->get($studentId)?->status ?? CenterPaymentRecord::STATUS_UNPAID,
                ];
            })
            ->filter()
            ->values();

        if ($q !== '') {
            $rows = $rows->filter(fn (array $r) => str_contains((string) $r['student_code'], $q) || str_contains((string) $r['center_code'], $q))
                ->values();
        }

        return ApiResponse::success(['students' => $rows->all()]);
    }

    /** POST /center/payments — "تسجيل حالة الدفع" (دفع/لم يدفع). */
    public function store(SetCenterPaymentStatusRequest $request, SetCenterPaymentStatus $setStatus): JsonResponse
    {
        /** @var User $teacher */
        $teacher = $request->user();

        $record = $setStatus(
            $request->group(),
            $teacher,
            $request->studentId(),
            $request->paymentType(),
            $request->period(),
            $request->status()
        );

        return ApiResponse::success([
            'student_id' => (string) $record->student_id,
            'payment_type' => $record->payment_type,
            'period' => $record->period,
            'status' => $record->status,
        ], 'تم حفظ حالة الدفع');
    }

    /**
     * GET /center/payments/students/{studentId}/report — "تقرير الطالب".
     * Every record for this (teacher, student) pair, across every group of
     * this teacher's the student was ever recorded under — a metadata
     * header (name/codes/home group) plus the full history table.
     */
    public function report(CenterPaymentReportRequest $request, string $studentId): JsonResponse
    {
        /** @var User $teacher */
        $teacher = $request->user();
        $student = $request->student();

        $membership = CenterMembership::query()
            ->where('student_id', $studentId)
            ->where('teacher_id', $teacher->getKey())
            ->with('code')
            ->first();

        // The student's home group — same "earliest center_group_students
        // row" definition MarkAttendance's makeup-session logic uses — shown
        // as context in the report header only, never used to filter rows.
        $homeGroup = DB::table('center_group_students')
            ->join('center_groups', 'center_groups.id', '=', 'center_group_students.center_group_id')
            ->where('center_group_students.student_id', $studentId)
            ->where('center_groups.teacher_id', $teacher->getKey())
            ->orderBy('center_group_students.joined_at')
            ->select(['center_groups.center_name', 'center_groups.group_name'])
            ->first();

        $records = CenterPaymentRecord::query()
            ->where('teacher_id', $teacher->getKey())
            ->where('student_id', $studentId)
            ->orderByDesc('period')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success([
            'student' => [
                'id' => (string) $student->getKey(),
                'name' => $student->user?->full_name,
                'student_code' => $student->student_code,
                'center_code' => $membership?->code?->code,
                'center_name' => $homeGroup?->center_name,
                'group_name' => $homeGroup?->group_name,
            ],
            'records' => $records->map(fn (CenterPaymentRecord $r): array => [
                'id' => (string) $r->getKey(),
                'payment_type' => $r->payment_type,
                'period' => $r->period,
                'status' => $r->status,
                'recorded_at' => $r->updated_at?->format('Y-m-d H:i:s'),
            ])->values()->all(),
        ]);
    }
}
