<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `teachers.login_blocked` — THE SECOND SWITCH. IT BLOCKS SIGN-IN AND     ║
 * ║ CHANGES NOTHING A VISITOR CAN SEE.                                       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * The platform already had ONE lever: `users.is_active = 0` ("إيقاف"), which
 * does three things at once — hides the teacher from the public directory,
 * takes their published courses off the catalogue, and locks them out. Admins
 * needed a fourth option that does ONLY the last of those, for the case where
 * an account must be frozen (a dispute, a leaked password, a handover) while
 * the students who paid for that teacher's courses keep their material.
 *
 * ┌── WHAT WOULD MAKE THIS FEATURE A LIE, AND IS THEREFORE ASSERTED ─────────┐
 * │ · the blocked teacher vanishing from GET /teachers, or                   │
 * │ · their published course vanishing from GET /courses,                    │
 * │   which would make `login_blocked` a slow re-implementation of           │
 * │   suspension under a friendlier name. Both are checked BELOW, against a  │
 * │   teacher whose users.is_active is deliberately left TRUE.               │
 * │ · the login rejection reusing 'هذا الحساب غير مفعل', which would erase   │
 * │   the distinction in every log line and support ticket.                  │
 * │ · the flag reaching students/admins, who have no `teachers` row at all.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * PRECEDENCE IS ASSERTED, NOT ASSUMED: when BOTH flags are set the ORIGINAL
 * suspension message must still win, because suspension is the larger statement
 * and the new branch sits strictly after the is_active check in LoginUser.
 */
