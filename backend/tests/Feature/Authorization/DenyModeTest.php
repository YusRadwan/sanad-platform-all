<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Exceptions\Handler;
use App\Exceptions\NotFoundException;
use App\Exceptions\PublicMessage;
use App\Http\Responses\ApiResponse;
use App\Models\Course;
use App\Models\User;
use App\Support\Authorization\DenyMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * App\Support\Authorization\DenyMode — the 404-instead-of-403 mechanism.
 *
 * Stage 3 has no REAL call site for it: a course is either published (public)
 * or owner-only (a clean 403 — the fact that a course exists is not a secret).
 * The first real consumer is Stage 5's hidden content items. So the mechanism
 * is built and proven NOW against a SYNTHETIC policy, and the proof goes all
 * the way to an HTTP status rather than stopping at the exception class —
 * because "produces a 404" is a claim about App\Exceptions\Handler's mapping,
 * not about DenyMode alone.
 */
final class DenyModeTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    // ── The exception itself ──────────────────────────────────────────────

    public function test_it_always_throws(): void
    {
        $this->expectException(NotFoundException::class);

        DenyMode::asNotFound('Content item not found');
    }

    public function test_the_default_message_is_the_generic_phrase(): void
    {
        try {
            DenyMode::asNotFound();
            $this->fail('asNotFound() must never return.');
        } catch (NotFoundException $e) {
            $this->assertSame('Not found', $e->getMessage());
        }
    }

    /**
     * The message is carried through rather than flattened, because
     * NotFoundException implements PublicMessage — Stage 2's opt-out.
     */
    public function test_the_message_is_a_public_one(): void
    {
        try {
            DenyMode::asNotFound('Content item not found');
            $this->fail('asNotFound() must never return.');
        } catch (NotFoundException $e) {
            $this->assertInstanceOf(PublicMessage::class, $e);
            $this->assertSame('Content item not found', $e->getMessage());
        }
    }

    /** Stage 0's mapping table answers 404 for it, with no new branch needed. */
    public function test_the_handler_maps_it_to_404(): void
    {
        $this->assertSame(404, Handler::statusFor(new NotFoundException('Content item not found')));
    }

    /**
     * It is NOT an AuthorizationException, which is the entire point: the
     * handler's `AuthorizationException => 403` branch must not claim it.
     */
    public function test_it_is_not_an_authorization_exception(): void
    {
        $this->assertNotInstanceOf(
            \Illuminate\Auth\Access\AuthorizationException::class,
            new NotFoundException('x')
        );
    }

    /** `never` return type — the compiler-enforced half of "cannot fall through". */
    public function test_the_return_type_is_never(): void
    {
        $method = new \ReflectionMethod(DenyMode::class, 'asNotFound');

        $this->assertSame('never', (string) $method->getReturnType());
    }

    // ── End to end, through a synthetic policy ability ────────────────────

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE REAL PROOF: a POLICY-style ability that calls DenyMode instead   │
     * │ of returning false answers **404**, while the same route's ordinary  │
     * │ denial answers **403**. Both go through the live middleware stack    │
     * │ and Stage 0's exception handler.                                     │
     * │                                                                      │
     * │ A Gate::define closure is used rather than a throwaway Policy class  │
     * │ because it exercises exactly the same Gate pipeline (before-callback │
     * │ -> ability -> AuthorizationException or whatever the body threw)     │
     * │ without adding a permanent fake model + fake policy to app/ just to  │
     * │ hold a test.                                                         │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_deny_mode_ability_answers_404_and_not_403(): void
    {
        Gate::define('test-hidden-resource', function (User $user, Course $course): bool {
            DenyMode::asNotFound('Content item not found');
        });

        Gate::define('test-ordinary-denial', fn (User $user, Course $course): bool => false);

        $course = $this->makeCourseFor($this->makeInstructor());
        $stranger = $this->makeInstructor();

        Route::middleware('api')->get('/api/v1/__test/deny/{ability}/{course}', function (string $ability, Course $course) {
            Gate::authorize($ability, $course);

            return ApiResponse::success();
        });

        $this->actingAs($stranger)
            ->getJson("/api/v1/__test/deny/test-hidden-resource/{$course->getKey()}")
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Content item not found');

        $this->actingAs($stranger)
            ->getJson("/api/v1/__test/deny/test-ordinary-denial/{$course->getKey()}")
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Forbidden');
    }

    /**
     * The 404 must be INDISTINGUISHABLE from a genuinely unknown id — that is
     * the anti-enumeration property the whole mechanism exists for. Only the
     * message may differ (backend/ uses the resource-specific phrase); the
     * STATUS and the envelope shape must not.
     */
    public function test_the_404_is_shaped_like_an_ordinary_not_found(): void
    {
        Gate::define('test-hidden-resource-2', function (User $user, Course $course): bool {
            DenyMode::asNotFound('Content item not found');
        });

        $course = $this->makeCourseFor($this->makeInstructor());

        Route::middleware('api')->get('/api/v1/__test/deny2/{course}', function (Course $course) {
            Gate::authorize('test-hidden-resource-2', $course);

            return ApiResponse::success();
        });

        $hidden = $this->actingAs($this->makeInstructor())
            ->getJson("/api/v1/__test/deny2/{$course->getKey()}");

        $unknown = $this->actingAs($this->makeInstructor())
            ->getJson('/api/v1/__test/deny2/0195c1a2-3b4c-7d8e-9f00-ffffffffffff');

        $hidden->assertStatus(404);
        $unknown->assertStatus(404);

        $this->assertSame(
            array_keys($hidden->json()),
            array_keys($unknown->json()),
            'The two 404s must have the same envelope shape.'
        );
    }
}
