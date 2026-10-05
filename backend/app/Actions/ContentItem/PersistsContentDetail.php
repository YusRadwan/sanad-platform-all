<?php

declare(strict_types=1);

namespace App\Actions\ContentItem;

use App\Models\ContentItem;

/**
 * One implementation per content type that HAS a detail table.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS REPLACES backend/ContentItemService::saveTypeDetail() — a single    ║
 * ║ 47-line if/elseif chain that both DISPATCHED on type and IMPLEMENTED     ║
 * ║ five different persistence rules.                                        ║
 * ║                                                                          ║
 * ║ The five rules genuinely differ (full-replace upsert for video/link/     ║
 * ║ note, conditional row creation for note, existence-branched null-filter  ║
 * ║ for exam/assignment), so collapsing them was never possible — the chain  ║
 * ║ merely hid that they differ. One class per type makes each rule          ║
 * ║ separately readable, separately testable, and impossible to change by    ║
 * ║ accident while editing its neighbour.                                    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THERE IS NO create()/update() SPLIT ON THIS INTERFACE, DELIBERATELY. ──┐
 * │ backend/ calls saveTypeDetail() with the SAME arguments from both        │
 * │ create() and update(); the distinction it draws is not "which HTTP verb" │
 * │ but "does a detail row already exist" — which each implementation        │
 * │ answers for itself, because only exam/assignment care. Adding a          │
 * │ `$isCreate` flag would invite the three that do not care to start        │
 * │ branching on it.                                                         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * $data is the VALIDATED request payload, not the raw body: every URL in it
 * has already been allow-listed by App\Rules\SafeUrlValue at the Form Request,
 * which is what guarantees no parent `content_items` row can be orphaned by a
 * rejected detail.
 */
interface PersistsContentDetail
{
    /** @param  array<string, mixed>  $data  the validated payload */
    public function persist(ContentItem $item, array $data): void;
}
