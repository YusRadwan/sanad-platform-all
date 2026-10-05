<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `teacher_profit_settings` from backend/database/migrations/0006,
 * with the percentage CHECK from 0015 folded in.
 *
 * The CURRENT profit split for a teacher, keyed 1:1 on the teacher. Default
 * 70% platform-configured. Historical splits are NOT read from here — each
 * revenue_transactions row snapshots the percentage that applied at the time
 * of sale (see that migration).
 *
 * The CHECK is the defence-in-depth copy of a service-layer rule, for anything
 * that writes the column directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_profit_settings', function (Blueprint $table) {
            $table->char('teacher_id', 36)->primary();
            $table->decimal('profit_percentage', 5, 2)->default(70);

            $table->foreign('teacher_id', 'fk_profit_settings_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE teacher_profit_settings ADD CONSTRAINT chk_profit_settings_percentage CHECK (profit_percentage >= 0 AND profit_percentage <= 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_profit_settings');
    }
};
