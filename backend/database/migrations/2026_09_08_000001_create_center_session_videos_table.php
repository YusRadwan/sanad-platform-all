<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * center_session_videos — which of the teacher's EXISTING course videos are
 * attached to a given center session, for per-student watch-percentage
 * tracking (computed live from `lesson_progress`, never duplicated here) —
 * the video sibling of `center_session_exams` (migration 2026_09_06_000007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_session_videos', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('center_session_id', 36);
            $table->char('video_content_item_id', 36);
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['center_session_id', 'video_content_item_id'], 'uq_center_session_videos');

            $table->foreign('center_session_id', 'fk_center_session_videos_session')
                ->references('id')->on('center_sessions')
                ->cascadeOnDelete();

            $table->foreign('video_content_item_id', 'fk_center_session_videos_video')
                ->references('content_item_id')->on('videos')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_session_videos');
    }
};
