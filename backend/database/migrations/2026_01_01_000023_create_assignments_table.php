<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `assignments` from backend/database/migrations/0006, with the config
 * columns from 0009, the UNIQUE from 0012 and the CHECK from 0015 folded in.
 *
 * UNIQUE(content_item_id) is a strict 1:1 for the same reasons documented on
 * `exams` — a duplicate would make the grading configuration ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('content_item_id', 36);
            $table->decimal('max_score', 6, 2)->nullable();
            $table->integer('allowed_attempts')->nullable();
            $table->boolean('lock_content')->default(false);
            $table->boolean('show_answers')->default(true);
            $table->dateTime('due_at')->nullable();

            $table->unique('content_item_id', 'uq_assignments_content_item');

            $table->foreign('content_item_id', 'fk_assignments_content_item')
                ->references('id')->on('content_items')
                ->cascadeOnDelete();
        });

        DB::statement('
            ALTER TABLE assignments ADD CONSTRAINT chk_assignments_config CHECK (
                (max_score IS NULL OR max_score >= 0)
                AND (allowed_attempts IS NULL OR allowed_attempts >= 1)
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
