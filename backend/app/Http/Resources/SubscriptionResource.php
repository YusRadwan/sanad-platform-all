<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The RAW subscription row, as backend/ returned it from
 * SubscriptionRepository::findByStudentAndCourse() — six keys, snake_case,
 * no joins.
 *
 * Two routes ship it: POST /enrollment-codes/redeem (under `subscription`) and
 * PUT /admin/enrollments/toggle (under `enrollment`). Same shape, two envelope
 * keys, because backend/ named them differently and both are on the wire.
 *
 * ┌── `status` IS A STRING HERE, NOT AN ENUM CASE. ──────────────────────────┐
 * │ Subscription casts the column to App\Enums\SubscriptionStatus, which is  │
 * │ right for the MODEL — comparisons and assignments become type-checked.   │
 * │ A backed enum JSON-encodes to its value anyway, so `->value` is not      │
 * │ strictly required; it is written explicitly so that removing the cast,   │
 * │ or adding a non-backed case, cannot quietly change the wire format.      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * DATES ARE 'Y-m-d H:i:s', not ISO-8601. Eloquent's default datetime
 * serialisation is ISO-8601 with a timezone offset; backend/ handed the client
 * MySQL's own DATETIME text. Every Resource in this project formats
 * explicitly for that reason — an implicit `->toJson()` on a cast attribute is
 * how a date format changes without anyone editing a date.
 */
final class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Subscription $subscription */
        $subscription = $this->resource;

        return [
            'id' => (string) $subscription->getKey(),
            'student_id' => (string) $subscription->student_id,
            'course_id' => (string) $subscription->course_id,
            'status' => $subscription->status?->value,
            'expires_at' => $subscription->expires_at?->format('Y-m-d H:i:s'),
            'created_at' => $subscription->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
