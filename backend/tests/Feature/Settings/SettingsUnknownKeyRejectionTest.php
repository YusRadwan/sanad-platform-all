<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Http\Requests\Settings\UpdateSettingsRequest as Rules;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE THREE-LEVEL UNKNOWN-KEY REJECTION, AND WHY `validated()` IS NOT IT. ║
 * ║                                                                          ║
 * ║ `$request->validated()` DROPS unrecognised keys silently — it does not   ║
 * ║ reject them. Under a validated()-only implementation EVERY test in this  ║
 * ║ file that asserts "the row did not change" would still pass, because     ║
 * ║ the stray key would indeed never be written. What would NOT pass is the  ║
 * ║ status code: 200 instead of 422, i.e. the admin is told a save succeeded ║
 * ║ while part of what they sent silently evaporated.                        ║
 * ║                                                                          ║
 * ║ So every test below asserts BOTH — the 422 AND the untouched table.      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class SettingsUnknownKeyRejectionTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private function seedKnownGoodState(): void
    {
        Setting::query()->create(['key' => 'settings', 'value' => ['privacy_policy' => 'الأصل']]);
    }

    private function assertNothingWasWritten(): void
    {
        self::assertSame(1, DB::table('settings')->count(), 'A rejected request must not create a row.');
        self::assertSame(
            ['privacy_policy' => 'الأصل'],
            Setting::query()->find('settings')?->getAttribute('value'),
            'A rejected request must not modify the existing row.'
        );
    }

    /** LEVEL 1 — the arbitrary-public-publishing primitive this endpoint once was. */
    public function test_an_unknown_top_level_key_is_rejected_not_dropped(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();
        $this->seedKnownGoodState();

        $response = $this->postJson('/api/v1/settings', [
            'settings' => ['privacy_policy' => 'محاولة'],
            'internal_api_key' => 'SUPER-SECRET',
        ]);

        $response->assertStatus(422);
        self::assertStringContainsString(
            Rules::UNKNOWN_TOP_LEVEL,
            (string) $response->json('data.errors.settings.0')
        );
        self::assertStringContainsString('internal_api_key', (string) $response->json('data.errors.settings.0'));

        $this->assertNothingWasWritten();

        // And the value never reached the database in ANY column.
        self::assertSame(0, DB::table('settings')->where('key', 'internal_api_key')->count());
    }

    /** LEVEL 2 — a stray key INSIDE the settings object. */
    public function test_an_unknown_key_inside_settings_is_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();
        $this->seedKnownGoodState();

        $response = $this->postJson('/api/v1/settings', [
            'settings' => ['privacy_policy' => 'ok', 'admin_password_hint' => 'oops'],
        ]);

        $response->assertStatus(422);
        self::assertStringContainsString(
            Rules::UNKNOWN_IN_SETTINGS,
            (string) $response->json('data.errors.settings.0')
        );

        $this->assertNothingWasWritten();
    }

    /**
     * `platform_logo` WAS an allow-listed settings key. It is not one any more.
     *
     * The logo is a static asset — frontend/public/branding/logo.png — and that
     * folder is the single source of truth. The settings key was the last
     * remnant of a removed upload tab: it was the ONLY field in the application
     * that accepted a `data:` URI, and it was served to every anonymous visitor
     * by the public GET /settings. This pins the removal so the key cannot be
     * reintroduced silently by a future edit to SETTINGS_KEYS.
     */
    public function test_the_removed_platform_logo_key_is_now_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();
        $this->seedKnownGoodState();

        self::assertNotContains('platform_logo', Rules::SETTINGS_KEYS);

        $response = $this->postJson('/api/v1/settings', [
            'settings' => ['platform_logo' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg=='],
        ]);

        $response->assertStatus(422);
        self::assertStringContainsString(
            Rules::UNKNOWN_IN_SETTINGS,
            (string) $response->json('data.errors.settings.0')
        );

        $this->assertNothingWasWritten();
    }

    /** LEVEL 3a — a stray key inside a socialLinks element. */
    public function test_an_unknown_key_inside_a_social_link_is_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();
        $this->seedKnownGoodState();

        $response = $this->postJson('/api/v1/settings', [
            'socialLinks' => [
                ['platform' => 'Facebook', 'url' => '', 'enabled' => false, 'order' => 1, 'script' => '<script>'],
            ],
        ]);

        $response->assertStatus(422);

        /*
         * The error key is the literal string 'socialLinks.0' — a DOTTED key
         * in the errors map, not a nested path. `json('data.errors.…')` would
         * try to traverse it, so the map is read whole and indexed directly.
         */
        $errors = $response->json('data.errors');

        self::assertArrayHasKey('socialLinks.0', $errors);
        self::assertStringContainsString(Rules::UNKNOWN_IN_ENTRY, $errors['socialLinks.0'][0]);
        self::assertStringContainsString('script', $errors['socialLinks.0'][0]);

        $this->assertNothingWasWritten();
    }

    /** LEVEL 3b — a stray key inside a supportNumbers element. */
    public function test_an_unknown_key_inside_a_support_contact_is_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();
        $this->seedKnownGoodState();

        $response = $this->postJson('/api/v1/settings', [
            'supportNumbers' => [
                ['type' => 'رقم الدعم الأساسي', 'value' => '0100', 'enabled' => true, 'order' => 1, 'href' => 'x'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertNothingWasWritten();
    }

    /**
     * THE NEGATIVE CONTROL FOR THIS WHOLE FILE.
     *
     * Every test above asserts a rejection. If the allow-list were
     * accidentally EMPTY — or the check inverted — they would all still pass
     * while no legitimate payload could ever be saved. This states the other
     * half: the six allow-listed settings keys and the full entry shapes are
     * ACCEPTED, so the rejections above are discrimination and not a blanket
     * refusal.
     */
    public function test_every_allow_listed_key_is_accepted(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $payload = [
            'settings' => [
                'social_section_enabled' => 'true',
                'privacy_section_enabled' => 'false',
                'terms_section_enabled' => 'true',
                'support_section_enabled' => 'false',
                'privacy_policy' => 'سياسة',
                'terms_conditions' => 'شروط',
            ],
            'socialLinks' => [
                ['platform' => 'Facebook', 'url' => 'https://facebook.com/x', 'enabled' => true, 'order' => 1],
            ],
            'supportNumbers' => [
                ['type' => 'أرقام واتساب', 'value' => '+201000000000', 'values' => ['0100'], 'enabled' => true, 'order' => 1],
            ],
        ];

        // The fixture must exercise EVERY allow-listed key, or the control is
        // weaker than it looks.
        self::assertSame(
            [],
            array_diff(Rules::SETTINGS_KEYS, array_keys($payload['settings'])),
            'The fixture must cover all six settings keys.'
        );
        self::assertSame([], array_diff(Rules::SOCIAL_LINK_KEYS, array_keys($payload['socialLinks'][0])));
        self::assertSame([], array_diff(Rules::SUPPORT_CONTACT_KEYS, array_keys($payload['supportNumbers'][0])));

        $this->postJson('/api/v1/settings', $payload)->assertOk();
    }

    /** An empty body is backend/'s own 'لا توجد إعدادات لتحديثها', not a 200. */
    public function test_an_empty_payload_is_rejected_with_the_legacy_message(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/settings', [])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.settings.0', Rules::EMPTY_PAYLOAD);
    }

    /** `settings` as a LIST, not a map — PHP cannot tell them apart, we must. */
    public function test_a_list_shaped_settings_object_is_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/settings', ['settings' => ['a', 'b']])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.settings.0', Rules::SETTINGS_NOT_AN_OBJECT);
    }

    /** …and the mirror image: socialLinks as a MAP rather than a list. */
    public function test_a_map_shaped_social_links_is_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/settings', [
            'socialLinks' => ['first' => ['platform' => 'Facebook', 'url' => '']],
        ])->assertStatus(422);
    }

    public function test_more_than_fifty_entries_are_rejected(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $links = [];

        for ($i = 0; $i < 51; $i++) {
            $links[] = ['platform' => "P{$i}", 'url' => '', 'enabled' => false, 'order' => $i];
        }

        $this->postJson('/api/v1/settings', ['socialLinks' => $links])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.socialLinks.0', Rules::TOO_MANY_ITEMS);
    }

    /** Only an admin may write. The read next door is anonymous; this is not. */
    public function test_a_guest_and_a_student_and_an_instructor_are_all_refused(): void
    {
        $this->disableRateLimiting();

        $this->postJson('/api/v1/settings', ['settings' => []])->assertStatus(401);

        $this->startNextRequest();
        $this->loginAs($this->makeStudentUser());
        $this->postJson('/api/v1/settings', ['settings' => []])->assertStatus(403);

        $this->startNextRequest();
        $this->loginAs($this->makeInstructor());
        $this->postJson('/api/v1/settings', ['settings' => []])->assertStatus(403);

        self::assertSame(0, DB::table('settings')->count());
    }
}
