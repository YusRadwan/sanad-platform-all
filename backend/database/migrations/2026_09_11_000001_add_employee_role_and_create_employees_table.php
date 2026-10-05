<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "إدارة الأدمنز" (decision 10.37) — teacher-scoped Employee/Amin sub-accounts.
 *
 * ┌── WHY A RAW `ALTER ... MODIFY` FOR THE `role` COLUMN. ────────────────────┐
 * │ `users.role` was created as a real MySQL ENUM (`create_users_table`),     │
 * │ generated from `UserRole::values()` at that migration's time. Widening    │
 * │ an ENUM's allowed set needs an `ALTER TABLE ... MODIFY COLUMN`; Laravel's  │
 * │ `$table->enum(...)->change()` requires doctrine/dbal, which this project  │
 * │ does not depend on (verified: absent from composer.json) — a raw          │
 * │ statement, fed from the SAME `UserRole::values()` the original migration  │
 * │ used, keeps the single-source-of-truth property without adding a new      │
 * │ dependency for one column.                                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `employees` is a thin link table, NOT a profile table like `teachers`/
 * `students`: the employee's own display name/phone/email/password all live
 * on their OWN `users` row (created alongside this one, same as
 * `App\Actions\Teacher\CreateTeacher` creates `User`+`Teacher` together) —
 * this table only records the TWO facts specific to the sub-account concept:
 * who owns it (`teacher_id`) and what it may do (`account_type`/`permissions`).
 *
 * `permissions` is a JSON array of permission-key strings (same column shape
 * as `teachers.grades`/`teachers.subjects`) — `null` for `account_type =
 * 'amin'` (an Amin's access is "everything the teacher can do", not an
 * enumerable list; App\Support\EmployeePermissions never reads this column
 * for an Amin row). The catalog of valid keys lives in PHP
 * (App\Support\EmployeePermissions::CATALOG), not in this schema, precisely
 * so a future page can gain a permission key without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE users MODIFY COLUMN role ENUM(' .
            implode(',', array_map(static fn (string $v): string => "'{$v}'", UserRole::values())) .
            ') NOT NULL'
        );

        Schema::create('employees', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->char('teacher_id', 36);
            $table->enum('account_type', ['amin', 'employee']);
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable()->useCurrentOnUpdate();

            $table->unique('user_id', 'uq_employees_user_id');
            $table->index('teacher_id', 'idx_employees_teacher_id');

            $table->foreign('user_id', 'fk_employees_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreign('teacher_id', 'fk_employees_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');

        DB::statement(
            "ALTER TABLE users MODIFY COLUMN role ENUM('student','instructor','admin') NOT NULL"
        );
    }
};
