<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `revenue_transactions` from backend/database/migrations/0006, with
 * the two CHECK constraints from 0015 folded in.
 *
 * One row per money-in event. `subscription_id` is nullable ON DELETE SET NULL
 * so that cancelling/removing a subscription never destroys the financial
 * record of the payment that created it — the transaction outlives the access
 * grant, which is what an auditor needs.
 *
 * `status` is a plain VARCHAR(30), not an ENUM, unlike most status columns in
 * this schema. That is preserved deliberately: payment-processor statuses are
 * an open vocabulary that grows with each integration, and an ENUM would turn
 * every new gateway state into a migration.
 *
 * teacher_profit_percentage / teacher_earnings are DENORMALISED SNAPSHOTS of
 * the profit split at the moment of sale. They must not be recomputed from
 * teacher_profit_settings at read time — that setting changes over time, and
 * historical statements have to keep showing what was actually owed then.
 *
 * CHECK constraints: the teacher's outstanding balance is computed as
 * SUM(teacher_earnings) - SUM(payouts.amount), so a negative earnings row
 * would INCREASE what the platform still owes — a direct financial-integrity
 * issue, not a cosmetic validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_transactions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('subscription_id', 36)->nullable();
            $table->char('student_id', 36);
            $table->char('course_id', 36);
            $table->char('teacher_id', 36);
            $table->decimal('gross_amount', 10, 2);
            $table->string('payment_method', 50);
            $table->string('status', 30)->default('paid');
            $table->decimal('teacher_profit_percentage', 5, 2)->nullable();
            $table->decimal('teacher_earnings', 10, 2)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->foreign('subscription_id', 'fk_revenue_tx_subscription')
                ->references('id')->on('subscriptions')
                ->nullOnDelete();

            $table->foreign('student_id', 'fk_revenue_tx_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();

            $table->foreign('course_id', 'fk_revenue_tx_course')
                ->references('id')->on('courses')
                ->cascadeOnDelete();

            $table->foreign('teacher_id', 'fk_revenue_tx_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE revenue_transactions ADD CONSTRAINT chk_revenue_tx_amounts CHECK (gross_amount >= 0 AND (teacher_earnings IS NULL OR teacher_earnings >= 0))');
        DB::statement('ALTER TABLE revenue_transactions ADD CONSTRAINT chk_revenue_tx_percentage CHECK (teacher_profit_percentage IS NULL OR (teacher_profit_percentage >= 0 AND teacher_profit_percentage <= 100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_transactions');
    }
};
