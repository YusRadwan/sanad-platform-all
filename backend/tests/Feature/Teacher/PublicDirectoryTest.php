<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\UserRole;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/teachers — the public instructor directory.
 *
 * Freeze-table row 32: DEGRADE, no auth, "profile fields only — no email, no
 * phone".
 */
final class PublicDirectoryTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    public function test_it_is_reachable_without_a_session(): void
    {
        $this->makeTeacherWithProfile();

        // Not 401. CourseDetailsPage.tsx resolves a course's teacher to a
        // profile link for LOGGED-OUT visitors; gating this breaks the
        // storefront.
        $this->getJson('/api/v1/teachers')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.teachers');
    }

    // ══ THE QUERY-LEVEL EXCLUSION ═══════════════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THIS ASSERTS THE **SQL**, NOT THE JSON — AND THE DIFFERENCE IS THE   ║
     * ║ ENTIRE POINT OF THE TEST.                                            ║
     * ║                                                                      ║
     * ║ A response-shape assertion ("the payload has no `email` key") passes ║
     * ║ equally well for two very different implementations:                 ║
     * ║                                                                      ║
     * ║   (a) the query fetches email/phone and the Resource drops them —    ║
     * ║       one careless edit to PublicTeacher away from leaking on an     ║
     * ║       UNAUTHENTICATED endpoint;                                      ║
     * ║   (b) the query never selects them, so no presenter CAN emit them.   ║
     * ║                                                                      ║
     * ║ Only (b) is acceptable here, and only an assertion on the generated  ║
     * ║ SQL can tell the two apart.                                          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_generated_sql_never_selects_email_or_phone(): void
    {
        $sql = Teacher::query()->publiclyVisible()->toSql();

        $this->assertStringNotContainsString(
            'email',
            $sql,
            'scopePubliclyVisible must not fetch users.email — this endpoint is unauthenticated.'
        );
        $this->assertStringNotContainsString(
            'phone',
            $sql,
            'scopePubliclyVisible must not fetch teachers.phone.'
        );

        // The complement: it DOES select what the directory needs, so the
        // assertions above are not passing because the query selects nothing.
        $this->assertStringContainsString('full_name', $sql);
        $this->assertStringContainsString('specialization', $sql);
    }

    /**
     * Belt and braces: the hydrated MODELS do not carry the attributes either,
     * so even a `->toArray()` on one of them cannot leak.
     */
    public function test_the_hydrated_models_do_not_carry_the_excluded_attributes(): void
    {
        $this->makeTeacherWithProfile();

        $teacher = Teacher::query()->publiclyVisible()->first();

        $this->assertNotNull($teacher);
        $this->assertArrayNotHasKey('email', $teacher->getAttributes());
        $this->assertArrayNotHasKey('phone', $teacher->getAttributes());
    }

    public function test_the_response_carries_no_contact_details(): void
    {
        $this->makeTeacherWithProfile();

        $row = $this->getJson('/api/v1/teachers')->assertStatus(200)->json('data.teachers.0');

        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('phone', $row);
        $this->assertArrayHasKey('specialization', $row);
        $this->assertArrayHasKey('bio', $row);
    }

    // ══ WHO APPEARS, AND WHO MUST NOT ═══════════════════════════════════

    public function test_a_suspended_teacher_does_not_appear(): void
    {
        [$visible] = $this->makeTeacherWithProfile();
        [$suspended] = $this->makeTeacherWithProfile();

        $suspended->forceFill(['is_active' => false])->save();

        $ids = collect($this->getJson('/api/v1/teachers')->json('data.teachers'))->pluck('id');

        $this->assertContains($visible->getKey(), $ids);
        $this->assertNotContains($suspended->getKey(), $ids, 'users.is_active = 0 must hide a teacher.');
    }

    public function test_a_soft_deleted_teacher_does_not_appear(): void
    {
        [$visible] = $this->makeTeacherWithProfile();
        [$deleted, $deletedProfile] = $this->makeTeacherWithProfile();

        $deleted->delete();

        $ids = collect($this->getJson('/api/v1/teachers')->json('data.teachers'))->pluck('id');

        $this->assertContains($visible->getKey(), $ids);
        $this->assertNotContains($deleted->getKey(), $ids);

        // The `teachers` row survives the delete by design — so this exclusion
        // is the scope's `deleted_at IS NULL` doing real work, not an artefact
        // of the profile disappearing.
        $this->assertNotNull(Teacher::query()->find($deletedProfile->getKey()));
    }

    /**
     * A `teachers` row whose account was later demoted is not an instructor
     * any more, and must drop out of the instructor directory.
     */
    public function test_a_demoted_account_does_not_appear(): void
    {
        [$demoted] = $this->makeTeacherWithProfile();

        $demoted->forceFill(['role' => UserRole::Student])->save();

        $this->getJson('/api/v1/teachers')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.teachers');
    }

    public function test_it_carries_social_links_without_a_query_per_teacher(): void
    {
        [, $teacher] = $this->makeTeacherWithProfile();
        $teacher->socialLinks()->create([
            'platform' => 'youtube', 'url' => 'https://yt.example/x',
            'enabled' => true, 'order_index' => 0,
        ]);
        $this->makeTeacherWithProfile();
        $this->makeTeacherWithProfile();

        DB::enableQueryLog();
        $body = $this->getJson('/api/v1/teachers')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data.teachers')
            ->json('data.teachers');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Two: the directory, plus ONE eager-load for every teacher's links.
        // backend/ solved the same N+1 with a bulk `WHERE teacher_id IN (…)`.
        $selects = collect($queries)->filter(
            fn (array $q): bool => str_contains(strtolower($q['query']), 'teacher_social_links')
        );

        $this->assertCount(
            1,
            $selects,
            'Social links must be eager-loaded once for the whole directory, not once per teacher.'
        );

        // …and the one eager-load really did attach them: exactly one of the
        // three teachers carries the link, whatever order full_name puts them
        // in.
        $withLinks = collect($body)->filter(fn (array $t): bool => $t['socialLinks'] !== []);

        $this->assertCount(1, $withLinks);
        $this->assertSame('youtube', $withLinks->first()['socialLinks'][0]['platform']);
    }
}
