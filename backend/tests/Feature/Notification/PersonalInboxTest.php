<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * The personal inbox — freeze-table rows 107-108.
 *
 * These tests are the PROOF for approved decision 3.3: there is no policy, so
 * every guarantee below has to come from the query, and the only way to show
 * that is over HTTP with a real session.
 */
final class PersonalInboxTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    // ── OWN ROWS ONLY ─────────────────────────────────────────────────────

    public function test_the_inbox_returns_only_the_callers_own_notifications(): void
    {
        $me = $this->makeStudentUser();
        $someoneElse = $this->makeStudentUser();

        $mine = $this->makeNotification($me, ['title' => 'لي']);
        $this->makeNotification($someoneElse, ['title' => 'ليس لي']);

        $this->actingAs($me);

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $ids = array_column($response->json('data.notifications'), 'id');

        $this->assertSame([$mine->getKey()], $ids);
    }

    /** Every role has an inbox — the routes carry no `role:` middleware. */
    public function test_every_authenticated_role_has_an_inbox(): void
    {
        foreach ([$this->makeAdmin(), $this->makeInstructor(), $this->makeStudentUser()] as $user) {
            $this->makeNotification($user);

            $this->actingAs($user);

            $this->getJson('/api/v1/notifications')
                ->assertOk()
                ->assertJsonCount(1, 'data.notifications');
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ AN ADMIN HAS **NO** SPECIAL REACH INTO ANOTHER USER'S INBOX.         ║
     * ║                                                                      ║
     * ║ This is the single most important assertion in the file, and it is    ║
     * ║ the whole reason NotificationPolicy was deleted rather than built.    ║
     * ║ Had mark-read gone through the Gate, `Gate::before` would have        ║
     * ║ returned true here and the admin would have marked a stranger's row   ║
     * ║ read — a privilege backend/ never granted.                            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_an_admin_cannot_see_or_mark_another_users_notification(): void
    {
        $student = $this->makeStudentUser();
        $notification = $this->makeNotification($student);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data.notifications');

        $this->postJson("/api/v1/notifications/{$notification->getKey()}/mark-read")
            ->assertStatus(404);

        $this->assertFalse((bool) $notification->fresh()->getAttribute('is_read'));
    }

    // ── SESSION IDENTITY ONLY ─────────────────────────────────────────────

    /**
     * `?user_id=` and a body `user_id` are SILENTLY IGNORED, not rejected.
     *
     * The acting identity is `$request->user()`, full stop. There is nothing
     * to reject because nothing reads the key — and asserting that the foreign
     * user's rows do NOT appear is what proves it, since a 200 alone would
     * also be produced by an endpoint that honoured the parameter and happened
     * to find the caller's rows too.
     */
    public function test_a_user_id_query_parameter_is_ignored_on_the_list(): void
    {
        $me = $this->makeStudentUser();
        $victim = $this->makeStudentUser();

        $this->makeNotification($me, ['title' => 'لي']);
        $this->makeNotification($victim, ['title' => 'ضحية']);

        $this->actingAs($me);

        $titles = array_column(
            $this->getJson('/api/v1/notifications?user_id=' . $victim->getKey())
                ->assertOk()
                ->json('data.notifications'),
            'title'
        );

        $this->assertSame(['لي'], $titles);
    }

    public function test_a_user_id_in_the_body_is_ignored_on_mark_read(): void
    {
        $me = $this->makeStudentUser();
        $victim = $this->makeStudentUser();

        $victimRow = $this->makeNotification($victim);

        $this->actingAs($me);

        $this->postJson(
            "/api/v1/notifications/{$victimRow->getKey()}/mark-read",
            ['user_id' => $victim->getKey()]
        )->assertStatus(404);

        $this->assertFalse((bool) $victimRow->fresh()->getAttribute('is_read'));
    }

    // ── ORDERING AND THE 100-ROW CAP ──────────────────────────────────────

    public function test_notifications_come_back_newest_first(): void
    {
        $me = $this->makeStudentUser();

        $old = $this->makeNotification($me, ['title' => 'قديم', 'created_at' => now()->subDays(3)]);
        $new = $this->makeNotification($me, ['title' => 'جديد', 'created_at' => now()]);
        $middle = $this->makeNotification($me, ['title' => 'وسط', 'created_at' => now()->subDay()]);

        $this->actingAs($me);

        $ids = array_column(
            $this->getJson('/api/v1/notifications')->assertOk()->json('data.notifications'),
            'id'
        );

        $this->assertSame([$new->getKey(), $middle->getKey(), $old->getKey()], $ids);
    }

    /**
     * The cap is 100 AND it keeps the NEWEST 100 — a `limit` applied without
     * the ordering would satisfy the count and silently return the oldest.
     */
    public function test_the_inbox_is_capped_at_the_hundred_newest_rows(): void
    {
        $me = $this->makeStudentUser();

        for ($i = 0; $i < 105; $i++) {
            $this->makeNotification($me, [
                'title' => "n{$i}",
                'created_at' => now()->subMinutes(200 - $i),
            ]);
        }

        $this->actingAs($me);

        $rows = $this->getJson('/api/v1/notifications')->assertOk()->json('data.notifications');

        $this->assertCount(100, $rows);
        $this->assertSame('n104', $rows[0]['title']);
        $this->assertSame('n5', $rows[99]['title']);
    }

    // ── SHAPE ─────────────────────────────────────────────────────────────

    /**
     * The column is `body`; the key is `message`. Emitting `body` would blank
     * every notification in the bell menu.
     */
    public function test_the_message_field_is_named_message_and_not_body(): void
    {
        $me = $this->makeStudentUser();
        $this->makeNotification($me, ['body' => 'النص الحقيقي', 'link' => '/courses/1']);

        $this->actingAs($me);

        $row = $this->getJson('/api/v1/notifications')->assertOk()->json('data.notifications.0');

        $this->assertSame(
            ['id', 'user_id', 'title', 'message', 'link', 'is_read', 'created_at'],
            array_keys($row)
        );
        $this->assertSame('النص الحقيقي', $row['message']);
        $this->assertArrayNotHasKey('body', $row);
        $this->assertSame('/courses/1', $row['link']);
        $this->assertFalse($row['is_read']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['created_at']);
    }

    // ── MARK READ ─────────────────────────────────────────────────────────

    public function test_marking_ones_own_notification_read_returns_200_and_persists(): void
    {
        $me = $this->makeStudentUser();
        $notification = $this->makeNotification($me);

        $this->actingAs($me);

        $this->postJson("/api/v1/notifications/{$notification->getKey()}/mark-read")
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديد الإشعار كمقروء')
            ->assertJsonPath('data.notification.is_read', true);

        $this->assertTrue((bool) $notification->fresh()->getAttribute('is_read'));
    }

    /** Idempotent: the bell menu re-sends freely. */
    public function test_marking_read_twice_is_not_an_error(): void
    {
        $me = $this->makeStudentUser();
        $notification = $this->makeNotification($me, ['is_read' => true]);

        $this->actingAs($me);

        $this->postJson("/api/v1/notifications/{$notification->getKey()}/mark-read")->assertOk();
        $this->postJson("/api/v1/notifications/{$notification->getKey()}/mark-read")->assertOk();

        $this->assertTrue((bool) $notification->fresh()->getAttribute('is_read'));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ **404**, NOT backend/'s 403. THE APPROVED BEHAVIOUR CHANGE.          ║
     * ║                                                                      ║
     * ║ And the assertion is deliberately that a FOREIGN row and a           ║
     * ║ NONEXISTENT id produce the IDENTICAL answer — status and message      ║
     * ║ both. That indistinguishability is the property; a test that only     ║
     * ║ checked `assertStatus(404)` on one of them would still pass if the    ║
     * ║ two cases carried different messages, which is an existence oracle    ║
     * ║ with extra steps.                                                     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_marking_someone_elses_notification_is_404_and_writes_nothing(): void
    {
        $me = $this->makeStudentUser();
        $victim = $this->makeStudentUser();
        $theirs = $this->makeNotification($victim);

        $this->actingAs($me);

        $foreign = $this->postJson("/api/v1/notifications/{$theirs->getKey()}/mark-read")
            ->assertStatus(404);

        $ghost = $this->postJson('/api/v1/notifications/0195c1a2-3b4c-7d8e-9f00-112233445566/mark-read')
            ->assertStatus(404);

        $this->assertSame($foreign->json('message'), $ghost->json('message'));
        $this->assertSame('Notification not found', $foreign->json('message'));

        $this->assertFalse((bool) $theirs->fresh()->getAttribute('is_read'));
    }

    public function test_a_malformed_id_is_404_not_500(): void
    {
        $this->actingAs($this->makeStudentUser());

        $this->postJson('/api/v1/notifications/not-a-uuid/mark-read')->assertStatus(404);
    }

    // ── GUESTS ────────────────────────────────────────────────────────────

    public function test_a_guest_gets_401_on_both_inbox_routes(): void
    {
        $notification = $this->makeNotification($this->makeStudentUser());

        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->postJson("/api/v1/notifications/{$notification->getKey()}/mark-read")->assertStatus(401);

        $this->assertFalse((bool) $notification->fresh()->getAttribute('is_read'));
    }

    /**
     * The relation compiles `user_id = ?` into the statement itself — asserted
     * at the QUERY level as well as over HTTP, because the HTTP assertions
     * above would also pass if the controller filtered in PHP after fetching
     * every row in the table.
     */
    public function test_the_relation_scopes_the_sql_not_the_php(): void
    {
        $me = $this->makeStudentUser();

        $sql = $me->notifications()->visible()->toSql();

        $this->assertStringContainsString('`user_id` = ?', $sql);
        $this->assertStringContainsString('`broadcast_id` is null', $sql);
    }

    /** Sanity: the model really does have created_at and no updated_at. */
    public function test_the_model_stamps_created_at_without_an_updated_at_column(): void
    {
        $notification = $this->makeNotification($this->makeStudentUser());

        $this->assertNotNull($notification->getAttribute('created_at'));
        $this->assertNull(Notification::UPDATED_AT);
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('notifications', 'updated_at'),
            'If this column is ever added, Notification::UPDATED_AT must stop being null.'
        );
    }
}
