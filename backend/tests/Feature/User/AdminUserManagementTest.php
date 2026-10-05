<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Enums\UserRole;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET/PATCH/DELETE /api/v1/admin/users — freeze-table rows 35-38.
 *
 * The centrepiece is the DECISION #3 group: an admin may not deactivate their
 * own account.
 */
final class AdminUserManagementTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        $this->admin = $this->loginAsAdmin();
    }

    // ══ DECISION #3 — THE SELF-DEACTIVATION GUARD ═══════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ NEW BEHAVIOUR. backend/ ALLOWED THIS, AND IT WAS UNRECOVERABLE.      ║
     * ║                                                                      ║
     * ║ backend/UserService::update() compared nothing about the actor. An   ║
     * ║ admin PATCHing their OWN id with is_active:false wrote the column,   ║
     * ║ and AuthMiddleware then killed their session on the very next        ║
     * ║ request — with no route back in, because the only endpoint that can  ║
     * ║ re-enable the account requires an admin session. On a platform with  ║
     * ║ one admin that means direct database access to recover.              ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $this->patchJson("/api/v1/admin/users/{$this->admin->getKey()}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.is_active.0', UpdateUserRequest::SELF_DEACTIVATION_MESSAGE);

        $this->assertTrue(
            (bool) User::query()->findOrFail($this->admin->getKey())->is_active,
            'The guard must run BEFORE any write — the flag must be untouched.'
        );
    }

    /** The camelCase spelling is guarded identically. */
    public function test_the_guard_covers_the_camelCase_spelling_too(): void
    {
        $this->patchJson("/api/v1/admin/users/{$this->admin->getKey()}", ['isActive' => false])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.isActive.0', UpdateUserRequest::SELF_DEACTIVATION_MESSAGE);

        $this->assertTrue((bool) User::query()->findOrFail($this->admin->getKey())->is_active);
    }

    /** "0" is the wire form a form-encoded client sends; it must not slip past. */
    public function test_the_guard_catches_the_string_zero_form(): void
    {
        $this->patchJson("/api/v1/admin/users/{$this->admin->getKey()}", ['is_active' => '0'])
            ->assertStatus(422);

        $this->assertTrue((bool) User::query()->findOrFail($this->admin->getKey())->is_active);
    }

    /**
     * THE GUARD IS NARROW ON PURPOSE. It must not turn every self-edit into a
     * 422 — an admin renaming themselves is a perfectly ordinary request, and
     * `boolean()` alone (without a has() check) would read the absent key as
     * false and reject it.
     */
    public function test_an_admin_may_still_edit_their_own_other_fields(): void
    {
        $this->patchJson("/api/v1/admin/users/{$this->admin->getKey()}", ['full_name' => 'المدير الجديد'])
            ->assertStatus(200)
            ->assertJsonPath('data.full_name', 'المدير الجديد');
    }

    /** Nor may it block an admin from RE-activating themselves. */
    public function test_an_admin_may_set_their_own_account_active(): void
    {
        $this->patchJson("/api/v1/admin/users/{$this->admin->getKey()}", ['is_active' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true);
    }

    /** And it must not protect anyone else — deactivating OTHERS still works. */
    public function test_an_admin_may_deactivate_a_different_account(): void
    {
        $victim = $this->makeUser();

        $this->patchJson("/api/v1/admin/users/{$victim->getKey()}", ['is_active' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse((bool) User::query()->findOrFail($victim->getKey())->is_active);
    }

    /** Including a DIFFERENT admin — the guard is about self, not about role. */
    public function test_an_admin_may_deactivate_another_admin(): void
    {
        $other = $this->makeAdmin();

        $this->patchJson("/api/v1/admin/users/{$other->getKey()}", ['is_active' => false])
            ->assertStatus(200);

        $this->assertFalse((bool) User::query()->findOrFail($other->getKey())->is_active);
    }

    // ══ UPDATE — A FLAT ALLOW-LIST, UNLIKE TEACHER ══════════════════════

    public function test_only_the_three_allow_listed_fields_are_writable(): void
    {
        $target = $this->makeUser(['username' => 'original_name', 'role' => UserRole::Student]);

        $this->patchJson("/api/v1/admin/users/{$target->getKey()}", [
            'full_name' => 'اسم جديد',
            'email' => 'new@sanad.local',
            // Neither of these is in the allow-list and neither may be written.
            'username' => 'hijacked',
            'role' => 'admin',
        ])->assertStatus(200);

        $fresh = User::query()->findOrFail($target->getKey());

        $this->assertSame('اسم جديد', $fresh->full_name);
        $this->assertSame('new@sanad.local', $fresh->email);
        $this->assertSame('original_name', $fresh->username, 'username is not allow-listed.');
        $this->assertSame(UserRole::Student, $fresh->role, 'role is not allow-listed.');
    }

    /** Both spellings work; snake_case wins when both are sent. */
    public function test_snake_case_wins_when_both_spellings_are_sent(): void
    {
        $target = $this->makeUser();

        $this->patchJson("/api/v1/admin/users/{$target->getKey()}", [
            'is_active' => true,
            'isActive' => false,
        ])->assertStatus(200);

        $this->assertTrue((bool) User::query()->findOrFail($target->getKey())->is_active);
    }

    public function test_an_empty_payload_is_a_no_op(): void
    {
        $target = $this->makeUser(['full_name' => 'ثابت']);

        $this->patchJson("/api/v1/admin/users/{$target->getKey()}", [])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم التحديث بنجاح');

        $this->assertSame('ثابت', User::query()->findOrFail($target->getKey())->full_name);
    }

    // ══ LISTING ═════════════════════════════════════════════════════════

    public function test_the_role_filter_narrows_the_listing(): void
    {
        $this->makeInstructor();
        $this->makeUser(['role' => UserRole::Student]);

        $roles = collect(
            $this->getJson('/api/v1/admin/users?role=instructor')->assertStatus(200)->json('data.items')
        )->pluck('role')->unique();

        $this->assertSame(['instructor'], $roles->values()->all());
    }

    /**
     * An unrecognised role must match NOTHING, not everything. backend/ bound
     * the raw string into the query, where an unknown value harmlessly matched
     * no rows; the enum check makes that a property rather than a coincidence.
     */
    public function test_an_unknown_role_filter_returns_no_rows_rather_than_all_of_them(): void
    {
        $this->makeInstructor();

        $this->getJson('/api/v1/admin/users?role=wizard')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_soft_deleted_users_never_appear_in_the_listing(): void
    {
        $deleted = $this->makeUser();
        $deleted->delete();

        $ids = collect($this->getJson('/api/v1/admin/users')->json('data.items'))->pluck('id');

        $this->assertNotContains($deleted->getKey(), $ids);
    }

    /** per_page is CLAMPED — an unbounded value is a memory-exhaustion lever. */
    public function test_per_page_is_clamped_to_one_hundred(): void
    {
        $this->getJson('/api/v1/admin/users?per_page=1000000')
            ->assertStatus(200)
            ->assertJsonPath('data.per_page', 100);

        $this->getJson('/api/v1/admin/users?per_page=0')
            ->assertStatus(200)
            ->assertJsonPath('data.per_page', 1);

        $this->getJson('/api/v1/admin/users?page=-5')
            ->assertStatus(200)
            ->assertJsonPath('data.page', 1);
    }

    public function test_an_unknown_user_id_is_404_with_the_contract_message(): void
    {
        $this->getJson('/api/v1/admin/users/' . Str::uuid7())
            ->assertStatus(404)
            ->assertJsonPath('message', 'User not found');
    }

    // ══ DECISION #1 — RE-AUTH IS NEW ON THIS ENDPOINT TOO ═══════════════

    public function test_delete_without_a_password_is_401(): void
    {
        $target = $this->makeUser();

        $this->deleteJson("/api/v1/admin/users/{$target->getKey()}")
            ->assertStatus(401)
            ->assertJsonPath('message', 'كلمة مرور الإدارة العامة مطلوبة لتأكيد الحذف');

        $this->assertNotNull(User::query()->find($target->getKey()));
    }

    public function test_delete_with_the_wrong_password_is_401(): void
    {
        $target = $this->makeUser();

        $this->deleteJson("/api/v1/admin/users/{$target->getKey()}", ['password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'كلمة مرور الإدارة العامة غير صحيحة');
    }

    public function test_delete_succeeds_with_the_admins_own_password(): void
    {
        $target = $this->makeUser();

        $this->deleteJson("/api/v1/admin/users/{$target->getKey()}", $this->reauth())
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم الحذف بنجاح');

        $this->assertSoftDeleted('users', ['id' => $target->getKey()]);
    }

    public function test_the_password_is_accepted_from_the_header(): void
    {
        $target = $this->makeUser();

        $this->deleteJson(
            "/api/v1/admin/users/{$target->getKey()}",
            [],
            ['x-admin-password' => self::FIXTURE_PASSWORD]
        )->assertStatus(200);
    }

    // ══ ROLE GATE ═══════════════════════════════════════════════════════

    public function test_a_non_admin_cannot_reach_the_user_routes(): void
    {
        $this->post('/api/v1/auth/logout');
        $this->loginAs($this->makeInstructor());

        $this->getJson('/api/v1/admin/users')->assertStatus(403);
    }

    public function test_a_guest_gets_401(): void
    {
        $this->post('/api/v1/auth/logout');

        $this->getJson('/api/v1/admin/users')->assertStatus(401);
    }
}
