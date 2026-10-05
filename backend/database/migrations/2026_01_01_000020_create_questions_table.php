<?php

declare(strict_types=1);

use App\Enums\QuestionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `questions` from backend/database/migrations/0006, with `image_url`
 * from 0010 folded in.
 *
 * `options` is a JSON array of choice strings, meaningful only for
 * type='mcq'. `correct_answer` is a plain VARCHAR for all three types (the
 * chosen option's text for mcq, 'true'/'false' for true_false, the expected
 * text for short_answer) — this is a wire contract with the exam runner and is
 * preserved as-is rather than being normalised into a per-type shape.
 *
 * `image_url` supports image-based questions, uploaded through the shared
 * upload endpoint and stored here as the returned URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('question_group_id', 36);
            $table->text('body');
            $table->enum('type', QuestionType::values())->default(QuestionType::Mcq->value);
            $table->json('options')->nullable();
            $table->string('image_url', 255)->nullable();
            $table->string('correct_answer', 255)->nullable();
            $table->decimal('points', 6, 2)->default(1);

            $table->foreign('question_group_id', 'fk_questions_group')
                ->references('id')->on('question_groups')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
