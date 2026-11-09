<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VideoWatchSession extends BaseModel
{
    protected $table = 'video_watch_sessions';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'watched_seconds' => 'integer',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id', 'id');
    }

    /**
     * Store the cumulative client-side playback time. GREATEST makes retries
     * and out-of-order heartbeats safe: a session can never lose or double its
     * already-recorded seconds.
     */
    public static function recordFor(
        string $studentId,
        string $contentItemId,
        string $sessionKey,
        int $watchedSeconds,
    ): void {
        DB::statement(
            'INSERT INTO video_watch_sessions
                (id, student_id, content_item_id, session_key, watched_seconds, started_at, last_activity_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                last_activity_at = IF(VALUES(watched_seconds) > watched_seconds, NOW(), last_activity_at),
                watched_seconds = GREATEST(watched_seconds, VALUES(watched_seconds))',
            [(string) Str::uuid7(), $studentId, $contentItemId, $sessionKey, $watchedSeconds]
        );
    }
}
