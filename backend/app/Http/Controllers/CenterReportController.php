<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Center\UnlinkCenterMembership;
use App\Exceptions\NotFoundException;
use App\Http\Requests\Center\CenterStudentReportRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CenterAttendance;
use App\Models\CenterGroup;
use App\Models\CenterMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "تقارير طلاب السنتر" — تقرير الطلاب. (تقرير حصة يُخدَم من
 * CenterSessionController::show()/CenterGroupController::sessions(), لا يحتاج
 * مساراً منفصلاً — نفس بيانات الجلسة المفصّلة.)
 *
 * ┌── STRICTLY SCOPED TO THE CALLING TEACHER'S OWN CENTER STUDENTS. ─────────┐
 * │ The base population is `CenterMembership::forTeacher($teacher)` — a      │
 * │ student who also belongs to another teacher's center never enters this   │
 * │ query at all, satisfying section 19/Test 20 of the spec.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── "كود الطالب" ON THIS REPORT MEANS THE **CENTER CODE**, NOT `student_code`.┐
 * │ Decision 10.28: the teacher reads this column as "which of my codes did  │
 * │ this student join with", not the platform-wide STU###### identifier —    │
 * │ so both the displayed `center_code` field and the `student_code` search  │
 * │ filter resolve through `CenterMembership::code()` (the specific          │
 * │ `center_codes` row used at join time), never through `students.student_  │
 * │ code`. The raw platform code is still returned as `student_code` for     │
 * │ anything else that may want it, but nothing on this report reads or      │
 * │ filters by it.                                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class CenterReportController extends Controller
{
    public function students(CenterStudentReportRequest $request): JsonResponse
    {
        /** @var User $teacher */
        $teacher = $request->user();

        $memberships = CenterMembership::query()
            ->forTeacher($teacher)
            ->with(['student.user', 'code'])
            ->when($request->phone() !== '', fn ($q) => $q->whereHas(
                'student',
                fn ($s) => $s->where('phone', 'like', '%' . $request->phone() . '%')
            ))
            ->when($request->studentCode() !== '', fn ($q) => $q->whereHas(
                'code',
                fn ($c) => $c->where('code', 'like', '%' . $request->studentCode() . '%')
            ))
            // "فلتر الصف الدراسي" — replaces the removed student-name filter.
            // Exact match: grade_level is chosen from the platform's fixed
            // grades catalog on the frontend, never free-typed.
            ->when($request->gradeLevel() !== '', fn ($q) => $q->whereHas(
                'student',
                fn ($s) => $s->where('grade_level', $request->gradeLevel())
            ))
            ->get();

        $studentIds = $memberships->pluck('student_id')->map(fn ($id) => (string) $id)->values();
        $teacherGroupIds = CenterGroup::query()->ownedBy($teacher)->pluck('id');

        $groupLinks = DB::table('center_group_students')
            ->join('center_groups', 'center_groups.id', '=', 'center_group_students.center_group_id')
            ->whereIn('center_group_students.student_id', $studentIds)
            ->whereIn('center_group_students.center_group_id', $teacherGroupIds)
            ->select(
                'center_group_students.student_id',
                'center_groups.id as group_id',
                'center_groups.center_name',
                'center_groups.grade_label',
                'center_groups.group_name'
            )
            ->get()
            ->groupBy('student_id');

        $attendances = CenterAttendance::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('center_group_id', $teacherGroupIds)
            ->orderByDesc('marked_at')
            ->get()
            ->groupBy(fn (CenterAttendance $a) => (string) $a->student_id);

        $sessionCountsByGroup = DB::table('center_sessions')
            ->whereIn('center_group_id', $teacherGroupIds)
            ->select('center_group_id', DB::raw('count(*) as total'))
            ->groupBy('center_group_id')
            ->pluck('total', 'center_group_id');

        $groupIdFilter = $request->groupId();
        $centerNameFilter = $request->centerName();

        $rows = [];

        foreach ($memberships as $membership) {
            $studentId = (string) $membership->student_id;
            $links = $groupLinks->get($studentId, collect());

            if ($groupIdFilter !== '' && !$links->contains('group_id', $groupIdFilter)) {
                continue;
            }

            if ($centerNameFilter !== '' && !$links->contains(
                fn ($l) => str_contains((string) $l->center_name, $centerNameFilter)
            )) {
                continue;
            }

            $present = $attendances->get($studentId, collect());
            $totalSessions = $links->sum(fn ($l) => (int) ($sessionCountsByGroup[$l->group_id] ?? 0));
            $presentCount = $present->count();
            $latestNote = $present->first(fn (CenterAttendance $a) => filled($a->note))?->note;

            $rows[] = [
                'student_id' => $studentId,
                // "فك الربط" from this report's own action column posts this
                // membership id to DELETE /center/memberships/{id} — a
                // teacher-scoped twin of the admin's own unlink endpoint.
                'membership_id' => (string) $membership->getKey(),
                // "كود الطالب" على هذا التقرير = كود السنتر الذي انضمّ به،
                // انظر الملاحظة أعلى الصنف. `student_code` باق للاستخدامات
                // الأخرى فقط، لا يُعرَض ولا يُفلتَر به هنا.
                'center_code' => $membership->code?->code,
                'student_code' => $membership->student?->student_code,
                'name' => $membership->student?->user?->full_name,
                'phone' => $membership->student?->phone,
                'parent_phone' => $membership->student?->parent_phone,
                'grade_level' => $membership->student?->grade_level,
                'groups' => $links->map(fn ($l) => [
                    'id' => (string) $l->group_id,
                    'center_name' => $l->center_name,
                    'grade_label' => $l->grade_label,
                    'group_name' => $l->group_name,
                ])->values()->all(),
                'present_days' => $presentCount,
                'absent_days' => max(0, $totalSessions - $presentCount),
                'note' => $latestNote,
            ];
        }

        return ApiResponse::success(['students' => $rows]);
    }

    /**
     * DELETE /center/memberships/{id} — "فك الربط" exposed directly to the
     * teacher from the students report's own action column, a scoped twin
     * of the admin's CenterCodeController::unlinkMembership(). `teacher_id`
     * is part of the lookup itself, not a check run after: a foreign
     * membership id simply does not match any row here, so it 404s exactly
     * like a guessed id would, never revealing whose it actually is.
     */
    public function unlinkStudent(Request $request, string $id, UnlinkCenterMembership $unlink): JsonResponse
    {
        /** @var User $teacher */
        $teacher = $request->user();

        $membership = CenterMembership::query()
            ->where('teacher_id', $teacher->getKey())
            ->whereKey($id)
            ->first()
            ?? throw new NotFoundException('الربط غير موجود');

        $unlink($membership);

        return ApiResponse::success(null, 'تم فك ارتباط الطالب بنجاح، ويمكنه استخدام الكود مرة أخرى');
    }
}
