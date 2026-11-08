<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per student/video/browser-player session. The client sends a
 * monotonically increasing watched_seconds total, so retries are idempotent
 * and cannot inflate the recorded duration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_watch_sessions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('student_id', 36);
            $table->char('content_item_id', 36);
            $table->char('session_key', 36);
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->dateTime('started_at')->useCurrent();
            $table->dateTime('last_activity_at')->useCurrent();

            $table->unique(
                ['student_id', 'content_item_id', 'session_key'],
                'uq_video_watch_sessions_identity'
            );
            $table->index(['content_item_id', 'student_id'], 'idx_video_watch_sessions_video_student');

            $table->foreign('student_id', 'fk_video_watch_sessions_student')
                ->references('user_id')->on('students')
                ->cascadeOnDelete();
            $table->foreign('content_item_id', 'fk_video_watch_sessions_content_item')
                ->references('id')->on('content_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_watch_sessions');
    }
};
