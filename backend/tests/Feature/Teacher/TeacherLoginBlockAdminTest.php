<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * THE ADMIN SIDE OF `teachers.login_blocked` — the write path, its presence
 * semantics, its independence from `isActive`, and its role gate.
 *
 * The login CONSEQUENCE lives in Tests\Feature\Auth\TeacherLoginBlockTest; this
 * file is about the column being written correctly and about the new field
 * inheriting — exactly, not approximately — the rules that
 * App\Actions\Teacher\UpdateTeacher's docblock lays out for `isActive`:
 *
 *   absent from the payload  →  UNTOUCHED   (never reset to false)
 *   explicit false           →  WRITTEN     (presence, not truthiness)
 *
 * The second row is the one that breaks silently. `$request->boolean()` alone
 * returns false for a MISSING key, so an implementation that skipped has()
 * would pass the "block" direction and quietly unblock every teacher on any
 * unrelated profile save — a 200 with the wrong column written, which no other
 * test in this suite would notice.
 *
 * INDEPENDENCE FROM users.is_active IS ASSERTED IN BOTH DIRECTIONS. They are
 * two switches with one row shape in the matrix and nothing else in common; a
 * payload naming one must leave the other exactly as it was.
 */
final class TeacherLoginBlockAdminTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $teacherUser;

    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        [$this->teacherUser, $this->teacher] = $this->makeTeacherWithProfile();

        $this->loginAsAdmin();
    }

    private function patchTeacher(array $payload): void
    {
        $this->patchJson("/api/v1/admin/teachers/{$this->teacher->getKey()}", $payload)
            ->assertStatus(200);
    }

    private function freshTeacher(): Teacher
    {
        return Teacher::query()->findOrFail($this->teacher->getKey());
    }

    private function isBlocked(): bool
    {
        return (bool) $this->freshTeacher()->getAttribute('login_blocked');
    }

    // ══ THE WRITE ═══════════════════════════════════════════════════════

    public function test_an_admin_can_block_a_teachers_login(): void
    {
        $this->patchTeacher(['loginBlocked' => true]);

        $this->assertTrue($this->isBlocked());
    }

    /** The unblock direction — a FALSE that must actually be written. */
    public function test_an_admin_can_unblock_a_previously_blocked_teacher(): void
    {
        $this->teacher->forceFill(['login_blocked' => true])->save();

        $this->patchTeacher(['loginBlocked' => false]);

        $this->assertFalse($this->isBlocked());
    }

    // ══ PRESENCE, NOT TRUTHINESS ════════════════════════════════════════

    /**
     * The regression this whole field-by-field style exists to prevent: a save
     * that never mentions the flag must not silently unblock the account.
     */
    public function test_a_payload_that_omits_the_field_leaves_it_untouched(): void
    {
        $this->teacher->forceFill(['login_blocked' => true])->save();

        $this->patchTeacher(['bio' => 'نبذة محدثة']);

        $this->assertTrue($this->isBlocked(), 'An unrelated edit must not clear login_blocked.');
        $this->assertSame('نبذة محدثة', $this->freshTeacher()->getAttribute('bio'));
    }

    /** The mirror: an unrelated edit must not BLOCK an unblocked teacher. */
    public function test_an_unrelated_edit_does_not_block_an_allowed_teacher(): void
    {
        $this->patchTeacher(['bio' => 'نبذة أخرى']);

        $this->assertFalse($this->isBlocked());
    }

    // ══ INDEPENDENCE FROM SUSPENSION ════════════════════════════════════

    public function test_blocking_login_does_not_touch_users_is_active(): void
    {
        $this->patchTeacher(['loginBlocked' => true]);

        $this->assertTrue(
            (bool) User::query()->findOrFail($this->teacherUser->getKey())->getAttribute('is_active'),
            'login_blocked must never write users.is_active — that is suspension, a different feature.'
        );
    }

    public function test_suspending_does_not_touch_login_blocked(): void
    {
        $this->patchTeacher(['isActive' => false]);

        $this->assertFalse($this->isBlocked());
    }

    /** Both in one payload: two switches, two writes, no interference. */
    public function test_both_flags_can_be_set_in_one_request(): void
    {
        $this->patchTeacher(['isActive' => false, 'loginBlocked' => true]);

        $this->assertTrue($this->isBlocked());
        $this->assertFalse(
            (bool) User::query()->findOrFail($this->teacherUser->getKey())->getAttribute('is_active')
        );
    }

    // ══ THE ADMIN RESOURCE ══════════════════════════════════════════════

    public function test_the_admin_resource_exposes_the_flag(): void
    {
        $this->patchTeacher(['loginBlocked' => true]);

        $row = collect($this->getJson('/api/v1/admin/teachers')->json('data.items'))
            ->firstWhere('teacher_id', $this->teacher->getKey());

        $this->assertNotNull($row);
        $this->assertTrue($row['loginBlocked']);
    }

    // ══ CREATE-TIME ═════════════════════════════════════════════════════

    public function test_a_teacher_can_be_created_already_blocked(): void
    {
        $this->postJson('/api/v1/admin/teachers', [
            'username' => 'preblocked',
            'fullName' => 'مدرس ممنوع مسبقاً',
            'password' => 'ValidPassword123',
            'loginBlocked' => true,
        ])->assertStatus(201);

        $created = User::query()->where('username', 'preblocked')->firstOrFail();

        $this->assertTrue(
            (bool) Teacher::query()->findOrFail($created->getKey())->getAttribute('login_blocked')
        );
    }

    public function test_an_ordinary_create_leaves_the_teacher_able_to_log_in(): void
    {
        $this->postJson('/api/v1/admin/teachers', [
            'username' => 'ordinary01',
            'fullName' => 'مدرس عادي',
            'password' => 'ValidPassword123',
        ])->assertStatus(201);

        $created = User::query()->where('username', 'ordinary01')->firstOrFail();

        $this->assertFalse(
            (bool) Teacher::query()->findOrFail($created->getKey())->getAttribute('login_blocked')
        );
    }

    // ══ ROLE GATE ═══════════════════════════════════════════════════════

    /**
     * No new middleware — the route already sits behind `role:admin`. Asserted
     * anyway, because "only an admin can lock a colleague out of their account"
     * is a claim this feature makes and inherits rather than implements.
     */
    public function test_a_non_admin_cannot_toggle_the_flag(): void
    {
        $this->app['auth']->forgetGuards();
        $this->loginAs($this->teacherUser);

        $this->patchJson(
            "/api/v1/admin/teachers/{$this->teacher->getKey()}",
            ['loginBlocked' => true]
        )->assertStatus(403);

        $this->assertFalse($this->isBlocked());
    }

    public function test_a_guest_cannot_toggle_the_flag(): void
    {
        $this->app['auth']->forgetGuards();
        $this->post('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();

        $this->patchJson(
            "/api/v1/admin/teachers/{$this->teacher->getKey()}",
            ['loginBlocked' => true]
        )->assertStatus(401);
    }

    // ══ VALIDATION ══════════════════════════════════════════════════════

    public function test_a_non_boolean_value_is_rejected(): void
    {
        $this->patchJson(
            "/api/v1/admin/teachers/{$this->teacher->getKey()}",
            ['loginBlocked' => 'maybe']
        )->assertStatus(422);
    }
}
