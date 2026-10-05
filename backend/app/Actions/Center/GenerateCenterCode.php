<?php

declare(strict_types=1);

namespace App\Actions\Center;

use App\Models\CenterCode;
use App\Models\Teacher;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Generates one globally-unique center code (1 capital letter + 5 digits) for
 * one teacher. Insert-then-catch on `uq_center_codes_code`, same shape as
 * App\Actions\Enrollment\GenerateEnrollmentCode — see that class's banner for
 * why this is not select-then-insert.
 */
final class GenerateCenterCode
{
    private const MAX_ATTEMPTS = 10;

    private const CODE_UNIQUE_INDEX = 'uq_center_codes_code';

    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    public const EXHAUSTED_MESSAGE = 'تعذر توليد كود فريد بعد عدة محاولات، برجاء المحاولة مرة أخرى';

    public function __invoke(Teacher $teacher, ?User $admin): CenterCode
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $code = CenterCode::query()->create([
                    'code' => $this->draw(),
                    'teacher_id' => $teacher->getKey(),
                    'created_by' => $admin?->getKey(),
                    'is_active' => true,
                ]);

                $code->refresh();

                SecurityLog::criticalOperation('Center code generated', [
                    'event' => 'center_code.created',
                    'code_id' => $code->getKey(),
                    'teacher_id' => $teacher->getKey(),
                    'created_by' => $admin?->getKey(),
                    'attempts' => $attempt,
                ]);

                return $code;
            } catch (QueryException $e) {
                if (!$this->isCodeCollision($e)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException(self::EXHAUSTED_MESSAGE);
    }

    /** One capital letter (A-Z) + five random digits, e.g. `A58214`. */
    private function draw(): string
    {
        $letter = \chr(random_int(65, 90));
        $digits = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        return $letter . $digits;
    }

    private function isCodeCollision(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === self::SQLSTATE_INTEGRITY_VIOLATION
            && str_contains($e->getMessage(), self::CODE_UNIQUE_INDEX);
    }
}
