<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * "تحويل إلى حساب الطالب" / "رجوع للأدمن" — open decision 10.18.
 * POST /admin/students/{id}/impersonate and POST /auth/stop-impersonation.
 */
final class ImpersonationTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_an_admin_can_impersonate_an_active_student(): void
    {
        $this->disableRateLimiting();
        [$studentUser] = $this->makeStudentWithCode();
        $studentUser->forceFill(['full_name' => 'طالب مجتهد'])->save();

        $this->loginAsAdmin();

        $this->postJson("/api/v1/admin/students/{$studentUser->getKey()}/impersonate")
            ->assertStatus(200)
            ->assertJsonPath('data.user.id', $studentUser->getKey())
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonPath('data.user.impersonating', true);

        // The session really did swap — a fresh /auth/me confirms it, not
        // just the impersonate response.
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.user.id', $studentUser->getKey())
            ->assertJsonPath('data.user.impersonating', true);
    }

    public function test_an_ordinary_session_never_reports_impersonating(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.user.impersonating', false);
    }

    public function test_only_an_admin_may_impersonate(): void
    {
        $this->disableRateLimiting();
        [$studentUser] = $this->makeStudentWithCode();
        $this->loginAs($this->makeInstructor());

        $this->postJson("/api/v1/admin/students/{$studentUser->getKey()}/impersonate")
            ->assertStatus(403);
    }

    public function test_a_non_student_target_is_rejected(): void
    {
        $this->disableRateLimiting();
        [$teacherUser] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        // findStudent() 404s before StartImpersonation's own role check is
        // even reachable — a teacher id simply has no `students` row.
        $this->postJson("/api/v1/admin/students/{$teacherUser->getKey()}/impersonate")
            ->assertStatus(404);
    }

    public function test_a_suspended_student_cannot_be_impersonated(): void
    {
        $this->disableRateLimiting();
        [$studentUser] = $this->makeStudentWithCode();
        $studentUser->forceFill(['is_active' => false])->save();

        $this->loginAsAdmin();

        $this->postJson("/api/v1/admin/students/{$studentUser->getKey()}/impersonate")
            ->assertStatus(401)
            ->assertJsonPath('message', 'لا يمكن الدخول إلى حساب غير نشط');
    }

    public function test_an_unknown_student_is_404(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/students/' . \Illuminate\Support\Str::uuid7() . '/impersonate')
            ->assertStatus(404);
    }

    public function test_stop_impersonation_restores_the_admin(): void
    {
        $this->disableRateLimiting();
        [$studentUser] = $this->makeStudentWithCode();
        $admin = $this->loginAsAdmin();

        $this->postJson("/api/v1/admin/students/{$studentUser->getKey()}/impersonate")
            ->assertStatus(200);

        $this->postJson('/api/v1/auth/stop-impersonation')
            ->assertStatus(200)
            ->assertJsonPath('data.user.id', $admin->getKey())
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.user.impersonating', false);

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.user.id', $admin->getKey())
            ->assertJsonPath('data.user.impersonating', false);
    }

    public function test_stop_impersonation_without_an_active_impersonation_is_401(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/auth/stop-impersonation')
            ->assertStatus(401)
            ->assertJsonPath('message', 'لا يوجد دخول نشط كطالب لإنهائه');
    }

    /**
     * THE CRITICAL SAFETY SCENARIO: the impersonated student is deactivated
     * WHILE the admin is still "inside" that session. EnsureUserIsActive must
     * restore the admin rather than destroy the whole session — see that
     * middleware's restoreImpersonatorIfPossible().
     */
    public function test_deactivating_the_impersonated_student_mid_session_restores_the_admin_not_a_full_logout(): void
    {
        $this->disableRateLimiting();
        [$studentUser] = $this->makeStudentWithCode();
        $admin = $this->loginAsAdmin();

        $this->postJson("/api/v1/admin/students/{$studentUser->getKey()}/impersonate")
            ->assertStatus(200);

        $studentUser->forceFill(['is_active' => false])->save();
        $this->startNextRequest();

        // The next authenticated request 401s (the student account it was
        // about to serve is now disabled)...
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'تم تعطيل حساب الطالب أثناء المراجعة، تم إرجاعك لحساب الأدمن');

        // ...but the SESSION now resolves to the admin again, not to nobody.
        $this->startNextRequest();
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.user.id', $admin->getKey())
            ->assertJsonPath('data.user.impersonating', false);
    }

    public function test_the_session_key_is_absent_after_a_clean_login(): void
    {
        $this->disableRateLimiting();
        $admin = $this->loginAsAdmin();

        self::assertFalse(session()->has(Impersonation::SESSION_KEY));
        self::assertSame($admin->getKey(), auth()->id());
    }
}