final class TeacherLoginBlockTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Several logins in a row against a 5-per-minute budget; throttling has
        // its own suite (Auth\RateLimitTest) and would fail these for the wrong
        // reason.
        $this->disableRateLimiting();
    }

    /**
     * @return array{0: User, 1: Teacher}
     */
    private function makeBlockableTeacher(string $username, bool $blocked, bool $active = true): array
    {
        /** @var array{0: User, 1: Teacher} $pair */
        $pair = $this->makeTeacherWithProfile(
            ['login_blocked' => $blocked],
            ['username' => $username, 'is_active' => $active],
        );

        return $pair;
    }

    private function attemptLogin(string $username): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => self::FIXTURE_PASSWORD,
        ]);
    }

    // ══ 1. THE REJECTION, WITH ITS OWN SENTENCE ═════════════════════════════

    public function test_a_login_blocked_teacher_is_refused_with_the_new_distinct_message(): void
    {
        $this->makeBlockableTeacher('blocked01', true);

        $this->attemptLogin('blocked01')
            ->assertStatus(401)
            ->assertJsonPath('message', 'تم منع هذا الحساب من تسجيل الدخول');

        $this->assertGuest();
    }

    /**
     * The message must NOT be the suspension one. Asserted separately from the
     * positive check above because the two strings are the only thing that
     * tells an admin — reading a log or a support ticket — which of the two
     * different admin actions produced the rejection.
     */
    public function test_the_rejection_does_not_reuse_the_inactive_account_message(): void
    {
        $this->makeBlockableTeacher('blocked02', true);

        $message = (string) $this->attemptLogin('blocked02')->json('message');

        $this->assertNotSame('هذا الحساب غير مفعل', $message);
    }

    // ══ 2. THE DEFAULT IS "CAN LOG IN" ══════════════════════════════════════

    public function test_the_column_defaults_to_false_and_such_a_teacher_logs_in_normally(): void
    {
        [$user] = $this->makeTeacherWithProfile([], ['username' => 'allowed01']);

        // The migration's DEFAULT false, observed rather than assumed: nothing
        // in the create path above mentions the column.
        $this->assertFalse(
            (bool) Teacher::query()->findOrFail($user->getKey())->getAttribute('login_blocked')
        );

        $this->attemptLogin('allowed01')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertAuthenticatedAs($user);
    }

    public function test_explicitly_unblocked_teacher_logs_in(): void
    {
        [$user] = $this->makeBlockableTeacher('allowed02', false);

        $this->attemptLogin('allowed02')->assertStatus(200);

        $this->assertAuthenticatedAs($user);
    }

    // ══ 3. VISIBILITY IS UNTOUCHED — THE WHOLE POINT ════════════════════════

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE FEATURE'S REASON TO EXIST. If this test ever fails, the flag has  │
     * │ silently become a second suspension and must be reverted, not         │
     * │ "fixed" by changing the expectation.                                  │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_login_blocked_teacher_stays_in_the_public_directory(): void
    {
        [$user] = $this->makeBlockableTeacher('visible01', true);

        // Unauthenticated, exactly as a visitor arrives.
        $usernames = collect($this->getJson('/api/v1/teachers')->json('data.teachers'))
            ->pluck('username')
            ->all();

        $this->assertContains('visible01', $usernames);

        // And the flag itself is NOT leaked to that unauthenticated audience.
        $this->assertStringNotContainsString(
            'loginBlocked',
            (string) $this->getJson('/api/v1/teachers')->getContent()
        );

        // Sanity: the SAME teacher genuinely cannot sign in. Without this the
        // test above would pass for a flag that does nothing at all.
        $this->attemptLogin($user->username)->assertStatus(401);
    }

    public function test_a_login_blocked_teachers_published_courses_stay_in_the_public_catalogue(): void
    {
        [$owner] = $this->makeBlockableTeacher('visible02', true);

        $course = $this->makeCourseFor($owner, [
            'title' => 'كورس مدرّس ممنوع من الدخول',
            'is_published' => true,
        ]);

        $ids = collect($this->getJson('/api/v1/courses')->json('data.items'))
            ->pluck('id')
            ->all();

        $this->assertContains($course->getKey(), $ids);

        // The single-course read is public too, and must be equally unaffected.
        $this->getJson("/api/v1/courses/{$course->getKey()}")->assertStatus(200);
    }

    // ══ 4. PRECEDENCE AGAINST THE ORIGINAL SUSPENSION ═══════════════════════

    /**
     * BOTH flags set. `is_active` is checked FIRST in LoginUser and must keep
     * winning: this branch was ADDED AFTER it, so the existing behaviour of
     * every already-suspended account is unchanged by the new feature.
     */
    public function test_a_suspended_and_blocked_teacher_still_gets_the_original_message(): void
    {
        $this->makeBlockableTeacher('both01', true, false);

        $this->attemptLogin('both01')
            ->assertStatus(401)
            ->assertJsonPath('message', 'هذا الحساب غير مفعل');
    }

    /** The suspension-only case, unchanged — the control for the row above. */
    public function test_a_suspended_but_unblocked_teacher_gets_the_original_message(): void
    {
        $this->makeBlockableTeacher('both02', false, false);

        $this->attemptLogin('both02')
            ->assertStatus(401)
            ->assertJsonPath('message', 'هذا الحساب غير مفعل');
    }

    // ══ 5. NON-INSTRUCTORS ARE NOT TOUCHED ══════════════════════════════════

    /**
     * A student has NO `teachers` row at all, so the new branch must not even
     * be entered — let alone dereference a null profile. The role gate, not the
     * null check, is what makes this true; the null check is the belt.
     */
    public function test_a_student_with_no_teacher_row_logs_in_unaffected(): void
    {
        $student = $this->makeUser(['username' => 'stud01']);

        $this->attemptLogin('stud01')
            ->assertStatus(200)
            ->assertJsonPath('data.user.role', 'student');

        $this->assertAuthenticatedAs($student);
    }

    public function test_an_admin_logs_in_unaffected(): void
    {
        $admin = $this->makeUser(['username' => 'admin01', 'role' => \App\Enums\UserRole::Admin]);

        $this->attemptLogin('admin01')->assertStatus(200);

        $this->assertAuthenticatedAs($admin);
    }
}
