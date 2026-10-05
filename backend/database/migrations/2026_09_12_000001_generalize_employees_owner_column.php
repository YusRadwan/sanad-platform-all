<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "إدارة الأدمنز" (decision 10.40) — generalizes `employees.teacher_id`
 * (FK -> `teachers.user_id`, decision 10.37) into `employees.owner_id`
 * (FK -> `users.id`).
 *
 * ┌── WHY THIS WAS NEEDED: A LATER, EXPLICIT REQUEST ADDED A SECOND OWNER   ─┐
 * │ KIND. Decision 10.37 built "إدارة الأدمنز" for TEACHER-owned employees   │
 * │ only, with the FK deliberately scoped to `teachers.user_id` (an Employee │
 * │ could only ever belong to a teacher). A follow-up request clarified that │
 * │ the ADMIN section's OWN "إدارة الأدمنز" page is a genuinely SEPARATE      │
 * │ concept: the Super Admin creates employees FOR THEMSELVES, isolated per  │
 * │ admin — not employees on behalf of a teacher. The identity-swap login    │
 * │ mechanism (App\Actions\Auth\LoginUser) already generalizes to this for   │
 * │ free — `Auth::guard('web')->login($employee->owner)` works identically   │
 * │ whether `owner` is an Instructor or an Admin row — so the ONLY thing     │
 * │ that had to change is this column's FK target and name (keeping it       │
 * │ called `teacher_id` once it could hold an admin's id would be actively   │
 * │ misleading, which this codebase treats as a real defect, not a style     │
 * │ nit).                                                                    │
 * └────────────────────────────────────────────────────────────────────────-┘
 *
 * `owner_id` is left WITHOUT a role constraint at the schema level — nothing
 * stops it from referencing a `Student` row either. That is deliberately not
 * prevented here: the only two writers of this column
 * (`App\Actions\Employee\CreateEmployee`, called from `App\Http\Controllers\
 * {Employee,Admin}Controller` — no admin-controller variant exists; the
 * SAME `/employees` routes now serve both an instructor and an admin
 * session, see routes/api.php) never pass anything but `$request->user()`,
 * whose role is already gated to `instructor`/`admin` at the route
 * (`role:admin,instructor`). Enforcing it again in the schema would be a
 * second copy of a rule that already lives in exactly one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign('fk_employees_teacher');
        });

        DB::statement('ALTER TABLE employees CHANGE teacher_id owner_id CHAR(36) NOT NULL');

        Schema::table('employees', function (Blueprint $table): void {
            $table->renameIndex('idx_employees_teacher_id', 'idx_employees_owner_id');
            $table->foreign('owner_id', 'fk_employees_owner')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign('fk_employees_owner');
        });

        DB::statement('ALTER TABLE employees CHANGE owner_id teacher_id CHAR(36) NOT NULL');

        Schema::table('employees', function (Blueprint $table): void {
            $table->renameIndex('idx_employees_owner_id', 'idx_employees_teacher_id');
            $table->foreign('teacher_id', 'fk_employees_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();
        });
    }
};
