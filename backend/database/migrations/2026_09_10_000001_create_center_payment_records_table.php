<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "متابعة مدفوعات السنتر" (explicit teacher request) — a PURE tracking log,
 * paid/unpaid, one row per (teacher, student, group, payment_type, period).
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS TABLE CARRIES NO MONEY. NO amount/price/balance COLUMN EXISTS HERE, ║
 * ║ ON PURPOSE, AND NONE MAY EVER BE ADDED. It is read by nothing outside    ║
 * ║ App\Http\Controllers\CenterPaymentController — no revenue/earnings/      ║
 * ║ subscription/invoice/financial-dashboard query anywhere in the app may   ║
 * ║ ever join against `center_payment_records`. The spec's own words: "ولا   ║
 * ║ يجب أن يكون لهذه الصفحة أو بياناتها أي تأثير نهائيًا على أي عمليات        ║
 * ║ حسابية أو مالية في المنصة بالكامل."                                      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `period` is a plain string, not a date/foreign key: "2026-09" for a month
 * record, "2026-09-07" (the session's calendar date) for a session record —
 * deliberately NOT a `center_sessions.id` FK, so a teacher can log a payment
 * for a date whether or not an attendance session was ever opened that day.
 *
 * UNIQUE(teacher_id, student_id, center_group_id, payment_type, period): one
 * status per period per student per teacher's group — setting it again
 * UPDATES the same row (see App\Actions\Center\SetCenterPaymentStatus),
 * never duplicates. `center_group_id` encodes BOTH "which center" and
 * "which group" — this schema has no separate `centers` table (a center is
 * just `center_groups.center_name`, same as everywhere else in this system).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_payment_records', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('teacher_id', 36);
            $table->char('student_id', 36);
            $table->char('center_group_id', 36);
            $table->string('payment_type', 10);
            $table->string('period', 20);
            $table->string('status', 10)->default('unpaid');
            $table->char('recorded_by', 36);
            $table->timestamps();

            $table->unique(
                ['teacher_id', 'student_id', 'center_group_id', 'payment_type', 'period'],
                'uq_center_payment_records_scope'
            );
            $table->index(['teacher_id', 'center_group_id'], 'idx_center_payment_records_teacher_group');
            $table->index('student_id', 'idx_center_payment_records_student');

            $table->foreign('teacher_id', 'fk_center_payment_records_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();

            $table->foreign('student_id', 'fk_center_payment_records_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();

            $table->foreign('center_group_id', 'fk_center_payment_records_group')
                ->references('id')->on('center_groups')
                ->cascadeOnDelete();

            $table->foreign('recorded_by', 'fk_center_payment_records_actor')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_payment_records');
    }
};
