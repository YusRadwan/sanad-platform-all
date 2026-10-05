<?php

declare(strict_types=1);

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ VERIFICATION-ONLY ROUTES. THEY EXIST **ONLY** WHEN APP_ENV=testing.      │
 * │                                                                          │
 * │ bootstrap/app.php loads this file from inside a                          │
 * │   if ($app->environment('testing'))                                      │
 * │ guard, so `php artisan route:list` in local/staging/production prints    │
 * │ nothing from here. Pinned by                                             │
 * │ tests/Feature/Authorization/VerificationRouteIsolationTest.php, which    │
 * │ shells out to a REAL `artisan route:list --env=local` and greps it.      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ══ WHY THESE EXIST ════════════════════════════════════════════════════════
 *
 * Stage 3 delivers CoursePolicy but NOT /courses/* — those are Stage 4/5. A
 * policy that is logically perfect and attached to no route is not a security
 * control, and an isolation matrix asserted only through Gate::allows() cannot
 * tell the two apart. These routes give the HTTP layer of
 * Tests\Concerns\AssertsTenantIsolation something real to talk to, so the
 * matrix is proven end to end — middleware, session, policy, exception
 * handler, status code — a stage before the production routes land.
 *
 * ══ WHY FIVE ROUTES AND NOT TWO ════════════════════════════════════════════
 *
 * The obvious shape is one GET (view + consumeContent + viewHidden) and one
 * PATCH (manage). It does not work, because ONE response carries ONE status
 * code: a subscribed student is 200 on consumeContent and 403 on viewHidden at
 * the same instant. Collapsing them would force the route to pick one ability
 * and the other two would go untested — the exact hole this file exists to
 * close.
 *
 * The split is also the more faithful rehearsal. Each route carries the
 * middleware ITS OWN future counterpart will carry, per
 * docs/authorization-freeze-table.md:
 *
 *   GET  /_verify/courses/{course}          auth+active   -> view
 *   GET  /_verify/courses/{course}/content  auth+active   -> consumeContent
 *   GET  /_verify/courses/{course}/hidden   auth+active   -> viewHidden
 *   PATCH /_verify/courses/{course}         auth+active   -> manage
 *   GET  /_verify/public/courses/{course}   (none)        -> the DEGRADE path
 *
 * ══ THE FIFTH ROUTE IS THE IMPORTANT ONE ═══════════════════════════════════
 *
 * ~15 routes in backend/routes/api.php are public and MUST stay public (the
 * storefront renders logged out). For those the pattern is not "authorize or
 * deny" but "authorize or serve a reduced payload" — backend/ spells it
 * ContentItemController::optionalIdentity(), which yields ['', ''] for an
 * anonymous caller and lets every downstream entitlement check answer "not
 * entitled" instead of throwing. Its Laravel spelling is
 *
 *     $request->user()?->can('viewHidden', $course) ?? false
 *
 * and NEVER $this->authorize() / Gate::authorize(), which throw a 403 and would
 * turn the public course page into an error for every logged-out visitor. The
 * public route below is the executable statement of that convention.
 */
