<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\Announcement;
use App\Models\BroadcastNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THREE STATES PER FIELD: **ABSENT / '' / REAL VALUE**.                    ║
 * ║ The discipline open decision 4.1 made binding on every stage since.      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── WHAT MAKES STAGE 8 DIFFERENT, AND WHY IT IS STATED HERE. ──────────────┐
 * │ Every stage from 4 onward had at least one field where '' and null MEANT │
 * │ DIFFERENT THINGS, which is what forces the                               │
 * │ ConvertEmptyStringsToNull exemption. Stage 8 has NONE — checked field by │
 * │ field below rather than assumed:                                         │
 * │                                                                          │
 * │   linkUrl / link   ''  -> clear      null -> clear      SAME             │
 * │   title / content  ''  -> 422        null -> 422        SAME             │
 * │   body (broadcast) ''  -> 422        null -> 422        SAME             │
 * │                                                                          │
 * │ Only PRESENCE is load-bearing, and the middleware rewrites the VALUE,    │
 * │ never the key — so the PATCH contract ("absent = untouched") holds       │
 * │ either way. The routes are on the exemption list anyway, and             │
 * │ AppServiceProvider says why at length. This file is the measurement      │
 * │ behind that claim; without it "we checked" is an assertion, not a test.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class NotificationFieldSemanticsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    // ── THE EXEMPTION IS ACTUALLY WIRED ───────────────────────────────────

    /**
     * `''` survives the global middleware on all four Stage-8 write paths.
     *
     * Asserted through a probe route registered INSIDE the exempted path space
     * rather than by reading AppServiceProvider's source: the question is what
     * the middleware does, and only running it can answer that. A `$request->
     * is(...)` pattern that does not match would leave this null and fail here.
     */
    public function test_empty_strings_survive_the_middleware_on_every_stage_8_write_path(): void
    {
        foreach ([
            'api/v1/announcements',
            'api/v1/announcements/probe-id',
            'api/v1/admin/broadcasts',
            'api/v1/admin/broadcasts/probe-id',
        ] as $path) {
            Route::post('/' . $path . '/__semantics_probe', static fn (Request $r) => [
                'value' => $r->input('field'),
                'isString' => \is_string($r->input('field')),
            ]);
        }

        foreach ([
            'api/v1/announcements',
            'api/v1/announcements/probe-id',
            'api/v1/admin/broadcasts',
            'api/v1/admin/broadcasts/probe-id',
        ] as $path) {
            $response = $this->postJson('/' . $path . '/__semantics_probe', ['field' => '']);

            // `data.` — ForceJsonEnvelope wraps EVERY response in
            // {success, message, data}, including a bare array returned from a
            // closure route. Reading the key at the top level would be null for
            // a reason that has nothing to do with the middleware under test.
            $this->assertTrue(
                $response->json('data.isString'),
                "'' was rewritten to null under {$path} — the exemption pattern does not cover it."
            );
            $this->assertSame('', $response->json('data.value'));
        }
    }

    /** …and a GET is deliberately NOT exempted: it carries no body to preserve. */
    public function test_the_exemption_is_scoped_to_write_verbs_only(): void
    {
        Route::get('/api/v1/announcements/__semantics_probe_get', static fn (Request $r) => [
            'isString' => \is_string($r->input('field')),
        ]);

        $this->getJson('/api/v1/announcements/__semantics_probe_get?field=')
            ->assertOk()
            ->assertJsonPath('data.isString', false);
    }

    // ── ANNOUNCEMENTS: linkUrl ────────────────────────────────────────────

    /**
     * ABSENT -> untouched. '' -> cleared. REAL -> written.
     *
     * All three in one method on purpose: the interesting property is that the
     * three answers DIFFER, and three separate tests would each pass against an
     * implementation that collapsed two of them together.
     */
    public function test_announcement_link_url_has_three_distinct_behaviours(): void
    {
        $instructor = $this->makeInstructor();
        $this->actingAs($instructor);

        $announcement = $this->makeAnnouncement($instructor, ['link_url' => 'https://example.test/original']);
        $url = "/api/v1/announcements/{$announcement->getKey()}";

        // ABSENT — untouched.
        $this->patchJson($url, ['title' => 'ع'])->assertOk();
        $this->assertSame('https://example.test/original', $announcement->fresh()->getAttribute('link_url'));

        // REAL — written.
        $this->patchJson($url, ['linkUrl' => 'https://example.test/new'])->assertOk();
        $this->assertSame('https://example.test/new', $announcement->fresh()->getAttribute('link_url'));

        // '' — cleared.
        $this->patchJson($url, ['linkUrl' => ''])->assertOk();
        $this->assertNull($announcement->fresh()->getAttribute('link_url'));

        // …and an explicit null clears it too — the SAME answer as ''. That
        // agreement is what makes Stage 8's exemption belt-and-braces rather
        // than load-bearing, and it is asserted rather than asserted-about.
        $this->patchJson($url, ['linkUrl' => 'https://example.test/again'])->assertOk();
        $this->patchJson($url, ['linkUrl' => null])->assertOk();
        $this->assertNull($announcement->fresh()->getAttribute('link_url'));
    }

    /** On CREATE, absent and '' both produce NULL — there is nothing to preserve. */
    public function test_announcement_link_url_on_create_is_null_when_absent_or_blank(): void
    {
        $this->actingAs($this->makeInstructor());

        foreach ([[], ['linkUrl' => ''], ['linkUrl' => null]] as $variant) {
            $this->postJson('/api/v1/announcements', array_merge(
                ['title' => 'عنوان', 'content' => 'محتوى'],
                $variant
            ))->assertStatus(201);
        }

        $this->assertSame(3, Announcement::query()->whereNull('link_url')->count());
    }

    // ── ANNOUNCEMENTS: title / content ────────────────────────────────────

    /**
     * ABSENT -> untouched. '' -> **422**. REAL -> written.
     *
     * The middle case is approved decision 4, and it is the one backend/ gets
     * wrong on PATCH. Both spellings of "blank" are rejected — '' and a string
     * of spaces — because prepareForValidation trims before `required` runs.
     */
    public function test_announcement_title_and_content_have_three_distinct_behaviours(): void
    {
        $instructor = $this->makeInstructor();
        $this->actingAs($instructor);

        $announcement = $this->makeAnnouncement($instructor, ['title' => 'الأصلي', 'body' => 'النص الأصلي']);
        $url = "/api/v1/announcements/{$announcement->getKey()}";

        foreach (['title' => 'title', 'content' => 'body'] as $field => $column) {
            $original = $announcement->fresh()->getAttribute($column);

            // ABSENT — untouched.
            $this->patchJson($url, ['isPublished' => true])->assertOk();
            $this->assertSame($original, $announcement->fresh()->getAttribute($column));

            // '' and '   ' — 422, and the old value survives.
            foreach (['', '   '] as $blank) {
                $this->patchJson($url, [$field => $blank])
                    ->assertStatus(422)
                    ->assertJsonPath('data.errors.' . $field . '.0', 'العنوان والمحتوى مطلوبان');

                $this->assertSame($original, $announcement->fresh()->getAttribute($column));
            }

            // An explicit null is rejected identically — the same answer as ''.
            $this->patchJson($url, [$field => null])->assertStatus(422);
            $this->assertSame($original, $announcement->fresh()->getAttribute($column));

            // REAL — written.
            $this->patchJson($url, [$field => 'قيمة جديدة'])->assertOk();
            $this->assertSame('قيمة جديدة', $announcement->fresh()->getAttribute($column));
        }
    }

    // ── ANNOUNCEMENTS: is_published and the dates ─────────────────────────

    /**
     * `isPublished: false` is a REAL INSTRUCTION, not an absence.
     *
     * This is the classic presence-vs-truthiness trap: a `!empty()` or `??`
     * guard treats `false` as "not supplied" and silently refuses to
     * unpublish. The contract is `array_key_exists`, so `false` must land.
     */
    public function test_is_published_false_is_written_and_absence_is_not(): void
    {
        $instructor = $this->makeInstructor();
        $this->actingAs($instructor);

        $announcement = $this->makeAnnouncement($instructor, ['is_published' => true]);
        $url = "/api/v1/announcements/{$announcement->getKey()}";

        $this->patchJson($url, ['title' => 'ع'])->assertOk();
        $this->assertTrue((bool) $announcement->fresh()->getAttribute('is_published'), 'Absent must not change it.');

        $this->patchJson($url, ['isPublished' => false])->assertOk();
        $this->assertFalse((bool) $announcement->fresh()->getAttribute('is_published'), 'false must be written.');

        $this->patchJson($url, ['is_published' => true])->assertOk();
        $this->assertTrue((bool) $announcement->fresh()->getAttribute('is_published'), 'The snake_case spelling works too.');
    }

    /** Dates: absent untouched, '' clears, a real value is stored. */
    public function test_announcement_dates_have_three_distinct_behaviours(): void
    {
        $instructor = $this->makeInstructor();
        $this->actingAs($instructor);

        $announcement = $this->makeAnnouncement($instructor, ['start_date' => '2026-01-01 09:00:00']);
        $url = "/api/v1/announcements/{$announcement->getKey()}";

        $this->patchJson($url, ['title' => 'ع'])->assertOk();
        $this->assertNotNull($announcement->fresh()->getAttribute('start_date'));

        $this->patchJson($url, ['startDate' => '2026-06-07T08:09'])->assertOk();
        $this->assertSame(
            '2026-06-07 08:09:00',
            $announcement->fresh()->getAttribute('start_date')->format('Y-m-d H:i:s')
        );

        $this->patchJson($url, ['startDate' => ''])->assertOk();
        $this->assertNull($announcement->fresh()->getAttribute('start_date'));
    }

    // ── camelCase / snake_case EQUIVALENCE ────────────────────────────────

    /**
     * Both spellings of all five aliased fields reach the same column.
     *
     * backend/ accepts both via `?? ` chains; normalising them in
     * prepareForValidation is what lets `sometimes` and `has()` be evaluated
     * against ONE canonical key — without which "was this field present?"
     * would be ambiguous, and that question is the whole basis of PATCH.
     */
    public function test_both_spellings_of_every_aliased_field_are_accepted(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourseFor($instructor);
        $this->actingAs($instructor);

        foreach ([
            ['courseId' => $course->getKey(), 'linkUrl' => 'https://example.test/a', 'startDate' => '2026-02-02T02:02', 'endDate' => '2026-03-03T03:03', 'isPublished' => false],
            ['course_id' => $course->getKey(), 'link_url' => 'https://example.test/a', 'start_date' => '2026-02-02T02:02', 'end_date' => '2026-03-03T03:03', 'is_published' => false],
        ] as $variant) {
            $this->postJson('/api/v1/announcements', array_merge(
                ['title' => 'عنوان', 'content' => 'محتوى'],
                $variant
            ))->assertStatus(201);
        }

        $rows = Announcement::query()->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame((string) $course->getKey(), (string) $row->getAttribute('course_id'));
            $this->assertSame('https://example.test/a', $row->getAttribute('link_url'));
            $this->assertSame('2026-02-02 02:02:00', $row->getAttribute('start_date')->format('Y-m-d H:i:s'));
            $this->assertSame('2026-03-03 03:03:00', $row->getAttribute('end_date')->format('Y-m-d H:i:s'));
            $this->assertFalse((bool) $row->getAttribute('is_published'));
        }
    }

    // ── BROADCASTS ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function broadcastPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'عنوان التنبيه',
            'body' => 'نص التنبيه',
            'audience' => 'all_teachers',
            'start_at' => '2026-01-01T09:00',
        ], $overrides);
    }

    /**
     * `link`: absent -> null, '' -> null, real -> stored.
     *
     * On a FULL-REPLACEMENT PUT, "absent" and "cleared" are necessarily the
     * same answer — there is no untouched state to preserve. That is the
     * contract difference from the announcement PATCH above, and asserting it
     * here is what stops someone "fixing" the broadcast PUT into a partial one.
     */
    public function test_broadcast_link_is_null_when_absent_or_blank_and_stored_otherwise(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInstructor();
        $this->actingAs($admin);

        $this->postJson('/api/v1/admin/broadcasts', $this->broadcastPayload())->assertStatus(201);
        $id = BroadcastNotification::query()->firstOrFail()->getKey();
        $url = "/api/v1/admin/broadcasts/{$id}";

        $this->assertNull(BroadcastNotification::query()->findOrFail($id)->getAttribute('link'));

        $this->putJson($url, $this->broadcastPayload(['link' => 'https://example.test/go']))->assertOk();
        $this->assertSame('https://example.test/go', BroadcastNotification::query()->findOrFail($id)->getAttribute('link'));

        $this->putJson($url, $this->broadcastPayload(['link' => '']))->assertOk();
        $this->assertNull(BroadcastNotification::query()->findOrFail($id)->getAttribute('link'));

        $this->putJson($url, $this->broadcastPayload(['link' => 'https://example.test/go']))->assertOk();
        $this->putJson($url, $this->broadcastPayload(['link' => null]))->assertOk();
        $this->assertNull(BroadcastNotification::query()->findOrFail($id)->getAttribute('link'));

        // ABSENT on a full replacement clears it too — no partial semantics.
        $this->putJson($url, $this->broadcastPayload(['link' => 'https://example.test/go']))->assertOk();
        $this->putJson($url, $this->broadcastPayload())->assertOk();
        $this->assertNull(BroadcastNotification::query()->findOrFail($id)->getAttribute('link'));
    }

    /**
     * `title` and `body`: '' , null AND absent are all 422 on a broadcast —
     * because PUT is a full replacement and all three are `required`.
     *
     * Contrast the announcement PATCH, where ABSENT is a 200. Same word,
     * two contracts, both ported.
     */
    public function test_broadcast_title_and_body_reject_blank_null_and_absent_alike(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInstructor();
        $this->actingAs($admin);

        foreach (['title', 'body'] as $field) {
            foreach (['', '   ', null] as $blank) {
                $this->postJson('/api/v1/admin/broadcasts', $this->broadcastPayload([$field => $blank]))
                    ->assertStatus(422);
            }

            $absent = $this->broadcastPayload();
            unset($absent[$field]);

            $this->postJson('/api/v1/admin/broadcasts', $absent)->assertStatus(422);
        }

        $this->assertSame(0, BroadcastNotification::query()->count());
    }

    /** `end_at`: absent and '' both mean "no expiry"; a real value is stored. */
    public function test_broadcast_end_at_is_null_when_absent_or_blank(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInstructor();
        $this->actingAs($admin);

        foreach ([[], ['end_at' => ''], ['end_at' => null]] as $variant) {
            $this->postJson('/api/v1/admin/broadcasts', $this->broadcastPayload($variant))->assertStatus(201);
        }

        $this->assertSame(3, BroadcastNotification::query()->whereNull('end_at')->count());

        $this->postJson('/api/v1/admin/broadcasts', $this->broadcastPayload(['end_at' => '2026-12-31T23:59']))
            ->assertStatus(201);

        $this->assertSame(1, BroadcastNotification::query()->whereNotNull('end_at')->count());
    }

    /**
     * `target_user_ids` and `specific_user` are IGNORED for audiences that do
     * not use them — not rejected, and never stored.
     *
     * A stored-but-unused target list would make the admin card render a
     * picker summary for an `all_teachers` broadcast, and `selectedTeachers`
     * must be NULL for every audience but one.
     */
    public function test_irrelevant_audience_fields_are_ignored_and_not_stored(): void
    {
        $admin = $this->makeAdmin();
        $instructor = $this->makeInstructor();
        $this->actingAs($admin);

        $this->postJson('/api/v1/admin/broadcasts', $this->broadcastPayload([
            'audience' => 'all_teachers',
            'target_user_ids' => [(string) $instructor->getKey()],
            'specific_user' => $admin->username,
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.broadcast.selectedTeachers', null)
            ->assertJsonPath('data.broadcast.specificUser', null);

        $this->assertNull(BroadcastNotification::query()->firstOrFail()->getAttribute('target_user_ids'));
    }

    /** Duplicate ids in the picker collapse — one recipient, one row. */
    public function test_duplicate_target_ids_are_deduplicated(): void
    {
        $admin = $this->makeAdmin();
        $instructor = $this->makeInstructor();
        $this->actingAs($admin);

        $id = (string) $instructor->getKey();

        $this->postJson('/api/v1/admin/broadcasts', $this->broadcastPayload([
            'audience' => 'selected_teachers',
            'target_user_ids' => [$id, $id, $id],
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.broadcast.recipients_count', 1)
            ->assertJsonPath('data.broadcast.selectedTeachers', [$id]);

        $this->assertSame(1, \App\Models\Notification::query()->count());
    }
}
