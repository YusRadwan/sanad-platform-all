<?php

declare(strict_types=1);

use App\Enums\RefundStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `refunds` from backend/database/migrations/0006, with the status
 * ENUM from 0007 and the amount CHECK from 0015 folded in.
 *
 * A refund is always against a specific revenue transaction — there is no
 * free-floating refund — hence the NOT NULL FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('revenue_transaction_id', 36);
            $table->decimal('amount', 10, 2);
            $table->text('reason')->nullable();
            $table->enum('status', RefundStatus::values())
                ->default(RefundStatus::Pending->value);
            $table->dateTime('created_at')->useCurrent();

            $table->foreign('revenue_transaction_id', 'fk_refunds_transaction')
                ->references('id')->on('revenue_transactions')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT chk_refunds_amount_non_negative CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
