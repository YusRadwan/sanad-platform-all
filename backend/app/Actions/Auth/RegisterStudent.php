<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Models\Student;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Port of backend/app/Services/AuthService::register()'s persistence half.
 * All VALIDATION lives in App\Http\Requests\Auth\RegisterRequest.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE USERNAME IS THE STUDENT'S LOCAL PHONE NUMBER, NOTHING ELSE.          │
 * │ No 'std_' prefix, no country code. Every caller sends a plain local      │
 * │ `01xxxxxxxxx` value, so no normalisation step is needed.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ATOMICITY: the users + students writes MUST land together. Without a
 * transaction, a failure on the students insert left an orphan users row
 * holding the username hostage — the student could never register again
 * ('هذا الهاتف مسجل بالفعل') yet had no student profile, so a login would
 * succeed into a broken account with no student_code. DB::transaction() is the
 * native version of the legacy inTransaction()/beginTransaction() dance, and it
 * nests correctly (savepoints) if a future caller wraps it.
 */
final class RegisterStudent
{
    /**
     * @param  array<string, mixed> $data validated payload from RegisterRequest
     * @return array{studentCode: string, userId: string}
     *
     * @throws ValidationException on a duplicate phone
     */
    public function __invoke(array $data): array
    {
        $username = (string) $data['studentPhone'];
        $email = $username . '@students.sanad.local';

        // Pre-flight check so the common case answers the exact contract
        // message rather than a database error. The UNIQUE index is still the
        // real guarantee — see the QueryException branch below for the race.
        if (User::query()->where('username', $username)->exists()) {
            throw ValidationException::withMessages([
                'studentPhone' => ['هذا الهاتف مسجل بالفعل'],
            ]);
        }

        $studentCode = $this->generateStudentCode();

        try {
            $userId = DB::transaction(function () use ($data, $username, $email, $studentCode): string {
                $user = new User();
                // password_hash / is_active are NOT $fillable — an account's
                // hash and active flag must never be settable from request
                // input — so they are assigned explicitly here.
                $user->forceFill([
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => Hash::make((string) $data['password']),
                    'full_name' => (string) $data['fullName'],
                    'role' => UserRole::Student,
                    'is_active' => true,
                ])->save();

                Student::query()->create([
                    'user_id' => $user->getKey(),
                    'student_code' => $studentCode,
                    'phone' => (string) $data['studentPhone'],
                    'parent_phone' => (string) $data['parentPhone'],
                    'governorate' => (string) $data['governorate'],
                    'gender' => $data['gender'] ?? null,
                    'educational_stage' => $data['educationalStage'] ?? null,
                    'education_type' => $data['eduType'] ?? null,
                    'grade_level' => (string) $data['gradeLevel'],
                ]);

                return (string) $user->getKey();
            });
        } catch (QueryException $e) {
            // Two concurrent signups for the same phone both pass the pre-flight
            // check, then one loses at uq_users_username. Answer the same
            // contract message rather than a 500 — the outcome is identical
            // from the caller's point of view.
            if ($this->isUsernameConflict($e)) {
                SecurityLog::warning('Student registration lost a username race', [
                    'event' => 'register.failure',
                    'username' => $username,
                    'reason' => 'duplicate_username',
                ]);

                throw ValidationException::withMessages([
                    'studentPhone' => ['هذا الهاتف مسجل بالفعل'],
                ]);
            }

            $this->reportFailure($username, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->reportFailure($username, $e);

            throw $e;
        }

        SecurityLog::info('Student registered', [
            'event' => 'register.success',
            'username' => $username,
            'target_user_id' => $userId,
            'student_code' => $studentCode,
        ]);

        return ['studentCode' => $studentCode, 'userId' => $userId];
    }

    private function reportFailure(string $username, Throwable $e): void
    {
        SecurityLog::error('Student registration failed and was rolled back', [
            'event' => 'register.failure',
            'username' => $username,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Matched on the CONSTRAINT NAME, not on a message substring: the index is
     * declared as `uq_users_username` in the migration, and MySQL echoes that
     * name in error 1062. (SQLSTATE 23000 alone would also catch the students
     * table's own unique keys.)
     */
    private function isUsernameConflict(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            && str_contains($e->getMessage(), 'uq_users_username');
    }

    /**
     * STU + six random digits, retried until free.
     *
     * random_int (CSPRNG), not rand(): a guessable student code is a weak
     * identifier in a system that also uses it for enrolment lookups.
     */
    private function generateStudentCode(): string
    {
        do {
            $code = 'STU' . random_int(100000, 999999);
        } while (Student::query()->where('student_code', $code)->exists());

        return $code;
    }
}
