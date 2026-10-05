<?php

declare(strict_types=1);

namespace Tests\Feature\ContentItem;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE `''` vs `null` vs ABSENT TABLE FOR `PUT /content-items/{id}`.       ║
 * ║                                                                          ║
 * ║  field        absent     null       ''                                   ║
 * ║  ───────────  ─────────  ─────────  ───────────────────────────────────  ║
 * ║  title        untouched  untouched  WRITTEN (VARCHAR, '' is legal)       ║
 * ║  description  untouched  untouched  **CLEAR**  (TEXT, '' is legal)       ║
 * ║  is_hidden    untouched  untouched  n/a — presence-based, `false` writes  ║
 * ║  start_at     untouched  untouched  **422** (was a 1292 500)             ║
 * ║  end_at       untouched  untouched  **422**                              ║
 * ║                                                                          ║
 * ║ THE WHOLE TABLE COLLAPSES WITHOUT App\Providers\AppServiceProvider::     ║
 * ║ preserveEmptyStringsOnClearableWrites(). Laravel's global                ║
 * ║ ConvertEmptyStringsToNull rewrites every '' to null before any rule or   ║
 * ║ controller sees it, which would make "clear this description" a silent   ║
 * ║ no-op answering 200 — the standing warning in open decision 4.1, and     ║
 * ║ the reason `api/v1/content-items/*` had to be added to that list.        ║
 * ║                                                                          ║
 * ║ MEASURED, NOT ASSUMED: test_the_middleware_exemption_is_what_makes_this_ ║
 * ║ table_possible below proves the '' actually survives the middleware      ║
 * ║ rather than merely that the controller would handle it if it did.        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ContentItemFieldSemanticsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private Course $course;

    private Section $section;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course);

        $this->item = $this->makeContentItem($this->section, [
            'title' => 'العنوان الأصلي',
            'description' => 'الوصف الأصلي',
            'is_hidden' => false,
        ]);

        $this->loginAs($this->owner);
    }

    private function edit(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->putJson("/api/v1/content-items/{$this->item->getKey()}", $payload);
    }

    // ── description ───────────────────────────────────────────────────────

    public function test_an_empty_description_clears_the_column(): void
    {
        $this->edit(['description' => ''])
            ->assertStatus(200)
            ->assertJsonPath('data.description', '');

        $this->assertSame('', $this->item->refresh()->getAttribute('description'));
    }

    public function test_a_null_description_leaves_the_column_alone(): void
    {
        $this->edit(['description' => null])->assertStatus(200);

        $this->assertSame('الوصف الأصلي', $this->item->refresh()->getAttribute('description'));
    }

    public function test_an_absent_description_leaves_the_column_alone(): void
    {
        $this->edit(['title' => 'عنوان جديد'])->assertStatus(200);

        $this->assertSame('الوصف الأصلي', $this->item->refresh()->getAttribute('description'));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE MEASUREMENT. If `api/v1/content-items/*` were missing from the   ║
     * ║ exemption list, `description: ''` would arrive as null and this test  ║
     * ║ would see the ORIGINAL value survive — a silent 200-shaped failure.  ║
     * ║ Asserting both branches in ONE test makes the difference the subject. ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_middleware_exemption_is_what_makes_this_table_possible(): void
    {
        $this->edit(['description' => ''])->assertStatus(200);
        $cleared = $this->item->refresh()->getAttribute('description');

        $this->item->setAttribute('description', 'الوصف الأصلي')->save();

        $this->edit(['description' => null])->assertStatus(200);
        $untouched = $this->item->refresh()->getAttribute('description');

        $this->assertSame('', $cleared);
        $this->assertSame('الوصف الأصلي', $untouched);
        $this->assertNotSame(
            $cleared,
            $untouched,
            "'' and null must NOT be the same value by the time the controller runs. "
            . 'If they are, ConvertEmptyStringsToNull is not exempted for this path — '
            . 'see AppServiceProvider::preserveEmptyStringsOnClearableWrites().'
        );
    }

    // ── title ─────────────────────────────────────────────────────────────

    /**
     * '' IS written to `title`, and there is no `min:` rule. backend/'s
     * array_filter genuinely wrote it to a NOT NULL VARCHAR — legal in MySQL —
     * so adding a minimum here would 422 a request that succeeds in production
     * today. Same conclusion, same reason, as Session A's `sections.title`.
     */
    public function test_an_empty_title_is_written_not_rejected(): void
    {
        $this->edit(['title' => ''])
            ->assertStatus(200)
            ->assertJsonPath('data.title', '');

        $this->assertSame('', $this->item->refresh()->getAttribute('title'));
    }

    public function test_a_null_title_leaves_the_column_alone(): void
    {
        $this->edit(['title' => null])->assertStatus(200);

        $this->assertSame('العنوان الأصلي', $this->item->refresh()->getAttribute('title'));
    }

    public function test_a_title_longer_than_the_column_is_422_not_a_data_truncation_500(): void
    {
        $this->edit(['title' => str_repeat('ا', 191)])->assertStatus(422);
    }

    // ── is_hidden ─────────────────────────────────────────────────────────

    public function test_is_hidden_is_presence_based_so_false_really_unhides(): void
    {
        $this->item->setAttribute('is_hidden', true)->save();

        $this->edit(['is_hidden' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_hidden', false)
            ->assertJsonPath('data.isHidden', false);

        $this->assertFalse((bool) $this->item->refresh()->getAttribute('is_hidden'));
    }

    /**
     * An unrelated rename must NOT un-hide the item. `boolean()` without a
     * presence check in front of it is the classic way this breaks — the same
     * rule as `is_published` on a course and `isActive` on a teacher.
     */
    public function test_an_unrelated_rename_does_not_unhide_the_item(): void
    {
        $this->item->setAttribute('is_hidden', true)->save();

        $this->edit(['title' => 'عنوان جديد'])->assertStatus(200);

        $this->assertTrue((bool) $this->item->refresh()->getAttribute('is_hidden'));
    }

    // ── start_at / end_at ─────────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `start_at: ''` IS **IGNORED**, NOT WRITTEN AND NOT REJECTED — THE    ║
     * ║ DATETIME TWIN OF DECISION 5.1, AND A MEASUREMENT THAT CHANGED THE    ║
     * ║ IMPLEMENTATION.                                                      ║
     * ║                                                                      ║
     * ║ backend/'s array_filter let '' through to a DATETIME column, which   ║
     * ║ MySQL under STRICT_TRANS_TABLES answers with                          ║
     * ║   SQLSTATE[22007] 1292 Incorrect datetime value: ''                  ║
     * ║ — a 500. A `nullable|date` rule looks like the fix and IS NOT:       ║
     * ║ Laravel skips non-implicit rules for a blank string entirely, so the ║
     * ║ '' sailed past `date` and produced that exact 500 in this very test  ║
     * ║ before ValidatesContentDetail::dropBlankTypedFields() existed.        ║
     * ║                                                                      ║
     * ║ Session A settled the identical DECIMAL case by adopting the         ║
     * ║ validator's reading — '' means "not sent" — and removing the key.    ║
     * ║ Same reading here, for the same reason: whether '' clears a column   ║
     * ║ or is ignored is a property of the COLUMN TYPE. TEXT/VARCHAR clear;  ║
     * ║ DATETIME and DECIMAL are ignored. Recorded as decision 5.10.          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_an_empty_start_at_is_ignored_not_an_incorrect_datetime_500(): void
    {
        $this->item->setAttribute('start_at', '2026-01-01 08:00:00')->save();

        $this->edit(['start_at' => ''])
            ->assertStatus(200)
            ->assertJsonPath('data.start_at', '2026-01-01 08:00:00');

        $this->assertSame(
            '2026-01-01 08:00:00',
            $this->item->refresh()->getAttribute('start_at')?->format('Y-m-d H:i:s')
        );
    }

    /** The same rule on the numeric columns, whose failure mode was a 1366. */
    public function test_an_empty_numeric_detail_field_is_ignored_not_a_1366(): void
    {
        $exam = $this->makeContentItemWithDetail($this->section, ContentItemType::Exam, [
            'duration_minutes' => 45,
            'pass_score' => 60,
        ]);

        $this->putJson("/api/v1/content-items/{$exam->getKey()}", [
            'duration_minutes' => '',
            'pass_score' => '',
            'total_score' => '',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.detail.duration_minutes', 45)
            ->assertJsonPath('data.detail.pass_score', fn ($v): bool => is_numeric($v) && (float) $v === 60.0);
    }

    public function test_a_null_start_at_leaves_the_column_alone(): void
    {
        $this->item->setAttribute('start_at', '2026-01-01 08:00:00')->save();

        $this->edit(['start_at' => null])->assertStatus(200);

        $this->assertSame(
            '2026-01-01 08:00:00',
            $this->item->refresh()->getAttribute('start_at')?->format('Y-m-d H:i:s')
        );
    }

    /**
     * Datetimes ship as 'Y-m-d H:i:s', NOT ISO-8601 — videoApiService.ts's
     * splitDateTime() splits on /[T ]/ and slices the time to five characters,
     * and the legacy payload was the raw MySQL string.
     */
    public function test_datetimes_ship_in_the_legacy_mysql_shape(): void
    {
        $this->edit(['start_at' => '2026-03-01 09:30:00', 'end_at' => '2026-03-08 21:00:00'])
            ->assertStatus(200)
            ->assertJsonPath('data.start_at', '2026-03-01 09:30:00')
            ->assertJsonPath('data.end_at', '2026-03-08 21:00:00');
    }

    // ── THE DUPLICATED KEY PAIR ───────────────────────────────────────────

    /** present() emits BOTH spellings; different frontend files read each. */
    public function test_both_spellings_of_is_hidden_are_present_in_every_payload(): void
    {
        $this->edit(['is_hidden' => true])
            ->assertJsonPath('data.is_hidden', true)
            ->assertJsonPath('data.isHidden', true);
    }

    /** The whole key set, so an accidental addition or removal is caught. */
    public function test_the_payload_carries_exactly_the_legacy_key_set(): void
    {
        $item = $this->makeContentItemWithDetail($this->section, ContentItemType::Link, [
            'url' => 'https://example.com/x',
        ]);

        $keys = array_keys(
            $this->putJson("/api/v1/content-items/{$item->getKey()}", [])->json('data')
        );
        sort($keys);

        $this->assertSame([
            'course_id', 'description', 'detail', 'end_at', 'group_id', 'id',
            'isHidden', 'is_hidden', 'order_index', 'section_id', 'start_at',
            'title', 'type',
        ], $keys);
    }
}
