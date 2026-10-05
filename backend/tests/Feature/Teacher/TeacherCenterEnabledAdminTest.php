<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * "زر تفعيل نظام السنتر" (teachers.center_enabled, migration
 * 2026_09_10_000002) — the admin write path, mirroring
 * TeacherLoginBlockAdminTest's shape for the same reason: this is a THIRD
 * presence-not-truthiness boolean on the same PATCH endpoint, and the
 * failure mode that matters is identical — `boolean()` without `has()` in
 * front would silently re-enable/disable every teacher on any unrelated
 * profile save.
 *
 * The actual GATE (a disabled teacher's requests 403ing on /center/*) is
 * covered by CenterSystemTest — this file is only about the column being
 * written correctly and staying independent of isActive/loginBlocked.
 */
final class TeacherCenterEnabledAdminTest extends TestCase
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

    private function isEnabled(): bool
    {
        return (bool) $this->freshTeacher()->getAttribute('center_enabled');
    }

    // ══ Existing teachers default to enabled (schema default, migration) ═

    public function test_an_existing_teacher_fixture_defaults_to_center_enabled(): void
    {
        $this->assertTrue($this->isEnabled());
    }

    // ══ THE WRITE — presence, not truthiness ═════════════════════════════

    public function test_an_admin_can_disable_the_center_system_for_a_teacher(): void
    {
        $this->patchTeacher(['centerEnabled' => false]);

        $this->assertFalse($this->isEnabled());
    }

    public function test_an_admin_can_re_enable_it(): void
    {
        $this->patchTeacher(['centerEnabled' => false]);
        $this->patchTeacher(['centerEnabled' => true]);

        $this->assertTrue($this->isEnabled());
    }

    public function test_an_absent_key_leaves_center_enabled_untouched(): void
    {
        $this->patchTeacher(['centerEnabled' => false]);

        // An unrelated profile field, no centerEnabled key at all.
        $this->patchTeacher(['bio' => 'نبذة جديدة']);

        $this->assertFalse($this->isEnabled(), 'centerEnabled must not silently flip back on an unrelated save.');
    }

    public function test_it_is_independent_of_isActive_and_loginBlocked(): void
    {
        $this->patchTeacher(['isActive' => false, 'loginBlocked' => true]);

        $this->assertTrue($this->isEnabled(), 'Suspending/blocking a teacher must not touch center_enabled.');

        $this->patchTeacher(['centerEnabled' => false]);

        $fresh = $this->freshTeacher();
        $this->assertFalse((bool) $fresh->getAttribute('center_enabled'));
        // The other two switches stay exactly as the previous request left them.
        $this->assertTrue((bool) $fresh->getAttribute('login_blocked'));
    }

    // ══ TeacherResource exposes it ════════════════════════════════════════

    public function test_the_admin_resource_carries_center_enabled(): void
    {
        $row = $this->patchJson("/api/v1/admin/teachers/{$this->teacher->getKey()}", ['centerEnabled' => false])
            ->assertStatus(200)->json('data');

        $this->assertFalse($row['centerEnabled']);
    }

    // ══ CREATE — opt-in, absent = false (the OPPOSITE of the schema default) ═

    public function test_a_newly_created_teacher_defaults_to_center_disabled(): void
    {
        $row = $this->postJson('/api/v1/admin/teachers', [
            'fullName' => 'مدرس جديد',
            'username' => 'newteacher' . random_int(1000, 9999),
            'password' => 'CorrectHorse42Battery',
        ])->assertStatus(201)->json('data');

        $this->assertFalse($row['centerEnabled']);
    }

    public function test_a_new_teacher_can_be_created_with_the_center_system_pre_enabled(): void
    {
        $row = $this->postJson('/api/v1/admin/teachers', [
            'fullName' => 'مدرس جديد',
            'username' => 'newteacher' . random_int(1000, 9999),
            'password' => 'CorrectHorse42Battery',
            'centerEnabled' => true,
        ])->assertStatus(201)->json('data');

        $this->assertTrue($row['centerEnabled']);
    }
}
