<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `exams` from backend/database/migrations/0006, with the config
 * columns from 0009, the UNIQUE from 0012 and the CHECK from 0015 folded in.
 *
 * Exams and assignments get SEPARATE tables rather than one shared table,
 * because frontend/src/types/course.ts treats them as two different content
 * kinds and their runtime behaviour genuinely diverges (attempts + auto
 * grading vs. submissions + manual grading).
 *
 * UNIQUE(content_item_id) — a STRICT 1:1, not a convenience index
 * ------------------------------------------------------------------
 * Every read path treats "the exam for this content item" as singular:
 * lookups SELECT ... LIMIT 1, the save path decides INSERT-vs-UPDATE by
 * whether a row already exists, and batch loaders key their result maps BY
 * content_item_id so a duplicate would silently shadow a row. A second row for
 * the same content item is therefore always a bug — it would make the exam
 * configuration a student is graded against ambiguous — never a legitimate
 * state.
 *
 * CHECK bounds (0015)
 * allowed_attempts NULL means "unlimited" BY DESIGN (the authoring form omits
 * it), but 0 or a negative cap is meaningless. The old service layer treated
 * <= 0 as unlimited, which is a silently wrong reading of an operator typo;
 * the constraint forbids it at the source instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('content_item_id', 36);
            $table->char('question_group_id', 36)->nullable();
            $table->integer('duration_minutes')->default(30);
            $table->decimal('pass_score', 6, 2)->nullable();
            $table->decimal('total_score', 6, 2)->nullable();
            $table->integer('allowed_attempts')->nullable();
            $table->boolean('randomize_questions')->default(false);
            $table->boolean('lock_content')->default(false);
            $table->boolean('show_answers')->default(true);

            $table->unique('content_item_id', 'uq_exams_content_item');

            $table->foreign('content_item_id', 'fk_exams_content_item')
                ->references('id')->on('content_items')
                ->cascadeOnDelete();

            $table->foreign('question_group_id', 'fk_exams_question_group')
                ->references('id')->on('question_groups')
                ->nullOnDelete();
        });

        DB::statement('
            ALTER TABLE exams ADD CONSTRAINT chk_exams_scores CHECK (
                (pass_score IS NULL OR (pass_score >= 0 AND pass_score <= 100))
                AND (total_score IS NULL OR total_score >= 0)
                AND (allowed_attempts IS NULL OR allowed_attempts >= 1)
                AND duration_minutes > 0
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
