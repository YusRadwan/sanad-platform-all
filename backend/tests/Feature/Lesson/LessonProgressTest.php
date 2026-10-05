<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\LessonProgress;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET|POST /api/v1/lessons/{id}/progress — freeze-table rows 28 and 29.
 *
 * Four behaviours are pinned here, and three of them are the kind that pass a
 * casual response-shape test while being wrong:
 *
 *   1. THE NON-STUDENT NO-OP — 200, and literally no row written. Verified by
 *      querying `lesson_progress` directly, because the response alone cannot
 *      distinguish a no-op from a successful write.
 *   2. CLAMPING, not rejection. 150% becomes 100, -5s becomes 0, no 422 exists.
 *   3. STICKY COMPLETION and MONOTONIC position. 95% then 10% leaves the lesson
 *      completed and does not rewind the saved second.
 *   4. THE `last_position_seconds` / `progress_seconds` NAME MISMATCH, which is
 *      the frontend contract and not a bug.
 */
final class LessonProgressTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private User $student;

    private Course $course;

    private Section $section;

    private ContentItem $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        $this->admin = $this->makeAdmin();
        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course);

        $this->lesson = $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Video,
            ['video_url' => 'https://example.test/lesson.m3u8', 'duration' => 1000],
        );

        $this->student = $this->makeStudentUser();
        $this->makeSubscription($this->student, $this->course);
    }

    // ══ 1. THE NON-STUDENT NO-OP — ESCAPE HATCH #3 ════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A NON-STUDENT **READ** GETS A ZEROED RECORD, NOT AN ERROR.           ║
     * ║                                                                      ║
     * ║ `lesson_progress.student_id` is an FK into `students.user_id`, so an  ║
     * ║ admin and an instructor have no row and never can. VideoPlayerPage   ║
     * ║ loads video + progress TOGETHER, so an error here would blank the    ║
     * ║ whole player for an instructor reviewing their own lesson — a        ║
     * ║ legitimate and common thing to do.                                   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_non_student_read_returns_a_zeroed_record_and_not_an_error(): void
    {
        foreach ([$this->owner, $this->admin] as $actor) {
            $this->freshLogin($actor);

            $this->getJson("/api/v1/lessons/{$this->lesson->getKey()}/progress")
                ->assertOk()
                ->assertJsonPath('data.progress.last_position_seconds', 0)
                ->assertJsonPath('data.progress.completed', false)
                ->assertJsonPath('data.progress.last_watched_at', null);
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE THIRD DOCUMENTED `Gate::before` ESCAPE HATCH, ASSERTED AT THE    ║
     * ║ ONLY LAYER THAT CAN PROVE IT: THE DATABASE.                          ║
     * ║                                                                      ║
     * ║ An admin passes `watch` via Gate::before, and the OWNING INSTRUCTOR  ║
     * ║ passes it on the merits. Both therefore reach the write path fully   ║
     * ║ authorised — and an INSERT for either dies on the FK, i.e. a raw 500 ║
     * ║ where the contract owes 200. The refusal lives in                    ║
     * ║ App\Actions\Lesson\SaveLessonProgress, which is the only layer the   ║
     * ║ Gate bypass does not cover.                                          ║
     * ║                                                                      ║
     * ║ ASSERTING THE 200 IS NOT ENOUGH. A successful write and a no-op look ║
     * ║ identical from the response, so this test counts the rows and reads  ║
     * ║ the table directly. That is the whole point of the file.             ║
     * ║                                                                      ║
     * ║ Same class of issue as open decision 3.1 (ExamPolicy::submit,        ║
     * ║ AssignmentPolicy::submit); DIFFERENT remedy — those owe a 403 with a ║
     * ║ literal message, this owes a silent success. See the Action.         ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_non_student_post_writes_absolutely_nothing(): void
    {
        $before = DB::table('lesson_progress')->count();

        foreach ([$this->owner, $this->admin] as $actor) {
            $this->freshLogin($actor);

            $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [
                'lastPosition' => 950,
                'watchedPercentage' => 95,
            ])
                ->assertOk()
                ->assertJsonPath('message', 'تم حفظ التقدم')
                ->assertJsonPath('data.progress.last_position_seconds', 0)
                ->assertJsonPath('data.progress.completed', false);

            $this->assertSame(
                0,
                DB::table('lesson_progress')->where('student_id', $actor->getKey())->count(),
                "A row was written for a {$actor->role->value}. The FK would have made this a "
                . '500 in production; the Action must no-op instead.'
            );
        }

        $this->assertSame(
            $before,
            DB::table('lesson_progress')->count(),
            'The lesson_progress table changed size during a non-student write.'
        );
    }

    /** …and the no-op does not disturb an existing STUDENT row for the lesson. */
    public function test_a_non_student_post_does_not_disturb_a_students_existing_row(): void
    {
        $this->freshLogin($this->student);
        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [
            'lastPosition' => 400,
            'watchedPercentage' => 40,
        ])->assertOk();

        $before = LessonProgress::findFor((string) $this->student->getKey(), (string) $this->lesson->getKey());
        $this->assertNotNull($before);

        $this->freshLogin($this->admin);
        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [
            'lastPosition' => 0,
            'watchedPercentage' => 0,
        ])->assertOk();

        $after = LessonProgress::findFor((string) $this->student->getKey(), (string) $this->lesson->getKey());

        $this->assertSame(400, $after?->progress_seconds);
        $this->assertEquals($before->last_watched_at, $after?->last_watched_at);
    }

    // ══ 2. CLAMPING, NOT REJECTION ════════════════════════════════════════

    /**
     * ┌── 150 BECOMES 100. IT IS **NOT** A 422. ─────────────────────────────┐
     * │ The numbers come from client-side `<video>` arithmetic over a        │
     * │ duration the browser may still be probing, so absurd values arrive   │
     * │ from honest clients. Rejecting them would drop real progress for     │
     * │ users whose only mistake is a slow network — through a               │
     * │ fire-and-forget request whose failure nobody sees.                   │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_an_over_100_percentage_is_clamped_to_100_and_completes_the_lesson(): void
    {
        $this->freshLogin($this->student);

        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [
            'lastPosition' => 999,
            'watchedPercentage' => 150,
        ])
            ->assertOk()
            ->assertJsonPath('data.progress.completed', true)
            ->assertJsonPath('data.progress.last_position_seconds', 999);
    }

    public function test_negative_values_are_clamped_to_zero_and_do_not_422(): void
    {
        $this->freshLogin($this->student);

        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [
            'lastPosition' => -5,
            'watchedPercentage' => -20,
        ])
            ->assertOk()
            ->assertJsonPath('data.progress.last_position_seconds', 0)
            ->assertJsonPath('data.progress.completed', false);

        $this->assertSame(
            0,
            LessonProgress::findFor((string) $this->student->getKey(), (string) $this->lesson->getKey())
                ?->progress_seconds
        );
    }

    /** The 90% completion threshold, asserted on both sides of the line. */
    public function test_the_completion_threshold_is_ninety_percent(): void
    {
        $below = $this->makeContentItemWithDetail($this->section, ContentItemType::Video, ['duration' => 100]);

        $this->freshLogin($this->student);
        $this->postJson("/api/v1/lessons/{$below->getKey()}/progress", ['watchedPercentage' => 89.99])
            ->assertOk()
            ->assertJsonPath('data.progress.completed', false);

        $this->freshLogin($this->student);
        $this->postJson("/api/v1/lessons/{$below->getKey()}/progress", ['watchedPercentage' => 90])
            ->assertOk()
            ->assertJsonPath('data.progress.completed', true);
    }

    /**
     * `''` ON A NUMERIC FIELD BEHAVES EXACTLY AS 0 — which is why
     * `api/v1/lessons/*` is deliberately NOT on
     * AppServiceProvider::preserveEmptyStringsOnClearableWrites().
     *
     * Checked against backend/ rather than assumed (decision 4.1's standing
     * obligation): there, '' arrived intact and `(int) round((float) '')` is 0;
     * here the global middleware turns it into null and `?? 0` is 0. Identical
     * on the wire, so no exemption is owed and adding one would be noise.
     */
    public function test_an_empty_string_progress_payload_behaves_exactly_as_a_zero(): void
    {
        $this->freshLogin($this->student);

        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [
            'lastPosition' => '',
            'watchedPercentage' => '',
        ])
            ->assertOk()
            ->assertJsonPath('data.progress.last_position_seconds', 0)
            ->assertJsonPath('data.progress.completed', false);
    }

    /** A body with no fields at all is a legal zero save, not a 422. */
    public function test_an_empty_body_is_a_zero_save_and_not_a_422(): void
    {
        $this->freshLogin($this->student);

        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [])
            ->assertOk()
            ->assertJsonPath('data.progress.last_position_seconds', 0);
    }

    /** Both spellings of both fields are accepted; camelCase wins on a tie. */
    public function test_the_snake_case_spellings_are_accepted_too(): void
    {
        $this->freshLogin($this->student);

        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [
            'last_position' => 300,
            'watched_percentage' => 95,
        ])
            ->assertOk()
            ->assertJsonPath('data.progress.last_position_seconds', 300)
            ->assertJsonPath('data.progress.completed', true);
    }

    // ══ 3. STICKY COMPLETION, MONOTONIC POSITION ══════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `GREATEST()` ON BOTH MUTABLE COLUMNS, AND IT IS A PRODUCT RULE.      ║
     * ║                                                                      ║
     * ║ A student who finishes a lesson and then re-watches it from second   ║
     * ║ zero posts a low percentage — and MUST NOT be un-completed. The      ║
     * ║ dashboard's completed-lesson count and the course progress bar would ║
     * ║ both visibly go backwards.                                            ║
     * ║                                                                      ║
     * ║ Eloquent's upsert() cannot express this: it compiles to a straight   ║
     * ║ overwrite. Using it would silently turn both rules into              ║
     * ║ last-write-wins, and this test is what catches that.                 ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_completion_is_sticky_and_progress_never_decreases(): void
    {
        $this->freshLogin($this->student);
        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [
            'lastPosition' => 950,
            'watchedPercentage' => 95,
        ])
            ->assertOk()
            ->assertJsonPath('data.progress.completed', true)
            ->assertJsonPath('data.progress.last_position_seconds', 950);

        // The student re-watches from the beginning.
        $this->freshLogin($this->student);
        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [
            'lastPosition' => 100,
            'watchedPercentage' => 10,
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.progress.completed',
                true,
            )
            ->assertJsonPath('data.progress.last_position_seconds', 950);

        $this->assertSame(
            1,
            DB::table('lesson_progress')
                ->where('student_id', $this->student->getKey())
                ->where('content_item_id', $this->lesson->getKey())
                ->count(),
            'The upsert appended a second row instead of updating — uq_lesson_progress is the '
            . 'conflict target and must remain so.'
        );
    }

    /** Scrubbing forward DOES move the position — monotonic, not frozen. */
    public function test_a_higher_position_still_advances_the_row(): void
    {
        $this->freshLogin($this->student);
        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", ['lastPosition' => 100])
            ->assertOk();

        $this->freshLogin($this->student);
        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", ['lastPosition' => 700])
            ->assertOk()
            ->assertJsonPath('data.progress.last_position_seconds', 700);
    }

    // ══ 4. THE NAME MISMATCH AND THE READ PATH ════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE RESPONSE KEY IS `last_position_seconds`; THE COLUMN IS           ║
     * ║ `progress_seconds`. THE MISMATCH IS THE CONTRACT.                    ║
     * ║                                                                      ║
     * ║ VideoPlayerPage.tsx seeks the <video> element using                  ║
     * ║ `progress.last_position_seconds`. Renaming the key to match the      ║
     * ║ column would make every returning student restart every lesson from  ║
     * ║ zero — silently, with a 200 and no error anywhere.                   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_response_key_is_last_position_seconds_and_the_column_is_progress_seconds(): void
    {
        $this->freshLogin($this->student);
        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", ['lastPosition' => 512])
            ->assertOk();

        $this->freshLogin($this->student);
        $progress = $this->getJson("/api/v1/lessons/{$this->lesson->getKey()}/progress")
            ->assertOk()
            ->json('data.progress');

        $this->assertSame(
            ['last_position_seconds', 'completed', 'last_watched_at'],
            array_keys($progress)
        );
        $this->assertSame(512, $progress['last_position_seconds']);
        $this->assertArrayNotHasKey('progress_seconds', $progress);

        // …and the COLUMN really is the other name.
        $this->assertDatabaseHas('lesson_progress', [
            'student_id' => $this->student->getKey(),
            'content_item_id' => $this->lesson->getKey(),
            'progress_seconds' => 512,
        ]);
    }

    /** A student who has never opened the lesson gets the same zeroed shape. */
    public function test_a_student_with_no_row_gets_the_zeroed_shape(): void
    {
        $this->freshLogin($this->student);

        $this->getJson("/api/v1/lessons/{$this->lesson->getKey()}/progress")
            ->assertOk()
            ->assertJsonPath('data.progress.last_position_seconds', 0)
            ->assertJsonPath('data.progress.completed', false)
            ->assertJsonPath('data.progress.last_watched_at', null);
    }

    /**
     * PROGRESS IS PER-STUDENT AND READ BY SESSION IDENTITY ALONE. One student
     * never sees another's row — and there is no request parameter through
     * which they could try, which is a stronger guarantee than rejecting one.
     */
    public function test_one_students_progress_is_invisible_to_another(): void
    {
        $other = $this->makeStudentUser();
        $this->makeSubscription($other, $this->course);

        $this->freshLogin($this->student);
        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", ['lastPosition' => 777])
            ->assertOk();

        $this->freshLogin($other);
        $this->getJson("/api/v1/lessons/{$this->lesson->getKey()}/progress?student_id={$this->student->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.progress.last_position_seconds', 0);
    }

    // ══ 5. THE SHARED REFUSAL LADDER ══════════════════════════════════════

    public function test_both_progress_routes_run_the_same_gate_as_the_video_route(): void
    {
        $unsubscribed = $this->makeStudentUser();
        $note = $this->makeContentItem($this->section, ['type' => ContentItemType::Note]);

        foreach (['GET', 'POST'] as $method) {
            // Unentitled -> 403.
            $this->freshLogin($unsubscribed);
            $this->json($method, "/api/v1/lessons/{$this->lesson->getKey()}/progress")
                ->assertStatus(403)
                ->assertJsonPath('message', 'You do not have access to this lesson');

            // Non-video -> 404, and the type check runs first.
            $this->freshLogin($this->student);
            $this->json($method, "/api/v1/lessons/{$note->getKey()}/progress")
                ->assertStatus(404)
                ->assertJsonPath('message', 'Lesson not found');

            // Guest -> 401.
            $this->flushSession();
            $this->startNextRequest();
            $this->json($method, "/api/v1/lessons/{$this->lesson->getKey()}/progress")
                ->assertStatus(401);
        }

        $this->assertSame(0, DB::table('lesson_progress')->count());
    }

    /** A refused write leaves the table untouched. */
    public function test_a_refused_write_persists_nothing(): void
    {
        $this->freshLogin($this->makeStudentUser());

        $this->postJson("/api/v1/lessons/{$this->lesson->getKey()}/progress", [
            'lastPosition' => 900,
            'watchedPercentage' => 99,
        ])->assertStatus(403);

        $this->assertSame(0, DB::table('lesson_progress')->count());
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function freshLogin(User $user): void
    {
        $this->flushSession();
        $this->startNextRequest();
        $this->loginAs($user);
        $this->startNextRequest();
    }
}
