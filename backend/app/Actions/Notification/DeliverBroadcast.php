<?php

declare(strict_types=1);

namespace App\Actions\Notification;

use App\Models\BroadcastNotification;
use App\Models\Notification;
use Illuminate\Support\Str;

/**
 * FAN-OUT ON WRITE: one `notifications` row per resolved recipient.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `Notification::insert()`, NOT `createMany()` / `Notification::create()`. ║
 * ║                                                                          ║
 * ║ createMany() instantiates a MODEL per row and fires four events on each   ║
 * ║ (saving/creating/created/saved). For an `all_teachers` broadcast on a     ║
 * ║ platform with a few thousand instructors that is thousands of objects and ║
 * ║ tens of thousands of event dispatches, held in memory inside an open      ║
 * ║ transaction, to produce rows nothing observes. `insert()` is the query-   ║
 * ║ builder path: one multi-row INSERT per chunk, no models, no events.       ║
 * ║                                                                          ║
 * ║ THE COST OF THAT CHOICE, STATED: no model events fire, so `HasUuids`     ║
 * ║ does not generate the primary key and `created_at` is not stamped by      ║
 * ║ Eloquent. BOTH ARE SET EXPLICITLY BELOW. If a later stage adds an         ║
 * ║ observer or a `creating` hook to Notification, it will NOT run here —     ║
 * ║ that is a real constraint, and it is why this is one small class with     ║
 * ║ its name on it rather than three lines inside a controller.               ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── ONE `$now` FOR EVERY ROW, TAKEN ONCE. ─────────────────────────────────┐
 * │ `now()` per row would spread a large fan-out across several seconds and  │
 * │ order two recipients' copies of the SAME broadcast differently in their  │
 * │ respective inboxes. One timestamp makes the delivery atomic in the data  │
 * │ as well as in the transaction.                                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * CALLED INSIDE THE CALLER'S TRANSACTION, never opening its own — so a partial
 * delivery can never be committed. See BroadcastNotificationController.
 */
final class DeliverBroadcast
{
    /**
     * MySQL's max_allowed_packet, not a business rule. 500 rows of a TEXT body
     * is comfortably inside the default 64 MB while keeping the statement count
     * low; a single INSERT of 50,000 rows is not.
     */
    private const CHUNK = 500;

    /**
     * @param  list<string>  $recipientUserIds
     * @return int  rows written, for the audit log
     */
    public function __invoke(BroadcastNotification $broadcast, array $recipientUserIds): int
    {
        if ($recipientUserIds === []) {
            return 0;
        }

        $now = now();

        $rows = array_map(static fn (string $userId): array => [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'title' => (string) $broadcast->getAttribute('title'),
            // The COLUMN is `body`. It is presented as `message` on the inbox
            // route and as BOTH `message` and `content` on the admin route.
            // Three names, one column — see NotificationResource.
            'body' => (string) $broadcast->getAttribute('body'),
            'link' => $broadcast->getAttribute('link'),
            'broadcast_id' => (string) $broadcast->getKey(),
            'is_read' => false,
            'created_at' => $now,
        ], array_values($recipientUserIds));

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            Notification::insert($chunk);
        }

        return \count($rows);
    }

    /**
     * Drop every delivered row for a broadcast, so an EDIT can re-resolve its
     * audience from scratch instead of leaving stale recipients behind.
     *
     * ┌── `is_read` STATE IS LOST ON EDIT. DOCUMENTED, NOT ACCIDENTAL. ──────┐
     * │ backend/update() does exactly this: deleteDeliveries() then deliver().│
     * │ A recipient who had already read the announcement sees it unread      │
     * │ again after any edit — which is arguably the RIGHT behaviour for an   │
     * │ edited message, and is certainly the ported one.                       │
     * │                                                                       │
     * │ Contrast the TOGGLE endpoint, which touches no delivered row at all:  │
     * │ disabling hides the notification through Notification::scopeVisible   │
     * │ and re-enabling restores it with every recipient's read state intact. │
     * │ Edit destroys, toggle hides. That difference is the reason the        │
     * │ visibility gate exists.                                               │
     * └───────────────────────────────────────────────────────────────────────┘
     */
    public function clear(BroadcastNotification $broadcast): void
    {
        Notification::query()
            ->where('broadcast_id', (string) $broadcast->getKey())
            ->delete();
    }
}
