<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\TeacherSocialLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE MOST IMPORTANT TEST FILE IN STAGE 4.                                ║
 * ║                                                                          ║
 * ║ App\Actions\Teacher\UpdateTeacher implements THREE DIFFERENT             ║
 * ║ absent/empty/real-value semantics across its fields. A regression here   ║
 * ║ does not throw, does not 500 and does not fail any other test — it       ║
 * ║ returns 200 and silently writes (or silently fails to write) the wrong   ║
 * ║ column. It has already corrupted production data once: a bare            ║
 * ║ array_filter() whose default callback discards '' meant a teacher's      ║
 * ║ avatar could be REPLACED but never REMOVED, for months, while            ║
 * ║ banner_url and profile_photo_url cleared correctly.                      ║
 * ║                                                                          ║
 * ║ Every cell of the matrix in UpdateTeacher's docblock has an assertion    ║
 * ║ below. Do not delete one because it "obviously" duplicates another: the  ║
 * ║ whole point is that fields which look alike behave differently.          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class TeacherUpdateSemanticsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $teacherUser;

    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        [$this->teacherUser, $this->teacher] = $this->makeTeacherWithProfile([], [
            'full_name' => 'الاسم الأصلي',
            'email' => 'original@sanad.edu',
            'avatar_url' => 'https://cdn.example/avatar.png',
        ]);

        $this->loginAsAdmin();
    }

    /** PATCH the teacher under test and assert 200. */
    private function patchTeacher(array $payload): void
    {
        $this->patchJson("/api/v1/admin/teachers/{$this->teacher->getKey()}", $payload)
            ->assertStatus(200);
    }

    private function freshTeacher(): Teacher
    {
        return Teacher::query()->findOrFail($this->teacher->getKey());
    }

    private function freshUser(): User
    {
        return User::query()->findOrFail($this->teacherUser->getKey());
    }

    // ══ ROW 1-2: fullName and email — '' IS IGNORED ══════════════════════

    /**
     * The half of the matrix that a blanket validated()+fill() would break.
     * An empty name or email is not a state an admin can ask for, so '' must
     * read as "the form submitted its untouched empty box".
     */
    public function test_fullName_absent_leaves_the_name_untouched(): void
    {
        $this->patchTeacher(['bio' => 'نص جديد']);

        $this->assertSame('الاسم الأصلي', $this->freshUser()->full_name);
    }

    public function test_fullName_empty_string_leaves_the_name_untouched(): void
    {
        $this->patchTeacher(['fullName' => '']);

        $this->assertSame(
            'الاسم الأصلي',
            $this->freshUser()->full_name,
            "'' must NOT clear fullName — an unnamed teacher is not a requestable state."
        );
    }

    public function test_fullName_real_value_updates_the_name(): void
    {
        $this->patchTeacher(['fullName' => 'الاسم الجديد']);

        $this->assertSame('الاسم الجديد', $this->freshUser()->full_name);
    }

    public function test_email_absent_leaves_the_email_untouched(): void
    {
        $this->patchTeacher(['bio' => 'نص']);

        $this->assertSame('original@sanad.edu', $this->freshUser()->email);
    }

    public function test_email_empty_string_leaves_the_email_untouched(): void
    {
        $this->patchTeacher(['email' => '']);

        $this->assertSame(
            'original@sanad.edu',
            $this->freshUser()->email,
            "'' must NOT clear the login email."
        );
    }

    public function test_email_real_value_updates_the_email(): void
    {
        $this->patchTeacher(['email' => 'new@sanad.edu']);

        $this->assertSame('new@sanad.edu', $this->freshUser()->email);
    }

    // ══ ROW 3: avatarUrl — '' CLEARS. THE HISTORICAL BUG. ════════════════

    public function test_avatarUrl_absent_leaves_the_avatar_untouched(): void
    {
        $this->patchTeacher(['bio' => 'نص']);

        $this->assertSame('https://cdn.example/avatar.png', $this->freshUser()->avatar_url);
    }

    /**
     * THE REGRESSION THAT ACTUALLY HAPPENED. '' is exactly what AddTeacher.tsx
     * sends when the admin presses "حذف وتصغير" to remove the card photo.
     */
    public function test_avatarUrl_empty_string_CLEARS_the_avatar(): void
    {
        $this->patchTeacher(['avatarUrl' => '']);

        $this->assertSame(
            '',
            $this->freshUser()->avatar_url,
            "'' MUST clear avatar_url — this is the 'remove photo' action, not a no-op."
        );
    }

    public function test_avatarUrl_real_value_updates_the_avatar(): void
    {
        $this->patchTeacher(['avatarUrl' => 'https://cdn.example/new.png']);

        $this->assertSame('https://cdn.example/new.png', $this->freshUser()->avatar_url);
    }

    // ══ ROWS 4-8: the clearable profile columns — '' CLEARS ══════════════

    /**
     * @dataProvider clearableProfileFields
     */
    public function test_clearable_profile_field_absent_is_untouched(string $wire, string $column, string $original): void
    {
        $this->patchTeacher(['fullName' => 'اسم']);

        $this->assertSame($original, $this->freshTeacher()->getAttribute($column));
    }

    /**
     * @dataProvider clearableProfileFields
     */
    public function test_clearable_profile_field_empty_string_CLEARS_it(string $wire, string $column): void
    {
        $this->patchTeacher([$wire => '']);

        $this->assertSame(
            '',
            $this->freshTeacher()->getAttribute($column),
            "{$wire}: '' must CLEAR {$column}, unlike fullName/email."
        );
    }

    /**
     * @dataProvider clearableProfileFields
     */
    public function test_clearable_profile_field_real_value_updates_it(string $wire, string $column): void
    {
        $this->patchTeacher([$wire => 'قيمة-جديدة']);

        $this->assertSame('قيمة-جديدة', $this->freshTeacher()->getAttribute($column));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function clearableProfileFields(): array
    {
        return [
            'phone' => ['phone', 'phone', '01011112222'],
            'specialization' => ['specialization', 'specialization', 'الرياضيات'],
            'bio' => ['bio', 'bio', 'نبذة'],
            'bannerUrl' => ['bannerUrl', 'banner_url', 'https://cdn.example/banner.png'],
            'profilePhotoUrl' => ['profilePhotoUrl', 'profile_photo_url', 'https://cdn.example/photo.png'],
        ];
    }

    // ══ ROW 9: subjects / grades — [] CLEARS ALL ═════════════════════════

    public function test_subjects_absent_leaves_the_list_untouched(): void
    {
        $this->patchTeacher(['bio' => 'نص']);

        $this->assertSame(['رياضيات'], $this->freshTeacher()->subjects);
    }

    public function test_subjects_empty_array_clears_the_list(): void
    {
        $this->patchTeacher(['subjects' => []]);

        $this->assertSame([], $this->freshTeacher()->subjects);
    }

    public function test_subjects_real_value_replaces_the_list(): void
    {
        $this->patchTeacher(['subjects' => ['فيزياء', 'كيمياء']]);

        $this->assertSame(['فيزياء', 'كيمياء'], $this->freshTeacher()->subjects);
    }

    public function test_grades_absent_leaves_the_list_untouched(): void
    {
        $this->patchTeacher(['bio' => 'نص']);

        $this->assertSame(['الصف الأول الثانوي'], $this->freshTeacher()->grades);
    }

    public function test_grades_empty_array_clears_the_list(): void
    {
        $this->patchTeacher(['grades' => []]);

        $this->assertSame([], $this->freshTeacher()->grades);
    }

    public function test_grades_real_value_replaces_the_list(): void
    {
        $this->patchTeacher(['grades' => ['الصف الثالث الثانوي']]);

        $this->assertSame(['الصف الثالث الثانوي'], $this->freshTeacher()->grades);
    }

    // ══ ROW 10: socialLinks — [] DELETES ALL ═════════════════════════════

    private function seedSocialLinks(): void
    {
        $this->teacher->socialLinks()->createMany([
            ['platform' => 'facebook', 'url' => 'https://fb.example/x', 'enabled' => true, 'order_index' => 0],
            ['platform' => 'youtube', 'url' => 'https://yt.example/x', 'enabled' => true, 'order_index' => 1],
        ]);
    }

    public function test_socialLinks_absent_leaves_existing_links_alone(): void
    {
        $this->seedSocialLinks();

        $this->patchTeacher(['bio' => 'نص']);

        $this->assertSame(2, TeacherSocialLink::query()->count());
    }

    /**
     * has(), not filled(). filled() reports [] as empty, which would turn
     * "delete all my links" into a silent no-op — the admin switches every
     * platform off, gets 200, and the icons stay on the public profile.
     */
    public function test_socialLinks_empty_array_DELETES_every_link(): void
    {
        $this->seedSocialLinks();

        $this->patchTeacher(['socialLinks' => []]);

        $this->assertSame(
            0,
            TeacherSocialLink::query()->count(),
            'An empty array is the "remove every link" instruction, not an absent field.'
        );
    }

    public function test_socialLinks_real_value_replaces_the_whole_set(): void
    {
        $this->seedSocialLinks();

        $this->patchTeacher(['socialLinks' => [
            ['platform' => 'twitter', 'url' => 'https://tw.example/x'],
        ]]);

        $links = TeacherSocialLink::query()->get();

        $this->assertCount(1, $links);
        $this->assertSame('twitter', $links->first()->platform);
    }

    // ══ ROW 11: isActive — PRESENCE, NOT TRUTHINESS ══════════════════════

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE SINGLE MOST DANGEROUS CELL OF THE MATRIX.                        │
     * │                                                                      │
     * │ $request->boolean('isActive') returns FALSE for an ABSENT key. If    │
     * │ the action tested truthiness instead of presence, THIS request — a   │
     * │ bio edit that never mentions isActive — would suspend the teacher    │
     * │ and lock them out of the platform, returning a cheerful 200.         │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_isActive_absent_does_NOT_suspend_the_account(): void
    {
        $this->patchTeacher(['bio' => 'تعديل لا علاقة له بالتفعيل']);

        $this->assertTrue(
            (bool) $this->freshUser()->is_active,
            'An update that never mentions isActive must not touch users.is_active.'
        );
    }

    /** `false` is a REAL value here, not "absent". This is the suspend direction. */
    public function test_isActive_false_suspends_the_account(): void
    {
        $this->patchTeacher(['isActive' => false]);

        $this->assertFalse((bool) $this->freshUser()->is_active);
    }

    public function test_isActive_true_reactivates_a_suspended_account(): void
    {
        $this->teacherUser->forceFill(['is_active' => false])->save();

        $this->patchTeacher(['isActive' => true]);

        $this->assertTrue((bool) $this->freshUser()->is_active);
    }

    /**
     * The wire may carry "0"/"false" rather than a JSON false — a raw string
     * cast would be TRUTHY and reactivate the account the admin is disabling.
     */
    public function test_isActive_string_zero_suspends_the_account(): void
    {
        $this->patchTeacher(['isActive' => '0']);

        $this->assertFalse((bool) $this->freshUser()->is_active);
    }

    /** Suspension is written to users.is_active, NOT to teachers.is_active. */
    public function test_isActive_writes_the_users_column_not_the_teachers_column(): void
    {
        $this->patchTeacher(['isActive' => false]);

        $this->assertFalse((bool) $this->freshUser()->is_active);
        $this->assertTrue(
            (bool) $this->freshTeacher()->is_active,
            'teachers.is_active is legacy and unwritten; EnsureUserIsActive reads users.is_active.'
        );
    }

    // ══ ROW 12: password — '' IGNORED, real value resets ═════════════════

    public function test_password_absent_leaves_the_hash_untouched(): void
    {
        $before = $this->freshUser()->getAuthPassword();

        $this->patchTeacher(['bio' => 'نص']);

        $this->assertSame($before, $this->freshUser()->getAuthPassword());
    }

    public function test_password_empty_string_leaves_the_hash_untouched(): void
    {
        $before = $this->freshUser()->getAuthPassword();

        $this->patchTeacher(['password' => '']);

        $this->assertSame(
            $before,
            $this->freshUser()->getAuthPassword(),
            "'' must be a no-op, not a reset and not a 422."
        );
    }

    public function test_password_real_value_resets_the_hash(): void
    {
        $this->patchTeacher(['password' => 'BrandNewPass2026']);

        $this->assertTrue(Hash::check('BrandNewPass2026', $this->freshUser()->getAuthPassword()));
    }

    // ══ EXPLICIT null — backend/'s isset()/?? behaviour, preserved ════════

    /**
     * backend/ reached these fields through `$data['x'] ?? null` + a
     * `!== null` filter (and `isset()` for avatarUrl), both of which are FALSE
     * for an explicit null. So `"phone": null` left the column alone, exactly
     * like omitting the key. has() alone would NOT preserve that.
     */
    public function test_explicit_null_leaves_a_clearable_field_untouched(): void
    {
        $this->patchTeacher(['phone' => null]);

        $this->assertSame(
            '01011112222',
            $this->freshTeacher()->phone,
            'An explicit null must behave like an absent key, as it did in backend/.'
        );
    }

    public function test_explicit_null_leaves_the_avatar_untouched(): void
    {
        $this->patchTeacher(['avatarUrl' => null]);

        $this->assertSame('https://cdn.example/avatar.png', $this->freshUser()->avatar_url);
    }

    // ══ CROSS-FIELD: one request, several different semantics ════════════

    /**
     * The integration case the matrix exists for: a single realistic payload
     * in which '' means three different things at once.
     */
    public function test_one_payload_applies_three_different_empty_string_rules(): void
    {
        $this->patchTeacher([
            'fullName' => '',          // ignored
            'email' => '',             // ignored
            'avatarUrl' => '',         // CLEARS
            'bio' => '',               // CLEARS
            'subjects' => [],          // clears the list
            'socialLinks' => [],       // deletes all links
        ]);

        $user = $this->freshUser();
        $teacher = $this->freshTeacher();

        $this->assertSame('الاسم الأصلي', $user->full_name, 'fullName: ignored');
        $this->assertSame('original@sanad.edu', $user->email, 'email: ignored');
        $this->assertSame('', $user->avatar_url, 'avatarUrl: cleared');
        $this->assertSame('', $teacher->bio, 'bio: cleared');
        $this->assertSame([], $teacher->subjects, 'subjects: cleared');
        $this->assertSame(0, TeacherSocialLink::query()->count(), 'socialLinks: deleted');
    }

    /** An entirely empty payload must change nothing at all and still be 200. */
    public function test_an_empty_payload_changes_nothing(): void
    {
        $before = $this->freshUser()->getAttributes();
        $beforeProfile = $this->freshTeacher()->getAttributes();

        $this->patchTeacher([]);

        $this->assertSame($before['full_name'], $this->freshUser()->full_name);
        $this->assertSame($before['email'], $this->freshUser()->email);
        $this->assertSame($before['avatar_url'], $this->freshUser()->avatar_url);
        $this->assertSame($beforeProfile['phone'], $this->freshTeacher()->phone);
        $this->assertSame($beforeProfile['bio'], $this->freshTeacher()->bio);
        $this->assertTrue((bool) $this->freshUser()->is_active);
    }

    // ══ PUT AND PATCH ARE THE SAME ACTION ════════════════════════════════

    /**
     * backend/ registered both verbs against one handler because
     * ManageTeachers.tsx uses PUT for its تفعيل/إيقاف toggle and PATCH for the
     * general edit form. Dropping either breaks a live flow.
     */
    public function test_put_behaves_identically_to_patch(): void
    {
        $this->putJson("/api/v1/admin/teachers/{$this->teacher->getKey()}", [
            'fullName' => '',
            'bio' => '',
            'isActive' => false,
        ])->assertStatus(200);

        $this->assertSame('الاسم الأصلي', $this->freshUser()->full_name);
        $this->assertSame('', $this->freshTeacher()->bio);
        $this->assertFalse((bool) $this->freshUser()->is_active);
    }
}
