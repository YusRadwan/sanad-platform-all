<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Http\Requests\Settings\UpdateSettingsRequest as Rules;
use App\Models\Setting;
use App\Rules\SafeUrlValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * The per-field semantics of POST /api/v1/settings — freeze-table row 127.
 *
 * Everything here was read out of backend/SettingsService.php line by line,
 * not inferred from field names. Where a behaviour looks wrong it is because
 * it IS the legacy behaviour and the port is faithful; those cases carry a
 * comment saying so.
 */
final class SettingsValueSemanticsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private function save(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/settings', $payload);
    }

    private function stored(string $key): mixed
    {
        return Setting::query()->find($key)?->getAttribute('value');
    }

    // ─────────────────────────────────────────────────────────────────────
    // BoolishString — the four section toggles
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE SECTION TOGGLES ARE STORED AS THE **STRINGS** 'true'/'false'.    ║
     * ║ A REAL JSON BOOLEAN WOULD BREAK THE ROUND-TRIP SILENTLY.             ║
     * ║                                                                      ║
     * ║ PlatformSettings.tsx reads them back with `=== 'true'`. Store a       ║
     * ║ boolean and that comparison is false forever: every section shows as  ║
     * ║ OFF after a reload, while the save itself answered 200.               ║
     * ║                                                                      ║
     * ║ Asserted on the RAW STORED VALUE with assertSame, so `true` and       ║
     * ║ `'true'` cannot be confused by a loose comparison.                    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_section_toggles_are_stored_as_strings_not_booleans(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['settings' => ['social_section_enabled' => 'true']])->assertOk();

        $value = $this->stored('settings')['social_section_enabled'];

        self::assertSame('true', $value);
        self::assertIsString($value, 'A real bool here breaks `=== "true"` in the UI.');
    }

    /** A real bool IS accepted — and normalised DOWN to the string form. */
    public function test_a_real_boolean_toggle_is_normalised_to_a_string(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['settings' => [
            'social_section_enabled' => true,
            'terms_section_enabled' => false,
        ]])->assertOk();

        $stored = $this->stored('settings');

        self::assertSame('true', $stored['social_section_enabled']);
        self::assertSame('false', $stored['terms_section_enabled']);
    }

    /** The accepted set is NARROW: 1/'1'/0/'yes' are all refused here. */
    public function test_a_toggle_rejects_everything_but_bool_and_the_two_literals(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        foreach ([1, '1', 0, 'yes', 'TRUE', ''] as $bad) {
            $this->startNextRequest();

            // The error key is the literal dotted string, not a nested path.
            $errors = $this->save(['settings' => ['social_section_enabled' => $bad]])
                ->assertStatus(422)
                ->json('data.errors');

            self::assertSame(
                \App\Rules\BoolishString::MESSAGE,
                $errors['settings.social_section_enabled'][0],
                'Accepted a value outside {bool, "true", "false"}.'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Boolish / Integerish — the per-entry flags
    // ─────────────────────────────────────────────────────────────────────

    /**
     * CONTRAST with the toggles above: `enabled` inside an entry IS a real
     * JSON boolean, because the frontend binds it straight to a checkbox.
     * Two normalisations, opposite directions, in one endpoint — inherited.
     */
    public function test_entry_enabled_is_stored_as_a_real_boolean(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['socialLinks' => [
            ['platform' => 'A', 'url' => '', 'enabled' => '1', 'order' => '3'],
            ['platform' => 'B', 'url' => '', 'enabled' => 'false', 'order' => 4],
        ]])->assertOk();

        $links = $this->stored('socialLinks');

        self::assertTrue($links[0]['enabled']);
        self::assertIsBool($links[0]['enabled'], 'The UI binds this to a checkbox as a real boolean.');
        self::assertFalse($links[1]['enabled']);

        // Integerish: the numeric STRING '3' becomes the int 3.
        self::assertSame(3, $links[0]['order']);
        self::assertIsInt($links[0]['order']);
    }

    /** Absent flags default to false / 0 — backend/'s `?? false` and `?? 0`. */
    public function test_absent_enabled_and_order_default_rather_than_failing(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['socialLinks' => [['platform' => 'A', 'url' => '']]])->assertOk();

        $link = $this->stored('socialLinks')[0];

        self::assertFalse($link['enabled']);
        self::assertSame(0, $link['order']);
    }

    public function test_a_non_integer_order_is_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['socialLinks' => [['platform' => 'A', 'url' => '', 'order' => '1.5']]])
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────
    // URL safety
    // ─────────────────────────────────────────────────────────────────────

    /**
     * '' IS A VALID URL HERE and means "in the catalogue, not configured".
     * `present`, not `required` — see the rules() comment.
     */
    public function test_an_empty_url_is_accepted_and_stored_as_an_empty_string(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['socialLinks' => [['platform' => 'Facebook', 'url' => '']]])->assertOk();

        self::assertSame('', $this->stored('socialLinks')[0]['url']);
    }

    /** …but the KEY must be there. Absent url is 'حقل الرابط مطلوب'. */
    public function test_a_missing_url_key_is_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $response = $this->save(['socialLinks' => [['platform' => 'Facebook']]])->assertStatus(422);

        self::assertSame(Rules::URL_REQUIRED, $response->json('data.errors')['socialLinks.0.url'][0]);
    }

    /**
     * THE BUG backend/ FIXED AND THIS PORT MUST NOT REOPEN: the URL allow-list
     * once keyed off `social_links` while the UI writes `socialLinks`, so the
     * list branch never ran on a real payload and `javascript:` URLs WERE
     * stored — then served to every anonymous visitor as an href.
     */
    public function test_dangerous_url_schemes_are_refused(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        foreach ([
            'javascript:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'vbscript:msgbox(1)',
            '//evil.example.com',
            'file:///etc/passwd',
        ] as $bad) {
            $this->startNextRequest();

            $response = $this->save(['socialLinks' => [['platform' => 'X', 'url' => $bad]]])
                ->assertStatus(422);

            self::assertSame(
                SafeUrlValue::MESSAGE,
                $response->json('data.errors')['socialLinks.0.url'][0],
                "Accepted a dangerous URL: {$bad}"
            );
        }

        self::assertNull($this->stored('socialLinks'));
    }

    public function test_internal_paths_and_https_are_accepted(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['socialLinks' => [
            ['platform' => 'A', 'url' => 'https://facebook.com/sanad'],
            ['platform' => 'B', 'url' => 'http://example.com'],
            ['platform' => 'C', 'url' => '/internal/page'],
        ]])->assertOk();

        self::assertCount(3, $this->stored('socialLinks'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // platform_logo — REMOVED
    //
    // The key is gone from the allow-list: the logo is a static asset
    // (frontend/public/branding/logo.png) and no longer a settings value.
    // The three tests that lived here covered App\Rules\SafeImageDataUriOrUrl,
    // which was deleted with it — no other field in the application accepts a
    // `data:` URI, so there is nothing left for them to assert. Rejection of
    // the key itself is now covered by SettingsUnknownKeyRejectionTest.
    // ─────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────
    // Contact values: control chars + the WhatsApp digit restriction
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE WHATSAPP RESTRICTION IS TRIGGERED BY AN ARABIC SUBSTRING IN A    ║
     * ║ FREE-TEXT LABEL, AND THAT MECHANISM IS PRESERVED EXACTLY.            ║
     * ║                                                                      ║
     * ║ `str_contains($type, 'واتساب')`. It is fragile — 'WhatsApp' in Latin ║
     * ║ script gets no restriction — but replacing it with an enum would     ║
     * ║ either reject every existing free-text row or start 422-ing rows it  ║
     * ║ never applied to. Both break live data. See the const's docblock.    ║
     * ║                                                                      ║
     * ║ Its real job is narrow: WhatsAppWidget.tsx interpolates this value    ║
     * ║ into `https://wa.me/{value}`, and the restriction is what keeps       ║
     * ║ anything but phone characters out of that URL builder.               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_whatsapp_contact_accepts_only_phone_characters(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $response = $this->save(['supportNumbers' => [
            ['type' => 'أرقام واتساب', 'value' => 'https://evil.example/?x'],
        ]])->assertStatus(422);

        self::assertSame(
            Rules::WHATSAPP_DIGITS_ONLY,
            $response->json('data.errors')['supportNumbers.0.value'][0]
        );
    }

    public function test_a_whatsapp_contact_accepts_a_real_phone_number(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['supportNumbers' => [
            ['type' => 'أرقام واتساب', 'value' => '+20 (100) 123-4567, 0111'],
        ]])->assertOk();

        self::assertSame('+20 (100) 123-4567, 0111', $this->stored('supportNumbers')[0]['value']);
    }

    /** The restriction applies to EVERY element of `values`, not just `value`. */
    public function test_the_digit_restriction_also_covers_the_values_array(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $response = $this->save(['supportNumbers' => [
            ['type' => 'أرقام واتساب', 'value' => '0100', 'values' => ['0100', 'not-a-number']],
        ]])->assertStatus(422);

        self::assertSame(
            Rules::WHATSAPP_DIGITS_ONLY,
            $response->json('data.errors')['supportNumbers.0.values.1'][0]
        );
    }

    /** A NON-WhatsApp contact is free text — an email must still be storable. */
    public function test_a_non_whatsapp_contact_is_not_digit_restricted(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['supportNumbers' => [
            ['type' => 'البريد الإلكتروني', 'value' => 'support@sanad.local'],
            ['type' => 'ساعات العمل', 'value' => 'من 9 صباحًا حتى 5 مساءً'],
        ]])->assertOk();

        self::assertSame('support@sanad.local', $this->stored('supportNumbers')[0]['value']);
    }

    /** Control characters are the classic "java\nscript:" filter bypass. */
    public function test_control_characters_are_rejected_in_contact_values(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $response = $this->save(['supportNumbers' => [
            ['type' => 'البريد الإلكتروني', 'value' => "a\x00b"],
        ]])->assertStatus(422);

        self::assertSame(
            Rules::FORBIDDEN_CHARS,
            $response->json('data.errors')['supportNumbers.0.value'][0]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // The `values` three-inputs-two-outputs rule
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `values` IS "SOMETIMES PRESENT IN THE OUTPUT", NOT "NULLABLE".       ║
     * ║                                                                      ║
     * ║   absent -> key ABSENT from the stored JSON                          ║
     * ║   null   -> key ABSENT from the stored JSON                          ║
     * ║   []     -> key PRESENT, stored as []                                ║
     * ║                                                                      ║
     * ║ Three inputs, two outputs. Storing `"values": null` instead of        ║
     * ║ omitting it is a byte-level change to a value the admin form          ║
     * ║ round-trips on EVERY save. backend/cleanSupportNumbers() appends the  ║
     * ║ key only when `$values !== null`; this is that line, tested.          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_three_values_inputs_produce_exactly_two_outputs(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['supportNumbers' => [
            ['type' => 'رقم الدعم الأساسي', 'value' => '0100'],                  // absent
            ['type' => 'رقم الدعم الإضافي', 'value' => '0101', 'values' => null], // null
            ['type' => 'أرقام واتساب', 'value' => '0102', 'values' => []],        // []
        ]])->assertOk();

        $stored = $this->stored('supportNumbers');

        self::assertArrayNotHasKey('values', $stored[0], 'absent must stay absent');
        self::assertArrayNotHasKey('values', $stored[1], 'null must be OMITTED, not stored as null');
        self::assertArrayHasKey('values', $stored[2], '[] must be preserved');
        self::assertSame([], $stored[2]['values']);
    }

    /** …and the raw JSON bytes agree — no `"values":null` anywhere. */
    public function test_no_null_values_key_reaches_the_stored_json(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['supportNumbers' => [
            ['type' => 'رقم الدعم الأساسي', 'value' => '0100', 'values' => null],
        ]])->assertOk();

        $raw = (string) \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'supportNumbers')
            ->value('value');

        self::assertStringNotContainsString('"values"', $raw);
    }

    public function test_a_populated_values_array_survives_the_round_trip(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['supportNumbers' => [
            ['type' => 'أرقام واتساب', 'value' => '0100', 'values' => ['0101', '0102']],
        ]])->assertOk();

        self::assertSame(['0101', '0102'], $this->stored('supportNumbers')[0]['values']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Required-ness and trimming
    // ─────────────────────────────────────────────────────────────────────

    /** A whitespace-only platform passes Laravel's `required`; backend/ says no. */
    public function test_a_whitespace_only_platform_is_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $response = $this->save(['socialLinks' => [['platform' => '   ', 'url' => '']]])
            ->assertStatus(422);

        self::assertSame(
            Rules::PLATFORM_REQUIRED,
            $response->json('data.errors')['socialLinks.0.platform'][0]
        );
    }

    public function test_a_whitespace_only_contact_type_is_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['supportNumbers' => [['type' => '  ', 'value' => '0100']]])
            ->assertStatus(422);
    }

    public function test_platform_type_and_value_are_trimmed_before_storage(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save([
            'socialLinks' => [['platform' => '  Facebook  ', 'url' => '  https://a.example  ']],
            'supportNumbers' => [['type' => '  البريد  ', 'value' => '  a@b.example  ']],
        ])->assertOk();

        self::assertSame('Facebook', $this->stored('socialLinks')[0]['platform']);
        self::assertSame('https://a.example', $this->stored('socialLinks')[0]['url']);
        self::assertSame('البريد', $this->stored('supportNumbers')[0]['type']);
        self::assertSame('a@b.example', $this->stored('supportNumbers')[0]['value']);
    }

    /**
     * Policy bodies are NOT trimmed — a leading blank line in a terms document
     * is content, not noise. Contrast the fields above.
     */
    public function test_policy_text_is_not_trimmed(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save(['settings' => ['privacy_policy' => "\n  نص  \n"]])->assertOk();

        self::assertSame("\n  نص  \n", $this->stored('settings')['privacy_policy']);
    }

    public function test_policy_text_beyond_two_hundred_thousand_characters_is_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $response = $this->save(['settings' => [
            'privacy_policy' => str_repeat('a', Rules::MAX_TEXT_LENGTH + 1),
        ]])->assertStatus(422);

        self::assertSame(
            Rules::TEXT_TOO_LONG,
            $response->json('data.errors')['settings.privacy_policy'][0]
        );
    }

    /**
     * A rejected entry must leave NO partial write — backend/ gets this by
     * validating everything into `$clean` first; here the Form Request rejects
     * before the controller is entered at all, and the write itself is one
     * transaction on top of that.
     */
    public function test_a_rejected_entry_leaves_no_partial_write(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->save([
            // Valid…
            'settings' => ['privacy_policy' => 'ok'],
            'socialLinks' => [['platform' => 'A', 'url' => '']],
            // …and one poisoned entry at the end.
            'supportNumbers' => [
                ['type' => 'أرقام واتساب', 'value' => 'javascript:alert(1)'],
            ],
        ])->assertStatus(422);

        self::assertSame(0, \Illuminate\Support\Facades\DB::table('settings')->count());
    }
}
