<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\User;
use App\Policies\Concerns\ResolvesCourseOwnership;
use Illuminate\Auth\Access\Response;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ manage() IMPLEMENTED IN STAGE 5 / SESSION B.                             ║
 * ║ view() AND watch() IMPLEMENTED IN STAGE 5 / SESSION C.                   ║
 * ║ downloadFile() IMPLEMENTED IN STAGE 9 — freeze-table row 73, the         ║
 * ║ file/upload family. **THE CLASS IS COMPLETE; NOTHING HERE THROWS.**      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Ported shape from backend/app/Services/ContentItemService (~lines 298-332,
 * 336-400) and backend/app/Services/LessonService (~lines 142-172).
 *
 * ┌── downloadFile() WAS DEFERRED FOR FOUR STAGES; HERE IS HOW IT LANDED. ───┐
 * │ Stage 5 left it throwing because its route was registered nowhere, and  │
 * │ a plausible stub on an unrouted ability is an unexercised code path its │
 * │ real owner would have to notice was wrong before rewriting it. Stage 8  │
 * │ pinned the placeholder COUNT at one rather than asserting zero, and     │
 * │ recorded the debt as open decision 8.4.                                 │
 * │                                                                          │
 * │ Stage 9 discharged it in the order the debt demanded: (1) the real body │
 * │ below, (2) the real route (routes/api.php, row 73), and only THEN (3)   │
 * │ the inventory test's exception list — structurally removed, so a future │
 * │ placeholder has no dead scaffolding to reuse.                            │
 * │                                                                          │
 * │ The constraint Stage 5 wrote down was honoured exactly: it uses the     │
 * │ SAME gate as view()'s detail payload (consumeContent on the parent      │
 * │ course), and its hidden-item refusal is 404 'File not found', not 403.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── DenyMode IS **NOT** USED HERE, AND THAT IS SESSION C's RESOLUTION OF ──┐
 * │ OPEN DECISION 3.5. Session B's version of this banner expected it would  │
 * │ be; the DEGRADE constraint on row 21 makes that impossible. The full     │
 * │ argument is on view() below. The wire behaviour is unchanged — same 404, │
 * │ same 'Content item not found' string — it is simply raised by            │
 * │ App\Support\ContentItemAccess::assertViewable() from a PURE BOOLEAN      │
 * │ ability, rather than thrown out of the ability itself.                   │
 * │                                                                          │
 * │ The rule 3.5 records still stands and is now obeyed everywhere: an       │
 * │ ability that can throw is safe only behind authorize(). None of the four │
 * │ abilities on this class throws, so all four are safe to consult with     │
 * │ Gate::allows()/denies() — which is what App\Support\ContentItemAccess    │
 * │ does on every route in this session.                                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ADMINS ARE ABSENT FROM THIS FILE, as from every policy: Gate::before has
 * already returned true for them before any method here is entered.
 */
class ContentItemPolicy
{
    use ResolvesCourseOwnership;

    /**
     * backend/ContentItemService::noteFileFor()'s two literal refusal
     * strings. Both are part of the API contract; the frontend renders the
     * second one verbatim.
     */
    public const FILE_NOT_FOUND = 'File not found';

    public const NO_ACCESS_TO_FILE = 'You do not have access to this file';

