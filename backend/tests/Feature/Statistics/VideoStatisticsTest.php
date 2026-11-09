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

    public function test_report_includes_each_student_watch_session_and_total_open_count(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        $section = $this->makeSection($course);
        $item = $this->makeContentItemWithDetail($section, ContentItemType::Video, [
            'video_url' => 'https://example.com/v.mp4',
            'duration' => 1800,
        ]);
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $course);

        $this->loginAs($student);
        $this->postJson("/api/v1/lessons/{$item->getKey()}/watch-sessions", [
            'session_key' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'watched_seconds' => 125,
        ])->assertOk();
        $this->postJson("/api/v1/lessons/{$item->getKey()}/watch-sessions", [
            'session_key' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
            'watched_seconds' => 300,
        ])->assertOk();

        $this->loginAs($teacher);
        $data = $this->getJson("/api/v1/content-items/{$item->getKey()}/watch-statistics")
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['stats']['total_watch_sessions']);
        $this->assertSame(425, $data['stats']['total_session_watch_seconds']);
        $this->assertSame(1, $data['stats']['watched_count']);

        $row = collect($data['watched'])->firstWhere('student_id', (string) $student->getKey());
        $this->assertSame(2, $row['sessions_count']);
        $this->assertSame(425, $row['session_watched_seconds']);
        $this->assertCount(2, $row['sessions']);
    }


    public function test_repeated_heartbeats_for_one_session_are_idempotent_and_monotonic(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        $section = $this->makeSection($course);
        $item = $this->makeContentItemWithDetail($section, ContentItemType::Video, [
            'video_url' => 'https://example.com/v.mp4',
            'duration' => 600,
        ]);
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $course);

        $this->loginAs($student);
        $sessionKey = 'cccccccc-dddd-4eee-8fff-aaaaaaaaaaaa';
        foreach ([120, 60, 180] as $watchedSeconds) {
            $this->postJson("/api/v1/lessons/{$item->getKey()}/watch-sessions", [
                'session_key' => $sessionKey,
                'watched_seconds' => $watchedSeconds,
            ])->assertOk();
        }

        $this->assertDatabaseCount('video_watch_sessions', 1);
        $this->assertDatabaseHas('video_watch_sessions', [
            'student_id' => $student->getKey(),
            'content_item_id' => $item->getKey(),
            'session_key' => $sessionKey,
            'watched_seconds' => 180,
        ]);
    }

    public function test_an_instructor_preview_does_not_create_a_student_watch_session(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        $section = $this->makeSection($course);
        $item = $this->makeContentItemWithDetail($section, ContentItemType::Video, [
            'video_url' => 'https://example.com/v.mp4',
            'duration' => 600,
        ]);

        $this->loginAs($teacher);
        $this->postJson("/api/v1/lessons/{$item->getKey()}/watch-sessions", [
            'session_key' => 'dddddddd-eeee-4fff-8aaa-bbbbbbbbbbbb',
            'watched_seconds' => 45,
        ])->assertOk();

        $this->assertDatabaseCount('video_watch_sessions', 0);
    }

    public function test_watch_session_payload_is_validated_before_writing(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        $section = $this->makeSection($course);
        $item = $this->makeContentItemWithDetail($section, ContentItemType::Video, [
            'video_url' => 'https://example.com/v.mp4',
            'duration' => 600,
        ]);
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $course);

        $this->loginAs($student);
        $this->postJson("/api/v1/lessons/{$item->getKey()}/watch-sessions", [
            'session_key' => 'not-a-uuid',
            'watched_seconds' => -1,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('video_watch_sessions', 0);
    }

}
