<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `teachers.login_blocked` — a SECOND, INDEPENDENT admin switch that stops one
 * instructor from SIGNING IN while leaving every trace of them on the public
 * site exactly as it was.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THIS IS NOT A RENAME, A TWIN OR A REPLACEMENT OF `users.is_active`.      │
 * │ THE TWO FLAGS ARE ORTHOGONAL AND BOTH ARE LOAD-BEARING.                  │
 * │                                                                          │
 * │ users.is_active = false does THREE things at once:                       │
 * │   · the teacher disappears from the public directory                     │
 * │     (Teacher::scopePubliclyVisible filters `users.is_active`),           │
 * │   · their published courses stop being publicly visible,                 │
 * │   · they cannot log in (App\Actions\Auth\LoginUser + EnsureUserIsActive).│
 * │                                                                          │
 * │ teachers.login_blocked = true does EXACTLY ONE of those — the third —    │
 * │ and NOTHING else. The admin asked for a way to lock an instructor out of │
 * │ their own account (a dispute, a shared password, a handover) WITHOUT     │
 * │ taking their profile and their students' courses off the site, which is  │
 * │ what "إيقاف" already does and is why "إيقاف" is the wrong tool for it.   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── WHY ON `teachers` AND NOT ON `users` ──────────────────────────────────┐
 * │ The only surface that sets or clears this flag is the teacher-management │
 * │ page (Admin/ManageTeachers.tsx → PUT /admin/teachers/{id}), and the only │
 * │ accounts it is meaningful for are instructor accounts. Putting it on     │
 * │ `users` would advertise a general per-account capability that no student │
 * │ or admin screen can reach, and would invite a future EnsureUserIsActive- │
 * │ style middleware to start reading it for every request — precisely the   │
 * │ scope creep this column must not have. Living on the instructor profile  │
 * │ keeps its blast radius equal to its UI.                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── THE ONE RULE THAT MAY NEVER BE BROKEN ─────────────────────────────────┐
 * │ THIS COLUMN IS READ IN EXACTLY ONE PLACE: App\Actions\Auth\LoginUser.    │
 * │ It must NEVER be consulted by any visibility, directory, catalog,        │
 * │ listing, course-publication or authorization decision anywhere in this   │
 * │ application, and it must NEVER be serialised by a public resource        │
 * │ (App\Http\Resources\PublicTeacher deliberately does not carry it — only  │
 * │ the admin-facing TeacherResource does). The moment a second reader       │
 * │ appears, this flag stops being "blocks login only" and silently becomes  │
 * │ a second `is_active` with none of its documentation.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NOT nullable, DEFAULT false. Unlike the nullable JSON columns beside it,
 * a three-valued "blocked / not blocked / unknown" has no meaning here: every
 * existing row must come out of this migration explicitly ABLE to log in, and
 * `null` reaching the login check would have to be coerced somewhere, which is
 * one more place to get the polarity backwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->boolean('login_blocked')
                ->nullable(false)
                ->default(false)
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('login_blocked');
        });
    }
};
