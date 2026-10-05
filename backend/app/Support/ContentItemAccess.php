<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE REFUSAL SHAPES OF THE CONTENT-ITEM SURFACE.                         ║
 * ║ THREE DIFFERENT 403 STRINGS ON SIX ROUTES — AND THAT IS THE CONTRACT.   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * App\Support\CourseAccess is the same idea one level up; this class exists
 * because the content-item routes do NOT reuse its single 'You do not own this
 * course' string. backend/ContentItemService passes a DIFFERENT message into
 * the same private guard depending on which door the request came through:
 *
 *   requireOwnsSection()      -> 'You do not own this section'   rows 23, 25
 *   requireOwnsContentItem()  -> 'You do not own this content item'  rows 24, 26
 *   requireGroupAccess()      -> 'You do not own this group'     rows 78, 79
 *
 * ┌── THIS IS THE SECOND HALF OF OPEN DECISION 3.4, AND IT RESOLVES IT. ─────┐
 * │ Session A settled the SECTION routes (rows 17, 19) on the COURSE wording │
 * │ and explicitly left the section/group/item wordings to this session,     │
 * │ with the warning that they must not be unified on a whim (decision 5.4). │
 * │ They are not unified: all three are ported literally, because all three  │
 * │ reach a frontend that shows the server's message. Decision 3.4 can now   │
 * │ be closed.                                                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── THE 404-BEFORE-403 LADDER IS PART OF THE PORT, NOT AN OPTIMISATION. ───┐
 * │ backend/assertOwnsCourseId() resolves the parent course FIRST and        │
 * │ answers 404 'Course not found' when it is missing (soft-deleted), only   │
 * │ then compares owners. Skipping that rung would turn a trashed course's   │
 * │ leftover items into 403s — a different status AND a different claim.     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * WHY Gate::denies AND NOT authorize(): identical to CourseAccess's argument.
 * authorize() raises AuthorizationException, which App\Exceptions\Handler
 * flattens to the generic 'Forbidden' — the right status with the wrong body.
 * Only an exception implementing App\Exceptions\PublicMessage survives, and
 * App\Exceptions\ForbiddenException is that. Going through the Gate (rather
 * than a hand-written teacher_id comparison) keeps Stage 3's admin bypass in
 * play; a manual comparison would lose it silently.
 */
final class ContentItemAccess
{
    /** backend/'s literal strings. All four are part of the API contract. */
    public const ITEM_NOT_FOUND = 'Content item not found';

    public const NOT_OWNER_ITEM = 'You do not own this content item';

    public const NOT_OWNER_SECTION = 'You do not own this section';

    public const NOT_OWNER_GROUP = 'You do not own this group';

    /**
     * Resolve a content-item id, or 404 with backend/'s message.
     *
     * NOT implicit route-model binding, for the same reason CourseAccess::
     * findOrFail() is not: binding's NotFoundHttpException carries "No query
     * results for model [App\Models\ContentItem] {id}", which Handler
     * genericises to 'Not found' precisely because it discloses internals — so
     * the contract string would be lost either way.
     */
    public static function findOrFail(?string $id): ContentItem
    {
        return ContentItem::query()->whereKey((string) $id)->first()
            ?? throw new NotFoundException(self::ITEM_NOT_FOUND);
    }

    public static function findSectionOrFail(?string $id): Section
    {
        return Section::query()->whereKey((string) $id)->first()
            ?? throw new NotFoundException(CourseAccess::SECTION_NOT_FOUND);
    }

    public static function findGroupOrFail(?string $id): CourseGroup
    {
        return CourseGroup::query()->whereKey((string) $id)->first()
            ?? throw new NotFoundException(CourseAccess::GROUP_NOT_FOUND);
    }

    /**
     * PUT/DELETE /content-items/{id} — rows 24 and 26.
     *
     * The ownership hop is the DENORMALISED `content_items.course_id`, one
     * join and not two; see App\Policies\ContentItemPolicy::manage().
     */
    public static function assertItemManageable(ContentItem $item): void
    {
        self::assertParentCourseExists($item->course === null);

        if (Gate::denies('manage', $item)) {
            throw new ForbiddenException(self::NOT_OWNER_ITEM);
        }
    }

    /** POST/PATCH under a section — rows 23 and 25. */
    public static function assertSectionManageable(Section $section): void
    {
        self::assertParentCourseExists($section->course === null);

        if (Gate::denies('manage', $section)) {
            throw new ForbiddenException(self::NOT_OWNER_SECTION);
        }
    }

    /** POST /groups/{groupId}/content-items — row 79. */
    public static function assertGroupManageable(CourseGroup $group): void
    {
        self::assertParentCourseExists($group->course === null);

        if (Gate::denies('manage', $group)) {
            throw new ForbiddenException(self::NOT_OWNER_GROUP);
        }
    }

    /**
     * GET /groups/{groupId}/content-items — row 78.
     *
     * A separate ability from manage(), with the SAME message: backend/'s
     * listByGroup() and createForGroup() both call requireGroupAccess(), which
     * throws one string for both. See CourseGroupPolicy::view() for why the
     * abilities are nonetheless distinct.
     */
    public static function assertGroupViewable(CourseGroup $group): void
    {
        self::assertParentCourseExists($group->course === null);

        if (Gate::denies('view', $group)) {
            throw new ForbiddenException(self::NOT_OWNER_GROUP);
        }
    }

    // ══ STAGE 5 / SESSION C — THE PUBLIC READ SURFACE ═══════════════════════

