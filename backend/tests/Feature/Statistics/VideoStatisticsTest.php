<?php

declare(strict_types=1);

namespace Tests\Feature\Statistics;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * "إحصائيات المشاهدات" (decision 10.36) — GET /content-items/{id}/watch-statistics.
 * Instructor-only, OWNER-only twin of ExamStatisticsTest.
 */
final class VideoStatisticsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private function recordProgress(ContentItem $item, User $student, int $seconds, bool $completed): void
    {
        $progress = new LessonProgress();
        $progress->forceFill([
            'id' => (string) Str::uuid7(),
            'student_id' => $student->getKey(),
            'content_item_id' => $item->getKey(),
            'progress_seconds' => $seconds,
            'completed' => $completed,
            'last_watched_at' => now(),
        ]);
        $progress->save();
    }

    public function test_the_owning_instructor_sees_a_correct_report(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        $section = $this->makeSection($course);
        $item = $this->makeContentItemWithDetail($section, ContentItemType::Video, ['video_url' => 'https://example.com/v.mp4', 'duration' => 200]);

        $full = $this->makeStudentUser();
        $half = $this->makeStudentUser();
        $absent = $this->makeStudentUser();

        $this->makeSubscription($full, $course);
        $this->makeSubscription($half, $course);
        $this->makeSubscription($absent, $course);

        $this->recordProgress($item, $full, 200, true);
        $this->recordProgress($item, $half, 100, false);

        $this->loginAs($teacher);
        $data = $this->getJson("/api/v1/content-items/{$item->getKey()}/watch-statistics")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(3, $data['stats']['total_students']);
        $this->assertSame(2, $data['stats']['watched_count']);
        $this->assertSame(1, $data['stats']['not_watched_count']);
        $this->assertSame(1, $data['stats']['completed_count']);
        $this->assertEqualsWithDelta(75.0, $data['stats']['average_watch_percent'], 0.1); // (100+50)/2
        $this->assertEqualsWithDelta(50.0, $data['stats']['completion_rate_percent'], 0.1); // 1 of 2 completed

        $halfRow = collect($data['watched'])->firstWhere('student_id', (string) $half->getKey());
        $this->assertEqualsWithDelta(50.0, $halfRow['watch_percent'], 0.1);
        $this->assertFalse($halfRow['completed']);

        $this->assertCount(1, $data['not_watched']);
        $this->assertSame((string) $absent->getKey(), $data['not_watched'][0]['student_id']);
    }

    public function test_a_non_video_content_item_is_404(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        $section = $this->makeSection($course);
        $note = $this->makeContentItemWithDetail($section, ContentItemType::Note, ['body' => 'نص']);

        $this->loginAs($teacher);
        $this->getJson("/api/v1/content-items/{$note->getKey()}/watch-statistics")->assertStatus(404);
    }

    public function test_a_foreign_teacher_cannot_view_another_teachers_video_statistics(): void
    {
        $this->disableRateLimiting();
        $owner = $this->makeInstructor();
        $stranger = $this->makeInstructor();
        $course = $this->makeCourseFor($owner);
        $section = $this->makeSection($course);
        $item = $this->makeContentItemWithDetail($section, ContentItemType::Video, ['video_url' => 'https://example.com/v.mp4', 'duration' => 100]);

        $this->loginAs($stranger);
        $this->getJson("/api/v1/content-items/{$item->getKey()}/watch-statistics")->assertStatus(403);
    }
}
