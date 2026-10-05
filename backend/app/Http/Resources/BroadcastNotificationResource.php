<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\BroadcastAudience;
use App\Models\BroadcastNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Port of backend/BroadcastNotificationService::present() — the shape
 * BroadcastNotifications.tsx already renders (the `PlatformNotification` type
 * in frontend/src/types/common.ts), so the component needed no JSX change.
 *
 * ┌── THREE ODDITIES THAT LOOK LIKE BUGS AND ARE ALL PORTED ON PURPOSE. ─────┐
 * │                                                                          │
 * │ 1. `message` AND `content` CARRY THE SAME VALUE. The admin card reads    │
 * │    one and the shared PlatformNotification type declares the other.      │
 * │    Dropping either blanks a surface.                                     │
 * │                                                                          │
 * │ 2. `is_read` IS HARD-CODED `false`, ALWAYS. A broadcast is not personal  │
 * │    — it has no reader — and this key exists only because the frontend    │
 * │    shares one type between the admin list and the recipient's bell menu. │
 * │    Computing something here (e.g. "have all recipients read it?") would  │
 * │    silently give an existing key a new meaning.                          │
 * │                                                                          │
 * │ 3. `user_id` IS THE **AUTHOR**, not a recipient — `created_by`. The name │
 * │    comes from the shared type again. It is '' rather than null when the  │
 * │    creator's account has been deleted (created_by is ON DELETE SET NULL),│
 * │    matching backend/'s `(string) ($row['created_by'] ?? '')`.            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `specificUser` RETURNS A USERNAME, NOT A UUID: the card renders "@{value}".
 * The uuid is what is STORED (so the broadcast survives a username change) and
 * is resolved back to a username only for display — which is why the controller
 * eager-loads it rather than letting this class query per row.
 *
 * @mixin BroadcastNotification
 */
final class BroadcastNotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BroadcastNotification $broadcast */
        $broadcast = $this->resource;

        $audience = $broadcast->getAttribute('audience');
        $targets = array_map('strval', (array) ($broadcast->getAttribute('target_user_ids') ?? []));

        return [
            'id' => $broadcast->getKey(),
            'user_id' => (string) ($broadcast->getAttribute('created_by') ?? ''),
            'title' => $broadcast->getAttribute('title'),
            'message' => $broadcast->getAttribute('body'),
            'content' => $broadcast->getAttribute('body'),
            'url' => $broadcast->getAttribute('link'),
            'is_read' => false,
            'targetAudience' => $audience instanceof BroadcastAudience ? $audience->value : $audience,

            // NULL — not [] — for every audience but selected_teachers. The
            // admin card branches on `!== null` to decide whether to render the
            // picker summary at all.
            'selectedTeachers' => $audience === BroadcastAudience::SelectedTeachers ? $targets : null,

            'specificUser' => $audience === BroadcastAudience::SpecificUser
                ? ($broadcast->specificUsername ?? $targets[0] ?? null)
                : null,

            'isActive' => (bool) $broadcast->getAttribute('is_active'),
            'startDate' => self::toIsoLocal($broadcast->getAttribute('start_at')),
            'endDate' => self::toIsoLocal($broadcast->getAttribute('end_at')),
            'created_at' => $broadcast->getAttribute('created_at')?->format('Y-m-d H:i:s'),
            'created_by_username' => $broadcast->creator?->getAttribute('username'),

            /*
             * ┌── withCount(), i.e. a SUBQUERY — NOT a join. ───────────────┐
             * │ `(SELECT COUNT(*) FROM notifications WHERE broadcast_id =    │
             * │  broadcast_notifications.id)`. That is what makes the number │
             * │ immune to fan-out multiplication: adding a second aggregate  │
             * │ alongside it cannot inflate either, because neither          │
             * │ multiplies rows in the outer query. A `leftJoin` + COUNT     │
             * │ would give 3 recipients x 1 creator = 3 today and 9 the      │
             * │ moment a second one-to-many is joined — the classic bug this │
             * │ shape structurally cannot have. Pinned by                    │
             * │ BroadcastCrudTest::test_recipients_count_is_not_multiplied.  │
             * └──────────────────────────────────────────────────────────────┘
             */
            'recipients_count' => (int) ($broadcast->getAttribute('notifications_count') ?? 0),
        ];
    }

    /**
     * 'Y-m-d H:i:s' -> 'Y-m-d\TH:i' — the exact format a `datetime-local` input
     * accepts, so the edit button can put the value straight back in the form.
     *
     * NOTE this is a THIRD date shape in Stage 8's payloads, alongside the
     * announcement surface's pseudo-UTC `…Z` and the raw 'Y-m-d H:i:s' used for
     * `created_at`. All three are backend/'s, each driven by what a different
     * piece of the frontend does with the value. See AnnouncementResource.
     */
    private static function toIsoLocal(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d\TH:i') : null;
    }
}
