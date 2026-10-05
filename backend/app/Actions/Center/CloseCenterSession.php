<?php

declare(strict_types=1);

namespace App\Actions\Center;

use App\Models\CenterSession;
use App\Support\SecurityLog;
use Illuminate\Validation\ValidationException;

/**
 * Finalises an OPEN session. Present/absent are computed and returned so the
 * caller can render the confirmation summary without a second request.
 */
final class CloseCenterSession
{
    public const ALREADY_CLOSED = 'هذه الحصة مغلقة بالفعل';

    /** @return array{total: int, present: int, absent: int} */
    public function __invoke(CenterSession $session): array
    {
        if (!$session->isOpen()) {
            throw ValidationException::withMessages(['session' => [self::ALREADY_CLOSED]]);
        }

        $total = $session->group?->students()->count() ?? 0;
        $present = $session->attendances()->count();

        $session->forceFill([
            'status' => CenterSession::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();

        SecurityLog::criticalOperation('Center session closed', [
            'event' => 'center_session.closed',
            'session_id' => $session->getKey(),
            'teacher_id' => $session->teacher_id,
            'total' => $total,
            'present' => $present,
        ]);

        return [
            'total' => $total,
            'present' => $present,
            'absent' => max(0, $total - $present),
        ];
    }
}
