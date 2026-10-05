<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Password policy for student self-registration (POST /auth/register), used
 * both by the public signup wizard and by Admin/AddStudent.tsx.
 *
 * Deliberately weaker than {@see PasswordPolicy} (which still governs
 * teacher/admin accounts and credential updates): a student may pick any
 * password of their choosing, no composition rules, with only a floor on
 * length. This is a product decision, not a security recommendation — see
 * the open-decisions log for the reasoning.
 */
final class StudentPasswordPolicy implements ValidationRule
{
    public const MIN_LENGTH = 6;

    public const MAX_LENGTH = 200;

    /** @return array<int, string|self> */
    public static function rules(): array
    {
        return ['required', 'string', new self()];
    }

    /** EXACT CONTRACT STRING — do not paraphrase. */
    public static function describe(): string
    {
        return 'يجب ألا تقل كلمة المرور عن ' . self::MIN_LENGTH . ' أحرف';
    }

    public static function passes(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        $length = mb_strlen($value);

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            $fail('كلمة المرور مطلوبة');

            return;
        }

        if (!self::passes($value)) {
            $fail(self::describe());
        }
    }
}
