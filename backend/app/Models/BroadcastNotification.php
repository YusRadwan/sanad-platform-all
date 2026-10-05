<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BroadcastAudience;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * broadcast_notifications — what an admin AUTHORED. STAGE 8.
 *
 * Columns per migration 2026_01_01_000032: id, title, body, link, audience,
 * target_user_ids (JSON), start_at, end_at, is_active, created_by, created_at,
 * updated_at.
 *
 * ┌── THE BROADCAST IS NOT THE DELIVERY. ────────────────────────────────────┐
 * │ This row is the admin's editorial object: audience, schedule window,     │
 * │ on/off switch, author. DELIVERY is fan-out-on-WRITE — one ordinary       │
 * │ `notifications` row per resolved recipient, carrying broadcast_id back   │
 * │ here. Keeping the two apart is what makes per-recipient `is_read` work   │
 * │ for free and keeps GET /notifications a single per-user query rather     │
 * │ than a read-time audience resolution.                                    │
 * │                                                                          │
 * │ Deleting this row cascade-deletes its deliveries AT THE DATABASE LEVEL   │
 * │ (fk_notifications_broadcast, ON DELETE CASCADE). There is deliberately   │
 * │ no application-side cascade to keep in sync with it.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO BroadcastPolicy EXISTS, AND THAT IS THE DECISION. All five routes are
 * `role:admin` at the router. A policy underneath middleware that already
 * admits exactly one role would only be re-asked of callers `Gate::before`
 * exempts anyway — zero added protection, one more place to get wrong. See
 * docs/open-decisions.md 8.2.
 */
class BroadcastNotification extends BaseModel
{
    protected $table = 'broadcast_notifications';

    /**
     * The `specific_user` audience's target, resolved to a USERNAME for display.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A DECLARED PROPERTY, **NOT** AN ELOQUENT ATTRIBUTE. THE DIFFERENCE    ║
     * ║ IS A LATENT 500.                                                      ║
     * ║                                                                      ║
     * ║ Writing `$broadcast->specific_username = …` on a model that has no    ║
     * ║ such column goes through Model::__set() into `$attributes`, which     ║
     * ║ marks the model DIRTY on a key the table does not have. Nothing       ║
     * ║ breaks while the row is only read — but the first `save()` that runs  ║
     * ║ afterwards emits `UPDATE … SET specific_username = ?` and dies at the ║
     * ║ driver with "Unknown column". The toggle endpoint saves a model this  ║
     * ║ resolver has touched, so that path is one refactor away from live.    ║
     * ║                                                                      ║
     * ║ A real PHP property is invisible to Eloquent's dirty tracking, so it  ║
     * ║ can never reach a statement. It also drops the static-analysis hint   ║
     * ║ about magic access, which was pointing at exactly this.               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * Null for every other audience, and for a targeted account that has since
     * been deleted — the resource falls back to the raw uuid there, matching the
     * degradation backend/'s LEFT JOIN produces.
     */
    public ?string $specificUsername = null;

    /**
     * This table DOES have both timestamps (unlike `notifications` and
     * `announcements`), so the default Eloquent behaviour is correct and no
     * UPDATED_AT constant is declared. The migration additionally carries
     * `useCurrentOnUpdate()`, which is harmless: Eloquent writes the column
     * explicitly on every save, so the DDL default never has to fire.
     */
    protected function casts(): array
    {
        return [
            'audience' => BroadcastAudience::class,
            'target_user_ids' => 'array',
            'is_active' => 'boolean',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** created_by is ON DELETE SET NULL, so this relation is genuinely nullable. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * The delivered rows. Used by `withCount('notifications')` to produce
     * `recipients_count` — an aggregate SUBQUERY, not a join, which is why
     * adding a second `withCount` alongside it cannot multiply either count.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'broadcast_id', 'id');
    }
}