    /**
     * GET /content-items/{id} — row 21. THE `is_hidden` -> 404 RULE.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ 404 ON A HIDDEN ITEM, NEVER 403. THE ARGUMENT IS THE ORACLE          ║
     * ║ ARGUMENT, IDENTICAL TO CourseAccess::assertVisible()'s.               ║
     * ║                                                                      ║
     * ║ 403 on a guessed UUID CONFIRMS that the id names a real, unreleased   ║
     * ║ lesson. Repeated, that lets someone enumerate an instructor's         ║
     * ║ unpublished pipeline one id at a time without ever seeing a byte of   ║
     * ║ its contents. 404 is indistinguishable from a wrong guess, so the     ║
     * ║ probe learns nothing.                                                 ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── AND THIS IS ONLY **HALF** OF THE HIDDEN RULE. ─────────────────────┐
     * │ The very same authorization outcome has TWO DIFFERENT RESPONSE       │
     * │ SHAPES depending on which route asks, and backend/ is deliberate     │
     * │ about both:                                                          │
     * │                                                                      │
     * │   SINGLE FETCH (here)  hidden + not permitted -> 404.                │
     * │   LIST (row 20, 10)    hidden + not permitted -> SILENTLY FILTERED   │
     * │                        out of the collection; the request is 200.    │
     * │                                                                      │
     * │ They are not unified, and unifying them would break both callers:    │
     * │ 404ing a whole section listing because one lesson in it is hidden    │
     * │ would take the public syllabus offline every time an instructor      │
     * │ staged next term's material, and returning a filtered-to-nothing     │
     * │ 200 for a direct id fetch would be a lie about a specific resource.  │
     * │                                                                      │
     * │ The list half is CourseAccess-free by design: it is a Gate call on   │
     * │ `viewHidden` against the COURSE, made once per request in the        │
     * │ controller — see ContentItemController::index(). Doing it per item   │
     * │ through this method would be one query per lesson AND would raise    │
     * │ where it must filter.                                                │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `Gate::forUser($user)->allows(...)` and NEVER `$user?->can(...)` — open
     * decision 3.7. ContentItemPolicy::view() is declared `?User` and
     * STRUCTURALLY ADMITS GUESTS (a visible item is public), so the null-safe
     * idiom would short-circuit on the USER and answer false before the policy
     * was consulted — turning every public content item into a 404 for every
     * logged-out visitor. A fail-closed, silent outage of the whole public
     * syllabus that no authenticated-actor test would catch.
     *
     * authorize() is likewise forbidden: this is a DEGRADE row and authorize()
     * throws 403 where the contract owes either 200-reduced or 404.
     */
    public static function assertViewable(?User $user, ContentItem $item): void
    {
        if (!Gate::forUser($user)->allows('view', $item)) {
            throw new NotFoundException(self::ITEM_NOT_FOUND);
        }
    }

    /**
     * THE PAYLOAD DECISION, ASKED THE SAME WAY AT ALL FOUR PUBLIC READS.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THIS RETURNS A BOOLEAN AND **NEVER** THROWS. THAT IS THE WHOLE       ║
     * ║ POINT, AND IT IS WHY IT IS NOT `assertConsumable()`.                 ║
     * ║                                                                      ║
     * ║ On a DEGRADE route the un-entitled answer is not a refusal, it is a  ║
     * ║ SMALLER 200. A guest browsing the storefront is the intended user of ║
     * ║ these endpoints, not an intruder. Any variant of this method that    ║
     * ║ raised — authorize(), a ForbiddenException — would take the public   ║
     * ║ course page down for everyone who is not yet a customer.             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * The ability is CoursePolicy::consumeContent, declared `User $user`
     * (non-nullable), so a guest is denied structurally without the policy body
     * ever running. `Gate::forUser()` is used anyway rather than `?->can()`,
     * per the freeze table's mandatory convention for DEGRADE rows: the two
     * idioms agree HERE only because this particular ability happens to be
     * owner-typed, and writing the idiom that is correct for both kinds means
     * a later widening of the signature cannot silently break the route.
     *
     * The caller turns this into a Resource choice — ContentItemResource when
     * true, PublicContentItemResource when false — and those are two separate
     * classes precisely so a wrong answer here degrades rather than leaks.
     */
    public static function mayConsume(?User $user, Course $course): bool
    {
        return Gate::forUser($user)->allows('consumeContent', $course);
    }

    /**
     * May the caller see `is_hidden = 1` rows in a LIST on this course?
     *
     * OWNER AND ADMIN ONLY. A SUBSCRIBED, FULLY PAID-UP STUDENT MUST GET
     * `false` HERE while getting `true` from mayConsume() on the same course in
     * the same request — that gap is the single most-repeated caution in this
     * whole rewrite, and the two methods sit adjacent so the difference is hard
     * to miss and impossible to conflate by accident.
     *
     * Asked ONCE PER REQUEST against the course, not once per row: the rule is
     * a property of the course/caller pair, and a per-row Gate call would add a
     * query per lesson to the hottest public read in the application.
     */
    public static function maySeeHidden(?User $user, Course $course): bool
    {
        return Gate::forUser($user)->allows('viewHidden', $course);
    }

    /**
     * The 404 rung of every ladder above.
     *
     * A soft-deleted course leaves its sections, groups and content items in
     * place (backend/CourseService::delete() says so explicitly), and Course's
     * SoftDeletes global scope then makes every one of those `course`
     * relations null. backend/assertOwnsCourseId() answered 404 'Course not
     * found' for exactly that state, so the policies' fail-closed `false` —
     * which would surface as a 403 — is caught one layer earlier here.
     */
    private static function assertParentCourseExists(bool $missing): void
    {
        if ($missing) {
            throw new NotFoundException(CourseAccess::COURSE_NOT_FOUND);
        }
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