    /**
     * GET /content-items/{id}. Hidden -> 404 via DenyMode. Otherwise the item's
     * safe DTO is public and canConsume() decides only whether the sensitive
     * `detail` payload (video_url / file_path / content_text) is attached.
     *
     * `?User` because backend/'s find() takes `$userId = ''` and serves the
     * public DTO to an anonymous caller.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ IMPLEMENTED IN SESSION C — AND IT DOES **NOT** CALL DenyMode,        ║
     * ║ CONTRARY TO WHAT THIS FILE'S BANNER ANTICIPATED. OPEN DECISION 3.5   ║
     * ║ IS THE REASON, AND IT IS A STRUCTURAL ONE, NOT A PREFERENCE.         ║
     * ║                                                                      ║
     * ║ DenyMode::asNotFound() THROWS. An exception escapes Gate::allows()    ║
     * ║ exactly as it escapes authorize(), so an ability that uses it is      ║
     * ║ usable ONLY behind authorize(). But row 21 is a **DEGRADE** row —     ║
     * ║ the freeze table forbids authorize() on it in bold, because 403ing a  ║
     * ║ logged-out storefront visitor is an outage of the public course page, ║
     * ║ not a protection.                                                     ║
     * ║                                                                      ║
     * ║ The two constraints are jointly unsatisfiable, so this ability stays  ║
     * ║ a PURE BOOLEAN and the 404 is raised one layer up by                  ║
     * ║ App\Support\ContentItemAccess::assertViewable(), which throws the     ║
     * ║ same NotFoundException with the same 'Content item not found' string  ║
     * ║ DenyMode itself would have thrown. Identical on the wire; 3.5's       ║
     * ║ constraint respected rather than worked around.                       ║
     * ║                                                                      ║
     * ║ This is the SAME resolution Session A reached for decision 3.6 at     ║
     * ║ CourseAccess::assertVisible(), and it is now the established pattern  ║
     * ║ for every DEGRADE row that owes a 404. Open decision 3.5 can close.   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── WHAT THIS ABILITY ACTUALLY DECIDES: **EXISTENCE**, NOT PAYLOAD. ───┐
     * │ It answers one question — "may this caller know this item is there?" │
     * │ — and nothing about which keys ship. The payload decision is a        │
     * │ SEPARATE Gate call on `consumeContent` against the parent COURSE,     │
     * │ made at the call site, choosing between ContentItemResource and       │
     * │ PublicContentItemResource.                                            │
     * │                                                                      │
     * │ Keeping them separate is the point. A subscribed student is `true`    │
     * │ here AND `true` on consumeContent; a guest is `true` here and         │
     * │ `false` there. One boolean cannot express both, and a version that    │
     * │ tried would be the payload leak this session exists to prevent.       │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * VISIBLE ITEM -> TRUE FOR EVERYONE, GUESTS INCLUDED. Only `is_hidden = 1`
     * narrows the answer, and it narrows it to canSeeHidden() — the OWNER (and
     * admins, upstream via Gate::before). A SUBSCRIBED STUDENT MUST FAIL HERE
     * on a hidden item even though they pass consumeContent on the same course;
     * see ResolvesCourseOwnership::canSeeHidden() for why that gap is the most
     * important one in this layer.
     *
     * `?User` because backend/'s find() takes `$userId = ''` and serves the
     * public DTO to an anonymous caller. The null branch is explicit and comes
     * BEFORE canSeeHidden(), which takes a non-nullable User.
     *
     * THE NULL-COURSE BRANCH FAILS CLOSED, identically to manage(): a
     * soft-deleted course leaves its items in place, and an orphan's existence
     * is nobody's to confirm.
     */
    public function view(?User $user, ContentItem $item): bool
    {
        if (!$item->getAttribute('is_hidden')) {
            return true;
        }

        $course = $item->course;

        if ($course === null || $user === null) {
            return false;
        }

        return $this->canSeeHidden($user, $course);
    }

    /**
     * Create/update/delete/reorder a content item. Owner of the parent COURSE
     * only. Rejection message: 'You do not own this content item'
     * (App\Support\ContentItemAccess::NOT_OWNER_ITEM) — NOT the course wording
     * Session A settled on for section and group routes. Open decision 3.4's
     * second half; freeze-table rows 24 and 26.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ONE HOP, THROUGH THE DENORMALISED `content_items.course_id`.         ║
     * ║                                                                      ║
     * ║ NOT `$item->section->course` and NOT `$item->group->course`. Three    ║
     * ║ reasons, in increasing order of importance:                           ║
     * ║                                                                      ║
     * ║  1. It is one query instead of two.                                   ║
     * ║  2. Only ONE of those two chains exists for any given row (the XOR    ║
     * ║     check guarantees exactly one parent), so a traversal-based        ║
     * ║     implementation needs a branch that this one does not.             ║
     * ║  3. backend/ContentItemService::requireOwnsContentItem() reads        ║
     * ║     `$item['course_id']` directly. Deriving it instead would be a     ║
     * ║     DIFFERENT ownership claim the moment the two ever disagree, and   ║
     * ║     the column exists precisely so they cannot.                       ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * THE NULL-COURSE BRANCH FAILS **CLOSED**, identically to
     * SectionPolicy::manage(): `content_items` has no `deleted_at`, so
     * soft-deleting a course leaves its items in place and Course's global
     * scope makes this relation null. Handing that null to ownsCourse() —
     * whose parameter is non-nullable — would be a TypeError, i.e. a 500 with
     * a stack trace where the contract owes a clean refusal. An orphaned item
     * belongs to nobody, so nobody may manage it; the controller layer turns
     * the same fact into backend/'s 404 'Course not found' one step earlier.
     */
    public function manage(User $user, ContentItem $item): bool
    {
        $course = $item->course;

        if ($course === null) {
            return false;
        }

        return $this->ownsCourse($user, $course);
    }

