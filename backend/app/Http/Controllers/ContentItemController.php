<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ContentItem\ContentDetailPersisters;
use App\Enums\ContentItemType;
use App\Http\Requests\ContentItem\DeleteContentItemRequest;
use App\Http\Requests\ContentItem\ListGroupContentItemsRequest;
use App\Http\Requests\ContentItem\ReorderContentItemsRequest;
use App\Http\Requests\ContentItem\StoreGroupContentItemRequest;
use App\Http\Requests\ContentItem\StoreSectionContentItemRequest;
use App\Http\Requests\ContentItem\UpdateContentItemRequest;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Http\Resources\ContentItemResource;
use App\Http\Resources\PublicContentItemResource;
use App\Http\Responses\ApiResponse;
use App\Models\ContentItem;
use App\Models\Course;
use App\Support\ContentItemAccess;
use App\Support\CourseAccess;
use App\Support\EmployeePermissions;
use App\Support\EmployeeSession;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Content items — freeze-table rows 20-26 and 78-79. NINE routes.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ SESSION C OPENED THE FIRST THREE **PUBLIC** ROUTES ON THIS CONTROLLER.  ║
 * ║ EVERYTHING BELOW THE `── PUBLIC READS ──` DIVIDER IS REACHABLE BY A     ║
 * ║ LOGGED-OUT VISITOR. TREAT EVERY LINE OF IT AS A LEAK VECTOR.            ║
 * ║                                                                          ║
 * ║   row 20  GET /sections/{sectionId}/content-items   DEGRADE              ║
 * ║   row 21  GET /content-items/{id}                   404 then DEGRADE     ║
 * ║   row 22  GET /courses/{courseId}/videos            DEGRADE              ║
 * ║                                                                          ║
 * ║ Sessions A and B kept the entire surface behind `auth` specifically so   ║
 * ║ that payload leakage was impossible BY CONSTRUCTION until the           ║
 * ║ public/private Resource split existed. It exists now                     ║
 * ║ (PublicContentItemResource), so the guarantee moves from "there is no    ║
 * ║ route" to "there are two structurally different classes and the         ║
 * ║ un-entitled one cannot express a paid field". The three rules that make  ║
 * ║ that true, and that must not be relaxed:                                 ║
 * ║                                                                          ║
 * ║  1. NEVER `$this->authorize()` / `Gate::authorize()` on these three.     ║
 * ║     They are DEGRADE rows. authorize() throws 403 at a storefront        ║
 * ║     visitor — an outage of the public course page, not a protection.     ║
 * ║  2. ALWAYS `Gate::forUser($request->user())->allows(...)`, never         ║
 * ║     `$request->user()?->can(...)` — open decision 3.7, a real            ║
 * ║     previously-caught bug: `?->` short-circuits on the USER, so a guest  ║
 * ║     is denied before any policy is consulted.                            ║
 * ║  3. Decision 3.6 CASCADES to all three. A draft course's content is 404, ║
 * ║     exactly as its row and its syllabus already are.                     ║
 * ║                                                                          ║
 * ║ Pinned by PublicPayloadLeakTest (all six content types, zero forbidden   ║
 * ║ keys), PublicContentReadTest (the actor matrix) and                      ║
 * ║ ContentItemAuthGateTest (the six writes are still `auth`-only).          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Every refusal (404 section/group/item, 404 course, the three different 403
 * strings) is raised by the Form Requests before these methods are entered;
 * see App\Support\ContentItemAccess.
 *
 * ┌── THE POST-MUTATION READ-BACK IS PORTED, NOT DECORATIVE. ────────────────┐
 * │ backend/'s create/update both end with `return $this->find($id, …)` and  │
 * │ say why: "Read back AS the mutating owner/admin so the management UI     │
 * │ still receives the real detail payload it needs to prepopulate its       │
 * │ forms." videoApiService.ts and friends map the RESPONSE straight into    │
 * │ their form state, so a 201 without `detail` would blank every            │
 * │ type-specific field the instructor just typed.                            │
 * │                                                                          │
 * │ Here the read-back is `->refresh()` plus a detail load rather than a     │
 * │ second service call, because the model is already in hand.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class ContentItemController extends Controller
{
    /**
     * POST /api/v1/sections/{sectionId}/content-items -> 201. Row 23.
     *
     * DB::transaction per approved decision #2: the parent `content_items` row
     * and its detail row are two statements that must both land or neither.
     * backend/ left them unwrapped, so a detail write that failed after the
     * parent insert (a `notes` row hitting a length limit, say) committed an
     * orphaned, detail-less content item that the UI then rendered as empty.
     * The URL case — the loudest one — is already handled a layer earlier by
     * Form Request validation; this covers the rest.
     */
    public function store(StoreSectionContentItemRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->assertEmployeePermissionForType($request, $request->resolvedType());

        $item = DB::transaction(function () use ($request, $data): ContentItem {
            $item = ContentItem::createUnderSection($request->section(), self::parentAttributes($data));

            ContentDetailPersisters::apply($item, $data);

            return $item;
        });

        return ApiResponse::created(
            new ContentItemResource(self::readBack($item)),
            'تم إنشاء العنصر بنجاح'
        );
    }

    /**
     * POST /api/v1/groups/{groupId}/content-items -> 201. Row 79.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE SUCCESS MESSAGE IS TYPE-CONDITIONAL ON THIS ROUTE, AND EVERY TYPE ║
     * ║ NOW GETS ITS OWN SENTENCE.                                            ║
     * ║                                                                      ║
     * ║ This used to be a straight port of backend/ContentItemController::     ║
     * ║ storeByGroup()'s lookup-with-fallback, under which a VIDEO or a LINK  ║
     * ║ created under a group was announced as "تم إنشاء المذكرة بنجاح" (the  ║
     * ║ NOTE was created) — the lookup fell through to its default for every  ║
     * ║ type except exam. Real-world confusion: the instructor's own Links    ║
     * ║ and Notes management pages (frontend/src/components/Instructor/       ║
     * ║ LinksManagement.tsx, NotesManagement.tsx) each read this message as a ║
     * ║ success toast, and a link that announces itself as a note is exactly  ║
     * ║ the cross-page confusion those two screens must not have. Fixed to    ║
     * ║ name the real type; the SECTION route's generic 'تم إنشاء العنصر      ║
     * ║ بنجاح' is untouched — that one was never type-conditional and never   ║
     * ║ claimed to be a specific type.                                        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function storeByGroup(StoreGroupContentItemRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->assertEmployeePermissionForType($request, $request->resolvedType());

        $item = DB::transaction(function () use ($request, $data): ContentItem {
            $item = ContentItem::createUnderGroup($request->group(), self::parentAttributes($data));

            ContentDetailPersisters::apply($item, $data);

            return $item;
        });

        return ApiResponse::created(
            new ContentItemResource(self::readBack($item)),
            self::groupCreationMessage($request->resolvedType())
        );
    }

    /**
     * GET /api/v1/groups/{groupId}/content-items. Row 78.
     *
     * ADMIN/INSTRUCTOR ONLY, in this session AND in the final contract — see
     * ListGroupContentItemsRequest for why this read is not one of the two
     * that Session C opens.
     *
     * The envelope is a BARE LIST, not `{ items: [...] }`:
     * backend/ContentItemController::indexByGroup passes the array straight to
     * ApiResponse::success(). That differs from GroupController's nested
     * `{ groups: [...] }` shape — two endpoints, two envelopes, both ported.
     *
     * ORDERING is `order_index ASC, id ASC`, from listByGroup(). Every
     * group-scoped item sits at order_index 0 (see
     * ContentItem::createUnderGroup), so the `id` tie-break — a UUIDv7, i.e.
     * creation order — is what actually decides the list. It is load-bearing,
     * not decoration.
     */
    public function indexByGroup(ListGroupContentItemsRequest $request): JsonResponse
    {
        $items = ContentItem::query()
            ->where('group_id', $request->group()->getKey())
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        // Type-grouped, NOT ->with([...all five...]). See the method's docblock.
        ContentItem::loadDetailsFor($items);

        // Stage 6 / Session A: ContentItemResource's exam/assignment arms now
        // consult ExamPolicy / AssignmentPolicy, which walk item -> course. ONE
        // query for the whole page instead of one per exam.
        $items->loadMissing('course');

        return ApiResponse::success(ContentItemResource::collection($items));
    }

    /**
     * PUT /api/v1/content-items/{id}. Row 24.
     *
     * ┌── THE PARENT WRITE IS `array_filter(…, fn ($v) => $v !== null)`. ────┐
     * │ Reproduced here as an explicit per-field `has() && !== null` ladder   │
     * │ rather than a filter over an array literal, because the two are not   │
     * │ the same for `is_hidden`: backend/ reads it presence-based            │
     * │ (`array_key_exists(…) ? (int) … : null`) so that `false` is a real    │
     * │ un-hide instruction, while the other four are read `?? null` so that  │
     * │ null is "leave it". One idiom cannot express both.                    │
     * │                                                                      │
     * │ `''` reaches the column for `title` and `description` — that is the   │
     * │ CLEAR semantic, and it survives only because                          │
     * │ `api/v1/content-items/*` is on AppServiceProvider's                   │
     * │ preserveEmptyStringsOnClearableWrites() allow-list. See               │
     * │ UpdateContentItemRequest's semantics table.                           │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function update(UpdateContentItemRequest $request): JsonResponse
    {
        $item = $request->item();
        $data = $request->validated();

        $this->assertEmployeePermissionForType($request, $item->getAttribute('type'));

        DB::transaction(function () use ($item, $data): void {
            foreach (['title', 'description', 'start_at', 'end_at'] as $field) {
                // `?? null` + array_filter: absent AND explicit-null both mean
                // "leave it". '' is neither, and is written.
                if (($data[$field] ?? null) !== null) {
                    $item->setAttribute($field, $data[$field]);
                }
            }

            // Presence-based, so `is_hidden: false` genuinely un-hides.
            if (array_key_exists('is_hidden', $data) && $data['is_hidden'] !== null) {
                $item->setAttribute('is_hidden', (bool) $data['is_hidden']);
            }

            // Same presence rule: order_index 0 is a real position, not
            // "absent". See UpdateContentItemRequest.
            if (array_key_exists('order_index', $data) && $data['order_index'] !== null) {
                $item->setAttribute('order_index', (int) $data['order_index']);
            }

            $item->save();

            // The type comes from the STORED row, never the request — see
            // UpdateContentItemRequest's banner.
            ContentDetailPersisters::apply($item, $data);
        });

        return ApiResponse::success(
            new ContentItemResource(self::readBack($item)),
            'تم تحديث العنصر بنجاح'
        );
    }

    /**
     * PATCH /api/v1/sections/{sectionId}/content-items/reorder. Row 25.
     *
     * The scoped `WHERE id = ? AND section_id = ?` UPDATE — and the tolerance
     * of foreign ids that comes with it — lives on the model; see
     * ContentItem::reorderWithinSection(), which also holds the transaction.
     */
    public function reorder(ReorderContentItemsRequest $request): JsonResponse
    {
        ContentItem::reorderWithinSection(
            (string) $request->section()->getKey(),
            $request->orderedIds()
        );

        return ApiResponse::success(null, 'تم إعادة ترتيب العناصر بنجاح');
    }

    /**
     * DELETE /api/v1/content-items/{id} — HARD delete, NOT audited. Row 26.
     * Both properties are ported; see DeleteContentItemRequest.
     */
    public function destroy(DeleteContentItemRequest $request): JsonResponse
    {
        $item = $request->item();

        $this->assertEmployeePermissionForType($request, $item->getAttribute('type'));

        $item->delete();

        return ApiResponse::success(null, 'تم حذف العنصر بنجاح');
    }

    /**
     * "إدارة الأدمنز" (decision 10.37) — the ONE place a content item's
     * TYPE decides which permission key gates it; see
     * EmployeePermissions::keyForContentType()'s own docblock for why this
     * cannot be a route-level `employee.permission:<key>` middleware like
     * every other gated route in this application.
     */
    private function assertEmployeePermissionForType(Request $request, ?ContentItemType $type): void
    {
        if ($type === null) {
            return;
        }

        $key = EmployeePermissions::keyForContentType($type);

        if (!EmployeeSession::hasPermission($request, $key)) {
            throw new ForbiddenException('لا تملك صلاحية الوصول إلى هذه الصفحة');
        }
    }

    // ══ PUBLIC READS — STAGE 5 / SESSION C ═══════════════════════════════
    //
    // No `auth`. No `role:`. A guest reaches every line below.

    /**
     * GET /api/v1/sections/{sectionId}/content-items — PUBLIC. Row 20.
     *
     * THE REFUSAL LADDER, IN ORDER, AND EACH RUNG IS LOAD-BEARING:
     *   1. 404 'Section not found'  — no such section.
     *   2. 404 'Course not found'   — parent course soft-deleted (its items
     *      survive the delete on purpose; see CourseAccess::parentCourseOf).
     *   3. 404 'Course not found'   — parent course is a DRAFT and the caller
     *      is neither its owner nor an admin. DECISION 3.6's CASCADE, and it
     *      is the rung backend/ does not have: listBySection() checked only
     *      that the section existed, so a draft course's entire item list was
     *      readable by anyone holding a section id. Hiding the course row
     *      while leaving its lessons enumerable would hide nothing.
     *
     * ┌── HIDDEN ITEMS ARE **FILTERED**, NOT REFUSED. ROW 20 IS NOT ROW 21. ─┐
     * │ Same authorization outcome, deliberately different response shape:   │
     * │ a hidden lesson simply is not in the collection, and the request is  │
     * │ 200. 404ing the whole listing because one lesson is staged for next  │
     * │ term would take the public syllabus offline every time an instructor │
     * │ prepares material. See ContentItemAccess::assertViewable() for the   │
     * │ full two-shapes argument.                                            │
     * │                                                                      │
     * │ A SUBSCRIBED STUDENT IS FILTERED TOO. maySeeHidden() is owner/admin  │
     * │ only while mayConsume() — two lines down, on the same course, in the │
     * │ same request — says yes for them. Two different questions, and the   │
     * │ paying student is exactly the actor whose answers differ.            │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * The Gate is consulted TWICE PER REQUEST, not twice per row: both rules
     * are properties of the (caller, course) pair, and a per-item call would
     * be a subscription EXISTS query per lesson on a public endpoint.
     */
    public function index(Request $request, string $sectionId): JsonResponse
    {
        $section = ContentItemAccess::findSectionOrFail($sectionId);
        $course = CourseAccess::parentCourseOf($section);

        CourseAccess::assertVisible($request->user(), $course);

        $items = ContentItem::query()
            ->where('section_id', $section->getKey())
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        if (!ContentItemAccess::maySeeHidden($request->user(), $course)) {
            $items = $items->reject(
                static fn (ContentItem $item): bool => (bool) $item->getAttribute('is_hidden')
            )->values();
        }

        return ApiResponse::success(self::present($request, $course, $items));
    }

    /**
     * GET /api/v1/content-items/{id} — PUBLIC. Row 21.
     *
     * TWO INDEPENDENT DECISIONS, IN THIS ORDER, AND THEY ARE NOT THE SAME
     * QUESTION:
     *
     *   assertViewable()  MAY YOU KNOW IT EXISTS?  -> 404 if not (hidden item,
     *                     non-owner). Includes a subscribed student.
     *   mayConsume()      WHICH SHAPE DO YOU GET?  -> full or public. Never a
     *                     refusal; the un-entitled answer is a smaller 200.
     *
     * Collapsing them into one boolean is the leak this session exists to
     * prevent: a guest is `true` on the first and `false` on the second.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $item = ContentItemAccess::findOrFail($id);

        // 404 'Course not found' for an orphan, then decision 3.6's cascade.
        $course = $item->course ?? throw new NotFoundException(CourseAccess::COURSE_NOT_FOUND);

        CourseAccess::assertVisible($request->user(), $course);

        // 404 'Content item not found' — hidden, and you are not its owner.
        ContentItemAccess::assertViewable($request->user(), $item);

        $items = ContentItem::loadDetailsFor(new EloquentCollection([$item]));

        return ApiResponse::success(self::present($request, $course, $items)->first());
    }

    /**
     * GET /api/v1/courses/{courseId}/videos — PUBLIC. Row 22.
     *
     * ┌── `is_hidden = 0` IS UNCONDITIONAL HERE — INCLUDING FOR THE OWNER. ──┐
     * │ backend/ContentItemRepository::listByCourseAndType() puts the filter │
     * │ IN THE SQL with no caller-dependent branch, so this listing has      │
     * │ never shown a hidden video to anybody — not an admin, not the        │
     * │ instructor who hid it. That differs from row 20, where the filter is │
     * │ entitlement-dependent.                                               │
     * │                                                                      │
     * │ Ported as-is rather than "made consistent". The endpoint backs the   │
     * │ storefront's course-wide video strip; widening it for owners would   │
     * │ change what the management UI renders, and narrowing row 20 to match │
     * │ would break the instructor's own section view. Two endpoints, two    │
     * │ rules, both real. Pinned by PublicContentReadTest::test_the_video_   │
     * │ listing_hides_a_hidden_video_from_the_owner_too.                     │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * SPANS BOTH PARENTS: `WHERE course_id = ?` with no section/group
     * predicate, so a video hanging off a CourseGroup appears here even though
     * it is excluded from /structure. That is backend/'s behaviour and the
     * reason the endpoint exists — CourseDetailsPage has no single section or
     * group context to scope a request to.
     */
    public function indexVideosByCourse(Request $request, string $courseId): JsonResponse
    {
        $course = CourseAccess::findOrFail($courseId);

        CourseAccess::assertVisible($request->user(), $course);

        $items = ContentItem::query()
            ->where('course_id', $course->getKey())
            ->where('type', ContentItemType::Video)
            // Unconditional. See the docblock — this is not maySeeHidden().
            ->where('is_hidden', false)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(self::present($request, $course, $items));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE ONE PLACE THE PUBLIC/PRIVATE PAYLOAD DECISION IS MADE.           ║
     * ║ ALL THREE PUBLIC READS GO THROUGH IT. DO NOT INLINE IT BACK.         ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * Written once so the entitlement question is asked identically at every
     * public route — three hand-written copies is how one of them ends up
     * with the ternary the wrong way round, and the freeze table's whole
     * DEGRADE section exists because backend/ had nine such copies.
     *
     * ┌── THE DETAIL ROWS ARE EAGER-LOADED FOR **BOTH** BRANCHES. ───────────┐
     * │ Deliberate, and it is the port: backend/presentMany() batches the    │
     * │ detail fetch BEFORE consulting `$entitled`, then simply does not     │
     * │ serialise it in the public branch. Keeping that shape here means the │
     * │ N+1 fix (ContentItem::loadDetailsFor — one query per type PRESENT,   │
     * │ zero for types absent) is not duplicated into two divergent paths.   │
     * │                                                                      │
     * │ It is safe precisely BECAUSE PublicContentItemResource is a separate │
     * │ class: a loaded `note` relation on the model cannot reach the wire   │
     * │ through a Resource that has no line of code capable of reading it.   │
     * │ A `when()`-based design could not make that claim — the data would   │
     * │ be one mis-evaluated condition from the response.                    │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @param  EloquentCollection<int, ContentItem>  $items
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    private static function present(Request $request, Course $course, EloquentCollection $items)
    {
        ContentItem::loadDetailsFor($items);

        // Stage 6 / Session A: the exam/assignment arms of ContentItemResource
        // ask ExamPolicy / AssignmentPolicy, which resolve item -> course. The
        // course is already in hand, so seed the relation rather than issuing a
        // SELECT per item — it is the SAME row by construction (`$items` was
        // scoped to it, or resolved from it).
        foreach ($items as $item) {
            $item->setRelation('course', $course);
        }

        return ContentItemAccess::mayConsume($request->user(), $course)
            ? ContentItemResource::collection($items)
            : PublicContentItemResource::collection($items);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * The `content_items` columns a CREATE writes, with backend/'s defaults.
     *
     * `is_hidden` is `?? 0` and NOT presence-based here: creation has no
     * previous value to leave alone, exactly as GroupController::store()
     * argues for `course_groups.is_hidden`.
     *
     * `section_id`/`group_id`/`course_id` are absent on purpose — they are not
     * fillable and are set by the two factory methods, which is what makes the
     * XOR invariant structural. See App\Models\ContentItem.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function parentAttributes(array $data): array
    {
        return [
            'title' => (string) $data['title'],
            'type' => $data['type'],
            'is_hidden' => (bool) ($data['is_hidden'] ?? false),
            'description' => $data['description'] ?? null,
            'start_at' => $data['start_at'] ?? null,
            'end_at' => $data['end_at'] ?? null,
        ];
    }

    /**
     * One sentence per real type — see this method's caller for why a video
     * or a link announcing itself as "the note was created" was a genuine
     * user-facing bug, not a cosmetic one. `null` (no type sent) defaults to
     * the same "note" wording StoreGroupContentItemRequest defaults the type
     * itself to.
     */
    private static function groupCreationMessage(?ContentItemType $type): string
    {
        return match ($type) {
            ContentItemType::Exam => 'تم إنشاء الامتحان بنجاح',
            ContentItemType::Video => 'تم إضافة الفيديو بنجاح',
            ContentItemType::Link => 'تم إضافة اللينك بنجاح',
            ContentItemType::File => 'تم رفع الملف بنجاح',
            ContentItemType::Note, null => 'تم إنشاء المذكرة بنجاح',
        };
    }

    /**
     * Reload the row and its detail so the response carries what was actually
     * persisted — including the columns the persister defaulted.
     *
     * `unsetRelations()` before the reload matters: a stale `video` relation
     * resolved BEFORE the persister ran would make loadMissing() a no-op and
     * ship the pre-write state back to the caller.
     */
    private static function readBack(ContentItem $item): ContentItem
    {
        $item->unsetRelations()->refresh();

        $relation = $item->detailRelationName();

        if ($relation !== null) {
            $item->load($relation);
        }

        return $item;
    }
}
