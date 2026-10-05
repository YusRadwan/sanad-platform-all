<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Models\Student;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * PATCH | PUT /api/v1/admin/students/{id}.
 *
 * Port of backend/StudentService::update(). Simpler than its teacher
 * counterpart, but its rules were READ FROM THE CODE rather than assumed to
 * mirror Teacher's — and one of them differs.
 *
 * ┌── THE MATRIX ────────────────────────────────────────────────────────────┐
 * │                                                                          │
 * │  field              absent      ''                real value             │
 * │  ─────────────────  ──────────  ────────────────  ──────────             │
 * │  fullName           untouched   UNTOUCHED         update                 │
 * │  studentPhone       untouched   CLEAR             update                 │
 * │  parentPhone        untouched   CLEAR             update                 │
 * │  governorate        untouched   CLEAR             update                 │
 * │  gradeLevel         untouched   CLEAR             update                 │
 * │  educationalStage   untouched   CLEAR             update                 │
 * │  isActive           untouched   n/a (boolean)     update, incl. false    │
 * │  password           untouched   UNTOUCHED         reset (policy)        │
 * │  username (derived) untouched   UNTOUCHED         synced to studentPhone│
 * │                                                    (see the boxed note   │
 * │                                                    below), 422 on clash │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── WHERE THE '' RULE COMES FROM — MEASURED, NOT INFERRED ─────────────────┐
 * │ backend/ writes the five profile columns through                         │
 * │     array_filter([...], fn ($v) => $v !== null)                          │
 * │ with each entry built as `$data['studentPhone'] ?? null`.                │
 * │                                                                          │
 * │ The callback is EXPLICIT `!== null`, NOT array_filter's default. That is │
 * │ the whole behaviour: the default callback would drop '' and '0' too,     │
 * │ whereas `!== null` keeps them. Combined with the `?? null` that turns an │
 * │ absent key into null, the net rule is exactly "absent = untouched,       │
 * │ everything else including '' = written".                                 │
 * │                                                                          │
 * │ So an explicit `"studentPhone": null` ALSO leaves the column alone,      │
 * │ which is why isPresentAndNotNull() below tests both conditions rather    │
 * │ than has() alone — identical treatment to UpdateTeacher's clearable      │
 * │ fields, arrived at from a different legacy idiom.                        │
 * │                                                                          │
 * │ `fullName` is the exception, and it is the one that does NOT mirror the  │
 * │ rest: backend/ guards it with `!empty($data['fullName'])`, so '' is      │
 * │ ignored. filled() is that test. A student with a blank name is not a     │
 * │ state an admin can ask for — same reasoning as the teacher form.         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `governorate` AND `grade_level` ARE NOT NULL IN THE SCHEMA (migration
 * 000009), so "cleared" means the empty string — which is what backend/ wrote
 * too. UpdateStudentRequest refuses an explicit null for those two so the
 * failure is a 422 naming the field rather than a driver-level 1048.
 *
 * SUSPENSION IS WRITTEN TO users.is_active, not to any students column — the
 * exact counterpart of the isActive branch in UpdateTeacher, and for the same
 * historical reason: adminService.updateStudentStatus() sends {isActive} to
 * PUT /admin/students/{id}, and before backend/ grew this branch the call
 * returned 200 while the student could still log in.
 *
 * ┌── `studentPhone` KEEPS `users.username` IN SYNC — THE ONE FIELD THAT WAS  ┐
 * │ SILENTLY BREAKING LOGIN, FOUND FROM THE SECURITY LOG, NOT A REPORT.       │
 * │                                                                          │
 * │ RegisterStudent sets `username = studentPhone` ONCE, at signup, and      │
 * │ nothing ever touched it again — this Action used to write a new value    │
 * │ to `students.phone` only. The result: an admin corrects a student's      │
 * │ phone number here, the edit "succeeds", and the student's LOGIN id is    │
 * │ still the old number. Testing the new password (or the student trying    │
 * │ to log in with the number now shown everywhere) fails with "unknown      │
 * │ username", which the login page folds into the same generic wrong-       │
 * │ username-or-password string as a bad password — reported as "reset the   │
 * │ password, then can't log in" with no obvious cause. Confirmed by reading  │
 * │ storage/logs/security-*.log: a `student.password_reset` immediately      │
 * │ followed by a `login.failure` / `unknown_username` for a DIFFERENT       │
 * │ number than the one just reset.                                         │
 * │                                                                          │
 * │ Fix: a REAL, CHANGED `studentPhone` value also becomes the new           │
 * │ `username`, inside the same transaction as the phone write — unless      │
 * │ another account already owns that number, in which case the whole        │
 * │ request is rejected with a 422 naming `studentPhone` rather than         │
 * │ silently keeping the stale username or crashing on the unique index.     │
 * │ '' (clearing the phone) never touches `username` — a login id must       │
 * │ never become empty.                                                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class UpdateStudent
{
    /** Wire name => `students` column. Clearable on ''. */
    private const CLEARABLE_PROFILE_FIELDS = [
        'studentPhone' => 'phone',
        'parentPhone' => 'parent_phone',
        'governorate' => 'governorate',
        'gradeLevel' => 'grade_level',
        'educationalStage' => 'educational_stage',
        // NEW — same clearable-on-'' treatment as the five above, so the
        // edit page's gender/eduType chips behave identically to every
        // other profile field on this same form.
        'gender' => 'gender',
        'eduType' => 'education_type',
    ];

    /**
     * Raw Request, not validated() — presence-vs-emptiness is the entire
     * contract and validated() erases it for `sometimes|nullable` rules.
     */
    public function __invoke(Request $request, Student $student): Student
    {
        /** @var User $user */
        $user = $student->getRelationValue('user');

        // NEW (open decision 10.19) — "إعادة تعيين كلمة السر". Same
        // filled()-gated reset as App\Actions\Teacher\UpdateTeacher: absent or
        // '' leaves the password untouched, a real value resets it through the
        // shared PasswordPolicy already enforced by UpdateStudentRequest.
        $passwordChanged = $request->filled('password');

        // See the boxed note above: a real, changed studentPhone must also
        // become the new login username, checked for a conflict BEFORE
        // anything is written so a taken number 422s instead of leaving a
        // half-applied edit or crashing on uq_users_username.
        $newUsername = null;

        if ($request->has('studentPhone') && $request->input('studentPhone') !== null) {
            $newPhone = trim((string) $request->input('studentPhone'));

            if ($newPhone !== '' && $newPhone !== $user->username) {
                $taken = User::query()
                    ->where('username', $newPhone)
                    ->whereKeyNot($user->getKey())
                    ->exists();

                if ($taken) {
                    throw ValidationException::withMessages([
                        'studentPhone' => ['رقم الهاتف هذا مستخدم بالفعل كاسم دخول لحساب آخر'],
                    ]);
                }

                $newUsername = $newPhone;
            }
        }

        DB::transaction(function () use ($request, $student, $user, $passwordChanged, $newUsername): void {
            foreach (self::CLEARABLE_PROFILE_FIELDS as $field => $column) {
                if ($request->has($field) && $request->input($field) !== null) {
                    $student->setAttribute($column, (string) $request->input($field));
                }
            }

            if ($student->isDirty()) {
                $student->save();
            }

            // '' IGNORED here, unlike the five above. See the boxed note.
            if ($request->filled('fullName')) {
                $user->full_name = (string) $request->input('fullName');
            }

            if ($newUsername !== null) {
                $user->username = $newUsername;
            }

            // forceFill: password_hash is outside $fillable by design (same
            // reasoning as UpdateTeacher's password branch).
            if ($passwordChanged) {
                $user->forceFill([
                    'password_hash' => Hash::make((string) $request->input('password')),
                ]);
            }

            // has() BEFORE boolean(), never boolean() alone: boolean() answers
            // false for an absent key, so a phone-only edit would read as
            // "suspend this student".
            $suspensionChanged = false;

            if ($request->has('isActive')) {
                $isActive = $request->boolean('isActive');
                $suspensionChanged = (bool) $user->is_active !== $isActive;
                $user->is_active = $isActive;
            }

            if ($user->isDirty()) {
                $user->save();
            }

            if ($suspensionChanged) {
                SecurityLog::warning(
                    $user->is_active ? 'Student reactivated' : 'Student suspended',
                    [
                        'event' => 'student.is_active_changed',
                        'target_user_id' => $student->getKey(),
                        'is_active' => (int) $user->is_active,
                    ]
                );
            }
        });

        if ($passwordChanged) {
            SecurityLog::warning('Student password reset by admin', [
                'event' => 'student.password_reset',
                'target_user_id' => $student->getKey(),
            ]);
        }

        if ($newUsername !== null) {
            SecurityLog::warning('Student login username synced to new phone', [
                'event' => 'student.username_synced_to_phone',
                'target_user_id' => $student->getKey(),
                'new_username' => $newUsername,
            ]);
        }

        return $student;
    }
}
