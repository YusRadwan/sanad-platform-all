<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Exceptions\UnauthorizedException;
use App\Http\Requests\ApiFormRequest;
use App\Models\CenterGroup;
use App\Models\User;
use App\Support\CenterAccess;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * PATCH/DELETE /center/groups/{id}.
 *
 * ┌── DELETE ON A GROUP WITH STUDENTS REQUIRES THE TEACHER'S OWN PASSWORD. ──┐
 * │ Explicit request: "لا يمكن حذف مجموعة مسجل بها طلاب إلا بعد إدخال باسورد │
 * │ المدرس". Deleting an EMPTY group (never had a roster) needs no password  │
 * │ — the guard only fires once `center_group_students` actually holds a row │
 * │ for this group, mirroring RequiresAdminReauth's shape (the ACTOR's own    │
 * │ password, body `password` or header `x-teacher-password`, 401 not 422    │
 * │ on failure) but scoped to the owning teacher instead of an admin, and     │
 * │ CONDITIONAL rather than always-on.                                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class ManageCenterGroupRequest extends ApiFormRequest
{
    private ?CenterGroup $group = null;

    public function authorize(): bool
    {
        CenterAccess::assertOwnsGroup($this->group());

        if ($this->isMethod('DELETE')) {
            $this->assertDeletionAllowed();
        }

        return true;
    }

    private function assertDeletionAllowed(): void
    {
        $hasStudents = DB::table('center_group_students')
            ->where('center_group_id', $this->group()->getKey())
            ->exists();

        if (!$hasStudents) {
            return;
        }

        /** @var User $actor */
        $actor = $this->user();
        $supplied = $this->suppliedTeacherPassword();

        if ($supplied === null || $supplied === '') {
            throw new UnauthorizedException('لا يمكن حذف مجموعة بها طلاب مسجَّلون إلا بعد إدخال كلمة مرورك لتأكيد الحذف');
        }

        if (!Hash::check($supplied, $actor->getAuthPassword())) {
            SecurityLog::warning('Center group deletion rejected: wrong teacher password', [
                'event' => 'center_group.delete_reauth_failed',
                'actor_id' => $actor->getKey(),
                'center_group_id' => $this->group()->getKey(),
            ]);

            throw new UnauthorizedException('كلمة المرور غير صحيحة');
        }
    }

    /** Body `password` first, header `x-teacher-password` second — same two-way shape RequiresAdminReauth uses. */
    private function suppliedTeacherPassword(): ?string
    {
        $body = $this->input('password');

        if (is_string($body) && $body !== '') {
            return $body;
        }

        $header = $this->header('x-teacher-password');

        return is_string($header) && $header !== '' ? $header : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'center_name' => ['sometimes', 'string', 'max:190'],
            'grade_label' => ['sometimes', 'string', 'max:190'],
            'group_name' => ['sometimes', 'string', 'max:190'],
            'billing_type' => ['sometimes', 'string', Rule::in(CenterGroup::BILLING_TYPES)],
            // Same shape as StoreCenterGroupRequest's — sending it REPLACES
            // every existing slot (see CenterGroupController::update()),
            // absent leaves the group's current schedule untouched.
            'schedules' => ['sometimes', 'array', 'min:1'],
            'schedules.*.day_of_week' => ['required_with:schedules', 'string', Rule::in(CenterGroup::DAYS)],
            'schedules.*.time' => ['required_with:schedules', 'date_format:H:i'],
        ];
    }

    public function group(): CenterGroup
    {
        return $this->group ??= CenterAccess::findGroupOrFail((string) $this->route('id'));
    }

    /** @return list<array{day_of_week: string, time: string}>|null */
    public function schedules(): ?array
    {
        if (!$this->has('schedules')) {
            return null;
        }

        /** @var list<array{day_of_week: string, time: string}> $schedules */
        $schedules = $this->validated('schedules');

        return $schedules;
    }
}
