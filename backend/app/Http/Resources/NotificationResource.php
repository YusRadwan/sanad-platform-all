<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Port of backend/app/Services/NotificationService::present() — seven keys.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE COLUMN IS `body`. THE KEY IS `message`. THIS IS NOT A TYPO.          ║
 * ║                                                                          ║
 * ║ `notifications.body` (migration 33) is renamed on the way out because    ║
 * ║ the frontend's `Notification` interface in services/notificationService  ║
 * ║ .ts reads `message`, and the bell menu is built on that name. backend/   ║
 * ║ says so in its own class docblock. Emitting `body` would be "more        ║
 * ║ correct" and would blank every notification in the UI.                    ║
 * ║                                                                          ║
 * ║ NOTE the same physical value travels under a THIRD name on the admin      ║
 * ║ broadcast surface (`message` AND `content`, both) — see                   ║
 * ║ BroadcastNotificationResource. Three names, one column, all three ported. ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `link` carries a real value since migration 0014 added the column; before
 * that backend/ emitted a hardcoded null purely to satisfy the interface.
 *
 * `created_at` is 'Y-m-d H:i:s', the raw MySQL DATETIME string backend/
 * shipped straight out of PDO — same decision, same reason, as
 * PublicUser::created_at. NOT ISO-8601: a consumer comparing it literally
 * against another timestamp from this API must keep getting the same shape.
 *
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Notification $notification */
        $notification = $this->resource;

        return [
            'id' => $notification->getKey(),
            'user_id' => $notification->getAttribute('user_id'),
            'title' => $notification->getAttribute('title'),
            'message' => $notification->getAttribute('body'),
            'link' => $notification->getAttribute('link'),
            'is_read' => (bool) $notification->getAttribute('is_read'),
            'created_at' => $notification->getAttribute('created_at')?->format('Y-m-d H:i:s'),
        ];
    }
}
