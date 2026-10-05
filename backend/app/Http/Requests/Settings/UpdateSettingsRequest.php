<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Http\Requests\ApiFormRequest;
use App\Rules\Boolish;
use App\Rules\BoolishString;
use App\Rules\Integerish;
use App\Rules\SafeUrlValue;
use App\Support\SecurityLog;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/settings — freeze-table row 127, the last write in the project.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE HISTORICAL BUG THIS CLASS EXISTS TO KEEP CLOSED.                     ║
 * ║                                                                          ║
 * ║ backend/SettingsService's own docblock records it: update() once looped  ║
 * ║ over `$data as $key => $value` and wrote EVERY key straight through to   ║
 * ║ the `settings` table. That table is served, verbatim and                 ║
 * ║ UNAUTHENTICATED, to the entire internet by GET /settings. So the         ║
 * ║ endpoint was not a settings form — it was an arbitrary public            ║
 * ║ data-publishing primitive behind one admin session.                      ║
 * ║                                                                          ║
 * ║ ┌── AND THIS IS THE PART THAT MATTERS FOR A LARAVEL PORT: ───────────┐   ║
 * ║ │ `$request->validated()` WOULD **NOT** CLOSE IT.                    │   ║
 * ║ │                                                                    │   ║
 * ║ │ validated() SILENTLY DROPS keys that have no rule. It does not     │   ║
 * ║ │ reject them. A payload of                                          │   ║
 * ║ │   {"settings": {...}, "evil": "<script>…</script>"}                │   ║
 * ║ │ would answer **200** and the admin would be told the save          │   ║
 * ║ │ succeeded, while `evil` vanished. That is a milder form of the     │   ║
 * ║ │ same bug — no longer a write primitive, but still a lie on the     │   ║
 * ║ │ wire — and it is invisible to any test that only asserts the       │   ║
 * ║ │ stored rows.                                                       │   ║
 * ║ │                                                                    │   ║
 * ║ │ backend/ answers 422 and LOGS the offending keys. Reproducing that │   ║
 * ║ │ requires EXPLICIT rejection, which is what checkUnknownKeys()      │   ║
 * ║ │ below does at THREE nesting levels:                                │   ║
 * ║ │   1. top level      -> {settings, socialLinks, supportNumbers}     │   ║
 * ║ │   2. inside settings-> the six-key allow-list                      │   ║
 * ║ │   3. inside each socialLinks[] / supportNumbers[] element          │   ║
 * ║ │                                                                    │   ║
 * ║ │ Pinned by SettingsUnknownKeyRejectionTest, which asserts 422 AND   │   ║
 * ║ │ that the row count did not change — a test that passes trivially   │   ║
 * ║ │ under validated()-only and fails on the status code.               │   ║
 * ║ └────────────────────────────────────────────────────────────────────┘   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── WHY THE SPLIT BETWEEN rules() AND withValidator(). ────────────────────┐
 * │ rules() carries the STRUCTURAL spine — presence, PHP type, length,       │
 * │ the four custom value rules. It is declarative and greppable, and it is  │
 * │ what a reader should scan first.                                         │
 * │                                                                          │
 * │ withValidator() carries the checks Laravel's rule syntax genuinely      │
 * │ cannot express:                                                          │
 * │ express:                                                                 │
 * │   * "unknown key" — the inverse of a rule set, which no rule states      │
 * │   * map-vs-list (`array` accepts both; legacy distinguishes them)        │
 * │   * a TRIMMED-empty required string ('   ' passes Laravel's `required`)  │
 * │   * a per-element rule that depends on a SIBLING field (the WhatsApp     │
 * │     digit restriction reads `type` to decide how to judge `value`)       │
 * │                                                                          │
 * │ Both run before the controller is entered, so nothing here can leave a   │
 * │ partial write — the ordering guarantee backend/ obtained by validating   │
 * │ everything into `$clean` before its write loop.                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── ONE ACCEPTED DIFFERENCE FROM backend/: ERROR **COUNT**. ───────────────┐
 * │ backend/ throws a ValidationException at the first failure, so           │
 * │ `data.errors` always holds exactly one key. Laravel accumulates, so a    │
 * │ payload with two bad fields reports both. Same status, same message      │
 * │ strings, same field keys — strictly more information. This is the same   │
 * │ inherited-framework difference every prior stage accepted; recorded once │
 * │ in open-decisions rather than re-argued per endpoint.                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class UpdateSettingsRequest extends ApiFormRequest
{
    /** The three keys POST /settings will persist. Nothing else is written. */
    public const ALLOWED_TOP_LEVEL = ['settings', 'socialLinks', 'supportNumbers'];

    /** The four section toggles, stored as the STRINGS 'true'/'false'. */
    public const SETTINGS_BOOLISH = [
        'social_section_enabled',
        'privacy_section_enabled',
        'terms_section_enabled',
        'support_section_enabled',
    ];

    /** The two long-form policy bodies. */
    public const SETTINGS_TEXT = ['privacy_policy', 'terms_conditions'];

    /**
     * The complete allow-list inside `settings`. SIX keys, and the list is
     * derived strictly from PlatformSettings.tsx's handleSave payload — no
     * invented keys, exactly as backend/ states.
     *
     * `platform_logo` was the seventh, and it is GONE. The platform logo is a
     * STATIC asset — frontend/public/branding/logo.png (plus favicon.png) — and
     * that folder is now the single source of truth. The settings key was the
     * last remnant of the removed logo-upload tab: nothing wrote it, nothing
     * read it except a defensive override, and keeping a publicly-served,
     * data-URI-accepting field alive for a feature that no longer exists was
     * pure attack surface.
     */
    public const SETTINGS_KEYS = [
        'social_section_enabled',
        'privacy_section_enabled',
        'terms_section_enabled',
        'support_section_enabled',
        'privacy_policy',
        'terms_conditions',
    ];

    /** Exact object shape of a socialLinks entry. */
    public const SOCIAL_LINK_KEYS = ['platform', 'url', 'enabled', 'order'];

    /** Exact object shape of a supportNumbers entry. */
    public const SUPPORT_CONTACT_KEYS = ['type', 'value', 'values', 'enabled', 'order'];

    public const MAX_TEXT_LENGTH = 200000;

    public const MAX_SHORT_LENGTH = 255;

    public const MAX_LIST_ITEMS = 50;

    public const MAX_URL_LENGTH = 2048;

    /**
     * The Arabic substring that makes a support contact a PHONE NUMBER.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THIS IS A SUBSTRING MATCH ON A FREE-TEXT LABEL, AND IT STAYS ONE.    ║
     * ║                                                                      ║
     * ║ `type` is whatever the admin typed — the seeded catalogue uses        ║
     * ║ 'أرقام واتساب' — and this check is `str_contains($type, 'واتساب')`.   ║
     * ║ It is obviously fragile: an admin who writes 'WhatsApp' in Latin      ║
     * ║ script gets no digit restriction at all.                              ║
     * ║                                                                      ║
     * ║ IT IS PRESERVED EXACTLY ANYWAY. "Improving" it to an enum or a role   ║
     * ║ column would mean either (a) rejecting every existing stored row      ║
     * ║ whose type is free text, or (b) silently APPLYING the digit rule to   ║
     * ║ rows it never applied to before — which would 422 an admin's saved    ║
     * ║ 'ساعات العمل' entry the first time they touched the page. Both are    ║
     * ║ breaking changes to live data on a defence-in-depth check whose real  ║
     * ║ purpose is narrow: WhatsAppWidget.tsx interpolates this value into a  ║
     * ║ `https://wa.me/{value}` URL, and the restriction is what guarantees   ║
     * ║ nothing but phone characters can reach that builder.                  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public const WHATSAPP_MARKER = 'واتساب';

    /** Phone-number characters only: digits, +, parens, hyphen, space, comma. */
    public const PHONE_PATTERN = '/^[0-9+()\-\s,]+$/';

    /** NUL through US, plus DEL. Rejected in every contact-related string. */
    public const CONTROL_CHARS = '/[\x00-\x1F\x7F]/';

    // ── Message literals, all lifted verbatim from backend/SettingsService ──

    public const EMPTY_PAYLOAD = 'لا توجد إعدادات لتحديثها';

    public const UNKNOWN_TOP_LEVEL = 'مفاتيح إعدادات غير مسموح بها: ';

    public const UNKNOWN_IN_SETTINGS = 'مفاتيح غير مسموح بها داخل settings: ';

    public const UNKNOWN_IN_ENTRY = 'حقول غير مسموح بها: ';

    public const NOT_AN_OBJECT = 'يجب أن يكون كائناً';

    public const SETTINGS_NOT_AN_OBJECT = 'قيمة settings يجب أن تكون كائناً';

    public const NOT_A_LIST = 'يجب أن تكون قائمة';

    public const TOO_MANY_ITEMS = 'عدد العناصر أكبر من الحد المسموح';

    public const TOO_MANY_VALUES = 'عدد القيم أكبر من الحد المسموح';

    public const PLATFORM_REQUIRED = 'اسم المنصة مطلوب';

    public const PLATFORM_TOO_LONG = 'اسم المنصة أطول من الحد المسموح';

    public const URL_REQUIRED = 'حقل الرابط مطلوب';

    public const URL_NOT_A_STRING = 'الرابط يجب أن يكون نصاً';

    public const URL_TOO_LONG = 'الرابط أطول من الحد المسموح';

    public const TYPE_REQUIRED = 'نوع وسيلة التواصل مطلوب';

    public const TYPE_TOO_LONG = 'النوع أطول من الحد المسموح';

    public const NOT_A_STRING = 'القيمة يجب أن تكون نصاً';

    public const TEXT_TOO_LONG = 'النص أطول من الحد المسموح';

    public const VALUE_TOO_LONG = 'القيمة أطول من الحد المسموح';

    public const FORBIDDEN_CHARS = 'القيمة تحتوي على رموز غير مسموح بها';

    public const WHATSAPP_DIGITS_ONLY = 'رقم الواتساب يجب أن يحتوي على أرقام فقط';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'settings' => ['sometimes', 'array'],

            'socialLinks' => ['sometimes', 'array', 'max:' . self::MAX_LIST_ITEMS],
            'socialLinks.*' => ['array'],
            'socialLinks.*.platform' => ['required', 'string', 'max:' . self::MAX_SHORT_LENGTH],
            /*
             * `present`, NOT `required` — '' is a MEANINGFUL value here.
             *
             * PlatformSettings.tsx always sends all ELEVEN platforms from its
             * hard-coded catalogue, with `url: ''` for every one the admin has
             * not configured. `required` would 422 the ordinary save of a
             * brand-new installation. backend/ makes the same distinction:
             * array_key_exists() for presence, then a separate emptiness
             * branch that returns '' unchallenged.
             *
             * SafeUrlValue(allowBlank: true) is the existing rule from Stage 5
             * — it is EXACTLY the "SafeUrlOrEmpty" this endpoint needs, and a
             * second class saying the same thing is a second place for the
             * URL allow-list to drift.
             */
            'socialLinks.*.url' => ['present', 'string', 'max:' . self::MAX_URL_LENGTH, new SafeUrlValue(allowBlank: true)],
            'socialLinks.*.enabled' => ['sometimes', new Boolish()],
            'socialLinks.*.order' => ['sometimes', new Integerish()],

            'supportNumbers' => ['sometimes', 'array', 'max:' . self::MAX_LIST_ITEMS],
            'supportNumbers.*' => ['array'],
            'supportNumbers.*.type' => ['required', 'string', 'max:' . self::MAX_SHORT_LENGTH],
            /*
             * No `max:` here, deliberately. backend/cleanContactValue() applies
             * its 255 cap to the TRIMMED value and only after the blank
             * short-circuit; that exact ordering is reproduced in
             * checkContactValues() below so the two cannot disagree.
             */
            'supportNumbers.*.value' => ['sometimes', 'string'],
            'supportNumbers.*.values' => ['sometimes', 'nullable', 'array', 'max:' . self::MAX_LIST_ITEMS],
            'supportNumbers.*.values.*' => ['string'],
            'supportNumbers.*.enabled' => ['sometimes', new Boolish()],
            'supportNumbers.*.order' => ['sometimes', new Integerish()],
        ];

        foreach (self::SETTINGS_BOOLISH as $key) {
            $rules["settings.{$key}"] = ['sometimes', new BoolishString()];
        }

        foreach (self::SETTINGS_TEXT as $key) {
            $rules["settings.{$key}"] = ['sometimes', 'string', 'max:' . self::MAX_TEXT_LENGTH];
        }

        return $rules;
    }

    /**
     * Every message here is backend/SettingsService's literal string. The admin
     * form renders `data.errors[field]` verbatim, so these ARE the contract —
     * ApiFormRequest's generic defaults would change what the operator reads.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'settings.array' => self::SETTINGS_NOT_AN_OBJECT,

            'socialLinks.array' => self::NOT_A_LIST,
            'socialLinks.max' => self::TOO_MANY_ITEMS,
            'socialLinks.*.array' => self::NOT_AN_OBJECT,
            'socialLinks.*.platform.required' => self::PLATFORM_REQUIRED,
            'socialLinks.*.platform.string' => self::PLATFORM_REQUIRED,
            'socialLinks.*.platform.max' => self::PLATFORM_TOO_LONG,
            'socialLinks.*.url.present' => self::URL_REQUIRED,
            'socialLinks.*.url.string' => self::URL_NOT_A_STRING,
            'socialLinks.*.url.max' => self::URL_TOO_LONG,

            'supportNumbers.array' => self::NOT_A_LIST,
            'supportNumbers.max' => self::TOO_MANY_ITEMS,
            'supportNumbers.*.array' => self::NOT_AN_OBJECT,
            'supportNumbers.*.type.required' => self::TYPE_REQUIRED,
            'supportNumbers.*.type.string' => self::TYPE_REQUIRED,
            'supportNumbers.*.type.max' => self::TYPE_TOO_LONG,
            'supportNumbers.*.value.string' => self::NOT_A_STRING,
            'supportNumbers.*.values.array' => self::NOT_A_LIST,
            'supportNumbers.*.values.max' => self::TOO_MANY_VALUES,
            'supportNumbers.*.values.*.string' => self::NOT_A_STRING,
        ];

        foreach (self::SETTINGS_TEXT as $key) {
            $messages["settings.{$key}.string"] = self::NOT_A_STRING;
            $messages["settings.{$key}.max"] = self::TEXT_TOO_LONG;
        }

        return $messages;
    }

    /**
     * `attributes()` on the base class builds an identity map from rules()
     * keys, which for a wildcard rule produces a literal '*' in the message.
     * Every message this request emits is a fixed sentence with no :attribute
     * placeholder, so the map is simply not needed here.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $payload = $this->all();

            if ($payload === []) {
                $validator->errors()->add('settings', self::EMPTY_PAYLOAD);

                return;
            }

            $this->checkUnknownTopLevelKeys($validator, $payload);
            $this->checkSettingsObject($validator, $payload);
            $this->checkEntryShapes($validator, $payload, 'socialLinks', self::SOCIAL_LINK_KEYS);
            $this->checkEntryShapes($validator, $payload, 'supportNumbers', self::SUPPORT_CONTACT_KEYS);
            $this->checkSocialLinkPlatforms($validator, $payload);
            $this->checkContactValues($validator, $payload);
        });
    }

    /**
     * LEVEL 1. Anything outside {settings, socialLinks, supportNumbers} is a
     * 422 AND a logged security event, exactly as backend/ does — the log line
     * is what makes a probing client visible to an operator, and it is the
     * half that `validated()` could never provide.
     *
     * @param  array<string, mixed>  $payload
     */
    private function checkUnknownTopLevelKeys(Validator $validator, array $payload): void
    {
        $unknown = array_values(array_diff(array_keys($payload), self::ALLOWED_TOP_LEVEL));

        if ($unknown === []) {
            return;
        }

        $this->logRejectedKeys('top-level', $unknown);

        $validator->errors()->add(
            'settings',
            self::UNKNOWN_TOP_LEVEL . implode(', ', array_map('strval', $unknown))
        );
    }

    /**
     * LEVEL 2. `settings` must be a MAP (not a list) and may hold only the
     * six allow-listed keys.
     *
     * The map-vs-list test matters: PHP has one array type, so `"settings":
     * ["a","b"]` decodes to an array and satisfies Laravel's `array` rule.
     * array_is_list() is the distinction backend/ draws with the same call.
     *
     * @param  array<string, mixed>  $payload
     */
    private function checkSettingsObject(Validator $validator, array $payload): void
    {
        if (!array_key_exists('settings', $payload)) {
            return;
        }

        $settings = $payload['settings'];

        if (!\is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            // The `array` rule already reports a non-array; only the LIST case
            // needs an error of its own, and it reuses the same sentence.
            if (\is_array($settings)) {
                $validator->errors()->add('settings', self::SETTINGS_NOT_AN_OBJECT);
            }

            return;
        }

        $unknown = array_values(array_diff(array_keys($settings), self::SETTINGS_KEYS));

        if ($unknown === []) {
            return;
        }

        $this->logRejectedKeys('settings', $unknown);

        $validator->errors()->add(
            'settings',
            self::UNKNOWN_IN_SETTINGS . implode(', ', array_map('strval', $unknown))
        );
    }

    /**
     * LEVEL 3. Each `socialLinks[]` / `supportNumbers[]` element must be a MAP
     * whose keys are a subset of that family's allow-list — and the container
     * itself must be a LIST, not a map.
     *
     * The container check is why a payload like
     * `{"socialLinks": {"a": {...}}}` is rejected: it would otherwise be
     * stored as a JSON object and the frontend's `.map()` would throw on it.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowedKeys
     */
    private function checkEntryShapes(Validator $validator, array $payload, string $field, array $allowedKeys): void
    {
        if (!array_key_exists($field, $payload)) {
            return;
        }

        $entries = $payload[$field];

        if (!\is_array($entries)) {
            return; // the `array` rule already reported it
        }

        if (!array_is_list($entries)) {
            $validator->errors()->add($field, self::NOT_A_LIST);

            return;
        }

        foreach ($entries as $index => $entry) {
            $key = "{$field}.{$index}";

            if (!\is_array($entry)) {
                continue; // the `socialLinks.*` array rule already reported it
            }

            if ($entry !== [] && array_is_list($entry)) {
                $validator->errors()->add($key, self::NOT_AN_OBJECT);

                continue;
            }

            $unknown = array_values(array_diff(array_keys($entry), $allowedKeys));

            if ($unknown === []) {
                continue;
            }

            $this->logRejectedKeys($key, $unknown);

            $validator->errors()->add(
                $key,
                self::UNKNOWN_IN_ENTRY . implode(', ', array_map('strval', $unknown))
            );
        }
    }

    /**
     * A `platform` of '   ' passes Laravel's `required` (which only rejects
     * null, '' and empty arrays) but fails backend/'s
     * `trim($entry['platform']) === ''`. This closes that gap.
     *
     * @param  array<string, mixed>  $payload
     */
    private function checkSocialLinkPlatforms(Validator $validator, array $payload): void
    {
        foreach ($this->entriesOf($payload, 'socialLinks') as $index => $entry) {
            $platform = $entry['platform'] ?? null;

            if (\is_string($platform) && trim($platform) === '' && $platform !== '') {
                $validator->errors()->add("socialLinks.{$index}.platform", self::PLATFORM_REQUIRED);
            }
        }
    }

    /**
     * The per-element contact rules, in backend/cleanContactValue()'s EXACT
     * order — the order is load-bearing because a blank short-circuits before
     * the length, control-character and phone checks ever run:
     *
     *   trim -> if '' return -> length 255 -> control chars -> WhatsApp digits
     *
     * Applied to `value` AND to every element of `values`, with the SAME
     * `$isWhatsApp` flag derived once from the sibling `type`. That
     * cross-field dependency is why this cannot be a stand-alone rule object.
     *
     * @param  array<string, mixed>  $payload
     */
    private function checkContactValues(Validator $validator, array $payload): void
    {
        foreach ($this->entriesOf($payload, 'supportNumbers') as $index => $entry) {
            $field = "supportNumbers.{$index}";

            $type = $entry['type'] ?? null;

            if (\is_string($type)) {
                $trimmedType = trim($type);

                if ($trimmedType === '' && $type !== '') {
                    $validator->errors()->add("{$field}.type", self::TYPE_REQUIRED);
                }

                if (mb_strlen($trimmedType) > self::MAX_SHORT_LENGTH) {
                    $validator->errors()->add("{$field}.type", self::TYPE_TOO_LONG);
                }
            }

            $isWhatsApp = \is_string($type) && str_contains(trim($type), self::WHATSAPP_MARKER);

            if (\is_string($entry['value'] ?? null)) {
                $this->checkContactValue($validator, (string) $entry['value'], "{$field}.value", $isWhatsApp);
            }

            $values = $entry['values'] ?? null;

            if (!\is_array($values)) {
                continue;
            }

            if (!array_is_list($values)) {
                $validator->errors()->add("{$field}.values", self::NOT_A_LIST);

                continue;
            }

            foreach ($values as $vIndex => $value) {
                if (\is_string($value)) {
                    $this->checkContactValue($validator, $value, "{$field}.values.{$vIndex}", $isWhatsApp);
                }
            }
        }
    }

    private function checkContactValue(Validator $validator, string $value, string $field, bool $isWhatsApp): void
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return;
        }

        if (mb_strlen($trimmed) > self::MAX_SHORT_LENGTH) {
            $validator->errors()->add($field, self::VALUE_TOO_LONG);

            return;
        }

        if (preg_match(self::CONTROL_CHARS, $trimmed) === 1) {
            $validator->errors()->add($field, self::FORBIDDEN_CHARS);

            return;
        }

        if ($isWhatsApp && preg_match(self::PHONE_PATTERN, $trimmed) !== 1) {
            $validator->errors()->add($field, self::WHATSAPP_DIGITS_ONLY);
        }
    }

    /**
     * The well-formed entries of a list field, keyed by their original index so
     * error keys stay aligned with what the caller sent. Malformed entries are
     * skipped — the structural rules have already reported them.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function entriesOf(array $payload, string $field): array
    {
        $entries = $payload[$field] ?? null;

        if (!\is_array($entries) || !array_is_list($entries)) {
            return [];
        }

        $result = [];

        foreach ($entries as $index => $entry) {
            if (\is_array($entry) && ($entry === [] || !array_is_list($entry))) {
                $result[$index] = $entry;
            }
        }

        return $result;
    }

    /**
     * backend/ logs `settings.rejected_keys` at WARNING on the security
     * channel for the top-level case only. It is emitted here for all three
     * levels, with a `level` field naming which one — a nested unknown key is
     * the same probe from the same caller and there is no reason for it to be
     * invisible.
     *
     * @param  list<string>  $keys
     */
    private function logRejectedKeys(string $level, array $keys): void
    {
        SecurityLog::security('WARNING', 'Rejected settings write with non-whitelisted keys', [
            'event' => 'settings.rejected_keys',
            'level' => $level,
            'keys' => $keys,
        ]);
    }
}
