<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Http\Requests\Settings\UpdateSettingsRequest as Rules;
use App\Models\Setting;
use App\Rules\Boolish;
use App\Rules\BoolishString;
use App\Rules\Integerish;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Freeze-table row 127 — the write half of POST /api/v1/settings.
 *
 * Everything that could REJECT the payload has already happened in
 * App\Http\Requests\Settings\UpdateSettingsRequest, so this class only
 * NORMALISES and WRITES. That split is backend/'s own ordering guarantee
 * ("validate EVERYTHING first, then write — a rejected socialLinks entry must
 * not leave a half-applied settings object behind") obtained structurally: a
 * Form Request rejection happens before the controller is entered, so there is
 * no code path on which this class runs against unvalidated data.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `values` IS "SOMETIMES PRESENT IN THE OUTPUT", NOT "NULLABLE".           ║
 * ║ THIS IS THE SUBTLEST BEHAVIOUR IN THE WHOLE ENDPOINT.                   ║
 * ║                                                                          ║
 * ║ backend/cleanSupportNumbers() builds each stored item with FOUR keys     ║
 * ║ (type, value, enabled, order) and then appends a fifth ONLY when the     ║
 * ║ request actually sent a non-null `values`:                               ║
 * ║                                                                          ║
 * ║     if ($values !== null) { $item['values'] = $values; }                 ║
 * ║                                                                          ║
 * ║ So there are THREE distinguishable inputs and only TWO outputs:          ║
 * ║                                                                          ║
 * ║     values absent   -> key ABSENT from the stored JSON                   ║
 * ║     values: null    -> key ABSENT from the stored JSON                   ║
 * ║     values: []      -> key PRESENT, stored as []                         ║
 * ║                                                                          ║
 * ║ Storing `"values": null` instead of omitting it would be a byte-level    ║
 * ║ change to a value the admin form round-trips on every save, and          ║
 * ║ PlatformSettings.tsx's read path distinguishes them: it maps a stored    ║
 * ║ entry back into its catalogue row and an unexpected `values: null`       ║
 * ║ replaces the `[]` the WhatsApp row is initialised with. Preserved        ║
 * ║ exactly, and pinned by SettingsValuesSemanticsTest.                      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── DB::transaction() IS AN ADDITION, AND A SAFE ONE. ─────────────────────┐
 * │ backend/ validates everything up front (so a rejection cannot produce a  │
 * │ partial write) but its actual write LOOP over the three keys is not      │
 * │ transactional. A mid-loop database failure — a lost connection after     │
 * │ `settings` was written but before `socialLinks` — leaves the public      │
 * │ payload internally inconsistent, with a section toggled on and its       │
 * │ content still the previous save's.                                       │
 * │                                                                          │
 * │ Wrapping the loop changes NOTHING on the success path (the same three    │
 * │ upserts, in the same order, with the same values) and makes the failure  │
 * │ path all-or-nothing. This is the same pattern every prior stage applied: │
 * │ ADD a transaction where none existed, never REMOVE one that did.         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── THE RETURN VALUE IS THE **FULL** MAP, NOT THE CHANGED KEYS. ───────────┐
 * │ backend/SettingsService::update() ends `return $this->settings->all()`.  │
 * │ A save that touched only `settings` still answers with socialLinks and   │
 * │ supportNumbers, because the admin form re-seeds its entire state from    │
 * │ the response. Returning a diff would blank two tabs on every save.       │
 * │                                                                          │
 * │ It is built by App\Actions\Settings\PublicSettingsMap — the SAME code    │
 * │ path GET /settings uses — so the read and the write can never disagree   │
 * │ about the shape or about which keys are public.                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class UpdateSettings
{
    public function __construct(private readonly PublicSettingsMap $map)
    {
    }

    /**
     * @param  array<string, mixed>  $payload  the validated request body
     * @return array<string, mixed>  the full public settings map after the write
     */
    public function __invoke(array $payload): array
    {
        $clean = [];

        /*
         * array_key_exists, NOT isset() and NOT $request->filled(). A key that
         * was SENT must be written even when its value is falsy — `settings:
         * {}` legitimately means "store an empty settings object" and isset()
         * would silently skip it. The three checks are in backend/'s order.
         */
        if (\array_key_exists('settings', $payload)) {
            $clean['settings'] = $this->settings($payload['settings']);
        }

        if (\array_key_exists('socialLinks', $payload)) {
            $clean['socialLinks'] = $this->socialLinks($payload['socialLinks']);
        }

        if (\array_key_exists('supportNumbers', $payload)) {
            $clean['supportNumbers'] = $this->supportNumbers($payload['supportNumbers']);
        }

        DB::transaction(static function () use ($clean): void {
            foreach ($clean as $key => $value) {
                /*
                 * Eloquent's equivalent of backend/'s
                 * `INSERT … ON DUPLICATE KEY UPDATE value = VALUES(value)`.
                 * The `key` column is the primary key, so this is an upsert on
                 * identity, and the grammar backticks both reserved words.
                 */
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        SecurityLog::criticalOperation('settings.updated', [
            'event' => 'settings.updated',
            'keys' => array_keys($clean),
        ]);

        return ($this->map)();
    }

    /**
     * The `settings` object: only allow-listed keys that were actually SENT
     * appear in the stored value, in the allow-list's own order — not the
     * caller's. backend/ iterates its constant lists, which produces exactly
     * that, so a payload that lists the keys in a different order still stores
     * an identical JSON document.
     *
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    private function settings(mixed $value): array
    {
        $value = \is_array($value) ? $value : [];
        $clean = [];

        foreach (Rules::SETTINGS_BOOLISH as $key) {
            if (\array_key_exists($key, $value)) {
                // TO A STRING, never to a real bool. See App\Rules\BoolishString.
                $clean[$key] = BoolishString::normalise($value[$key]);
            }
        }

        foreach (Rules::SETTINGS_TEXT as $key) {
            if (\array_key_exists($key, $value)) {
                // NOT trimmed — a policy body's leading blank line is content.
                $clean[$key] = (string) $value[$key];
            }
        }

        return $clean;
    }

    /**
     * @param  mixed  $value
     * @return list<array<string, mixed>>
     */
    private function socialLinks(mixed $value): array
    {
        $clean = [];

        foreach ($this->entries($value) as $entry) {
            $clean[] = [
                'platform' => trim((string) ($entry['platform'] ?? '')),
                'url' => trim((string) ($entry['url'] ?? '')),
                // `?? false` / `?? 0`: an absent flag is not an error, it is a
                // default — backend/'s `$entry['enabled'] ?? false`, verbatim.
                'enabled' => Boolish::normalise($entry['enabled'] ?? false),
                'order' => Integerish::normalise($entry['order'] ?? 0),
            ];
        }

        return $clean;
    }

    /**
     * @param  mixed  $value
     * @return list<array<string, mixed>>
     */
    private function supportNumbers(mixed $value): array
    {
        $clean = [];

        foreach ($this->entries($value) as $entry) {
            $item = [
                'type' => trim((string) ($entry['type'] ?? '')),
                'value' => trim((string) ($entry['value'] ?? '')),
                'enabled' => Boolish::normalise($entry['enabled'] ?? false),
                'order' => Integerish::normalise($entry['order'] ?? 0),
            ];

            /*
             * THE THREE-INPUTS-TWO-OUTPUTS RULE — see the class docblock.
             * array_key_exists AND a null test, both, because absent and null
             * must collapse to the same output while `[]` must not.
             */
            $values = \array_key_exists('values', $entry) ? $entry['values'] : null;

            if (\is_array($values)) {
                $item['values'] = array_values(array_map(
                    static fn (mixed $v): string => trim((string) $v),
                    $values
                ));
            }

            $clean[] = $item;
        }

        return $clean;
    }

    /**
     * @param  mixed  $value
     * @return list<array<string, mixed>>
     */
    private function entries(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $entry): bool => \is_array($entry)));
    }
}
