<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions to `questions`, both instructor-facing on the question-bank
 * add/edit form (frontend/src/components/Instructor/QuestionBank.tsx):
 *
 *   `title`          — a short label distinct from `body` (the full question
 *                       text/prompt). Nullable: existing rows and any future
 *                       caller that never sets one are untouched.
 *
 *   `choice_format`  — how the four MCQ options are labelled when rendered:
 *                       '1234' (numeric), 'abcd' (Latin letters) or 'arabic'
 *                       (أ ب ج د). Presentation-only — it never changes which
 *                       option is `correct_answer`, only how the option list
 *                       is prefixed. Defaults to '1234', the format already
 *                       in use everywhere before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->after('question_group_id');
            $table->enum('choice_format', ['1234', 'abcd', 'arabic'])->default('1234')->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['title', 'choice_format']);
        });
    }
};
