<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `subscriptions` from backend/database/migrations/0006, with the
 * UNIQUE from 0011 folded in.
 *
 * The status ENUM matches frontend/src/types/payment.ts literally
 * ('active'|'expired'|'cancelled').
 *
 * UNIQUE(student_id, course_id) — the double-charge guard
 * ------------------------------------------------------------------
 * The table originally had NO uniqueness guarantee on this pair, so the
 * enrollment path could silently produce duplicate rows for the same
 * student+course: a double-charging risk AND an ambiguous access check (which
 * of the two rows decides whether the student can watch?). The constraint is
 * the authority; the application's "already subscribed?" lookup is only a
 * nicety that produces a friendlier message.
 *
 * backend/'s 0011 also carried a one-off DELETE to collapse pre-existing
 * duplicates before adding the index. That statement is DELIBERATELY OMITTED
 * here: it was a data repair for a live table, and a freshly migrated schema
 * has nothing to repair. Replaying it would be a no-op at best and a
 * confusing piece of cargo cult at worst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('student_id', 36);
            $table->char('course_id', 36);
            $table->enum('status', SubscriptionStatus::values())
                ->default(SubscriptionStatus::Active->value);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['student_id', 'course_id'], 'uq_subscription_student_course');

            $table->foreign('student_id', 'fk_subscriptions_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();

            $table->foreign('course_id', 'fk_subscriptions_course')
                ->references('id')->on('courses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
