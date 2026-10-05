<?php

declare(strict_types=1);

namespace Tests\Feature\ContentItem;

use App\Enums\ContentItemType;
use App\Models\Course;
use App\Models\Exam;
use App\Models\Note;
use App\Models\Section;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE DETAIL TABLES DO NOT ALL UPDATE THE SAME WAY, AND THIS FILE IS THE   ║
 * ║ PROOF THAT THE DIFFERENCE WAS MEASURED RATHER THAN ASSUMED.              ║
 * ║                                                                          ║
 * ║   video / link / note   FULL REPLACE — every column is rebound from the  ║
 * ║                         payload, so an omitted field becomes NULL.       ║
 * ║   exam                  PARTIAL — array_filter strips the nulls first,   ║
 * ║                         so an omitted field is left alone.               ║
 * ║                                                                          ║
 * ║ Both halves come from ONE method, backend/ContentItemService::           ║
 * ║ saveTypeDetail(), whose branches genuinely disagree: the first three go  ║
 * ║ through `INSERT … ON DUPLICATE KEY UPDATE col = VALUES(col)` with every  ║
 * ║ value bound `$data[…] ?? null`, while the last two branch on whether a   ║
 * ║ row exists and call array_filter on the update path only.                ║
 * ║                                                                          ║
 * ║ Assuming symmetry in EITHER direction would have been a silent contract  ║
 * ║ change. Recorded as decision 5.8 in docs/open-decisions.md.              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ContentItemDetailSemanticsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private Course $course;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course);
    }

    // ── NOTE: THE CONDITIONAL ROW ─────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A NOTE WITH NEITHER content_text NOR file_path GETS **NO ROW**.      ║
     * ║ NOT AN EMPTY ONE. THE ABSENT ROW IS THE SIGNAL.                      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_note_with_neither_field_creates_no_notes_row_at_all(): void
    {
        $this->loginAs($this->owner);

        $response = $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
            'title' => 'مذكّرة بلا محتوى',
            'type' => 'note',
            'description' => 'وصف فقط',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'note')
            // …and the parent row is perfectly fine, description included.
            ->assertJsonPath('data.description', 'وصف فقط')
            ->assertJsonPath('data.detail', null);

        $this->assertDatabaseHas('content_items', ['id' => $response->json('data.id')]);
        $this->assertSame(0, Note::query()->count(), 'No `notes` row may exist.');
    }

    /** An explicit null is "absent" too — the legacy guard is isset(). */
    public function test_a_note_with_explicit_nulls_creates_no_notes_row_either(): void
    {
        $this->loginAs($this->owner);

        $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
            'title' => 'مذكّرة',
            'type' => 'note',
            'content_text' => null,
            'file_path' => null,
        ])->assertStatus(201);

        $this->assertSame(0, Note::query()->count());
    }

    /**
     * `content_text: ''` DOES create the row — '' is `isset()`, null is not.
     * This is the semantic that only survives because `api/v1/sections/*` is on
     * AppServiceProvider's ConvertEmptyStringsToNull exemption list; without
     * it the '' would already be null here and the row would silently stop
     * being created.
     */
    public function test_an_empty_string_content_text_does_create_the_row(): void
    {
        $this->loginAs($this->owner);

        $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
            'title' => 'مذكّرة فارغة',
            'type' => 'note',
            'content_text' => '',
        ])->assertStatus(201);

        $this->assertSame(1, Note::query()->count());
        $this->assertSame('', Note::query()->first()?->getAttribute('content_text'));
    }

    /** `file_path` ALONE is enough — the guard is an OR, not an AND. */
    public function test_a_file_path_alone_creates_the_row(): void
    {
        $this->loginAs($this->owner);

        $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
            'title' => 'مستند',
            'type' => 'note',
            'file_name' => 'شرح.pdf',
            'file_path' => 'uploads/notes/abc.pdf',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.detail.file_name', 'شرح.pdf')
            ->assertJsonPath('data.detail.content_text', null);

        $this->assertSame(1, Note::query()->count());
    }

    // ── VIDEO: FULL REPLACE ───────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ POSTING ONLY `video_url` ON AN UPDATE **CLEARS** THE OTHER COLUMNS.  ║
     * ║                                                                      ║
     * ║ This is the assertion that a symmetry assumption would have got       ║
     * ║ backwards. upsertVideo() rebinds all five columns from the payload    ║
     * ║ with `?? null`, so duration, provider and thumbnail_url go to NULL    ║
     * ║ and is_preview back to 0. There is NO array_filter on this branch.    ║
     * ║                                                                      ║
     * ║ It is also the ONLY way a poster or a duration can ever be cleared    ║
     * ║ through the API — a merge semantic would have removed a capability    ║
     * ║ the video form relies on, while looking like a safety improvement.    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_video_update_replaces_the_whole_detail_row_exactly_as_backend_does(): void
    {
        $item = $this->makeContentItemWithDetail($this->section, ContentItemType::Video, [
            'video_url' => 'https://cdn.example/old.m3u8',
            'duration' => 600,
            'is_preview' => true,
            'provider' => 'Bunny',
            'thumbnail_url' => 'https://cdn.example/old.png',
        ]);

        $this->loginAs($this->owner);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", [
            'video_url' => 'https://cdn.example/new.m3u8',
        ])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم تحديث العنصر بنجاح')
            ->assertJsonPath('data.detail.video_url', 'https://cdn.example/new.m3u8')
            ->assertJsonPath('data.detail.duration', null)
            ->assertJsonPath('data.detail.provider', null)
            ->assertJsonPath('data.detail.thumbnail_url', null)
            ->assertJsonPath('data.detail.is_preview', false);

        $video = Video::query()->find($item->getKey());

        $this->assertSame('https://cdn.example/new.m3u8', $video?->getAttribute('video_url'));
        $this->assertNull($video?->getAttribute('duration'));
    }

    /** Sending the whole form back — what the UI actually does — preserves it all. */
    public function test_a_video_update_carrying_every_field_preserves_them(): void
    {
        $item = $this->makeContentItemWithDetail($this->section, ContentItemType::Video, [
            'video_url' => 'https://cdn.example/old.m3u8',
            'duration' => 600,
            'provider' => 'Bunny',
        ]);

        $this->loginAs($this->owner);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", [
            'video_url' => 'https://cdn.example/new.m3u8',
            'duration' => 600,
            'provider' => 'Bunny',
        ])
            ->assertJsonPath('data.detail.duration', 600)
            ->assertJsonPath('data.detail.provider', 'Bunny');
    }

    /**
     * `thumbnail_url: ''` is a CLEAR instruction, not a validation failure —
     * UrlValidator::nullable() maps '' to null on purpose. Contrast
     * `video_url: ''`, which is a 422 (ContentItemUrlSafetyTest).
     */
    public function test_an_empty_thumbnail_url_clears_the_poster(): void
    {
        $item = $this->makeContentItemWithDetail($this->section, ContentItemType::Video, [
            'video_url' => 'https://cdn.example/v.m3u8',
            'thumbnail_url' => 'https://cdn.example/poster.png',
        ]);

        $this->loginAs($this->owner);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", [
            'video_url' => 'https://cdn.example/v.m3u8',
            'thumbnail_url' => '',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.detail.thumbnail_url', null);
    }

    /** A video created without a URL gets no `videos` row — the isset() guard. */
    public function test_a_video_without_a_url_creates_no_videos_row(): void
    {
        $this->loginAs($this->owner);

        $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
            'title' => 'فيديو قادم',
            'type' => 'video',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.detail', null);

        $this->assertSame(0, Video::query()->count());
    }

    // ── EXAM: PARTIAL ─────────────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE OTHER HALF: an exam update carrying ONE field leaves the rest    ║
     * ║ alone. `array_filter($fields, fn ($v) => $v !== null)` on the update  ║
     * ║ path — and ONLY on the update path.                                   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_an_exam_update_leaves_unsent_fields_alone(): void
    {
        $item = $this->makeContentItemWithDetail($this->section, ContentItemType::Exam, [
            'duration_minutes' => 45,
            'allowed_attempts' => 3,
            'show_answers' => false,
        ]);

        $this->loginAs($this->owner);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", ['pass_score' => 60])
            ->assertStatus(200)
            // JSON number, not '60.00' — see Stage 6 / Session A's unification
            // of the two exam presenters (ExamResourceFactory).
            ->assertJsonPath('data.detail.pass_score', fn ($v): bool => is_numeric($v) && (float) $v === 60.0)
            ->assertJsonPath('data.detail.duration_minutes', 45)
            ->assertJsonPath('data.detail.allowed_attempts', 3)
            ->assertJsonPath('data.detail.show_answers', false);
    }

    /**
     * `false` IS written, because the legacy read is presence-based
     * (`array_key_exists(…) ? (int) … : null`) and int 0 survives
     * array_filter's `!== null`. Only a MISSING key means "leave it".
     */
    public function test_an_exam_flag_can_be_turned_off_by_sending_false(): void
    {
        $item = $this->makeContentItemWithDetail($this->section, ContentItemType::Exam, [
            'show_answers' => true,
            'lock_content' => true,
        ]);

        $this->loginAs($this->owner);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", ['show_answers' => false])
            ->assertJsonPath('data.detail.show_answers', false)
            ->assertJsonPath('data.detail.lock_content', true);
    }

    /** An exam item edited before its config was ever set INSERTS with defaults. */
    public function test_an_update_creates_the_detail_row_when_none_existed(): void
    {
        $item = $this->makeContentItem($this->section, ['type' => ContentItemType::Exam]);

        $this->assertSame(0, Exam::query()->count());

        $this->loginAs($this->owner);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", ['pass_score' => 50])
            ->assertStatus(200)
            // The CREATE branch, so the repository defaults apply.
            ->assertJsonPath('data.detail.duration_minutes', 30)
            ->assertJsonPath('data.detail.show_answers', true);

        $this->assertSame(1, Exam::query()->count());
    }

    // ── THE TYPE CANNOT BE CHANGED THROUGH THE UPDATE ROUTE ───────────────

    /**
     * The stored type governs both the URL rules and the persister. A caller
     * cannot post `type: 'note'` next to a hostile `video_url` and have the
     * allow-list skipped, and cannot strand a `videos` row by re-typing an
     * item.
     */
    public function test_the_update_route_ignores_a_submitted_type(): void
    {
        $item = $this->makeContentItemWithDetail($this->section, ContentItemType::Video, [
            'video_url' => 'https://cdn.example/v.m3u8',
        ]);

        $this->loginAs($this->owner);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", [
            'type' => 'note',
            'content_text' => 'محاولة تغيير النوع',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'video');

        $this->assertSame(0, Note::query()->count(), 'No `notes` row may be created for a video.');
        $this->assertSame('video', $item->refresh()->getAttribute('type')->value);
    }

    /** …and the hostile-URL half of the same claim. */
    public function test_a_hostile_video_url_is_still_rejected_when_a_note_type_is_claimed(): void
    {
        $item = $this->makeContentItemWithDetail($this->section, ContentItemType::Video, [
            'video_url' => 'https://cdn.example/v.m3u8',
        ]);

        $this->loginAs($this->owner);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", [
            'type' => 'note',
            'video_url' => 'javascript:alert(1)',
        ])->assertStatus(422);
    }

    // ── CHECK CONSTRAINTS BECOME 422s ─────────────────────────────────────

    /**
     * backend/ validated none of the exam bounds, so
     * `allowed_attempts: 0` was a raw chk_exams_scores violation — a 500. The
     * rules answer 422 now. No request that previously succeeded changes; same
     * class of fix as decision 5.2.
     */
    public function test_out_of_range_exam_config_is_422_not_a_constraint_violation(): void
    {
        $this->loginAs($this->owner);
        $url = "/api/v1/sections/{$this->section->getKey()}/content-items";

        $this->postJson($url, ['title' => 'ا', 'type' => 'exam', 'allowed_attempts' => 0])->assertStatus(422);
        $this->postJson($url, ['title' => 'ا', 'type' => 'exam', 'pass_score' => 150])->assertStatus(422);
        $this->postJson($url, ['title' => 'ا', 'type' => 'exam', 'duration_minutes' => 0])->assertStatus(422);

        $this->assertSame(0, Exam::query()->count());
    }
}
