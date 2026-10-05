<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE END-TO-END CONSEQUENCE OF SUSPENSION — A REGRESSION CHECK ON        ║
 * ║ STAGE 1, NOT NEW LOGIC.                                                 ║
 * ║                                                                          ║
 * ║ Stage 4 gives an admin three ways to write users.is_active = 0 (the      ║
 * ║ teacher, student and generic-user update endpoints). Each of those has   ║
 * ║ its own unit-level assertion that the COLUMN changed. This file asserts  ║
 * ║ the thing the column exists FOR: that the suspended account's VERY NEXT  ║
 * ║ REQUEST is refused, mid-session, without waiting for a cookie to expire. ║
 * ║                                                                          ║
 * ║ The enforcement lives in Stage 1's EnsureUserIsActive middleware. This   ║
 * ║ file is what stops Stage 4's new write paths from being connected to     ║
 * ║ nothing — a column that flips while access continues is the exact bug    ║
 * ║ backend/ shipped before it grew the isActive branches.                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * startNextRequest() IS MANDATORY IN EVERY TEST BELOW. Under `php artisan
 * test` the container is reused across requests, so Auth::guard('web') is the
 * SAME object and returns its cached user without touching the database.
 * Without forgetting the guards, these tests would assert the harness's
 * caching rather than the application's behaviour — measured, not assumed:
 * they returned 200 before the helper existed. See CreatesAuthFixtures.
 */
final class SuspensionTakesEffectTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    public function test_suspending_a_teacher_mid_session_blocks_their_very_next_request(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();

        // The instructor is signed in and working normally.
        $this->loginAs($instructor);
        $this->getJson('/api/v1/teachers/statistics')->assertStatus(200);

        // An admin suspends them THROUGH THE STAGE 4 ENDPOINT — not by writing
        // the column directly, so the whole path is exercised.
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $this->patchJson("/api/v1/admin/teachers/{$instructor->getKey()}", ['isActive' => false])
            ->assertStatus(200);

        // Back to the instructor's own session, as a fresh process would be.
        $this->app['auth']->forgetGuards();
        $this->actingAs($instructor);
        $this->startNextRequest();

        $this->getJson('/api/v1/teachers/statistics')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Account is disabled or no longer exists');
    }

    public function test_suspending_a_student_mid_session_blocks_their_very_next_request(): void
    {
        [$student] = $this->makeStudentWithCode();

        $this->loginAs($student);
        $this->getJson('/api/v1/students/dashboard')->assertStatus(200);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $this->patchJson("/api/v1/admin/students/{$student->getKey()}", ['isActive' => false])
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->actingAs($student);
        $this->startNextRequest();

        $this->getJson('/api/v1/students/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Account is disabled or no longer exists');
    }

    /** The generic user endpoint is a third write path to the same column. */
    public function test_deactivating_through_the_user_endpoint_also_takes_effect_at_once(): void
    {
        [$student] = $this->makeStudentWithCode();

        $this->loginAs($student);
        $this->getJson('/api/v1/students/dashboard')->assertStatus(200);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $this->patchJson("/api/v1/admin/users/{$student->getKey()}", ['is_active' => false])
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->actingAs($student);
        $this->startNextRequest();

        $this->getJson('/api/v1/students/dashboard')->assertStatus(401);
    }

    /** Deletion is the other revocation, and must behave the same way. */
    public function test_deleting_a_teacher_mid_session_blocks_their_very_next_request(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();

        $this->loginAs($instructor);
        $this->getJson('/api/v1/teachers/statistics')->assertStatus(200);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $this->deleteJson("/api/v1/admin/teachers/{$instructor->getKey()}", $this->reauth())
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->actingAs($instructor);
        $this->startNextRequest();

        $this->getJson('/api/v1/teachers/statistics')->assertStatus(401);
    }

    /** Reactivation restores access just as immediately. */
    public function test_reactivating_restores_access_on_the_next_request(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $instructor->forceFill(['is_active' => false])->save();

        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $this->patchJson("/api/v1/admin/teachers/{$instructor->getKey()}", ['isActive' => true])
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->loginAs($instructor);

        $this->getJson('/api/v1/teachers/statistics')->assertStatus(200);
    }
}