Route::prefix('_verify')->name('_verify.')->group(function (): void {

    Route::middleware(['auth', 'active'])->group(function (): void {

        /*
         * `view`.
         *
         * NOTE this is the AUTHENTICATED single-course read — the instructor
         * dashboard's course detail — not the public storefront one. It is
         * auth-gated precisely so the deactivatedOwner actor has a route on
         * which EnsureUserIsActive can answer 401 BEFORE CoursePolicy is
         * consulted. The guest's expectation on this route is therefore
         * [false, 401]: the Gate would deny a guest anyway, but the middleware
         * gets there first, and the matrix states both facts separately.
         */
        Route::get('/courses/{course}', function (Request $request, Course $course) {
            $request->user()->can('view', $course) ?: abort(403);

            return ['ability' => 'view', 'course' => $course->getKey()];
        })->name('courses.view');

        Route::get('/courses/{course}/content', function (Request $request, Course $course) {
            $request->user()->can('consumeContent', $course) ?: abort(403);

            return ['ability' => 'consumeContent', 'course' => $course->getKey()];
        })->name('courses.content');

        Route::get('/courses/{course}/hidden', function (Request $request, Course $course) {
            $request->user()->can('viewHidden', $course) ?: abort(403);

            return ['ability' => 'viewHidden', 'course' => $course->getKey()];
        })->name('courses.hidden');

        /*
         * `manage`. A PATCH that writes nothing — the point is the gate, and a
         * verification route must not be able to corrupt a fixture.
         */
        Route::patch('/courses/{course}', function (Request $request, Course $course) {
            $request->user()->can('manage', $course) ?: abort(403);

            return ['ability' => 'manage', 'course' => $course->getKey()];
        })->name('courses.manage');
    });

    /*
     * The DEGRADE path. NO auth, NO active — on purpose, and it must stay that
     * way: this is the shape of GET /courses/{id}, which a logged-out visitor
     * hits on every storefront page load.
     *
     * ALWAYS 200. The authorization answer is a FIELD, never a status.
     */
    Route::get('/public/courses/{course}', function (Request $request, Course $course) {
        return [
            'id' => $course->getKey(),
            'title' => $course->title,

            /*
             * ┌──────────────────────────────────────────────────────────────┐
             * │ THE CORRECT SPELLING FOR A GUEST-ADMITTING ABILITY.          │
             * │                                                              │
             * │ Gate::forUser(null) still CONSULTS the policy, because       │
             * │ CoursePolicy::view() is declared `?User $user` and Laravel's  │
             * │ methodAllowsGuests() therefore lets a null through. So a      │
             * │ logged-out visitor gets the honest answer — true for a        │
             * │ published course — which is what the storefront needs.        │
             * └──────────────────────────────────────────────────────────────┘
             */
            'may_view' => Gate::forUser($request->user())->allows('view', $course),

            /*
             * ┌──────────────────────────────────────────────────────────────┐
             * │ ⚠ THE SAME QUESTION, ASKED THE **WRONG** WAY. MEASURED.      │
             * │                                                              │
             * │ `$request->user()?->can('view', $course) ?? false` never      │
             * │ reaches the Gate for a guest at all: `?->` short-circuits on  │
             * │ the null USER, so `?? false` answers before any policy runs.  │
             * │ A published course therefore reports may_view = FALSE to      │
             * │ every logged-out visitor — a fail-CLOSED bug, invisible in    │
             * │ any test written only for logged-in actors, that would blank  │
             * │ the public catalogue.                                         │
             * │                                                              │
             * │ Kept here as a live specimen, asserted by                     │
             * │ CourseIsolationMatrixTest::test_the_null_safe_can_idiom_...   │
             * │ and recorded as open decision 3.7. See                        │
             * │ docs/authorization-freeze-table.md §"Public routes" for the   │
             * │ rule this produced: use `?->can() ?? false` ONLY for          │
             * │ owner-scoped abilities whose guest answer is false anyway;    │
             * │ use Gate::forUser() for anything a guest may legitimately     │
             * │ pass.                                                         │
             * └──────────────────────────────────────────────────────────────┘
             */
            'may_view_via_nullsafe' => $request->user()?->can('view', $course) ?? false,

            /*
             * manage / consumeContent / viewHidden all take a NON-nullable
             * `User`, so the Gate denies a guest structurally and the two
             * spellings agree. `?->can() ?? false` is safe here — and is still
             * never `authorize()`, which would throw a 403 and break the page.
             */
            'may_manage' => $request->user()?->can('manage', $course) ?? false,
            'may_consume' => $request->user()?->can('consumeContent', $course) ?? false,
            'may_view_hidden' => $request->user()?->can('viewHidden', $course) ?? false,
        ];
    })->name('public.courses.show');
});
