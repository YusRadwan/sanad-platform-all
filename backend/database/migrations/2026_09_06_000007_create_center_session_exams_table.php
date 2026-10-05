<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * center_session_exams — which of the teacher's EXISTING exams (the
 * platform's own `exams` table, no new exam system) are attached to a given
 * center session. Scores are read live from `exam_attempts`; nothing is
 * duplicated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_session_exams', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('center_session_id', 36);
            $table->char('exam_id', 36);
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['center_session_id', 'exam_id'], 'uq_center_session_exams');

            $table->foreign('center_session_id', 'fk_center_session_exams_session')
                ->references('id')->on('center_sessions')
                ->cascadeOnDelete();

            $table->foreign('exam_id', 'fk_center_session_exams_exam')
                ->references('id')->on('exams')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_session_exams');
    }
};
