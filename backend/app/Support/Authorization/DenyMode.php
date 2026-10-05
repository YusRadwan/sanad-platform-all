<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Exceptions\NotFoundException;

/**
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE 404-INSTEAD-OF-403 ESCAPE HATCH FOR POLICIES.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Returning `false` from a policy method produces an AuthorizationException,
 * which App\Exceptions\Handler maps to **403 Forbidden**. That is the right
 * answer almost everywhere: "this resource exists and it is not yours" is not
 * a secret, and 403 is the honest, debuggable reply.
 *
 * It is the WRONG answer in one specific situation: when the mere fact that
 * the resource EXISTS is itself the thing being protected. backend/ has three
 * such call sites and all three answer 404 on purpose —
 *
 *   ContentItemService::find()          hidden item -> 'Content item not found'
 *   ContentItemService::noteFileFor()   hidden item -> 'File not found'
 *   LessonService::requireVideoItem()   non-video   -> 'Lesson not found'
 *
 * — because a 403 on a guessed UUID is a positive oracle: it confirms that the
 * id names a real unpublished lesson, which lets an attacker enumerate a
 * competitor's unreleased catalogue one id at a time without ever seeing its
 * contents. A 404 is indistinguishable from a wrong guess.
 *
 * ┌── HOW TO USE IT ─────────────────────────────────────────────────────────┐
 * │ Inside a policy method, THROW instead of returning false:                │
 * │                                                                          │
 * │   public function view(User $user, ContentItem $item): bool              │
 * │   {                                                                      │
 * │       if ($item->is_hidden && ! $this->canSeeHidden($user, $course)) {   │
 * │           DenyMode::asNotFound('Content item not found');                │
 * │       }                                                                  │
 * │       return $this->canConsume($user, $course);                          │
 * │   }                                                                      │
 * │                                                                          │
 * │ The `never` return type is what makes this safe to write as a bare       │
 * │ statement: PHP and every static analyser know control flow stops there,  │
 * │ so no `return` is needed after it and no reviewer has to check whether   │
 * │ execution falls through into an accidental `true`.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── WHAT THIS CLASS DELIBERATELY IS NOT ───────────────────────────────────┐
 * │ It is NOT a general "deny" helper and it must never gain an              │
 * │ `asForbidden()` sibling. Plain `return false` already produces the       │
 * │ correct 403 through the framework, and offering a throwing alternative   │
 * │ would create two ways to say the same thing — with the throwing one      │
 * │ bypassing Gate::after, Gate::inspect(), the `->allows()`/`->denies()`    │
 * │ boolean API and every policy-response message. Every use of this class   │
 * │ is a deliberate, documented exception to the default; there must be no   │
 * │ reason to reach for it casually.                                         │
 * │                                                                          │
 * │ COROLLARY, and Session 2 must hold the line on it: a policy method that  │
 * │ calls this can only be consulted through `authorize()`/`Gate::authorize` │
 * │ — the exception escapes `Gate::allows()` too, so any call site that      │
 * │ merely wants a boolean (a UI capability flag, a `when()` in a Resource)  │
 * │ MUST NOT be pointed at a 404-mode ability. That is a genuine constraint  │
 * │ on ContentItemPolicy's shape in Stage 5, not a footnote.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * It reuses Stage 2's App\Exceptions\NotFoundException rather than inventing a
 * new type: that class already extends Symfony's NotFoundHttpException (so
 * Handler's existing `=> 404` branch needs no new case) and already implements
 * App\Exceptions\PublicMessage (so the Arabic/English message survives instead
 * of being flattened to the generic 'Not found').
 *
 * ┌── THE MESSAGE MUST NOT ECHO CALLER INPUT. ───────────────────────────────┐
 * │ The whole point is to reveal nothing. Pass a fixed phrase; never         │
 * │ interpolate the id that was looked up, the model class, or the reason —  │
 * │ 'Content item not found', not "Item {$id} is hidden".                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class DenyMode
{
    /**
     * Refuse by pretending the resource does not exist: 404, not 403.
     *
     * @throws NotFoundException always
     */
    public static function asNotFound(string $message = 'Not found'): never
    {
        throw new NotFoundException($message);
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
