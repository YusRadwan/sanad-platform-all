<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE SINGLE MOST IMPORTANT TEST IN STAGE 10.                             ║
 * ║                                                                          ║
 * ║ It submits the COMPLETE payload PlatformSettings.tsx actually sends on   ║
 * ║ a fresh installation — eleven social links and five support contacts,    ║
 * ║ almost every one of them carrying an EMPTY STRING — and asserts 200.    ║
 * ║                                                                          ║
 * ║ WHY A COMBINED PAYLOAD AND NOT SIXTEEN FIELD-LEVEL TESTS.                ║
 * ║ ConvertEmptyStringsToNull is GLOBAL middleware. It rewrites every '' in  ║
 * ║ the body before any rule runs, and it is all-or-nothing per request. A   ║
 * ║ narrow fixture — one social link, one empty url — can PASS even with the ║
 * ║ exemption deleted, because a validator that reports one error still      ║
 * ║ reports 422 for reasons a small test may not distinguish, and because a  ║
 * ║ fixture built to isolate one field tends not to carry the other fifteen  ║
 * ║ empties that make the failure obvious. Field-level tests are necessary   ║
 * ║ and they exist below; they are NOT SUFFICIENT.                          ║
 * ║                                                                          ║
 * ║ THE FAILURE THIS CATCHES IS NOT A SILENT NO-OP. It is an admin who      ║
 * ║ cannot save the settings page at all, from the first click, on a brand   ║
 * ║ new deployment — with a 422 that names a URL field they never touched.  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class SettingsRealPayloadTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /**
     * SOCIAL_PLATFORM_CATALOG from PlatformSettings.tsx, verbatim — all eleven
     * rows, in order, every url ''.
     *
     * @return list<array<string, mixed>>
     */
    public static function socialCatalogue(): array
    {
        $platforms = [
            'Facebook', 'Instagram', 'X (Twitter)', 'YouTube', 'LinkedIn',
            'WhatsApp', 'Telegram', 'TikTok', 'Snapchat', 'Discord', 'Gmail',
        ];

        $rows = [];

        foreach ($platforms as $index => $platform) {
            $rows[] = [
                'platform' => $platform,
                'url' => '',
                'enabled' => false,
                'order' => $index + 1,
            ];
        }

        return $rows;
    }

    /**
     * SUPPORT_CONTACT_CATALOG from PlatformSettings.tsx, verbatim.
     *
     * NOTE the third row: it is the ONLY one that carries `values`, and it
     * carries `[]`. handleSave() maps `({type, value, values, enabled, order})`
     * over the catalogue, and JSON.stringify DROPS an undefined property — so
     * the other four entries reach the server with NO `values` key at all.
     * That asymmetry is real wire behaviour, reproduced here exactly.
     *
     * @return list<array<string, mixed>>
     */
    public static function supportCatalogue(): array
    {
        return [
            ['type' => 'رقم الدعم الأساسي', 'value' => '', 'enabled' => false, 'order' => 1],
            ['type' => 'رقم الدعم الإضافي', 'value' => '', 'enabled' => false, 'order' => 2],
            ['type' => 'أرقام واتساب', 'value' => '', 'values' => [], 'enabled' => false, 'order' => 3],
            ['type' => 'البريد الإلكتروني', 'value' => '', 'enabled' => false, 'order' => 4],
            ['type' => 'ساعات العمل', 'value' => '', 'enabled' => false, 'order' => 5],
        ];
    }

    /** The complete handleSave() body on a fresh install. */
    public static function freshInstallPayload(): array
    {
        return [
            'settings' => [
                'social_section_enabled' => 'false',
                'privacy_section_enabled' => 'false',
                'terms_section_enabled' => 'false',
                'support_section_enabled' => 'false',
                'privacy_policy' => '',
                'terms_conditions' => '',
            ],
            'socialLinks' => self::socialCatalogue(),
            'supportNumbers' => self::supportCatalogue(),
        ];
    }

    public function test_the_real_frontend_payload_saves_with_all_its_empty_strings(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $payload = self::freshInstallPayload();

        // Sanity — the fixture must actually be full of empty strings, or this
        // test proves nothing about the middleware.
        self::assertSame(11, \count($payload['socialLinks']));
        self::assertCount(11, array_filter($payload['socialLinks'], static fn ($l) => $l['url'] === ''));
        self::assertCount(5, array_filter($payload['supportNumbers'], static fn ($c) => $c['value'] === ''));

        $this->postJson('/api/v1/settings', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث الإعدادات بنجاح');

        // And the empties were STORED as '', not dropped and not nulled.
        $links = Setting::query()->find('socialLinks')?->getAttribute('value');

        self::assertCount(11, $links);
        self::assertSame('', $links[0]['url']);
        self::assertSame('Facebook', $links[0]['platform']);
    }

    /**
     * The same payload a SECOND time, on top of the first — the round trip the
     * admin form performs on every save. Byte-identical stored value both
     * times: no field drifts, no key appears or disappears.
     */
    public function test_saving_the_same_payload_twice_is_byte_identical(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $payload = self::freshInstallPayload();

        $first = $this->postJson('/api/v1/settings', $payload)->assertOk()->json('data');

        $this->startNextRequest();

        $second = $this->postJson('/api/v1/settings', $payload)->assertOk()->json('data');

        self::assertSame($first, $second);
    }

    /**
     * The response of the WRITE is the FULL map, not a diff — the admin form
     * re-seeds its whole state from it, so a partial answer blanks two tabs.
     */
    public function test_a_partial_write_still_answers_the_full_map(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/settings', self::freshInstallPayload())->assertOk();

        $this->startNextRequest();

        $data = $this->postJson('/api/v1/settings', [
            'settings' => ['privacy_policy' => 'نص جديد'],
        ])->assertOk()->json('data');

        self::assertSame(['settings', 'socialLinks', 'supportNumbers'], array_keys($data));
        self::assertCount(11, $data['socialLinks'], 'socialLinks must survive a settings-only save.');
        self::assertSame('نص جديد', $data['settings']['privacy_policy']);
    }

    /**
     * THE SETTINGS OBJECT IS REPLACED WHOLESALE, NOT MERGED — which is why the
     * frontend always sends a complete snapshot of every tab's state on every
     * save. Preserved from backend/, whose `set()` is a single-row upsert of
     * the whole JSON value.
     */
    public function test_the_settings_object_is_replaced_not_merged(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/settings', self::freshInstallPayload())->assertOk();

        $this->startNextRequest();

        $data = $this->postJson('/api/v1/settings', [
            'settings' => ['privacy_policy' => 'x'],
        ])->assertOk()->json('data');

        self::assertSame(['privacy_policy' => 'x'], $data['settings']);
    }

    /** Public read and admin write agree, key for key, after a real save. */
    public function test_the_public_read_returns_exactly_what_the_write_returned(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $written = $this->postJson('/api/v1/settings', self::freshInstallPayload())
            ->assertOk()
            ->json('data');

        $this->startNextRequest();

        // No session on the read at all.
        $read = $this->getJson('/api/v1/settings')->assertOk()->json('data');

        self::assertSame($written, $read);
    }
}
