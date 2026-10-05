<?php

declare(strict_types=1);

namespace App\Actions\Teacher;

use App\Models\Teacher;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PATCH | PUT /api/v1/admin/teachers/{id}.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE HIGHEST-RISK CODE IN STAGE 4. READ THE WHOLE DOCBLOCK BEFORE        ║
 * ║ CHANGING ONE LINE OF THE METHOD BELOW.                                  ║
 * ║                                                                          ║
 * ║ Teacher update has THREE DIFFERENT absent/empty/real-value semantics    ║
 * ║ across its fields. Getting them uniform — in either direction — has     ║
 * ║ ALREADY CORRUPTED DATA IN PRODUCTION ONCE, and the failure is silent:   ║
 * ║ the request returns 200 and the wrong column is written or skipped.     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THE MATRIX. EVERY ROW IS ENFORCED BY ITS OWN TEST. ────────────────────┐
 * │                                                                          │
 * │  field                 absent        ''                real value        │
 * │  ────────────────────  ────────────  ─────────────────  ───────────────   │
 * │  fullName              untouched     UNTOUCHED          update            │
 * │  email                 untouched     UNTOUCHED          update            │
 * │  avatarUrl             untouched     CLEAR the column   update            │
 * │  bannerUrl             untouched     CLEAR              update            │
 * │  profilePhotoUrl       untouched     CLEAR              update            │
 * │  phone                 untouched     CLEAR              update            │
 * │  specialization        untouched     CLEAR              update            │
 * │  bio                   untouched     CLEAR              update            │
 * │  subjects / grades     untouched     [] = clear all     update            │
 * │  whatsappNumbers       untouched     [] = clear all     update            │
 * │  socialLinks           untouched     [] = DELETE ALL    replace entirely  │
 * │  isActive              untouched     n/a (boolean)      update, incl. false│
 * │  loginBlocked          untouched     n/a (boolean)      update, incl. false│
 * │  centerEnabled         untouched     n/a (boolean)      update, incl. false│
 * │  password              untouched     UNTOUCHED          reset (policy)    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── isActive AND loginBlocked ARE TWO SWITCHES, NOT ONE ───────────────────┐
 * │ `isActive` writes users.is_active: suspension — hides the teacher from   │
 * │ the public directory, hides their courses AND blocks login.              │
 * │ `loginBlocked` writes teachers.login_blocked (migration 000037): blocks  │
 * │ login and NOTHING ELSE — the profile and the courses stay exactly as     │
 * │ visible as they were.                                                    │
 * │                                                                          │
 * │ They share a ROW SHAPE in this matrix and nothing else. Never fold them  │
 * │ into one branch, and never write one from the other's key: a payload     │
 * │ carrying only `loginBlocked` must leave users.is_active untouched, and   │
 * │ vice versa. Both are exercised by their own tests.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── WHY fullName/email DIFFER FROM THE IMAGE FIELDS ───────────────────────┐
 * │ A teacher with a blank name, or a blank login email, is not a state an   │
 * │ admin can meaningfully ask for — the account would be unusable and the   │
 * │ table unreadable. So '' there means "the form submitted its untouched    │
 * │ empty box", i.e. ignore.                                                 │
 * │                                                                          │
 * │ For the image fields '' is the OPPOSITE: it is exactly what              │
 * │ AddTeacher.tsx sends when the admin presses "حذف وتصغير" to REMOVE a     │
 * │ photo. This is the bug that actually happened: an early version filtered │
 * │ the whole payload through a bare `array_filter()`, whose DEFAULT         │
 * │ callback discards '' as well as null — so the avatar could be replaced   │
 * │ but never removed, while banner_url and profile_photo_url (built with an │
 * │ explicit `!== null` filter) cleared correctly. Two fields with the same  │
 * │ meaning behaved differently for months.                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── THE EXACT LARAVEL MECHANICS, AND WHY EACH IS THE ONLY CORRECT ONE ─────┐
 * │                                                                          │
 * │ $request->filled($f)   present AND non-empty. Treats absent and '' the   │
 * │                        same. THE RIGHT TOOL for fullName/email/password. │
 * │                                                                          │
 * │ $request->has($f)      array_key_exists-equivalent. NOT fooled by a      │
 * │                        falsy value — `false`, `0`, `''` and `[]` all     │
 * │                        count as PRESENT. THE RIGHT TOOL for isActive,    │
 * │                        loginBlocked and socialLinks, whose whole point   │
 * │                        is a falsy value.                                 │
 * │                                                                          │
 * │ $request->input($f)    the RAW value, '' included. Used AS-IS for the    │
 * │                        clearable fields — never re-tested with filled(). │
 * │                                                                          │
 * │ ✗ NEVER $request->validated() + fill() + save() AS A BLANKET PATTERN     │
 * │   HERE. It passes '' through uniformly to every field, which clears      │
 * │   fullName and email — the exact array_filter bug, re-introduced from    │
 * │   the other direction.                                                   │
 * │                                                                          │
 * │ ✗ NEVER $request->boolean('isActive') — OR ->boolean('loginBlocked') —   │
 * │   WITHOUT has() IN FRONT. boolean() returns FALSE for an absent key, so  │
 * │   a payload that only changes the bio would read as "suspend this        │
 * │   teacher" and silently lock the instructor out — and, for the new flag, │
 * │   as "unblock this teacher's login", quietly undoing an admin lockout.   │
 * │   has() establishes PRESENCE; boolean() then interprets the value.       │
 * │   Both, in that order, always.                                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── ONE DELIBERATE REFINEMENT OF backend/'s BEHAVIOUR: EXPLICIT null ──────┐
 * │ backend/ reached the clearable fields through `$data['phone'] ?? null`   │
 * │ and then filtered `fn ($v) => $v !== null`, and reached avatarUrl        │
 * │ through `isset()` — which is ALSO false for null. So in backend/ an      │
 * │ explicit `"phone": null` was indistinguishable from omitting the key:    │
 * │ both left the column alone. `has()` alone would NOT preserve that — it   │
 * │ is true for an explicit null — so every clearable field below tests      │
 * │ has() AND `input() !== null`. Same observable behaviour as backend/,     │
 * │ stated in one place instead of emerging from two different idioms.       │
 * │ Clearing is done with '' (what the form sends), not null.                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ATOMICITY: this writes `users`, `teachers` and `teacher_social_links`. A
 * half-applied edit would show the new name in the users table while the
 * profile fields kept the old values, with no way for the admin to tell which
 * half landed.
 */
