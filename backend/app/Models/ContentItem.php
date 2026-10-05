<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentItemType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * content_items — the polymorphic content row, and the busiest table in the
 * course surface.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ GRADUATED FROM App\Models\Concerns\PlaceholderModel IN STAGE 5 / B.      ║
 * ║ Same three-part hand-off Section and CourseGroup went through in         ║
 * ║ Session A: the marker is dropped here, the matching expectations leave   ║
 * ║ tests/Unit/Authorization/PolicySkeletonTest.php, and                     ║
 * ║ App\Policies\ContentItemPolicy::manage() gets its real body. All three   ║
 * ║ move together or none of them do.                                        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Columns (migration 2026_01_01_000014): id, section_id, group_id, course_id,
 * title, type, order_index, is_hidden, description, start_at, end_at,
 * created_at. There is NO updated_at, so `$timestamps = false` — Eloquent
 * would otherwise write a column that does not exist. `created_at` is filled
 * by the column's own `DEFAULT CURRENT_TIMESTAMP`, exactly as backend/'s
 * `INSERT … NOW()` did.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE POLYMORPHISM HERE IS **NOT** ELOQUENT'S `morphTo`, AND MUST NOT      │
 * │ BECOME IT.                                                               │
 * │                                                                          │
 * │ `morphTo` needs a `*_type` / `*_id` PAIR on this table. This schema has  │
 * │ neither: the discriminator is the `type` ENUM and the join key is the    │
 * │ detail table's own `content_item_id`. Introducing morphTo would mean     │
 * │ ADDING COLUMNS, and the schema is frozen as Stage 0 ported it.           │
 * │                                                                          │
 * │ So the shape is four conditional `hasOne` relations plus a type-dispatch │
 * │ helper. Each relation is legitimately absent for four of the five types, │
 * │ which is why nothing here ever eager-loads all four at once — see        │
 * │ loadDetailsFor().                                                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `course_id` IS DENORMALISED ON PURPOSE. IT IS THE OWNERSHIP HOP. ──────┐
 * │ It could be derived (section_id -> sections.course_id, or group_id ->    │
 * │ course_groups.course_id), and it is stored anyway precisely so that      │
 * │ "who owns this content item?" is ONE join and not two — including for    │
 * │ items under a group, where the section chain does not exist at all.      │
 * │ App\Policies\ContentItemPolicy::manage() uses it directly and must not   │
 * │ be "tidied up" into a section/group traversal.                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class ContentItem extends BaseModel
{
    protected $table = 'content_items';

    public $timestamps = false;

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `section_id`, `group_id` AND `course_id` ARE **DELIBERATELY ABSENT**.║
     * ║                                                                      ║
     * ║ `content_items` carries                                              ║
     * ║   CHECK ((section_id IS NULL) <> (group_id IS NULL))                 ║
     * ║ — exactly one parent, never both, never neither. If both columns     ║
     * ║ were mass-assignable, a single `ContentItem::create($request->all())`║
     * ║ anywhere in a future stage could set both (a 3819 constraint error   ║
     * ║ at best) or neither.                                                 ║
     * ║                                                                      ║
     * ║ The invariant is therefore enforced BY CONSTRUCTION, not by a check: ║
     * ║ the only two ways to make a row are createUnderSection() and         ║
     * ║ createUnderGroup(), each of which sets ONE parent column and derives ║
     * ║ `course_id` from that parent. There is no third door.                ║
     * ║                                                                      ║
     * ║ Pinned by ContentItemCrudTest::test_neither_parent_column_is_mass_   ║
     * ║ assignable.                                                          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    protected $fillable = [
        'title',
        'type',
        'order_index',
        'is_hidden',
        'description',
        'start_at',
        'end_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ContentItemType::class,
            'order_index' => 'integer',
            'is_hidden' => 'boolean',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // ── PARENTS ───────────────────────────────────────────────────────────

    /**
     * The owning course — reached through the DENORMALISED `course_id`.
     *
     * Null for a soft-deleted course, like Section::course() and
     * CourseGroup::course(): Course's SoftDeletes global scope applies to the
     * relation, while this row survives (backend/CourseService::delete()
     * deliberately leaves sections and content items in place). Every caller
     * owes that null an explicit branch — a policy that dereferences it is a
     * 500 where a 404 was owed.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'id');
    }

    /** The parent section, or null when this item hangs off a GROUP. */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id', 'id');
    }

    /** The parent group, or null when this item hangs off a SECTION. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CourseGroup::class, 'group_id', 'id');
    }

    // ── THE FOUR DETAIL RELATIONS ─────────────────────────────────────────

    public function video(): HasOne
    {
        return $this->hasOne(Video::class, 'content_item_id', 'id');
    }

    public function link(): HasOne
    {
        return $this->hasOne(Link::class, 'content_item_id', 'id');
    }

    public function note(): HasOne
    {
        return $this->hasOne(Note::class, 'content_item_id', 'id');
    }

    public function exam(): HasOne
    {
        return $this->hasOne(Exam::class, 'content_item_id', 'id');
    }

    /**
     * Which of the four relations (if any) carries THIS item's detail row.
     *
     * ┌── `File => null` IS AN EXPLICIT DECISION, NOT A FALLTHROUGH. ────────┐
     * │ Approved decision #1 for this session: `type = 'file'` stays         │
     * │ UNIMPLEMENTED. It is a legal enum value, and a `file` item gets a    │
     * │ `content_items` row and NOTHING ELSE — exactly what backend/         │
     * │ ContentItemService::saveTypeDetail() does, whose if/elseif chain has │
     * │ no `file` branch at all.                                             │
     * │                                                                      │
     * │ The `match` is EXHAUSTIVE over the enum and names File explicitly    │
     * │ rather than relying on a `default`, so adding a seventh type is a    │
     * │ loud UnhandledMatchError rather than a silently detail-less content  │
     * │ type in production.                                                  │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function detailRelationName(): ?string
    {
        return self::relationNameFor($this->getAttribute('type'));
    }

    /** The loaded detail model for this item's type, or null. */
    public function detail(): Video|Link|Note|Exam|null
    {
        $relation = $this->detailRelationName();

        return $relation === null ? null : $this->getRelationValue($relation);
    }

    /** @see detailRelationName() — the same match, reachable without an instance. */
    public static function relationNameFor(?ContentItemType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        return match ($type) {
            ContentItemType::Video => 'video',
            ContentItemType::Link => 'link',
            ContentItemType::Note => 'note',
            ContentItemType::Exam => 'exam',
            // Decision #1 — deliberately no detail table. See the docblock.
            ContentItemType::File => null,
        };
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ TYPE-GROUPED EAGER LOADING. THE N+1 FIX, AND **NOT** A BLANKET       ║
     * ║ `->with(['video','link','note','exam'])`.                            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * backend/ContentItemService::presentMany() buckets the rows by `type`
     * FIRST and then issues at most one query per type ACTUALLY PRESENT. A
     * group holding ten videos therefore costs ONE extra query, not four.
     *
     * The obvious Eloquent translation — naming all four relations on the
     * query — is a REGRESSION disguised as idiomatic code: `with()` fires one
     * SELECT per named relation unconditionally, so that same all-videos list
     * would run three extra queries against `links`, `notes` and `exams`
     * whose IN() lists can only ever come back empty.
     *
     * So the collection is grouped by type here and `loadMissing()` is called
     * per bucket — one query per type present, zero for types absent, and zero
     * for a bucket of `file` items (relationNameFor() answers null).
     *
     * `loadMissing`, not `load`: a caller that already resolved a relation (the
     * post-mutation read-back does) must not pay for it twice.
     *
     * Pinned by ContentItemQueryCountTest, which asserts the count does NOT
     * scale with the number of unused detail types.
     *
     * @param  Collection<int, ContentItem>  $items
     * @return Collection<int, ContentItem>  the same collection, hydrated
     */
    public static function loadDetailsFor(Collection $items): Collection
    {
        $buckets = $items->groupBy(
            static fn (self $item): string => (string) $item->getAttribute('type')?->value
        );

        foreach ($buckets as $bucket) {
            /** @var Collection<int, ContentItem> $bucket */
            $relation = self::relationNameFor($bucket->first()?->getAttribute('type'));

            if ($relation !== null) {
                $bucket->loadMissing($relation);
            }
        }

        return $items;
    }

    // ── THE ONLY TWO WAYS TO CREATE A ROW ─────────────────────────────────

    /**
     * A content item under a SECTION. `group_id` stays NULL by construction.
     *
     * `order_index` is `COALESCE(MAX(order_index), -1) + 1` over the SECTION,
     * verbatim from backend/ContentItemRepository::create()'s inline default —
     * and with the same unlocked race as sections and groups, left alone by
     * approved decision #3 (cosmetic: two items tie for a display position, and
     * the tie is broken deterministically by the `id` secondary sort).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createUnderSection(Section $section, array $attributes): self
    {
        $item = new self();

        $item->fill($attributes);
        $item->setAttribute('section_id', $section->getKey());
        $item->setAttribute('group_id', null);
        $item->setAttribute('course_id', $section->getAttribute('course_id'));
        $item->setAttribute(
            'order_index',
            $attributes['order_index'] ?? self::nextOrderIndexInSection((string) $section->getKey())
        );
        $item->save();

        return $item;
    }

    /**
     * A content item under a GROUP. `section_id` stays NULL by construction.
     *
     * ┌── `order_index` IS ALWAYS 0 HERE, AND THAT IS PORTED, NOT FORGOTTEN. ┐
     * │ backend/ContentItemService::createForGroup() passes no `order_index`,│
     * │ and ContentItemRepository::create()'s default expression is          │
     * │  $data['order_index'] ?? ($data['section_id'] ? nextOrderIndex(…) : 0)│
     * │ — the MAX()+1 branch is reachable ONLY through a section_id. Every    │
     * │ group-scoped item in production therefore sits at 0, and the list is  │
     * │ ordered by the `id` tie-break (a UUIDv7, i.e. creation order). There  │
     * │ is no group reorder route to disturb it.                             │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createUnderGroup(CourseGroup $group, array $attributes): self
    {
        $item = new self();

        $item->fill($attributes);
        $item->setAttribute('section_id', null);
        $item->setAttribute('group_id', $group->getKey());
        $item->setAttribute('course_id', $group->getAttribute('course_id'));
        $item->setAttribute('order_index', $attributes['order_index'] ?? 0);
        $item->save();

        return $item;
    }

    /**
     * `COALESCE(MAX(order_index), -1) + 1` scoped to one section.
     *
     * The COALESCE default of -1 (not 0) is what puts the FIRST item at index
     * 0; see SectionController::nextOrderIndex() for the same reasoning and for
     * why a plain `(int) $max` cast would be wrong.
     */
    public static function nextOrderIndexInSection(string $sectionId): int
    {
        $max = self::query()->where('section_id', $sectionId)->max('order_index');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * The section-scoped reorder write, expressed once.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ BOTH COLUMNS IN THE WHERE, EVERY TIME:                               ║
     * ║   UPDATE content_items SET order_index = ?                           ║
     * ║   WHERE id = ? AND section_id = ?                                     ║
     * ║                                                                      ║
     * ║ With `id` alone, a caller who legitimately owns section A could pass  ║
     * ║ an id from section B — in ANOTHER teacher's course — and renumber it. ║
     * ║ The `section_id` predicate is the tenant guard; the ownership check   ║
     * ║ upstream only proved the SECTION is theirs.                           ║
     * ║                                                                      ║
     * ║ A foreign id must be IGNORED, never rejected: the scoped UPDATE       ║
     * ║ matches zero rows and the request still answers 200, exactly as       ║
     * ║ backend/ContentItemRepository::reorder() behaved. Same tolerance, and ║
     * ║ the same reason, as Session A's section reorder.                      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * DB::transaction per approved decision #2: backend/'s loop was unwrapped,
     * so a failure halfway through left an order that was neither the old one
     * nor the new one.
     *
     * @param  list<string>  $orderedIds
     */
    public static function reorderWithinSection(string $sectionId, array $orderedIds): void
    {
        DB::transaction(static function () use ($sectionId, $orderedIds): void {
            foreach ($orderedIds as $index => $id) {
                self::query()
                    ->where('id', $id)
                    ->where('section_id', $sectionId)
                    ->update(['order_index' => $index]);
            }
        });
    }
}
