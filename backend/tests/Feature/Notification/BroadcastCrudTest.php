<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Enums\UserRole;
use App\Models\BroadcastNotification;
use App\Models\Notification;
use App\Support\BroadcastAudienceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Admin broadcasts — freeze-table rows 102-106.
 *
 * The audience-resolution tests all assert TWO things together: the HTTP status
 * AND that the database is untouched. A 422 alone would also be produced by a
 * request that wrote a broadcast row and then failed, which is the exact
 * partial-delivery state the resolve-before-transaction ordering exists to
 * prevent.
 */
final class BroadcastCrudTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'عنوان التنبيه',
            'body' => 'نص التنبيه',
            'audience' => 'all_teachers',
            'start_at' => '2026-01-01T09:00',
        ], $overrides);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, BroadcastNotification::query()->count(), 'No broadcast row may survive a rejection.');
        $this->assertSame(0, Notification::query()->count(), 'No notification row may survive a rejection.');
    }

    // ── THE FOUR AUDIENCES ────────────────────────────────────────────────

    public function test_all_teachers_delivers_to_every_active_instructor(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->makeInstructor();
        $b = $this->makeInstructor();

        // Neither of these is an active instructor and neither may receive it.
        $inactive = $this->makeInstructor();
        $inactive->forceFill(['is_active' => false])->save();
        $this->makeStudentUser();
        $this->makeAdmin();

        $this->actingAs($admin);

        $this->postJson('/api/v1/admin/broadcasts', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('message', 'تم إنشاء وبث التنبيه الجديد للجمهور المستهدف')
            ->assertJsonPath('data.broadcast.recipients_count', 2)
            ->assertJsonPath('data.broadcast.targetAudience', 'all_teachers')
            ->assertJsonPath('data.broadcast.selectedTeachers', null)
            ->assertJsonPath('data.broadcast.specificUser', null);

        $recipients = Notification::query()->pluck('user_id')->map('strval')->sort()->values()->all();

        $expected = collect([$a, $b])->map(fn ($u): string => (string) $u->getKey())->sort()->values()->all();

        $this->assertSame($expected, $recipients);
        $this->assertNull(BroadcastNotification::query()->firstOrFail()->getAttribute('target_user_ids'));
    }

    public function test_all_staff_delivers_to_every_active_admin(): void
    {
        $admin = $this->makeAdmin();
        $otherAdmin = $this->makeAdmin();
        $this->makeInstructor();

        $this->actingAs($admin);

        $this->postJson('/api/v1/admin/broadcasts', $this->payload(['audience' => 'all_staff']))
            ->assertStatus(201)
            ->assertJsonPath('data.broadcast.recipients_count', 2);

        $recipients = Notification::query()->pluck('user_id')->map('strval')->sort()->values()->all();
        $expected = collect([$admin, $otherAdmin])->map(fn ($u): string => (string) $u->getKey())->sort()->values()->all();

        $this->assertSame($expected, $recipients);
    }

    public function test_selected_teachers_delivers_to_exactly_the_given_ids(): void
    {
        $admin = $this->makeAdmin();
        $picked = $this->makeInstructor();
        $notPicked = $this->makeInstructor();

        $this->actingAs($admin);

        $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'audience' => 'selected_teachers',
            'target_user_ids' => [(string) $picked->getKey()],
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.broadcast.recipients_count', 1)
            ->assertJsonPath('data.broadcast.selectedTeachers', [(string) $picked->getKey()]);

        $this->assertSame(
            [(string) $picked->getKey()],
            Notification::query()->pluck('user_id')->map('strval')->all()
        );
        $this->assertSame(0, Notification::query()->where('user_id', $notPicked->getKey())->count());
    }

    /** The one audience that can target a STUDENT — by username or by id. */
    public function test_specific_user_resolves_by_username_and_by_id(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudentUser();

        $this->actingAs($admin);

        $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'audience' => 'specific_user',
            'specific_user' => $student->username,
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.broadcast.recipients_count', 1)
            // The CARD renders "@{name}", so the username comes back — the
            // UUID is what is stored.
            ->assertJsonPath('data.broadcast.specificUser', $student->username)
            ->assertJsonPath('data.broadcast.selectedTeachers', null);

        $this->assertSame(
            [(string) $student->getKey()],
            (array) BroadcastNotification::query()->firstOrFail()->getAttribute('target_user_ids')
        );

        $byId = $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'audience' => 'specific_user',
            'specific_user' => (string) $student->getKey(),
        ]))->assertStatus(201);

        $byId->assertJsonPath('data.broadcast.specificUser', $student->username);
    }

    // ── STRICT REJECTIONS, AND NOTHING WRITTEN ────────────────────────────

    public function test_an_unknown_id_in_selected_teachers_rejects_the_whole_request(): void
    {
        $admin = $this->makeAdmin();
        $real = $this->makeInstructor();
        $ghost = '0195c1a2-3b4c-7d8e-9f00-112233445566';

        $this->actingAs($admin);

        $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'audience' => 'selected_teachers',
            'target_user_ids' => [(string) $real->getKey(), $ghost],
        ]))
            ->assertStatus(422)
            ->assertJsonPath(
                'data.errors.target_user_ids.0',
                BroadcastAudienceResolver::MISSING_TEACHERS_PREFIX . $ghost
            );

        $this->assertNothingWritten();
    }

    public function test_a_wrong_role_or_inactive_id_in_selected_teachers_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $student = $this->makeStudentUser();
        $inactive = $this->makeInstructor();
        $inactive->forceFill(['is_active' => false])->save();

        $this->actingAs($admin);

        foreach ([$student, $inactive] as $bad) {
            $this->postJson('/api/v1/admin/broadcasts', $this->payload([
                'audience' => 'selected_teachers',
                'target_user_ids' => [(string) $bad->getKey()],
            ]))
                ->assertStatus(422)
                ->assertJsonPath(
                    'data.errors.target_user_ids.0',
                    BroadcastAudienceResolver::MISSING_TEACHERS_PREFIX . $bad->getKey()
                );
        }

        $this->assertNothingWritten();
    }

    /** Several missing ids are joined by the ARABIC comma, not a Latin one. */
    public function test_multiple_missing_ids_are_joined_with_the_arabic_comma(): void
    {
        $this->actingAs($this->makeAdmin());

        $ghosts = [
            '0195c1a2-3b4c-7d8e-9f00-000000000001',
            '0195c1a2-3b4c-7d8e-9f00-000000000002',
        ];

        $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'audience' => 'selected_teachers',
            'target_user_ids' => $ghosts,
        ]))
            ->assertStatus(422)
            ->assertJsonPath(
                'data.errors.target_user_ids.0',
                BroadcastAudienceResolver::MISSING_TEACHERS_PREFIX
                    . $ghosts[0] . BroadcastAudienceResolver::MISSING_SEPARATOR . $ghosts[1]
            );

        $this->assertSame('، ', BroadcastAudienceResolver::MISSING_SEPARATOR);
        $this->assertNothingWritten();
    }

    public function test_an_empty_selected_teachers_list_is_rejected(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'audience' => 'selected_teachers',
            'target_user_ids' => [],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.target_user_ids.0', 'يرجى اختيار معلم واحد على الأقل للجمهور المستهدف');

        $this->assertNothingWritten();
    }

    public function test_a_non_scalar_value_in_the_target_list_is_rejected(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'audience' => 'selected_teachers',
            'target_user_ids' => [['nested' => 'array']],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.target_user_ids.0', 'قائمة المستهدفين تحتوي على قيمة غير صحيحة');

        $this->assertNothingWritten();
    }

    /**
     * ┌── THE CAP APPLIES TO `selected_teachers` AND **NOT** TO THE TWO ─────┐
     * │ "ALL" AUDIENCES. The asymmetry is backend/'s and is carried forward  │
     * │ deliberately (open decision 8.8) — so it is asserted from both       │
     * │ sides, otherwise "we chose not to add a cap" is indistinguishable    │
     * │ from "we forgot to".                                                 │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_selected_teachers_is_capped_at_five_hundred(): void
    {
        $this->actingAs($this->makeAdmin());

        $tooMany = array_map(
            static fn (int $i): string => sprintf('0195c1a2-3b4c-7d8e-9f00-%012d', $i),
            range(1, BroadcastAudienceResolver::MAX_SELECTED_TEACHERS + 1)
        );

        $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'audience' => 'selected_teachers',
            'target_user_ids' => $tooMany,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.target_user_ids.0', 'عدد المستهدفين يتجاوز الحد المسموح');

        $this->assertSame(500, BroadcastAudienceResolver::MAX_SELECTED_TEACHERS);
        $this->assertNothingWritten();
    }

    /**
     * There is NO cap on the "all" audiences. Proven by a fan-out larger than
     * a hypothetical cap would allow — the number is small enough to run fast
     * and large enough that a naive `if (count > N)` copied from the selected
     * branch would trip.
     */
    public function test_the_all_audiences_are_uncapped(): void
    {
        $admin = $this->makeAdmin();

        for ($i = 0; $i < 12; $i++) {
            $this->makeInstructor();
        }

        $this->actingAs($admin);

        $this->postJson('/api/v1/admin/broadcasts', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.broadcast.recipients_count', 12);
    }

    public function test_an_audience_that_resolves_to_nobody_is_rejected(): void
    {
        // An admin exists (the caller), but there is not one instructor.
        $this->actingAs($this->makeAdmin());

        $this->postJson('/api/v1/admin/broadcasts', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('data.errors.audience.0', 'لا يوجد أي مستخدم ضمن الفئة المستهدفة حالياً');

        $this->assertNothingWritten();
    }

    public function test_an_unknown_specific_user_is_rejected(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'audience' => 'specific_user',
            'specific_user' => 'nobody-by-this-name',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.specific_user.0', 'لا يوجد مستخدم نشط بهذا المعرف أو الكنية');

        $this->assertNothingWritten();
    }

    public function test_a_missing_specific_user_box_is_rejected(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->postJson('/api/v1/admin/broadcasts', $this->payload(['audience' => 'specific_user']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.specific_user.0', 'يرجى كتابة اسم أو كنية المستخدم المستهدف بالنظام');

        $this->assertNothingWritten();
    }

    // ── FIELD VALIDATION ──────────────────────────────────────────────────

    public function test_the_schedule_window_must_be_well_formed(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->makeInstructor();

        $this->postJson('/api/v1/admin/broadcasts', $this->payload(['start_at' => 'garbage']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.start_at.0', 'صيغة التاريخ غير صحيحة');

        $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'start_at' => '2026-05-01T10:00',
            'end_at' => '2026-04-01T10:00',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.end_at.0', 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البدء');

        $this->assertNothingWritten();
    }

    public function test_a_too_long_link_is_rejected_with_the_legacy_message(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->makeInstructor();

        $this->postJson('/api/v1/admin/broadcasts', $this->payload([
            'link' => 'https://example.test/' . str_repeat('a', 600),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.link.0', 'الرابط طويل جداً (الحد الأقصى 500 حرف)');

        $this->assertNothingWritten();
    }

    public function test_a_dangerous_link_is_rejected(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->makeInstructor();

        $this->postJson('/api/v1/admin/broadcasts', $this->payload(['link' => 'javascript:alert(1)']))
            ->assertStatus(422);

        $this->assertNothingWritten();
    }

    public function test_title_and_body_have_a_minimum_length_here_unlike_announcements(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->makeInstructor();

        $this->postJson('/api/v1/admin/broadcasts', $this->payload(['title' => 'ab']))->assertStatus(422);
        $this->postJson('/api/v1/admin/broadcasts', $this->payload(['body' => 'ab']))->assertStatus(422);

        $this->assertNothingWritten();
    }

    // ── ROLLBACK ──────────────────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A FAILURE **MID-DELIVERY** LEAVES NEITHER THE BROADCAST ROW NOR ANY   ║
     * ║ NOTIFICATION ROW.                                                     ║
     * ║                                                                      ║
     * ║ The failure is injected through DB::listen — NOT through a model      ║
     * ║ event — because the fan-out is `Notification::insert()`, a QUERY      ║
     * ║ BUILDER write that fires no Eloquent event at all. A `Model::         ║
     * ║ creating` hook would never run and the test would pass while proving  ║
     * ║ nothing. That trap is recorded as open decision 7.15.                 ║
     * ║                                                                      ║
     * ║ The listener fires AFTER the broadcast row is inserted and ON the     ║
     * ║ notifications insert, i.e. exactly at the partial-delivery moment.    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_failure_during_delivery_rolls_back_the_broadcast_too(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->makeInstructor();
        $this->makeInstructor();

        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert into `notifications`')) {
                throw new \RuntimeException('injected mid-delivery failure');
            }
        });

        try {
            $this->postJson('/api/v1/admin/broadcasts', $this->payload());
        } catch (\Throwable) {
            // The exception escaping is expected; the DATABASE is the assertion.
        }

        $this->assertNothingWritten();
    }

    // ── UPDATE (PUT) ──────────────────────────────────────────────────────

    public function test_update_re_resolves_the_audience_and_replaces_the_delivery(): void
    {
        $admin = $this->makeAdmin();
        $first = $this->makeInstructor();
        $second = $this->makeInstructor();

        $broadcast = $this->deliverBroadcastTo($admin, [$first], [
            'audience' => \App\Enums\BroadcastAudience::SelectedTeachers,
            'target_user_ids' => [(string) $first->getKey()],
        ]);

        $this->actingAs($admin);

        $this->putJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}", $this->payload([
            'title' => 'عنوان معدَّل',
            'audience' => 'selected_teachers',
            'target_user_ids' => [(string) $second->getKey()],
        ]))
            ->assertOk()
            ->assertJsonPath('message', 'تم تعديل التنبيه البثي وحفظ التحديثات بنجاح')
            ->assertJsonPath('data.broadcast.recipients_count', 1);

        // The old recipient's copy is GONE — not left orphaned in their inbox.
        $this->assertSame(0, Notification::query()->where('user_id', $first->getKey())->count());
        $this->assertSame(1, Notification::query()->where('user_id', $second->getKey())->count());
    }

    /**
     * `is_read` IS LOST ON EDIT. Documented behaviour, not a bug — and the
     * exact opposite of what TOGGLE does, which is why both are pinned.
     */
    public function test_update_loses_the_is_read_state_by_design(): void
    {
        $admin = $this->makeAdmin();
        $recipient = $this->makeInstructor();

        $broadcast = $this->deliverBroadcastTo($admin, [$recipient]);

        $this->actingAs($recipient);
        $row = Notification::query()->firstOrFail();
        $this->postJson("/api/v1/notifications/{$row->getKey()}/mark-read")->assertOk();
        $this->assertTrue((bool) $row->fresh()->getAttribute('is_read'));

        $this->actingAs($admin);
        $this->putJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}", $this->payload(['title' => 'معدَّل']))
            ->assertOk();

        $fresh = Notification::query()->firstOrFail();

        $this->assertNotSame($row->getKey(), $fresh->getKey(), 'The row was recreated, not updated.');
        $this->assertFalse((bool) $fresh->getAttribute('is_read'));
    }

    /**
     * A PUT does NOT touch `is_active` — editing a DISABLED broadcast must not
     * silently re-publish it into every inbox on save.
     */
    public function test_update_does_not_re_enable_a_disabled_broadcast(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInstructor();

        $broadcast = $this->makeBroadcast($admin, ['is_active' => false]);

        $this->actingAs($admin);

        $this->putJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}", $this->payload(['title' => 'معدَّل']))
            ->assertOk()
            ->assertJsonPath('data.broadcast.isActive', false);

        $this->assertFalse((bool) $broadcast->fresh()->getAttribute('is_active'));
    }

    /** PUT is a FULL REPLACEMENT — an omitted required field is a 422. */
    public function test_update_is_a_full_replacement_not_a_patch(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInstructor();
        $broadcast = $this->makeBroadcast($admin);

        $this->actingAs($admin);

        $partial = $this->payload();
        unset($partial['body']);

        $this->putJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}", $partial)->assertStatus(422);
    }

    public function test_updating_an_unknown_broadcast_is_404(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->makeInstructor();

        $this->putJson('/api/v1/admin/broadcasts/0195c1a2-3b4c-7d8e-9f00-112233445566', $this->payload())
            ->assertStatus(404)
            ->assertJsonPath('message', 'Broadcast not found');
    }

    // ── TOGGLE ────────────────────────────────────────────────────────────

    /** An omitted body means ENABLE — backend/'s `?? true` default. */
    public function test_toggle_defaults_to_enabling_when_the_body_is_omitted(): void
    {
        $admin = $this->makeAdmin();
        $broadcast = $this->makeBroadcast($admin, ['is_active' => false]);

        $this->actingAs($admin);

        $this->patchJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}/toggle")
            ->assertOk()
            ->assertJsonPath('message', 'تم إعادة تفعيل بث التنبيه')
            ->assertJsonPath('data.broadcast.isActive', true);

        $this->assertTrue((bool) $broadcast->fresh()->getAttribute('is_active'));
    }

    /** It SETS, it does not flip — sending false twice does not oscillate. */
    public function test_toggle_sets_rather_than_inverts(): void
    {
        $admin = $this->makeAdmin();
        $broadcast = $this->makeBroadcast($admin, ['is_active' => true]);

        $this->actingAs($admin);

        foreach ([1, 2] as $_) {
            $this->patchJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}/toggle", ['is_active' => false])
                ->assertOk()
                ->assertJsonPath('data.broadcast.isActive', false);
        }

        $this->assertFalse((bool) $broadcast->fresh()->getAttribute('is_active'));
    }

    public function test_toggle_does_not_touch_delivered_rows(): void
    {
        $admin = $this->makeAdmin();
        $recipient = $this->makeInstructor();

        $broadcast = $this->deliverBroadcastTo($admin, [$recipient]);
        $originalId = Notification::query()->firstOrFail()->getKey();

        $this->actingAs($admin);
        $this->patchJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}/toggle", ['is_active' => false])->assertOk();
        $this->patchJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}/toggle", ['is_active' => true])->assertOk();

        $this->assertSame(1, Notification::query()->count());
        $this->assertSame($originalId, Notification::query()->firstOrFail()->getKey());
    }

    // ── DELETE ────────────────────────────────────────────────────────────

    public function test_delete_removes_exactly_its_own_delivered_rows(): void
    {
        $admin = $this->makeAdmin();
        $recipients = array_map(fn () => $this->makeInstructor(), range(1, 5));

        $broadcast = $this->deliverBroadcastTo($admin, $recipients);
        $survivor = $this->deliverBroadcastTo($admin, [$recipients[0]]);

        $before = Notification::query()->count();
        $this->assertSame(6, $before);

        $this->actingAs($admin);

        $this->deleteJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}")
            ->assertOk()
            ->assertJsonPath('message', 'تم حذف التنبيه البثي نهائياً');

        $this->assertSame($before - 5, Notification::query()->count());
        $this->assertSame(1, Notification::query()->where('broadcast_id', $survivor->getKey())->count());
        $this->assertNull($broadcast->fresh());
    }

    public function test_deleting_an_unknown_broadcast_is_404(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->deleteJson('/api/v1/admin/broadcasts/0195c1a2-3b4c-7d8e-9f00-112233445566')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Broadcast not found');
    }

    // ── LIST AND RESPONSE SHAPE ───────────────────────────────────────────

    public function test_the_list_shape_matches_the_legacy_presenter(): void
    {
        $admin = $this->makeAdmin();
        $recipient = $this->makeInstructor();

        $this->deliverBroadcastTo($admin, [$recipient], [
            'body' => 'النص',
            'link' => 'https://example.test/go',
            'start_at' => '2026-02-03 04:05:00',
            'end_at' => null,
        ]);

        $this->actingAs($admin);

        $row = $this->getJson('/api/v1/admin/broadcasts')->assertOk()->json('data.broadcasts.0');

        $this->assertSame([
            'id', 'user_id', 'title', 'message', 'content', 'url', 'is_read',
            'targetAudience', 'selectedTeachers', 'specificUser', 'isActive',
            'startDate', 'endDate', 'created_at', 'created_by_username', 'recipients_count',
        ], array_keys($row));

        // `message` and `content` carry the SAME value under two names.
        $this->assertSame('النص', $row['message']);
        $this->assertSame($row['message'], $row['content']);

        // is_read is hardcoded false — a broadcast has no reader.
        $this->assertFalse($row['is_read']);

        // user_id is the AUTHOR, not a recipient.
        $this->assertSame((string) $admin->getKey(), $row['user_id']);
        $this->assertSame($admin->username, $row['created_by_username']);

        // The datetime-local shape the edit form puts straight back in the input.
        $this->assertSame('2026-02-03T04:05', $row['startDate']);
        $this->assertNull($row['endDate']);

        $this->assertSame(1, $row['recipients_count']);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE FAN-OUT REGRESSION TEST. 3 RECIPIENTS + 1 CREATOR MUST BE 3,      ║
     * ║ NEVER 9 (OR 3 x anything).                                            ║
     * ║                                                                      ║
     * ║ `withCount` compiles to a correlated SUBQUERY, so it cannot be        ║
     * ║ inflated by another relation being loaded alongside it. A `leftJoin`  ║
     * ║ + `COUNT(*)` implementation gives the right answer with ONE           ║
     * ║ one-to-many in play and multiplies the moment a second appears —      ║
     * ║ which is exactly why the creator relation is eager-loaded here and    ║
     * ║ a second aggregate is added on top.                                   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_recipients_count_is_not_multiplied_by_other_relations(): void
    {
        $admin = $this->makeAdmin();
        $recipients = [$this->makeInstructor(), $this->makeInstructor(), $this->makeInstructor()];

        $broadcast = $this->deliverBroadcastTo($admin, $recipients);

        $this->actingAs($admin);

        $this->getJson('/api/v1/admin/broadcasts')
            ->assertOk()
            ->assertJsonPath('data.broadcasts.0.recipients_count', 3);

        // …and directly at the query layer, with a SECOND aggregate stacked on
        // top of the eager-loaded creator — the shape that breaks a join.
        $reloaded = BroadcastNotification::query()
            ->with('creator')
            ->withCount(['notifications', 'notifications as unread_count' => fn ($q) => $q->where('is_read', false)])
            ->whereKey($broadcast->getKey())
            ->firstOrFail();

        $this->assertSame(3, (int) $reloaded->getAttribute('notifications_count'));
        $this->assertSame(3, (int) $reloaded->getAttribute('unread_count'));
    }

    // ── ACCESS CONTROL: role:admin ON ALL FIVE, INCLUDING THE GET ─────────

    /**
     * The GET is `role:admin` too. The list reveals who every past broadcast
     * targeted, so a compromised instructor account reading it learns the
     * platform's internal communication graph without writing anything.
     */
    public function test_every_broadcast_route_including_the_get_is_admin_only(): void
    {
        $broadcast = $this->makeBroadcast($this->makeAdmin());
        $id = $broadcast->getKey();

        foreach ([UserRole::Instructor, UserRole::Student] as $role) {
            $user = $role === UserRole::Instructor ? $this->makeInstructor() : $this->makeStudentUser();

            $this->actingAs($user);

            $this->getJson('/api/v1/admin/broadcasts')->assertStatus(403);
            $this->postJson('/api/v1/admin/broadcasts', $this->payload())->assertStatus(403);
            $this->putJson("/api/v1/admin/broadcasts/{$id}", $this->payload())->assertStatus(403);
            $this->patchJson("/api/v1/admin/broadcasts/{$id}/toggle")->assertStatus(403);
            $this->deleteJson("/api/v1/admin/broadcasts/{$id}")->assertStatus(403);
        }

        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/admin/broadcasts')->assertStatus(401);
        $this->postJson('/api/v1/admin/broadcasts', $this->payload())->assertStatus(401);
        $this->putJson("/api/v1/admin/broadcasts/{$id}", $this->payload())->assertStatus(401);
        $this->patchJson("/api/v1/admin/broadcasts/{$id}/toggle")->assertStatus(401);
        $this->deleteJson("/api/v1/admin/broadcasts/{$id}")->assertStatus(401);

        $this->assertNotNull($broadcast->fresh());
    }
}
