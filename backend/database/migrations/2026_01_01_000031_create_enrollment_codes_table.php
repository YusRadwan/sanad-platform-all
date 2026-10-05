<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of backend/database/migrations/0013_create_enrollment_codes_table.sql.
 *
 * An admin generates an 8-digit numeric code bound to ONE specific course. A
 * student redeems it, which creates the real subscription (+ revenue
 * transaction) atomically.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ `uq_enrollment_codes_code` IS A LOAD-BEARING LITERAL STRING.         │
 * │                                                                      │
 * │ backend/app/Repositories/EnrollmentCodeRepository.php:62 does:       │
 * │                                                                      │
 * │   if ($e->getCode() !== '23000'                                      │
 * │       || !str_contains($e->getMessage(), 'uq_enrollment_codes_code'))│
 * │                                                                      │
 * │ i.e. it inspects the DRIVER'S ERROR MESSAGE for this exact index     │
 * │ name to distinguish "code collision, retry the generator" from any   │
 * │ other integrity violation (which must bubble up). Renaming this      │
 * │ index to a Laravel-conventional                                      │
 * │ `enrollment_codes_code_unique` would not fail any migration or any   │
 * │ schema diff — it would silently turn a recoverable collision into    │
 * │ an unhandled 500 under concurrency, i.e. exactly the kind of bug     │
 * │ that only shows up in production.                                    │
 * │                                                                      │
 * │ This is the ONE index name in the whole schema that application      │
 * │ code parses. Verified by grepping backend/app/ for constraint-name   │
 * │ string matching: it is the only `str_contains($e->getMessage(), …)`  │
 * │ site. (All other constraint names appear only inside comments.)      │
 * │                                                                      │
 * │ Stage 1+ note: a Laravel port should NOT keep parsing driver         │
 * │ messages — see the report's "قرارات تحتاج قرار المستخدم" section.    │
 * │ Until that decision is made, the name is preserved verbatim.         │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * Business rules encoded in this table:
 *   * `code` is UNIQUE -> no duplicate code can ever exist platform-wide. The
 *     generator's retry loop is a convenience; THIS CONSTRAINT is the
 *     authority.
 *   * `course_id` is the ONLY source of truth for what the code unlocks. The
 *     redemption endpoint deliberately does not accept a course id from the
 *     request, so a code can never be pointed at a different course.
 *   * is_used / used_by_student_id / used_at record single use. The redemption
 *     UPDATE is guarded by `is_used = 0`, so two concurrent redemptions of the
 *     same code cannot both succeed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_codes', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('code', 8);
            $table->char('course_id', 36);
            $table->char('created_by', 36)->nullable();
            $table->boolean('is_used')->default(false);
            $table->char('used_by_student_id', 36)->nullable();
            $table->dateTime('used_at')->nullable();
            $table->dateTime('created_at')->useCurrent();

            // DO NOT RENAME — see the class docblock.
            $table->unique('code', 'uq_enrollment_codes_code');

            $table->index('course_id', 'idx_enrollment_codes_course');
            $table->index('is_used', 'idx_enrollment_codes_used');

            $table->foreign('course_id', 'fk_enrollment_codes_course')
                ->references('id')->on('courses')
                ->cascadeOnDelete();

            $table->foreign('created_by', 'fk_enrollment_codes_creator')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->foreign('used_by_student_id', 'fk_enrollment_codes_student')
                ->references('user_id')->on('students')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_codes');
    }
};
