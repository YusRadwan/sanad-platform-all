<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `payouts` from backend/database/migrations/0006, with the amount
 * CHECK from 0015 folded in.
 *
 * Money-out: what the platform has actually paid a teacher.
 *
 * `created_by` is ON DELETE SET NULL, not CASCADE: removing the admin who
 * recorded a payout must never erase the payout itself. The financial record
 * survives the actor.
 *
 * CHECK amount >= 0 — the outstanding balance is SUM(teacher_earnings) -
 * SUM(payouts.amount), so a negative payout row would INCREASE what the
 * platform still owes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('teacher_id', 36);
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 50);
            $table->text('notes')->nullable();
            $table->char('created_by', 36)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->foreign('teacher_id', 'fk_payouts_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();

            $table->foreign('created_by', 'fk_payouts_created_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE payouts ADD CONSTRAINT chk_payouts_amount_non_negative CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
