<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Models\EnrollmentCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /admin/enrollment-codes/search — freeze-table row 114 (open decision
 * 10.13). The admin review/filter screen behind FilterEnrollmentCodes.tsx.
 */
final class EnrollmentCodeSearchTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_no_filters_returns_every_code(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $this->makeEnrollmentCode($course);
        $this->makeEnrollmentCode($course);

        $this->loginAsAdmin();

        $rows = $this->getJson('/api/v1/admin/enrollment-codes/search')
            ->assertStatus(200)
            ->json('data.enrollmentCodes');

        self::assertCount(2, $rows);
    }

    public function test_the_code_filter_matches_a_partial_string(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $wanted = $this->makeEnrollmentCode($course, ['code' => '12345678']);
        $this->makeEnrollmentCode($course, ['code' => '99999999']);

        $this->loginAsAdmin();

        $codes = array_column(
            $this->getJson('/api/v1/admin/enrollment-codes/search?code=2345')
                ->assertStatus(200)
                ->json('data.enrollmentCodes'),
            'code'
        );

        self::assertSame([$wanted->code], $codes);
    }

    public function test_the_teacher_name_filter_matches_through_the_course(): void
    {
        $this->disableRateLimiting();
        [$wantedTeacher] = $this->makeTeacherWithProfile(user: ['full_name' => 'أ. محمد سامي']);
        [$otherTeacher] = $this->makeTeacherWithProfile(user: ['full_name' => 'أ. ليلى حسن']);
        $wantedCourse = $this->makeCourseFor($wantedTeacher);
        $otherCourse = $this->makeCourseFor($otherTeacher);
        $wanted = $this->makeEnrollmentCode($wantedCourse);
        $this->makeEnrollmentCode($otherCourse);

        $this->loginAsAdmin();

        $codes = array_column(
            $this->getJson('/api/v1/admin/enrollment-codes/search?teacher_name=' . urlencode('محمد'))
                ->assertStatus(200)
                ->json('data.enrollmentCodes'),
            'code'
        );

        self::assertSame([$wanted->code], $codes);
    }

    /** `teacher_id` is an EXACT match, unlike `teacher_name` (open decision 10.15). */
    public function test_the_teacher_id_filter_matches_exactly(): void
    {
        $this->disableRateLimiting();
        [$wantedTeacher] = $this->makeTeacherWithProfile();
        [$otherTeacher] = $this->makeTeacherWithProfile();
        $wantedCourse = $this->makeCourseFor($wantedTeacher);
        $otherCourse = $this->makeCourseFor($otherTeacher);
        $wanted = $this->makeEnrollmentCode($wantedCourse);
        $this->makeEnrollmentCode($otherCourse);

        $this->loginAsAdmin();

        $codes = array_column(
            $this->getJson('/api/v1/admin/enrollment-codes/search?teacher_id=' . $wantedTeacher->getKey())
                ->assertStatus(200)
                ->json('data.enrollmentCodes'),
            'code'
        );

        self::assertSame([$wanted->code], $codes);
    }

    public function test_the_student_name_phone_and_parent_phone_filters_only_match_redeemed_codes(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);

        [$student] = $this->makeStudentWithCode(profile: [
            'phone' => '01011122233',
            'parent_phone' => '01099988877',
        ]);
        $student->forceFill(['full_name' => 'طالب مجتهد'])->save();

        $redeemed = $this->makeEnrollmentCode($course, [
            'is_used' => true,
            'used_by_student_id' => $student->getKey(),
            'used_at' => now(),
        ]);
        $unredeemed = $this->makeEnrollmentCode($course);

        $this->loginAsAdmin();

        foreach ([
            'student_name=' . urlencode('مجتهد'),
            'student_phone=01011122233',
            'parent_phone=01099988877',
        ] as $query) {
            $codes = array_column(
                $this->getJson('/api/v1/admin/enrollment-codes/search?' . $query)
                    ->assertStatus(200)
                    ->json('data.enrollmentCodes'),
                'code'
            );

            self::assertSame([$redeemed->code], $codes, "Query \"{$query}\" did not isolate the redeemed code.");
            self::assertNotContains($unredeemed->code, $codes);
        }
    }

    public function test_filters_combine_with_and(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile(user: ['full_name' => 'أ. محمد سامي']);
        $course = $this->makeCourseFor($instructor);
        $wanted = $this->makeEnrollmentCode($course, ['code' => '11112222']);
        // Same teacher, different code — must be excluded once both filters apply.
        $this->makeEnrollmentCode($course, ['code' => '33334444']);

        $this->loginAsAdmin();

        $codes = array_column(
            $this->getJson(
                '/api/v1/admin/enrollment-codes/search?code=1111&teacher_name=' . urlencode('محمد')
            )->assertStatus(200)->json('data.enrollmentCodes'),
            'code'
        );

        self::assertSame([$wanted->code], $codes);
    }

    public function test_results_are_newest_first(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $older = $this->makeEnrollmentCode($course, ['created_at' => now()->subDay()]);
        $newer = $this->makeEnrollmentCode($course, ['created_at' => now()]);

        $this->loginAsAdmin();

        $codes = array_column(
            $this->getJson('/api/v1/admin/enrollment-codes/search')
                ->assertStatus(200)
                ->json('data.enrollmentCodes'),
            'code'
        );

        self::assertSame([$newer->code, $older->code], $codes);
    }

    public function test_only_an_admin_may_search(): void
    {
        $this->disableRateLimiting();
        $this->loginAs($this->makeInstructor());

        $this->getJson('/api/v1/admin/enrollment-codes/search')->assertStatus(403);

        $this->postJson('/api/v1/auth/logout');

        $this->getJson('/api/v1/admin/enrollment-codes/search')->assertStatus(401);
    }

    public function test_search_changes_nothing(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $this->makeEnrollmentCode($course);

        $before = EnrollmentCode::query()->get()->toArray();

        $this->loginAsAdmin();
        $this->getJson('/api/v1/admin/enrollment-codes/search')->assertStatus(200);

        self::assertEquals($before, EnrollmentCode::query()->get()->toArray());
    }
}
