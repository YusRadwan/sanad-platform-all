<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Http\Requests\Settings\UpdateSettingsRequest as Rules;
use App\Models\Setting;

/**
 * The payload of GET /api/v1/settings — freeze-table row 126 — and the return
 * value of the POST on row 127.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE READ IS ALLOW-LIST FILTERED. THIS IS A DELIBERATE, USER-APPROVED     ║
 * ║ BEHAVIOUR CHANGE FROM backend/, AND THE ONLY ONE ON THESE TWO ROWS.      ║
 * ║                                                                          ║
 * ║ backend/SettingsRepository::all() is `SELECT key, value FROM settings`   ║
 * ║ with NO filter — every row in the table is serialised to every anonymous ║
 * ║ visitor on the internet. That was safe only because the WRITE path was   ║
 * ║ locked down; it is a "the door is bolted so the wall does not need to    ║
 * ║ be" argument, and the historical bug that argument failed to survive is  ║
 * ║ recorded on UpdateSettingsRequest.                                       ║
 * ║                                                                          ║
 * ║ Filtering the READ too is defence in depth: even if a row reached this   ║
 * ║ table by some path that is not POST /settings — a migration, a seeder, a ║
 * ║ future feature, a DBA, a bug in a later stage — it CANNOT be published   ║
 * ║ to the public internet by this endpoint.                                 ║
 * ║                                                                          ║
 * ║ IT CANNOT BREAK THE FRONTEND. settingsApiService and PlatformSettings    ║
 * ║ .tsx destructure exactly `{ settings, socialLinks, supportNumbers }`     ║
 * ║ from the response and read nothing else; a fourth key was already being  ║
 * ║ ignored by every consumer. The change is therefore invisible to today's  ║
 * ║ clients and closes a publishing channel for tomorrow's.                  ║
 * ║                                                                          ║
 * ║ Recorded as open decision 10.3. Pinned by SettingsReadTest::             ║
 * ║ test_a_stray_row_is_never_served_publicly, which inserts a row directly  ║
 * ║ through the model and asserts it is absent from the response.            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── SHAPE: `data` IS THE MAP ITSELF. THERE IS NO WRAPPER KEY. ─────────────┐
 * │ backend/SettingsController::index() is                                   │
 * │ `ApiResponse::success($this->settings->all())`, so the envelope is       │
 * │                                                                          │
 * │   {"success":true,"message":"OK","data":{                                │
 * │      "settings":{…},"socialLinks":[…],"supportNumbers":[…]}}             │
 * │                                                                          │
 * │ NOT `data.settings.settings`. Every other admin read in this application │
 * │ wraps its payload in a named key (`{"stats": …}`, `{"sessions": …}`), so │
 * │ adding one here is the natural mistake, and it would break              │
 * │ WhatsAppWidget.tsx and Footer.tsx on every public page load.             │
 * │                                                                          │
 * │ AN EMPTY TABLE ANSWERS `data: {}` — an OBJECT, not `[]`. PHP cannot tell │
 * │ an empty map from an empty list, and ApiResponse::normalise() is the one │
 * │ place that decision lives; this Action returns a plain `[]` and lets it  │
 * │ do the coercion rather than reaching for stdClass here.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NESTED EMPTIES ARE **NOT** COERCED, and that is correct: a stored
 * `socialLinks` of `[]` must stay a JSON array, because the frontend calls
 * `.map()` on it. Only the top level is object-coerced.
 */
final class PublicSettingsMap
{
    /**
     * The keys this endpoint will serve. Deliberately the SAME constant the
     * write path allow-lists against — one list, so the read and the write can
     * never disagree about what "a settings key" is.
     */
    public const PUBLIC_KEYS = Rules::ALLOWED_TOP_LEVEL;

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        /*
         * ONE query, no pagination, at most three rows by construction of the
         * WHERE clause. `key` is the primary key so this is a PK range scan.
         *
         * The `value` column is cast to 'array' on the model, which decodes
         * the JSON — replacing backend/'s explicit json_decode(..., true).
         * A NULL column decodes to null and is emitted as null, matching
         * backend/'s `$row['value'] !== null ? json_decode(...) : null`.
         */
        $rows = Setting::query()
            ->whereIn('key', self::PUBLIC_KEYS)
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->getKey()] = $row->getAttribute('value');
        }

        return $map;
    }
}
