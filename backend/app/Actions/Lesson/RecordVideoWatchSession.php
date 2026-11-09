<?php

declare(strict_types=1);

namespace App\Actions\Lesson;

use App\Enums\UserRole;
use App\Models\ContentItem;
use App\Models\User;
use App\Models\VideoWatchSession;

final class RecordVideoWatchSession
{
    /**
     * Non-students (including instructors previewing their own video) are a
     * silent no-op, matching SaveLessonProgress and avoiding FK violations.
     */
    public function __invoke(User $user, ContentItem $lesson, array $data): void
    {
        if ($user->role !== UserRole::Student) {
            return;
        }

        $seconds = max(0, min(86400, (int) ($data['watched_seconds'] ?? 0)));

        VideoWatchSession::recordFor(
            (string) $user->getKey(),
            (string) $lesson->getKey(),
            (string) $data['session_key'],
            $seconds,
        );
    }
}
