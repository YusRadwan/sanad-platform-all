<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * sections — the primary organisational layer of a course's syllabus.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ GRADUATED FROM App\Models\Concerns\PlaceholderModel IN STAGE 5 / A.      ║
 * ║ Dropping that marker is the documented hand-off signal; the matching     ║
 * ║ expectations were retired from tests/Unit/Authorization/                 ║
 * ║ PolicySkeletonTest.php in the same change, and SectionPolicy::manage()   ║
 * ║ got its real body. All three move together or none of them do.           ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Columns (migration 2026_01_01_000013): id, course_id, title, order_index.
 * NO timestamps — the table has neither created_at nor updated_at, so
 * Eloquent's automatic management would UPDATE columns that do not exist.
 *
 * NO SoftDeletes, and none may be added: `sections` has no `deleted_at`, so a
 * delete here is a HARD delete and `content_items` cascade with it
 * (fk_content_items_section, ON DELETE CASCADE). backend/'s SectionRepository
 * documents the same.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THERE IS NO OWNER COLUMN ON THIS TABLE.                                 │
 * │                                                                          │
 * │ A section's tenant is its COURSE's teacher, reachable only through       │
 * │ course(). That is why SectionPolicy::manage() must resolve the parent    │
 * │ first, and why it must handle a NULL course: `courses` uses SoftDeletes, │
 * │ so the relation below resolves to null the moment the course is trashed  │
 * │ while the section row itself still loads perfectly. A policy that        │
 * │ dereferences that null is a 500 where a 404 was owed.                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class Section extends BaseModel
{
    protected $table = 'sections';

    public $timestamps = false;

    /**
     * `order_index` IS FILLABLE, `id` IS NOT.
     *
     * Creation goes through App\Http\Controllers\SectionController, which
     * computes the next index itself; reorder writes the column directly with
     * a scoped UPDATE and never mass-assigns. Listing it keeps both paths
     * honest without opening anything the request body controls — no route in
     * this session accepts a caller-supplied order_index.
     */
    protected $fillable = [
        'course_id',
        'title',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'order_index' => 'integer',
        ];
    }

    /**
     * The owning course — and therefore the tenant boundary.
     *
     * ┌── THIS RETURNS **null** FOR A SOFT-DELETED COURSE. ──────────────────┐
     * │ Deleting a course writes `courses.deleted_at` and DELIBERATELY LEAVES │
     * │ its sections and groups in place (backend/CourseService::delete says  │
     * │ so explicitly, and Stage 5 preserves it). Course's SoftDeletes global │
     * │ scope then makes this relation null while the section row survives.   │
     * │                                                                       │
     * │ Every dereference must be explicit about that case. `?->` is NOT      │
     * │ enough: null-chaining produces a well-formed answer describing an     │
     * │ orphan, which is worse than the 404 backend/ returned                 │
     * │ ('Course not found', from SectionService::assertOwnsCourse).          │
     * └───────────────────────────────────────────────────────────────────────┘
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'id');
    }

    /**
     * The section's lessons — ADDED IN STAGE 5 / SESSION C for
     * `GET /courses/{id}/structure`.
     *
     * ┌── ORDERING IS DECLARED HERE, ONCE, AND IT IS TWO KEYS. ──────────────┐
     * │ `order_index ASC, id ASC` — the same pair, for the same reason, as   │
     * │ Course::sections(). nextOrderIndexInSection() is racy by an approved │
     * │ decision, so two items CAN share an order_index; without the `id`    │
     * │ tie-break MySQL may return them in either order on each request and  │
     * │ the public syllabus appears to shuffle itself between page loads.    │
     * │ backend/ContentItemRepository sorts by exactly this pair in all      │
     * │ three of its listing queries.                                         │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── NO `section_id IS NOT NULL` PREDICATE IS NEEDED, AND THAT IS NOT ──┐
     * │ AN OVERSIGHT. backend/'s structure query carries one because it      │
     * │ selects by `course_id` and must exclude group-owned rows by hand.    │
     * │ This relation selects BY `section_id`, so a row with a null          │
     * │ section_id cannot match it — the exclusion is structural. The        │
     * │ `content_items` XOR check guarantees a group-owned item has          │
     * │ section_id NULL, so /structure sees section-organised content only,  │
     * │ exactly as backend/ does.                                            │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'section_id', 'id')
            ->orderBy('order_index')
            ->orderBy('id');
    }
}
