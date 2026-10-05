<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/settings — freeze-table row 126. Fully public.
 */
final class SettingsReadTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_it_is_reachable_with_no_session_at_all(): void
    {
        $this->disableRateLimiting();

        // No login, no cookie, no CSRF token. Footer.tsx and WhatsAppWidget
        // .tsx call this on every public page load.
        $this->getJson('/api/v1/settings')->assertOk();
    }

    /**
     * `data` IS THE MAP. Not `data.settings.settings`, not `data.data`.
     * The nesting mistake is the natural one — every other admin read in this
     * application wraps its payload in a named key.
     */
    public function test_data_is_the_settings_map_itself_with_no_wrapper_key(): void
    {
        $this->seedThreeRows();

        $body = $this->getJson('/api/v1/settings')->assertOk()->json();

        self::assertSame(
            ['settings', 'socialLinks', 'supportNumbers'],
            array_keys($body['data'])
        );

        self::assertSame('true', $body['data']['settings']['social_section_enabled']);
        self::assertSame('Facebook', $body['data']['socialLinks'][0]['platform']);
        self::assertSame('أرقام واتساب', $body['data']['supportNumbers'][0]['type']);
    }

    /** An empty table answers an OBJECT, never `[]` and never null. */
    public function test_an_empty_table_answers_an_empty_json_object(): void
    {
        $this->disableRateLimiting();

        $raw = $this->getJson('/api/v1/settings')->assertOk()->getContent();

        self::assertStringContainsString('"data":{}', (string) $raw);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE READ IS ALLOW-LIST FILTERED — A DELIBERATE, USER-APPROVED        ║
     * ║ DIFFERENCE FROM backend/, WHICH SERVES `SELECT * FROM settings`.     ║
     * ║                                                                      ║
     * ║ This test writes a stray row through the model itself — i.e. by a    ║
     * ║ path that is NOT POST /settings, which is exactly the scenario the   ║
     * ║ filter exists for (a migration, a seeder, a DBA, a future feature)   ║
     * ║ — and asserts it never reaches the anonymous public response.         ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_stray_row_is_never_served_publicly(): void
    {
        $this->disableRateLimiting();

        Setting::query()->create(['key' => 'settings', 'value' => ['privacy_policy' => 'ok']]);
        Setting::query()->create(['key' => 'internal_api_key', 'value' => 'SUPER-SECRET']);

        $response = $this->getJson('/api/v1/settings')->assertOk();

        $response->assertJsonPath('data.settings.privacy_policy', 'ok');
        self::assertArrayNotHasKey('internal_api_key', $response->json('data'));
        self::assertStringNotContainsString('SUPER-SECRET', (string) $response->getContent());
    }

    /**
     * Arabic must ship as literal UTF-8 in the body, not as \uXXXX escapes —
     * asserted on the RAW bytes, because json() decodes both spellings
     * identically and would make the assertion vacuous.
     */
    public function test_arabic_ships_as_literal_utf8_not_as_unicode_escapes(): void
    {
        $this->disableRateLimiting();

        Setting::query()->create([
            'key' => 'supportNumbers',
            'value' => [['type' => 'أرقام واتساب', 'value' => '+201000000000', 'enabled' => true, 'order' => 1]],
        ]);

        $raw = (string) $this->getJson('/api/v1/settings')->assertOk()->getContent();

        self::assertStringContainsString('أرقام واتساب', $raw);

        /*
         * The escaped spelling is DERIVED, not typed: json_encode() with no
         * flags is exactly the encoder we are asserting is NOT in use, so it
         * produces the "أر..." form to look for. Deriving it means
         * the test cannot drift from PHP's actual escaping behaviour.
         */
        $escaped = trim((string) json_encode('أرقام واتساب'), '"');

        self::assertStringNotContainsString($escaped, $raw, 'JSON_UNESCAPED_UNICODE was lost.');
    }

    /**
     * The MODEL stores unescaped UTF-8 too — backend/SettingsRepository::set()
     * passes JSON_UNESCAPED_UNICODE, and Setting::asJson() reproduces it. This
     * asserts the DATABASE BYTES, not the response, so it fails if the
     * override is removed even though the wire format would be unchanged.
     */
    public function test_the_stored_json_bytes_are_unescaped_utf8(): void
    {
        Setting::query()->create(['key' => 'settings', 'value' => ['privacy_policy' => 'سياسة الخصوصية']]);

        $raw = (string) \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'settings')
            ->value('value');

        self::assertStringContainsString('سياسة الخصوصية', $raw);

        $escaped = trim((string) json_encode('سياسة الخصوصية'), '"');

        self::assertStringNotContainsString($escaped, $raw, 'Setting::asJson() lost the flag.');
    }

    /** A stored empty LIST must stay a JSON array — the frontend calls .map(). */
    public function test_a_stored_empty_list_is_not_object_coerced(): void
    {
        $this->disableRateLimiting();

        Setting::query()->create(['key' => 'socialLinks', 'value' => []]);

        $raw = (string) $this->getJson('/api/v1/settings')->assertOk()->getContent();

        self::assertStringContainsString('"socialLinks":[]', $raw);
    }

    private function seedThreeRows(): void
    {
        $this->disableRateLimiting();

        Setting::query()->create([
            'key' => 'settings',
            'value' => [
                'social_section_enabled' => 'true',
                'privacy_policy' => 'نص السياسة',
            ],
        ]);
        Setting::query()->create([
            'key' => 'socialLinks',
            'value' => [['platform' => 'Facebook', 'url' => 'https://facebook.com/x', 'enabled' => true, 'order' => 1]],
        ]);
        Setting::query()->create([
            'key' => 'supportNumbers',
            'value' => [['type' => 'أرقام واتساب', 'value' => '+201000000000', 'enabled' => true, 'order' => 1, 'values' => []]],
        ]);
    }
}
