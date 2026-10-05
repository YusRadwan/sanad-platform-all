<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Http\Resources\NotificationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The PERSONAL INBOX — freeze-table rows 102-103. Any authenticated role.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ NO POLICY, NO FORM REQUEST, NO `authorize()` CALL ANYWHERE IN THIS FILE. ║
 * ║ THE QUERY IS THE SECURITY BOUNDARY. (Approved decision 3.3 / open        ║
 * ║ decision 8.1.)                                                           ║
 * ║                                                                          ║
 * ║     $request->user()->notifications()->visible()->…                       ║
 * ║                                                                          ║
 * ║ The `user_id = <session user>` predicate is compiled in by the relation.  ║
 * ║ There is no ability name to bypass, no policy for `Gate::before` to wave  ║
 * ║ an admin past, and no request key that could widen the set — the acting   ║
 * ║ identity comes from the session and from nowhere else.                    ║
 * ║                                                                          ║
 * ║ A NotificationPolicy would have been STRICTLY WORSE, which is why it was  ║
 * ║ deleted rather than implemented: `Gate::before` returns true for an       ║
 * ║ admin, so `authorize('update', $notification)` would let an admin mark a  ║
 * ║ stranger's inbox row read. backend/'s inline comparison never allowed     ║
 * ║ that. The admin bypass is right for course data and wrong for a mailbox.  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class NotificationController extends Controller
{
    /**
     * The oldest and most reliable of the three names a broadcast's text
     * travels under. Duplicated nowhere: both methods below read it.
     */
    public const NOT_FOUND = 'Notification not found';

    /**
     * GET /api/v1/notifications. Row 102.
     *
     * ┌── THE 100-ROW CAP IS backend/'s, AND IT IS NOT PAGINATION. ──────────┐
     * │ NotificationRepository::listForUser() ends in `LIMIT 100` with no     │
     * │ offset and no cursor — the bell menu renders a fixed recent window    │
     * │ and has no "load more". Ported as-is. Adding pagination here would    │
     * │ change the response envelope for a UI that does not ask for it.        │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ORDERING IS `latest()` — `created_at DESC`, matching backend/. Note this
     * has no deterministic tie-break, and cannot usefully be given one: a
     * broadcast fan-out writes every recipient's row with the SAME `$now`, but
     * those rows go to DIFFERENT users, so no single inbox ever contains two of
     * them. Within one user's inbox a tie needs two notifications created in
     * the same second, which the product has no path to produce.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->visible()
            ->latest()
            ->limit(100)
            ->get();

        return ApiResponse::success([
            'notifications' => NotificationResource::collection($notifications),
        ]);
    }

    /**
     * POST /api/v1/notifications/{id}/mark-read. Row 103.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ 404, NOT backend/'s 403 — A DELIBERATE, APPROVED BEHAVIOUR CHANGE.    ║
     * ║ (Open decision 8.5.)                                                  ║
     * ║                                                                      ║
     * ║ backend/NotificationService::markRead() looks the row up GLOBALLY,    ║
     * ║ then compares user_id and throws ForbiddenException('Not your         ║
     * ║ notification'). That answer tells an attacker holding a guessed uuid  ║
     * ║ the difference between "no such notification" (404) and "it exists    ║
     * ║ and belongs to someone else" (403) — an existence oracle over a       ║
     * ║ CHAR(36) space, for no product benefit: no legitimate client ever     ║
     * ║ sends an id it did not receive from GET /notifications.               ║
     * ║                                                                      ║
     * ║ Same principle Stage 5 applied in ContentItemService. Here it is      ║
     * ║ FREE, because scoping the lookup to the relation makes the two cases  ║
     * ║ physically indistinguishable: `find()` returns null for both, and     ║
     * ║ there is no branch that could leak which one it was.                  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── `visible()` IS APPLIED HERE TOO, AND backend/ DID NOT. ────────────┐
     * │ backend/'s markRead() consults no visibility gate, so a recipient     │
     * │ could mark-read a row belonging to a broadcast that had been disabled │
     * │ or had expired — a row the very same session was being told did not   │
     * │ exist by GET /notifications. One scope, both routes, one answer.      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->visible()
            ->find($id);

        if (!$notification instanceof Notification) {
            throw new NotFoundException(self::NOT_FOUND);
        }

        /*
         * setAttribute + save(), NOT `update([...])`. Notification declares
         * `$fillable = []`, so update() would silently discard the key and
         * answer 200 having written nothing — the exact class of failure that
         * only shows up as "the bell badge never clears". The explicit form
         * cannot be defeated by a guard list.
         *
         * `notifications` has no updated_at column and Notification::UPDATED_AT
         * is null, so this emits `UPDATE … SET is_read = 1` and nothing else.
         * A second call is a no-op, not an error — backend/ behaved the same
         * way and the bell menu re-sends freely.
         */
        $notification->setAttribute('is_read', true);
        $notification->save();

        return ApiResponse::success(
            ['notification' => new NotificationResource($notification)],
            'تم تحديد الإشعار كمقروء'
        );
    }
}
