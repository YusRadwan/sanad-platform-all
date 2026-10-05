<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * notifications — the per-user inbox row. STAGE 8.
 *
 * Columns per migration 2026_01_01_000033: id, user_id, title, body (the
 * message text), link, broadcast_id, is_read, created_at.
 *
 * NOT Laravel's `Illuminate\Notifications\DatabaseNotification` — this app does
 * not use the Notifications component. It is a plain domain table whose rows
 * the frontend's bell menu renders.
 *
 * ┌── OWNERSHIP IS `user_id`, AND IT IS ENFORCED BY THE **QUERY**. ──────────┐
 * │ Open decision 3.3: there is NO NotificationPolicy. The security boundary │
 * │ is `$request->user()->notifications()->visible()->find($id)` — a scope   │
 * │ chain that literally cannot return another user's row. A policy would    │
 * │ have been strictly WORSE here, because `Gate::before` hands admins a     │
 * │ `true` and would have let an admin mark a stranger's notification read,  │
 * │ which backend/ never allowed. See docs/open-decisions.md 8.1.            │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class Notification extends BaseModel
{
    protected $table = 'notifications';

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `const UPDATED_AT = null`, **NOT** `$timestamps = false`.            ║
     * ║                                                                      ║
     * ║ The table has `created_at` and no `updated_at` (migration 33). The    ║
     * ║ reflex fix is `public $timestamps = false`, and it is wrong twice:    ║
     * ║                                                                      ║
     * ║  1. it also switches OFF `created_at`, so every insert would have to  ║
     * ║     hand-set it — and the bulk fan-out in                             ║
     * ║     App\Actions\Notification\DeliverBroadcast already does, but the   ║
     * ║     ORDINARY `Notification::create()` path would then write a NULL    ║
     * ║     into a NOT NULL column and 500 at the driver;                     ║
     * ║  2. it is silent. A missing timestamp is not a test failure until     ║
     * ║     something orders by it.                                          ║
     * ║                                                                      ║
     * ║ `UPDATED_AT = null` is Eloquent's own first-party expression of       ║
     * ║ "this model has a created_at only" (Model::setUpdatedAt is skipped    ║
     * ║ when the constant is null), so created_at keeps working and no        ║
     * ║ UPDATE statement ever mentions a column that does not exist.          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public const UPDATED_AT = null;

    /**
     * ┌── EMPTY ON PURPOSE, THE SAME RULE App\Models\LessonProgress STATES. ─┐
     * │ The ONE column any request may change is `is_read`, and it is set    │
     * │ explicitly in NotificationController::markRead(). `user_id` in       │
     * │ particular must never be mass-assignable: a fillable spelling of the │
     * │ owner column is a request key that reassigns a notification.          │
     * │                                                                      │
     * │ The bulk fan-out (App\Actions\Notification\DeliverBroadcast) does not │
     * │ go through here at all — it uses Notification::insert(), the query-   │
     * │ builder path, which bypasses the model entirely by design.            │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * The broadcast this row was fanned out from, or null for an ordinary
     * per-user notification. `notifications.broadcast_id` is ON DELETE CASCADE,
     * so deleting the parent removes this row at the DATABASE level — no
     * application-side cascade exists or should.
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(BroadcastNotification::class, 'broadcast_id', 'id');
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE SINGLE DEFINITION OF "VISIBLE". BOTH ROUTES USE IT.              ║
     * ║                                                                      ║
     * ║ Ported from backend/NotificationRepository::listForUser()'s WHERE     ║
     * ║ clause, which existed on the LIST query only. backend/'s markRead()   ║
     * ║ did NOT consult it, so a recipient could still mark-read (and the     ║
     * ║ response would echo back) a notification belonging to a broadcast     ║
     * ║ that had been disabled or had expired — a row the very same session   ║
     * ║ was being told did not exist. Making this a SCOPE, and applying it on ║
     * ║ both routes, closes that split.                                      ║
     * ║                                                                      ║
     * ║   broadcast_id IS NULL                     -> always visible          ║
     * ║   otherwise, the parent broadcast must be   is_active = 1             ║
     * ║                                        AND start_at <= NOW()          ║
     * ║                                        AND (end_at IS NULL            ║
     * ║                                             OR end_at >= NOW())       ║
     * ║                                                                      ║
     * ║ WHY A `whereHas` AND NOT backend/'s LEFT JOIN: the join spelling      ║
     * ║ requires the caller to also remember `SELECT n.*`, or the broadcast's ║
     * ║ own `id`/`title` columns overwrite the notification's when Eloquent   ║
     * ║ hydrates. `whereHas` compiles to a correlated EXISTS against the same ║
     * ║ primary key, uses idx_notifications_broadcast identically, and cannot ║
     * ║ contaminate the selected columns however it is chained.               ║
     * ║                                                                      ║
     * ║ DELIVERED ROWS ARE NOT DELETED WHEN A BROADCAST IS DISABLED — that is ║
     * ║ the whole point. Hiding at READ time is what lets a disable/re-enable ║
     * ║ cycle restore every recipient's `is_read` state untouched.            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('broadcast_id')
                ->orWhereHas('broadcast', function (Builder $b): void {
                    $b->where('is_active', true)
                        ->where('start_at', '<=', now())
                        ->where(function (Builder $window): void {
                            $window->whereNull('end_at')->orWhere('end_at', '>=', now());
                        });
                });
        });
    }
}