final class UpdateTeacher
{
    /**
     * Fields that live on `teachers` and CLEAR on ''. Wire name => column.
     * Listed as data rather than as eight if-blocks so the matrix above and
     * the code cannot drift.
     */
    private const CLEARABLE_PROFILE_FIELDS = [
        'phone' => 'phone',
        'specialization' => 'specialization',
        'bio' => 'bio',
        'bannerUrl' => 'banner_url',
        'profilePhotoUrl' => 'profile_photo_url',
    ];

    /** JSON array columns on `teachers`. Same presence rule, array values. */
    private const ARRAY_PROFILE_FIELDS = [
        'subjects' => 'subjects',
        'grades' => 'grades',
        'whatsappNumbers' => 'whatsapp_support_numbers',
    ];

    public function __construct(
        private readonly NormaliseSocialLinks $normaliseSocialLinks,
        private readonly ReplaceSocialLinks $replaceSocialLinks,
    ) {
    }

    /**
     * Takes the RAW Request, not validated(): the whole method turns on
     * presence-vs-emptiness, and validated() erases that distinction for every
     * `sometimes|nullable` rule. Shape has already been checked by
     * UpdateTeacherRequest; this reads intent.
     */
    public function __invoke(Request $request, Teacher $teacher): Teacher
    {
        /** @var User $user */
        $user = $teacher->getRelationValue('user');

        $passwordChanged = $request->filled('password');

        DB::transaction(function () use ($request, $teacher, $user, $passwordChanged): void {
            // ── users: name / email — '' IGNORED ──────────────────────────
            if ($request->filled('fullName')) {
                $user->full_name = (string) $request->input('fullName');
            }

            if ($request->filled('email')) {
                $user->email = (string) $request->input('email');
            }

            // ── users: avatar — '' CLEARS ────────────────────────────────
            if ($this->isPresentAndNotNull($request, 'avatarUrl')) {
                $user->avatar_url = (string) $request->input('avatarUrl');
            }

            // ── users: password — '' IGNORED, real value resets ──────────
            // forceFill: password_hash is outside $fillable by design.
            if ($passwordChanged) {
                $user->forceFill([
                    'password_hash' => Hash::make((string) $request->input('password')),
                ]);
            }

            // ── users: suspension — presence, NOT truthiness ─────────────
            // has() before boolean(). See the mechanics box. `false` is the
            // entire point of the suspend direction, so any idiom that treats
            // falsy as absent is wrong here.
            //
            // This is what makes ManageTeachers.tsx's تفعيل/إيقاف button work
            // at all: before backend/ grew this branch, the endpoint wrote only
            // profile columns, so the call returned 200, the row flipped in the
            // UI, and users.is_active never changed — a "suspended" instructor
            // could still log in.
            $suspensionChanged = false;

            if ($request->has('isActive')) {
                $isActive = $request->boolean('isActive');

                if ((bool) $user->is_active !== $isActive) {
                    $suspensionChanged = true;
                }

                $user->is_active = $isActive;
            }

            if ($user->isDirty()) {
                $user->save();
            }

            // ── teachers: clearable text/url columns — '' CLEARS ─────────
            foreach (self::CLEARABLE_PROFILE_FIELDS as $field => $column) {
                if ($this->isPresentAndNotNull($request, $field)) {
                    // The RAW value, used AS-IS. Deliberately not filled():
                    // filled() would treat '' as absent, which is exactly
                    // backwards for these five.
                    $teacher->setAttribute($column, (string) $request->input($field));
                }
            }

            // ── teachers: JSON arrays — [] CLEARS ALL ────────────────────
            foreach (self::ARRAY_PROFILE_FIELDS as $field => $column) {
                if ($this->isPresentAndNotNull($request, $field)) {
                    $teacher->setAttribute($column, (array) $request->input($field));
                }
            }

            // ── teachers: login block — presence, NOT truthiness ─────────
            // The SECOND, INDEPENDENT switch (migration 000037). Same has()-
            // before-boolean() rule as the suspension branch above and for the
            // same reason: `false` — "let this teacher sign in again" — is half
            // the feature, so any idiom that reads falsy as absent would make
            // the unblock direction a silent no-op.
            //
            // It writes `teachers`, not `users`: this is NOT suspension. A
            // teacher blocked here stays in the public directory and their
            // courses stay published; only App\Actions\Auth\LoginUser reads the
            // column. Never merge this branch with the isActive one.
            $loginBlockChanged = false;

            if ($request->has('loginBlocked')) {
                $loginBlocked = $request->boolean('loginBlocked');

                if ((bool) $teacher->getAttribute('login_blocked') !== $loginBlocked) {
                    $loginBlockChanged = true;
                }

                $teacher->setAttribute('login_blocked', $loginBlocked);
            }

            // "زر تفعيل نظام السنتر" — turning it off gates the `/center/*`
            // routes (App\Http\Middleware\EnsureCenterEnabled) and nothing
            // else: no row in center_groups/center_sessions/center_
            // attendances/center_payment_records is touched, read, or
            // deleted here. Same has()-before-boolean() shape as
            // isActive/loginBlocked above, for the same reason: `false`
            // ("turn it off") must never be read as "key absent".
            $centerEnabledChanged = false;

            if ($request->has('centerEnabled')) {
                $centerEnabled = $request->boolean('centerEnabled');

                if ((bool) $teacher->getAttribute('center_enabled') !== $centerEnabled) {
                    $centerEnabledChanged = true;
                }

                $teacher->setAttribute('center_enabled', $centerEnabled);
            }

            if ($teacher->isDirty()) {
                $teacher->save();
            }

            // ── teacher_social_links: [] DELETES ALL ─────────────────────
            // has(), not filled(): an EMPTY array is how the admin removes
            // every link, and filled() reports [] as empty — which would turn
            // "delete all my links" into a silent no-op. Omitting the key
            // entirely leaves the existing rows alone.
            if ($request->has('socialLinks')) {
                ($this->replaceSocialLinks)(
                    $teacher,
                    ($this->normaliseSocialLinks)($request->input('socialLinks'))
                );
            }

            if ($suspensionChanged) {
                SecurityLog::warning(
                    $user->is_active ? 'Teacher reactivated' : 'Teacher suspended',
                    [
                        'event' => 'teacher.is_active_changed',
                        'target_user_id' => $teacher->getKey(),
                        'is_active' => (int) $user->is_active,
                    ]
                );
            }

            // Logged like the suspension above — same warning level, same
            // "only when the value actually CHANGED" rule — but under its own
            // event name, so an auditor reading the log can tell a lockout
            // ('teacher.login_blocked_changed') from a suspension
            // ('teacher.is_active_changed'). They have different consequences
            // for the public site and must never look alike in the log.
            if ($loginBlockChanged) {
                SecurityLog::warning(
                    $teacher->getAttribute('login_blocked')
                        ? 'Teacher login blocked'
                        : 'Teacher login unblocked',
                    [
                        'event' => 'teacher.login_blocked_changed',
                        'target_user_id' => $teacher->getKey(),
                        'login_blocked' => (int) $teacher->getAttribute('login_blocked'),
                    ]
                );
            }

            if ($centerEnabledChanged) {
                SecurityLog::warning(
                    $teacher->getAttribute('center_enabled')
                        ? 'Teacher center system enabled'
                        : 'Teacher center system disabled',
                    [
                        'event' => 'teacher.center_enabled_changed',
                        'target_user_id' => $teacher->getKey(),
                        'center_enabled' => (int) $teacher->getAttribute('center_enabled'),
                    ]
                );
            }
        });

        if ($passwordChanged) {
            SecurityLog::warning('Teacher password reset by admin', [
                'event' => 'teacher.password_reset',
                'target_user_id' => $teacher->getKey(),
            ]);
        }

        return $teacher;
    }

    /**
     * "The caller mentioned this field, and did not mean null."
     *
     * has() is array_key_exists-equivalent, so '' / [] / false all pass — which
     * is required. The extra null test is what preserves backend/'s treatment
     * of an explicit null as "leave it alone"; see the boxed note above.
     */
    private function isPresentAndNotNull(Request $request, string $field): bool
    {
        return $request->has($field) && $request->input($field) !== null;
    }
}
