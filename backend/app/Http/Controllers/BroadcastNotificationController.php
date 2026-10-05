<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Notification\DeliverBroadcast;
use App\Exceptions\NotFoundException;
use App\Http\Requests\Broadcast\BroadcastWriteRequest;
use App\Http\Resources\BroadcastNotificationResource;
use App\Http\Responses\ApiResponse;
use App\Models\BroadcastNotification;
use App\Models\User;
use App\Support\BroadcastAudienceResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin broadcast authoring — freeze-table rows 104-108. `role:admin` only.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THERE IS NO BroadcastPolicy, AND ADDING ONE WOULD PROTECT NOTHING.      ║
 * ║ (Open decision 8.2.)                                                     ║
 * ║                                                                          ║
 * ║ All five routes carry `role:admin` at the router. A policy underneath    ║
 * ║ that would be asked only of callers who are ALREADY the single admitted  ║
 * ║ role — and `Gate::before` waves exactly those callers through without    ║
 * ║ entering it. The net effect is a file that always returns true, plus a   ║
 * ║ second place for the access rule to drift out of sync with the first.    ║
 * ║                                                                          ║
 * ║ THE **GET** IS `role:admin` TOO, AND THAT MATTERS MORE THAN THE WRITES.  ║
 * ║ The list reveals who every past broadcast targeted — the `selected_      ║
 * ║ teachers` picker contents and the `specific_user` username. A            ║
 * ║ compromised instructor account reading it learns the platform's internal ║
 * ║ communication graph without writing anything. Read secrecy is the point, ║
 * ║ not a side effect of grouping the routes together.                       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── RATE LIMITING: THE **WRITES** ONLY, KEYED PER-ADMIN. ──────────────────┐
 * │ `throttle.lockout:broadcast` — 10 per 5 minutes, escalating 5min -> 30min │
 * │ (config/ratelimit.php, declared since Stage 1). Every accepted write     │
 * │ fans a message out to EVERY instructor, so the cost per call is          │
 * │ O(users) inserts landing in real inboxes.                                │
 * │                                                                          │
 * │ The GET carries NO limiter: it is a single indexed read, the admin       │
 * │ screen polls it after every mutation, and throttling it would lock an    │
 * │ admin out of their own audit view for half an hour over a read.          │
 * │                                                                          │
 * │ KEYED ON THE ACTING ADMIN'S USER ID, NOT THE IP — so one careless admin  │
 * │ session cannot lock out the others, and a shared office NAT does not     │
 * │ pool every admin into one bucket. Registered in routes/api.php.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class BroadcastNotificationController extends Controller
{
    public const NOT_FOUND = 'Broadcast not found';

    public function __construct(private readonly DeliverBroadcast $deliver)
    {
    }

    /** GET /api/v1/admin/broadcasts — NOT rate-limited. Row 104. */
    public function index(): JsonResponse
    {
        $broadcasts = self::baseQuery()
            ->orderByDesc('created_at')
            // backend/listAll() caps at 500. Ported: the admin screen renders a
            // flat list with no pagination, so an unbounded SELECT is one bad
            // seeding script away from a multi-megabyte response.
            ->limit(500)
            ->get()
            ->each(self::attachSpecificUsername(...));

        return ApiResponse::success([
            'broadcasts' => BroadcastNotificationResource::collection($broadcasts),
        ]);
    }

    /**
     * POST /api/v1/admin/broadcasts -> 201. Row 105.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ RESOLVE FIRST, **THEN** OPEN THE TRANSACTION.                        ║
     * ║                                                                      ║
     * ║ Every audience rejection (unknown id, wrong role, inactive account,   ║
     * ║ empty audience) is a 422 raised by BroadcastAudienceResolver BEFORE   ║
     * ║ a single row is written. Doing it inside would roll back correctly    ║
     * ║ but would take locks and perform a full fan-out first.                ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── `DB::transaction($closure)` WITH **NO** RETRY COUNT. ──────────────┐
     * │ The standing rule since Stage 6. Laravel's optional second argument   │
     * │ re-runs the whole closure on a deadlock — which here would create a   │
     * │ SECOND broadcast row and fan out a SECOND full delivery, because the  │
     * │ first attempt's rows are rolled back but the closure has no way to    │
     * │ know it is a retry. Double-delivering a platform announcement to      │
     * │ every instructor is a worse outcome than a 500 the admin can retry    │
     * │ deliberately.                                                        │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function store(BroadcastWriteRequest $request): JsonResponse
    {
        $resolved = BroadcastAudienceResolver::resolve(
            $request->audience(),
            $request->targetUserIds(),
            $request->specificUser(),
        );

        $broadcast = DB::transaction(function () use ($request, $resolved): BroadcastNotification {
            $broadcast = new BroadcastNotification();

            $this->applyEditableAttributes($broadcast, $request, $resolved['targetUserIds']);

            // is_active is set ONLY on create. PUT deliberately omits it (the
            // toggle endpoint owns it), so it is written here and never again.
            $broadcast->setAttribute('is_active', true);
            $broadcast->setAttribute('created_by', (string) $request->user()->getKey());
            $broadcast->save();

            ($this->deliver)($broadcast, $resolved['recipients']);

            return $broadcast;
        });

        return ApiResponse::created(
            ['broadcast' => new BroadcastNotificationResource($this->reload($broadcast->getKey()))],
            'تم إنشاء وبث التنبيه الجديد للجمهور المستهدف'
        );
    }

    /**
     * PUT /api/v1/admin/broadcasts/{id}. Row 106.
     *
     * FULL REPLACEMENT — see BroadcastWriteRequest for why one Form Request
     * serves both verbs. The audience is RE-RESOLVED and the delivery is
     * DESTROYED AND REBUILT, so a retargeted broadcast stops sitting in the
     * inbox of a user who is no longer a recipient.
     *
     * `is_read` STATE IS LOST BY DESIGN — documented behaviour, not a bug. See
     * DeliverBroadcast::clear() for the contrast with the toggle endpoint,
     * which preserves it precisely because it deletes nothing.
     */
    public function update(BroadcastWriteRequest $request, string $id): JsonResponse
    {
        $broadcast = $this->find($id);

        $resolved = BroadcastAudienceResolver::resolve(
            $request->audience(),
            $request->targetUserIds(),
            $request->specificUser(),
        );

        DB::transaction(function () use ($request, $broadcast, $resolved): void {
            $this->applyEditableAttributes($broadcast, $request, $resolved['targetUserIds']);
            $broadcast->save();

            $this->deliver->clear($broadcast);
            ($this->deliver)($broadcast, $resolved['recipients']);
        });

        return ApiResponse::success(
            ['broadcast' => new BroadcastNotificationResource($this->reload($broadcast->getKey()))],
            'تم تعديل التنبيه البثي وحفظ التحديثات بنجاح'
        );
    }

    /**
     * PATCH /api/v1/admin/broadcasts/{id}/toggle. Row 107.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ TOGGLING TOUCHES **NO DELIVERED ROW**. THAT IS THE WHOLE POINT OF     ║
     * ║ Notification::scopeVisible().                                         ║
     * ║                                                                      ║
     * ║ Disabling flips one boolean. The delivered `notifications` rows stay   ║
     * ║ exactly where they are, and the read-time visibility gate hides them   ║
     * ║ from every recipient's inbox AND makes mark-read answer 404 on them.   ║
     * ║ Re-enabling restores the broadcast with every recipient's `is_read`    ║
     * ║ state byte-identical to before — because nothing was ever deleted.     ║
     * ║                                                                      ║
     * ║ A delete-and-recreate implementation would look equivalent and would   ║
     * ║ silently mark every previously-read notification unread on every       ║
     * ║ disable/enable cycle.                                                  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── AN OMITTED BODY MEANS **ENABLE**. ─────────────────────────────────┐
     * │ backend/: `filter_var($request->input('is_active', true),            │
     * │ FILTER_VALIDATE_BOOLEAN)`. The default is `true`, so `PATCH .../      │
     * │ toggle` with no body enables. `Request::boolean($key, $default)` is   │
     * │ the same coercion (it is filter_var with the same flag underneath),   │
     * │ so 'false', '0', 'off' and 0 all disable, exactly as before.          │
     * │                                                                      │
     * │ Note this is NOT a flip-what-it-is toggle despite the route name:     │
     * │ backend/ never reads the current value. `{is_active: false}` sent     │
     * │ twice leaves it disabled, it does not oscillate.                      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function toggle(Request $request, string $id): JsonResponse
    {
        $broadcast = $this->find($id);

        $isActive = $request->boolean('is_active', true);

        $broadcast->setAttribute('is_active', $isActive);
        $broadcast->save();

        return ApiResponse::success(
            ['broadcast' => new BroadcastNotificationResource($this->reload($broadcast->getKey()))],
            $isActive ? 'تم إعادة تفعيل بث التنبيه' : 'تم إيقاف وتعطيل التنبيه بنجاح'
        );
    }

    /**
     * DELETE /api/v1/admin/broadcasts/{id}. Row 108.
     *
     * ┌── THE CASCADE IS THE **DATABASE'S**, AND NO CODE HERE HELPS IT. ─────┐
     * │ `fk_notifications_broadcast` is ON DELETE CASCADE (migration 33), so  │
     * │ deleting this row removes every delivered notification with it. There │
     * │ is deliberately no application-side `Notification::where(...)->       │
     * │ delete()` alongside: a second cascade would be a second thing to keep │
     * │ correct, and would mask the FK silently breaking. Pinned directly by  │
     * │ BroadcastCrudTest, which counts `notifications` rows before and after.│
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function destroy(string $id): JsonResponse
    {
        $this->find($id)->delete();

        return ApiResponse::success(null, 'تم حذف التنبيه البثي نهائياً');
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * The seven columns BOTH verbs write. `is_active` and `created_by` are
     * absent on purpose — see store()/update().
     *
     * @param  list<string>|null  $targetUserIds
     */
    private function applyEditableAttributes(
        BroadcastNotification $broadcast,
        BroadcastWriteRequest $request,
        ?array $targetUserIds,
    ): void {
        $broadcast->setAttribute('title', $request->title());
        $broadcast->setAttribute('body', $request->body());
        $broadcast->setAttribute('link', $request->normalisedLink());
        $broadcast->setAttribute('audience', $request->audience());
        $broadcast->setAttribute('target_user_ids', $targetUserIds);
        $broadcast->setAttribute('start_at', $request->startAt());
        $broadcast->setAttribute('end_at', $request->endAt());
    }

    /** @return Builder<BroadcastNotification> */
    private static function baseQuery(): Builder
    {
        return BroadcastNotification::query()
            ->with('creator:id,username')
            ->withCount('notifications');
    }

    private function find(string $id): BroadcastNotification
    {
        return BroadcastNotification::query()->whereKey($id)->first()
            ?? throw new NotFoundException(self::NOT_FOUND);
    }

    /**
     * Re-read through baseQuery so the response carries `notifications_count`
     * and the eager-loaded creator. A saved model does not have them, and a
     * resource that reads a missing count silently reports 0 — which on a
     * successful fan-out is the single most misleading number this API can
     * return.
     */
    private function reload(mixed $id): BroadcastNotification
    {
        $broadcast = self::baseQuery()->whereKey($id)->first()
            ?? throw new NotFoundException(self::NOT_FOUND);

        self::attachSpecificUsername($broadcast);

        return $broadcast;
    }

    /**
     * Resolve the single stored id of a `specific_user` broadcast back to a
     * username, because the admin card renders "@{name}".
     *
     * ┌── WHY NOT A JOIN, AS backend/ DOES. ─────────────────────────────────┐
     * │ backend/ LEFT JOINs `users` on                                        │
     * │   JSON_UNQUOTE(JSON_EXTRACT(target_user_ids, '$[0]'))                 │
     * │ — a MySQL-specific, unindexable join predicate that also drags every  │
     * │ non-specific_user row through the JSON functions for nothing.         │
     * │                                                                       │
     * │ One extra SELECT over the handful of specific_user rows on the page   │
     * │ is cheaper AND portable. It is also why the fallback below exists at  │
     * │ all: if the targeted account is later deleted, the card shows the raw │
     * │ uuid rather than an empty "@" — the same degradation backend/'s LEFT  │
     * │ JOIN produces.                                                        │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    private static function attachSpecificUsername(BroadcastNotification $broadcast): void
    {
        if ($broadcast->getAttribute('audience') !== \App\Enums\BroadcastAudience::SpecificUser) {
            return;
        }

        $targetId = ((array) ($broadcast->getAttribute('target_user_ids') ?? []))[0] ?? null;

        if ($targetId === null) {
            return;
        }

        // A DECLARED property on the model, never `setAttribute` — see
        // BroadcastNotification::$specificUsername for why an Eloquent
        // attribute here would make the model dirty on a column that does not
        // exist, and 500 on the next save().
        $broadcast->specificUsername = User::query()
            ->whereKey((string) $targetId)
            ->value('username');
    }
}
