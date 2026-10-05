<?php

declare(strict_types=1);

namespace App\Actions\Center;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\CenterCode;
use App\Models\CenterMembership;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * A student redeems a center code and joins that teacher's center.
 *
 * ┌── ONE CODE, ONE STUDENT AT A TIME — EXPLICIT TEACHER REQUEST. ───────────┐
 * │ A code links to exactly one student. Once linked, a DIFFERENT student    │
 * │ trying the same code is rejected outright (409): the teacher hands one   │
 * │ code to one student, and a second person typing it in (by mistake, or    │
 * │ having seen it somewhere) must not silently attach to that same slot.    │
 * │ Only the admin's "فك الربط" (CenterCodeController::unlinkMembership,     │
 * │ deletes the CenterMembership row) frees the code for reuse — by the same │
 * │ or a different student, exactly as the admin's own UI already promises   │
 * │ ("سيتمكن الطالب من استخدام الكود مرة أخرى").                             │
 * │                                                                          │
 * │ THE SAME student re-submitting the SAME code (or any of this teacher's   │
 * │ other codes, after already joining) still short-circuits to the existing │
 * │ membership below — idempotent, never an error — because that is not a   │
 * │ second person taking the slot, it is the rightful owner asking again.    │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class JoinCenter
{
    public const CODE_NOT_FOUND = 'الكود غير صحيح أو غير صالح';

    public const CODE_ALREADY_LINKED = 'هذا الكود مرتبط بالفعل بطالب آخر، يرجى الحصول على كود جديد من المدرس';

    public function __invoke(string $code, User $student): CenterMembership
    {
        $row = CenterCode::query()->where('code', $code)->where('is_active', true)->first()
            ?? throw new NotFoundException(self::CODE_NOT_FOUND);

        $membership = DB::transaction(function () use ($row, $student): CenterMembership {
            $existing = CenterMembership::query()
                ->where('student_id', $student->getKey())
                ->where('teacher_id', $row->teacher_id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $linkedToAnother = CenterMembership::query()
                ->where('center_code_id', $row->getKey())
                ->exists();

            if ($linkedToAnother) {
                throw new ConflictException(self::CODE_ALREADY_LINKED);
            }

            return CenterMembership::query()->create([
                'student_id' => $student->getKey(),
                'teacher_id' => $row->teacher_id,
                'center_code_id' => $row->getKey(),
                'joined_at' => now(),
            ]);
        });

        SecurityLog::criticalOperation('Student joined center', [
            'event' => 'center_code.joined',
            'code_id' => $row->getKey(),
            'teacher_id' => $row->teacher_id,
            'student_id' => $student->getKey(),
        ]);

        return $membership;
    }
}
