<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * videos — the detail table for `content_items.type = 'video'`.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE PRIMARY KEY **IS** THE FOREIGN KEY.                                  ║
 * ║                                                                          ║
 * ║ `videos` has no `id` column at all (migration 000015): `content_item_id` ║
 * ║ is the PK, which makes the 1:1 with content_items structural rather than ║
 * ║ conventional. Three consequences Eloquent has to be told about:          ║
 * ║                                                                          ║
 * ║  1. $primaryKey = 'content_item_id' — otherwise every query looks for    ║
 * ║     `id` and fails with a 1054.                                          ║
 * ║  2. $incrementing = false / $keyType = 'string' — a CHAR(36) UUID.       ║
 * ║  3. uniqueIds() = [] — BaseModel's HasUuids would otherwise GENERATE a   ║
 * ║     fresh UUID for the key on create. Here the key is not ours to make:  ║
 * ║     it is the parent content item's id and must always be supplied. A    ║
 * ║     generated one would point at nothing and die on the FK.              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Columns: content_item_id, video_url, duration, is_preview, provider,
 * thumbnail_url. No timestamps.
 *
 * `is_preview` is the free-sample flag behind open decision 3.8 — the FOURTH
 * entitlement branch that ResolvesCourseOwnership::canConsume() does not know
 * about. It is read in this session only as a payload field; the access rule it
 * implies belongs to Session C / ContentItemPolicy::watch().
 */
class Video extends BaseModel
{
    protected $table = 'videos';

    protected $primaryKey = 'content_item_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'content_item_id',
        'video_url',
        'duration',
        'is_preview',
        'provider',
        'thumbnail_url',
    ];

    /**
     * `duration` INT and `is_preview` TINYINT(1) — PDO hands both back as PHP
     * ints (tests/Feature/PdoTypeCastingTest.php), and frontend/src/types/
     * course.ts declares `is_preview?: boolean`. The cast is the contract.
     */
    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'is_preview' => 'boolean',
        ];
    }

    /** No key generation — the parent supplies it. See the class docblock. */
    public function uniqueIds(): array
    {
        return [];
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id', 'id');
    }

    /**
     * Every video under one course, in syllabus order. Same shape as
     * Exam::scopeForCourse() — a whereHas rather than a direct column since
     * `videos` has no `course_id` of its own, only `content_items` does.
     */
    public function scopeForCourse(Builder $query, string $courseId): Builder
    {
        return $query
            ->whereHas('contentItem', fn (Builder $q): Builder => $q->where('course_id', $courseId))
            ->orderBy(
                ContentItem::query()
                    ->select('order_index')
                    ->whereColumn('content_items.id', 'videos.content_item_id')
            )
            ->orderBy('content_item_id');
    }
}