    /**
     * Play a video lesson (LessonService::assertCanAccess).
     *
     * THREE ways in, and the middle one is easy to lose: the owning
     * instructor; an explicitly FREE PREVIEW lesson (`videos.is_preview = 1`),
     * which is open to any AUTHENTICATED user regardless of subscription; or a
     * student with an active subscription. Dropping the preview branch silently
     * paywalls every course's free sample — open decision 3.8, which also
     * settles that the preview branch belongs HERE and never inside
     * ResolvesCourseOwnership::canConsume() (it is a `videos` column, not a
     * course property).
     *
     * Rejection message: 'You do not have access to this lesson'.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ IMPLEMENTED IN SESSION C. **OPEN DECISION 3.8 IS RESOLVED HERE, AND  ║
     * ║ HERE ONLY.**                                                          ║
     * ║                                                                      ║
     * ║ The `is_preview` branch lives in THIS policy and must never migrate   ║
     * ║ into ResolvesCourseOwnership::canConsume(). `is_preview` is a column  ║
     * ║ on `videos` — a property of ONE CONTENT ITEM — not a property of a    ║
     * ║ course. Putting it in the trait would make "preview" a course-level   ║
     * ║ concept and hand it to every policy that uses the trait, including    ║
     * ║ ExamPolicy and AssignmentPolicy in Stage 6, which have no videos and  ║
     * ║ no business inheriting a video rule.                                  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * FOUR ways in — admin (upstream, Gate::before), and the three below. The
     * MIDDLE one is the easy one to lose, and losing it silently paywalls
     * every course's free sample:
     *
     *   1. the owning instructor, previewing their own material;
     *   2. `videos.is_preview = 1` — open to ANY AUTHENTICATED user, subscribed
     *      or not, because that is precisely what "free preview" means. Note it
     *      is not open to a GUEST: the parameter is non-nullable `User`, and
     *      backend/LessonService::assertCanAccess() rejects `$userId === ''`
     *      before every other branch. A real session is still required.
     *   3. a STUDENT with an active subscription.
     *
     * The order is backend/'s order and it matters for cost, not correctness:
     * ownership is a string compare, preview is one already-loaded relation,
     * and the subscription EXISTS query — the only one that hits the database
     * unavoidably — runs last.
     *
     * Branches 1 and 3 are canConsume(), which is called rather than re-derived
     * so the subscription predicate stays defined once (Subscription::
     * scopeActive()).
     *
     * `$item->video` may legitimately be null — a video content item created
     * without its URL never got a `videos` row — and a null relation is NOT a
     * preview. `?->` here is safe precisely because the fallback is the
     * fail-closed answer.
     *
     * Rejection message: 'You do not have access to this lesson'.
     *
     * THE NULL-COURSE BRANCH FAILS CLOSED, as in view() and manage().
     */
    public function watch(User $user, ContentItem $item): bool
    {
        $course = $item->course;

        if ($course === null) {
            return false;
        }

        if ($this->ownsCourse($user, $course)) {
            return true;
        }

        // Decision 3.8 — the fourth entitlement branch, and it lives HERE.
        if ($item->video?->is_preview === true) {
            return true;
        }

        return $this->canConsume($user, $course);
    }

