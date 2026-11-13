<?php

declare(strict_types=1);

namespace App\Actions\Enrollment;

use App\Enums\SubscriptionStatus;
use App\Exceptions\NotFoundException;
use App\Models\Course;
use App\Models\Student;
use App\Models\Subscription;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Grants complimentary course access from the dedicated admin tool.
 *
 * This deliberately does NOT call ToggleEnrollment or RecordRevenueTransaction:
 * this grant is an access override, not an off-platform sale.
 */
final class GrantManualActivation
{
    public function __construct(private readonly ActivateSubscription $activate)
    {
    }

    public function __invoke(
        string $studentId,
        string $courseId,
        string $accessType,
        ?string $expiresAt,
        string $adminId,
    ): Subscription {
        $student = Student::query()->withLiveUser()->whereKey($studentId)->first()
            ?? throw new NotFoundException('الطالب غير موجود');

        $course = Course::query()->whereKey($courseId)->first()
            ?? throw new NotFoundException('الدورة غير موجودة');

        $expiry = $accessType === 'limited'
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $expiresAt)->endOfDay()
            : null;

        $subscription = DB::transaction(function () use ($student, $course, $expiry): Subscription {
            // ActivateSubscription owns the concurrency-safe create-or-revive path.
            $activation = ($this->activate)((string) $student->getKey(), (string) $course->getKey());
            $row = $activation['row'];
            $row->status = SubscriptionStatus::Active;
            $row->expires_at = $expiry;
            $row->save();

            return $row->refresh();
        });

        SecurityLog::criticalOperation('Complimentary course access granted', [
            'event' => 'subscription.manual_activation',
            'subscription_id' => $subscription->getKey(),
            'student_id' => $student->getKey(),
            'course_id' => $course->getKey(),
            'access_type' => $accessType,
            'expires_at' => $subscription->expires_at?->toDateTimeString(),
            'admin_id' => $adminId,
        ]);

        return $subscription;
    }
}
