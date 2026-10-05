<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Notification::scopeVisible() — the READ-TIME gate that makes enable/disable
 * and scheduling work without a cron job and without deleting anything.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ EVERY TEST HERE ASSERTS **BOTH ROUTES**, AND THAT IS THE POINT.          ║
 * ║                                                                          ║
 * ║ backend/ applied this gate to the LIST query only. mark-read looked the   ║
 * ║ row up globally, so a recipient could mark-read a notification that GET   ║
 * ║ /notifications was simultaneously telling them did not exist. Stage 8     ║
 * ║ made it a scope and applied it to both, so asserting only the list would  ║
 * ║ leave the half that was actually broken untested.                        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class BroadcastVisibilityTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\BroadcastNotification, 2: Notification} */
    private function delivered(array $broadcastAttributes = []): array
    {
        $admin = $this->makeAdmin();
        $recipient = $this->makeInstructor();

        $broadcast = $this->deliverBroadcastTo($admin, [$recipient], $broadcastAttributes);

        $notification = Notification::query()
            ->where('broadcast_id', $broadcast->getKey())
            ->firstOrFail();

        return [$recipient, $broadcast, $notification];
    }

    private function assertVisibleTo(\App\Models\User $user, Notification $notification, bool $expected): void
    {
        $this->actingAs($user);

        $ids = array_column(
            $this->getJson('/api/v1/notifications')->assertOk()->json('data.notifications'),
            'id'
        );

        $this->assertSame(
            $expected,
            \in_array($notification->getKey(), $ids, true),
            $expected ? 'Expected the notification to be listed.' : 'Expected the notification to be hidden.'
        );

        // …and mark-read must agree. A row that is invisible is 404, not 200.
        $this->postJson("/api/v1/notifications/{$notification->getKey()}/mark-read")
            ->assertStatus($expected ? 200 : 404);
    }

    // ── THE FOUR WINDOW STATES ────────────────────────────────────────────

    public function test_a_disabled_broadcast_is_invisible_to_all_its_recipients(): void
    {
        $admin = $this->makeAdmin();
        $one = $this->makeInstructor();
        $two = $this->makeInstructor();

        $broadcast = $this->deliverBroadcastTo($admin, [$one, $two], ['is_active' => false]);

        // The rows still EXIST — hiding is not deleting. That is what lets the
        // toggle test below restore is_read state.
        $this->assertSame(2, Notification::query()->where('broadcast_id', $broadcast->getKey())->count());

        foreach ([$one, $two] as $recipient) {
            $row = Notification::query()
                ->where('broadcast_id', $broadcast->getKey())
                ->where('user_id', $recipient->getKey())
                ->firstOrFail();

            $this->assertVisibleTo($recipient, $row, false);
        }
    }

    public function test_a_future_scheduled_broadcast_is_invisible_until_it_starts(): void
    {
        [$recipient, , $notification] = $this->delivered(['start_at' => now()->addDay()]);

        $this->assertVisibleTo($recipient, $notification, false);
    }

    public function test_a_broadcast_past_its_end_at_is_invisible(): void
    {
        [$recipient, , $notification] = $this->delivered([
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDay(),
        ]);

        $this->assertVisibleTo($recipient, $notification, false);
    }

    /** end_at NULL means "no expiry", not "expired". */
    public function test_a_broadcast_with_a_null_end_at_stays_visible_indefinitely(): void
    {
        [$recipient, , $notification] = $this->delivered([
            'start_at' => now()->subYears(5),
            'end_at' => null,
        ]);

        $this->assertVisibleTo($recipient, $notification, true);
    }

    /** The boundary is inclusive on both sides: `start_at <= now <= end_at`. */
    public function test_the_window_boundaries_are_inclusive(): void
    {
        [$recipient, , $notification] = $this->delivered([
            'start_at' => now()->subSecond(),
            'end_at' => now()->addSecond(),
        ]);

        $this->assertVisibleTo($recipient, $notification, true);
    }

    // ── PLAIN NOTIFICATIONS ARE NEVER GATED ───────────────────────────────

    /**
     * `broadcast_id IS NULL` short-circuits the whole gate. Asserted with a
     * DISABLED, EXPIRED broadcast sitting in the same inbox, so the test fails
     * if the scope ever loses its `orWhere` grouping and starts applying the
     * broadcast predicate to every row.
     */
    public function test_a_plain_notification_is_visible_regardless_of_any_broadcast_state(): void
    {
        $admin = $this->makeAdmin();
        $recipient = $this->makeInstructor();

        $this->deliverBroadcastTo($admin, [$recipient], [
            'is_active' => false,
            'start_at' => now()->subYear(),
            'end_at' => now()->subMonth(),
        ]);

        $plain = $this->makeNotification($recipient, ['title' => 'شخصي']);

        $this->actingAs($recipient);

        $rows = $this->getJson('/api/v1/notifications')->assertOk()->json('data.notifications');

        $this->assertCount(1, $rows);
        $this->assertSame($plain->getKey(), $rows[0]['id']);
    }

    // ── THE DISABLE / RE-ENABLE CYCLE ─────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE TEST THE WHOLE DESIGN EXISTS FOR.                                 ║
     * ║                                                                      ║
     * ║ read -> disable -> invisible (and mark-read 404s) -> re-enable ->      ║
     * ║ visible again WITH `is_read` EXACTLY AS IT WAS.                        ║
     * ║                                                                      ║
     * ║ A delete-and-recreate toggle would pass every OTHER test in this file ║
     * ║ and fail only this one, silently marking every previously-read        ║
     * ║ notification unread on each cycle.                                    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_disabling_and_re_enabling_preserves_is_read_exactly(): void
    {
        $admin = $this->makeAdmin();
        $readIt = $this->makeInstructor();
        $didNot = $this->makeInstructor();

        $broadcast = $this->deliverBroadcastTo($admin, [$readIt, $didNot]);

        $rowFor = fn (\App\Models\User $u): Notification => Notification::query()
            ->where('broadcast_id', $broadcast->getKey())
            ->where('user_id', $u->getKey())
            ->firstOrFail();

        // One recipient reads it, the other does not.
        $this->actingAs($readIt);
        $this->postJson("/api/v1/notifications/{$rowFor($readIt)->getKey()}/mark-read")->assertOk();

        $this->assertTrue((bool) $rowFor($readIt)->getAttribute('is_read'));
        $this->assertFalse((bool) $rowFor($didNot)->getAttribute('is_read'));

        // DISABLE — through the real endpoint, not by editing the model.
        $this->actingAs($admin);
        $this->patchJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}/toggle", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('message', 'تم إيقاف وتعطيل التنبيه بنجاح');

        $this->assertVisibleTo($readIt, $rowFor($readIt), false);
        $this->assertVisibleTo($didNot, $rowFor($didNot), false);

        // The rows are still there, untouched.
        $this->assertSame(2, Notification::query()->where('broadcast_id', $broadcast->getKey())->count());

        // RE-ENABLE.
        $this->actingAs($admin);
        $this->patchJson("/api/v1/admin/broadcasts/{$broadcast->getKey()}/toggle", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('message', 'تم إعادة تفعيل بث التنبيه');

        $this->assertTrue(
            (bool) $rowFor($readIt)->getAttribute('is_read'),
            'A disable/enable cycle must not lose a recipient\'s read state.'
        );
        $this->assertFalse(
            (bool) $rowFor($didNot)->getAttribute('is_read'),
            'And must not invent one either.'
        );

        // Visible again for both.
        $this->actingAs($readIt);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data.notifications');
    }

    /**
     * A hidden row cannot be marked read AT ALL — not even by its rightful
     * owner. This is the half backend/ got wrong: its markRead() consulted no
     * visibility gate, so this call would have returned 200 and flipped a flag
     * on a notification the same session was told did not exist.
     */
    public function test_mark_read_on_a_hidden_broadcast_row_is_404_for_its_own_recipient(): void
    {
        [$recipient, , $notification] = $this->delivered(['is_active' => false]);

        $this->actingAs($recipient);

        $this->postJson("/api/v1/notifications/{$notification->getKey()}/mark-read")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Notification not found');

        $this->assertFalse((bool) $notification->fresh()->getAttribute('is_read'));
    }

    // ── THE CASCADE ───────────────────────────────────────────────────────

    /**
     * ON DELETE CASCADE, asserted AT THE DATABASE and with no HTTP involved —
     * because the guarantee belongs to the FK, not to any code path.
     */
    public function test_deleting_a_broadcast_row_cascades_to_its_notifications_at_the_database_level(): void
    {
        $admin = $this->makeAdmin();
        $recipients = [
            $this->makeInstructor(), $this->makeInstructor(), $this->makeInstructor(),
            $this->makeInstructor(), $this->makeInstructor(),
        ];

        $broadcast = $this->deliverBroadcastTo($admin, $recipients);
        $unrelated = $this->makeNotification($recipients[0], ['title' => 'شخصي']);

        $before = Notification::query()->count();
        $this->assertSame(6, $before);

        // Raw DELETE via the query builder: no model events, no application
        // cascade, nothing but the statement and the foreign key.
        \Illuminate\Support\Facades\DB::table('broadcast_notifications')
            ->where('id', $broadcast->getKey())
            ->delete();

        $this->assertSame($before - 5, Notification::query()->count());
        $this->assertNotNull($unrelated->fresh(), 'A plain notification must survive.');
    }
}