    /**
     * GET /content-items/{id}/file — a note's attached document. Freeze-table
     * row 73.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ IMPLEMENTED IN STAGE 9. **THIS CLOSES OPEN DECISION 8.4** — IT WAS   ║
     * ║ THE LAST `LogicException` PLACEHOLDER IN THE ENTIRE APPLICATION.     ║
     * ║                                                                      ║
     * ║ Stage 5 deferred it on the recorded ground that a body written       ║
     * ║ before its route existed would be an unexercised guess. Stage 8      ║
     * ║ pinned the placeholder count at exactly one and said the files stage ║
     * ║ must take it to zero. It is now zero, ASSERTED ABSOLUTELY: the       ║
     * ║ exception list that used to name this file has been REMOVED from     ║
     * ║ GateBeforeTest, not merely emptied.                                  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── IT RETURNS AN `Illuminate\Auth\Access\Response`, NOT A BOOL, AND ──┐
     * │ NOT `App\Support\Authorization\DenyMode`. THE CHOICE IS FORCED.      │
     * │                                                                      │
     * │ This ability owes THREE distinct wire answers:                       │
     * │   404 'File not found'                    (wrong type, or hidden)    │
     * │   403 'You do not have access to this file' (not entitled)           │
     * │   200                                                                │
     * │                                                                      │
     * │ A plain `bool` cannot distinguish the first two. DenyMode CAN, but   │
     * │ it THROWS, and decision 3.5 records the consequence: a throwing      │
     * │ ability escapes `Gate::allows()` as readily as `authorize()`, so it  │
     * │ can never be consulted for a boolean — which would make row 73       │
     * │ untestable by the nine-actor isolation matrix, whose logical layer   │
     * │ is `Gate::forUser($actor)->allows(...)`. Losing that matrix on the   │
     * │ one route that hands out private course material is not a trade      │
     * │ worth making for an idiom.                                           │
     * │                                                                      │
     * │ `Response::denyAsNotFound()` is the framework's own answer to        │
     * │ exactly this: it CARRIES a 404 status and a message as DATA rather   │
     * │ than raising, so `Gate::allows()` sees a clean `false` while         │
     * │ `authorize()` raises an AuthorizationException that still knows it   │
     * │ owes 404. App\Exceptions\Handler learned to read both in Stage 9.    │
     * │                                                                      │
     * │ DenyMode is untouched and still correct for the abilities that use   │
     * │ it; this is a second, narrower tool for the case where the SAME      │
     * │ ability must be readable as a boolean AND raise a non-403.           │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── WHAT IS **NOT** HERE: THE `file_path IS NULL` CHECK. ──────────────┐
     * │ backend/ContentItemService::noteFileFor() has FOUR branches; only    │
     * │ three of them are authorization. The fourth — "the notes row has no  │
     * │ file_path" — is an EXISTENCE fact about the filesystem, not a fact   │
     * │ about (user, resource), and putting it here would make this policy   │
     * │ query a detail table to answer a question about permission. A note   │
     * │ with no attachment must 404 for EVERYONE INCLUDING ITS OWNER, which  │
     * │ is precisely the shape of an existence check and not of a denial.    │
     * │                                                                      │
     * │ It lives in the controller as `abort_if(blank(...), 404)`. Keeping   │
     * │ the policy a pure function of (user, resource) is what lets the      │
     * │ isolation matrix assert on it without seeding a file on disk.        │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * THE GATE IS `canConsume`, THE SAME ONE view()'s detail payload uses.
     * That identity is the security property, not a convenience: a file
     * attached to a note must never be easier to reach than the note text it
     * belongs to. Wiring a NEW rule here — even an equivalent-looking one —
     * would create a second place for the two to drift apart.
     *
     * ORDER: type, then hidden, then entitlement. Hidden is checked BEFORE
     * entitlement so a subscribed student meets 404 (not 403) on a staged
     * item — a 403 would confirm that a guessed UUID names a real unreleased
     * lesson, the oracle CourseAccess::assertVisible() exists to close.
     *
     * ADMIN IS ABSENT: Gate::before already returned true before this runs.
     *
     * THE NULL-COURSE BRANCH FAILS CLOSED with the 403, as in manage() and
     * watch(): a soft-deleted course leaves its items in place and makes this
     * relation null. backend/'s canAccessCourseContent() likewise answered
     * false — i.e. ForbiddenException — for that state, so the 403 is the
     * ported behaviour and not a new one.
     */
    public function downloadFile(User $user, ContentItem $item): Response
    {
        if ($item->type !== ContentItemType::Note) {
            /*
             * Includes `type = 'file'`, which decision 5.x records as having
             * NO WRITE PATH anywhere in the system — so a file-typed item
             * cannot exist, and if one ever did it would be permanently
             * undownloadable. Cross-referenced in decision 9.5.
             */
            return Response::denyAsNotFound(self::FILE_NOT_FOUND);
        }

        $course = $item->course;

        if ($course === null) {
            return Response::deny(self::NO_ACCESS_TO_FILE);
        }

        if ($item->getAttribute('is_hidden') && !$this->canSeeHidden($user, $course)) {
            return Response::denyAsNotFound(self::FILE_NOT_FOUND);
        }

        return $this->canConsume($user, $course)
            ? Response::allow()
            : Response::deny(self::NO_ACCESS_TO_FILE);
    }
}
