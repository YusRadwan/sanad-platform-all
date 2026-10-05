<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\UserRole;
use App\Models\Teacher;
use App\Models\TeacherSocialLink;
use App\Models\User;
use App\Rules\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Teacher create / read / delete. The UPDATE semantics live in their own file
 * (TeacherUpdateSemanticsTest) because they are the risk concentration of the
 * whole stage.
 */
final class TeacherCrudTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'teacher' . bin2hex(random_bytes(3)),
            'fullName' => 'مدرس جديد',
            'password' => 'ValidPassword123',
        ], $overrides);
    }

    // ══ DECISION #2 — THE UNIFIED PASSWORD POLICY ════════════════════════

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE DELIBERATE BEHAVIOUR CHANGE, ASSERTED.                           │
     * │                                                                      │
     * │ backend/TeacherService::create() validated `required|min:8`. This    │
     * │ exact payload — 8 characters, a letter and a digit — SUCCEEDED there │
     * │ and creates an instructor account with content-owner privileges. It  │
     * │ is now a 422 carrying PasswordPolicy's own sentence.                 │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_an_eight_character_password_is_now_rejected(): void
    {
        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/teachers', $this->validPayload(['password' => 'Abcd1234']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.password.0', PasswordPolicy::describe());

        $this->assertSame(0, Teacher::query()->count());
    }

    /** Twelve characters but no digit — the composition half of the policy. */
    public function test_a_letters_only_password_is_rejected(): void
    {
        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/teachers', $this->validPayload(['password' => 'abcdefghijkl']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.password.0', PasswordPolicy::describe());
    }

    /**
     * DECISION #2, SECOND HALF: backend/ reset teacher passwords with NO
     * validation at all — "1" was a valid new instructor password.
     */
    public function test_a_weak_password_is_rejected_on_reset_too(): void
    {
        [, $teacher] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        $this->patchJson("/api/v1/admin/teachers/{$teacher->getKey()}", ['password' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.password.0', PasswordPolicy::describe());
    }

    // ══ CREATE ══════════════════════════════════════════════════════════

    public function test_it_creates_the_user_and_the_profile_together(): void
    {
        $this->loginAsAdmin();

        $payload = $this->validPayload([
            'phone' => '01099998888',
            'specialization' => 'الفيزياء',
            'subjects' => ['فيزياء'],
            'grades' => ['الصف الثاني الثانوي'],
        ]);

        $response = $this->postJson('/api/v1/admin/teachers', $payload)
            ->assertStatus(201)
            ->assertJsonPath('message', 'تم إضافة المدرس بنجاح');

        $teacherId = $response->json('data.teacher_id');

        $user = User::query()->findOrFail($teacherId);

        $this->assertSame(UserRole::Instructor, $user->role);
        $this->assertTrue((bool) $user->is_active);
        $this->assertTrue(Hash::check('ValidPassword123', $user->getAuthPassword()));

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->assertSame('01099998888', $teacher->phone);
        $this->assertSame(['فيزياء'], $teacher->subjects);
    }

    /**
     * The admin form has no email input, so the client always posts the
     * derived address — and the server applies the identical default when the
     * field arrives empty, so both sides agree on one real address.
     */
    public function test_the_email_defaults_to_username_at_sanad_edu(): void
    {
        $this->loginAsAdmin();

        $payload = $this->validPayload(['username' => 'ostaz_ali', 'email' => '']);

        $id = $this->postJson('/api/v1/admin/teachers', $payload)
            ->assertStatus(201)
            ->json('data.teacher_id');

        $this->assertSame('ostaz_ali@sanad.edu', User::query()->findOrFail($id)->email);
    }

    public function test_a_duplicate_username_is_rejected_with_the_legacy_message(): void
    {
        $existing = $this->makeUser(['username' => 'taken_name']);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/teachers', $this->validPayload(['username' => 'taken_name']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.username.0', 'اسم المستخدم مستخدم بالفعل');

        $this->assertSame($existing->getKey(), User::query()->where('username', 'taken_name')->first()?->getKey());
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ ATOMICITY — THE ORPHANED-USER BUG, ASSERTED.                        │
     * │                                                                      │
     * │ A hostile social-link url raises a ValidationException from INSIDE   │
     * │ the transaction, after the `users` row has already been inserted. If │
     * │ the transaction were removed, that row would survive: an account     │
     * │ with role='instructor', no `teachers` profile, and a username the    │
     * │ admin can never reuse. This asserts the rollback, not the 422.       │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_failed_create_leaves_no_orphaned_user_row(): void
    {
        $this->loginAsAdmin();

        $payload = $this->validPayload([
            'username' => 'will_be_rolled_back',
            'socialLinks' => [
                ['platform' => 'facebook', 'url' => 'javascript:alert(1)'],
            ],
        ]);

        $this->postJson('/api/v1/admin/teachers', $payload)->assertStatus(422);

        $this->assertNull(
            User::query()->where('username', 'will_be_rolled_back')->first(),
            'The users insert must roll back with the failed teachers/links write.'
        );
        $this->assertSame(0, Teacher::query()->count());
    }

    /**
     * TWO DIFFERENT FAILURE MODES, verified against backend/'s ACTUAL code
     * rather than its comment: an entry with no url is DROPPED and the request
     * still succeeds, while an UNSAFE url fails the whole request.
     */
    public function test_an_entry_with_no_url_is_dropped_without_failing_the_request(): void
    {
        $this->loginAsAdmin();

        $id = $this->postJson('/api/v1/admin/teachers', $this->validPayload([
            'socialLinks' => [
                ['platform' => 'facebook', 'url' => 'https://fb.example/ok'],
                ['platform' => 'youtube', 'url' => ''],
                ['platform' => '', 'url' => 'https://x.example/no-platform'],
            ],
        ]))->assertStatus(201)->json('data.teacher_id');

        $links = TeacherSocialLink::query()->where('teacher_id', $id)->get();

        $this->assertCount(1, $links);
        $this->assertSame('facebook', $links->first()->platform);
    }

    /** @dataProvider unsafeUrls */
    public function test_an_unsafe_social_url_fails_the_whole_request(string $url): void
    {
        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/teachers', $this->validPayload([
            'socialLinks' => [['platform' => 'facebook', 'url' => $url]],
        ]))->assertStatus(422);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeUrls(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,<script>x</script>'],
            'protocol relative' => ['//evil.example/x'],
            'bare host' => ['evil.example'],
            'control character bypass' => ["java\tscript:alert(1)"],
        ];
    }

    /**
     * `whatsappNumbers` (the camelCase request key) has no column of that
     * exact name — it is persisted under `whatsapp_support_numbers` (see
     * migration 000036), never as a literal `whatsappNumbers`/`whatsapp_numbers`
     * attribute. Teacher::$fillable's explicit allow-list is what makes
     * accepting arbitrary request keys safe by construction.
     */
    public function test_whatsappNumbers_is_accepted_and_never_persisted_under_its_request_key(): void
    {
        $this->loginAsAdmin();

        $id = $this->postJson('/api/v1/admin/teachers', $this->validPayload([
            'whatsappNumbers' => ['01000000000'],
        ]))->assertStatus(201)->json('data.teacher_id');

        $columns = array_keys(Teacher::query()->findOrFail($id)->getAttributes());

        $this->assertNotContains('whatsappNumbers', $columns);
        $this->assertNotContains('whatsapp_numbers', $columns);
    }

    // ══ READ ════════════════════════════════════════════════════════════

    public function test_the_listing_carries_social_links_for_every_row(): void
    {
        [, $teacher] = $this->makeTeacherWithProfile();
        $teacher->socialLinks()->create([
            'platform' => 'facebook', 'url' => 'https://fb.example/x',
            'enabled' => true, 'order_index' => 0,
        ]);

        $this->loginAsAdmin();

        // ManageTeachers.tsx hands a listing row straight to AddTeacher.tsx as
        // `editTeacher`, and that form restores its toggles from
        // `editTeacher.socialLinks`. A listing without them silently wipes the
        // teacher's links on the next save.
        $this->getJson('/api/v1/admin/teachers')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.socialLinks.0.platform', 'facebook');
    }

    /**
     * The `teachers` row survives a delete by design, so a bare find() would
     * return it and the presenter would dereference a null `user`.
     * Teacher::scopeWithLiveUser() is what makes this a 404 instead.
     */
    public function test_a_soft_deleted_teacher_is_a_404_even_though_the_profile_row_remains(): void
    {
        [$user, $teacher] = $this->makeTeacherWithProfile();
        $user->delete();

        $this->loginAsAdmin();

        $this->getJson("/api/v1/admin/teachers/{$teacher->getKey()}")->assertStatus(404);

        $this->assertNotNull(
            Teacher::query()->find($teacher->getKey()),
            'The profile row is deliberately left intact — only users.deleted_at is written.'
        );
    }

    public function test_an_unknown_teacher_id_is_a_404_with_the_contract_message(): void
    {
        $this->loginAsAdmin();

        $this->getJson('/api/v1/admin/teachers/' . Str::uuid7())
            ->assertStatus(404)
            ->assertJsonPath('message', 'Teacher not found');
    }

    // ══ DELETE + DECISION #1 RE-AUTHENTICATION ══════════════════════════

    public function test_delete_without_a_password_is_401_not_422(): void
    {
        [, $teacher] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        $this->deleteJson("/api/v1/admin/teachers/{$teacher->getKey()}")
            ->assertStatus(401)
            ->assertJsonPath('message', 'كلمة مرور الإدارة العامة مطلوبة لتأكيد الحذف');

        $this->assertNotNull(User::query()->find($teacher->getKey()));
    }

    public function test_delete_with_the_wrong_password_is_401(): void
    {
        [, $teacher] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        $this->deleteJson("/api/v1/admin/teachers/{$teacher->getKey()}", ['password' => 'not-my-password'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'كلمة مرور الإدارة العامة غير صحيحة');

        $this->assertNotNull(User::query()->find($teacher->getKey()));
    }

    /**
     * It is the ACTOR's password, never the target's. Comparing against the
     * account being deleted would be useless (the admin does not know it) and
     * a disclosure oracle.
     */
    public function test_the_targets_own_password_does_not_authorise_the_delete(): void
    {
        [$targetUser, $teacher] = $this->makeTeacherWithProfile();
        $targetUser->forceFill(['password_hash' => Hash::make('TargetPassword99')])->save();

        $this->loginAsAdmin();

        $this->deleteJson("/api/v1/admin/teachers/{$teacher->getKey()}", ['password' => 'TargetPassword99'])
            ->assertStatus(401);
    }

    public function test_delete_succeeds_with_the_admins_own_password(): void
    {
        [, $teacher] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        $this->deleteJson("/api/v1/admin/teachers/{$teacher->getKey()}", $this->reauth())
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم حذف المدرس بنجاح');

        $this->assertSoftDeleted('users', ['id' => $teacher->getKey()]);
    }

    /** adminService.deleteTeacher() sends the password BOTH ways; either works. */
    public function test_the_password_is_accepted_from_the_header(): void
    {
        [, $teacher] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        $this->deleteJson(
            "/api/v1/admin/teachers/{$teacher->getKey()}",
            [],
            ['x-admin-password' => self::FIXTURE_PASSWORD]
        )->assertStatus(200);

        $this->assertSoftDeleted('users', ['id' => $teacher->getKey()]);
    }

    // ══ ROLE GATE ═══════════════════════════════════════════════════════

    public function test_a_non_admin_cannot_reach_the_admin_teacher_routes(): void
    {
        $instructor = $this->makeInstructor();
        $this->loginAs($instructor);

        $this->getJson('/api/v1/admin/teachers')->assertStatus(403);
        $this->postJson('/api/v1/admin/teachers', $this->validPayload())->assertStatus(403);
    }

    public function test_a_guest_cannot_reach_the_admin_teacher_routes(): void
    {
        $this->getJson('/api/v1/admin/teachers')->assertStatus(401);
    }
}
