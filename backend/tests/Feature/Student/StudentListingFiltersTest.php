<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/admin/students — the five optional filters, the `?all=1`
 * bypass, and the new `educationType` field (open decision 10.17). The base
 * listing/pagination behaviour is unchanged and untested here — see
 * StudentCrudTest for everything else this endpoint does.
 */
final class StudentListingFiltersTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_no_filters_returns_every_student(): void
    {
        $this->disableRateLimiting();
        $this->makeStudentWithCode();
        $this->makeStudentWithCode();

        $this->loginAsAdmin();

        $this->getJson('/api/v1/admin/students')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_the_student_code_filter_matches_a_partial_string(): void
    {
        $this->disableRateLimiting();
        $this->makeStudentWithCode('STU123456');
        $this->makeStudentWithCode('STU999999');

        $this->loginAsAdmin();

        $codes = array_column(
            $this->getJson('/api/v1/admin/students?student_code=1234')
                ->assertStatus(200)
                ->json('data.items'),
            'studentCode'
        );

        self::assertSame(['STU123456'], $codes);
    }

    public function test_the_name_filter_matches_through_the_joined_user(): void
    {
        $this->disableRateLimiting();
        [$wanted] = $this->makeStudentWithCode();
        $wanted->forceFill(['full_name' => 'محمد الطالب المجتهد'])->save();
        [$other] = $this->makeStudentWithCode();
        $other->forceFill(['full_name' => 'أحمد آخر'])->save();

        $this->loginAsAdmin();

        $names = array_column(
            $this->getJson('/api/v1/admin/students?name=' . urlencode('مجتهد'))
                ->assertStatus(200)
                ->json('data.items'),
            'name'
        );

        self::assertSame(['محمد الطالب المجتهد'], $names);
    }

    public function test_the_phone_parent_phone_and_governorate_filters(): void
    {
        $this->disableRateLimiting();
        $this->makeStudentWithCode('STU111111', [
            'phone' => '01011112222',
            'parent_phone' => '01099998888',
            'governorate' => 'القاهرة',
        ]);
        $this->makeStudentWithCode('STU222222', [
            'phone' => '01033334444',
            'parent_phone' => '01077776666',
            'governorate' => 'الجيزة',
        ]);

        $this->loginAsAdmin();

        foreach ([
            'phone=01011112222' => 'STU111111',
            'parent_phone=01099998888' => 'STU111111',
            'governorate=' . urlencode('القاهرة') => 'STU111111',
        ] as $query => $expectedCode) {
            $codes = array_column(
                $this->getJson('/api/v1/admin/students?' . $query)
                    ->assertStatus(200)
                    ->json('data.items'),
                'studentCode'
            );

            self::assertSame([$expectedCode], $codes, "Query \"{$query}\" did not isolate the expected student.");
        }
    }

    public function test_filters_combine_with_and(): void
    {
        $this->disableRateLimiting();
        $this->makeStudentWithCode('STU333333', ['governorate' => 'القاهرة']);
        $this->makeStudentWithCode('STU444444', ['governorate' => 'الجيزة']);

        $this->loginAsAdmin();

        $codes = array_column(
            $this->getJson('/api/v1/admin/students?student_code=3333&governorate=' . urlencode('القاهرة'))
                ->assertStatus(200)
                ->json('data.items'),
            'studentCode'
        );

        self::assertSame(['STU333333'], $codes);
    }

    /** `?all=1` bypasses pagination — every student in one response, ignoring `per_page`. */
    public function test_all_bypasses_pagination(): void
    {
        $this->disableRateLimiting();
        for ($i = 0; $i < 3; $i++) {
            $this->makeStudentWithCode();
        }

        $this->loginAsAdmin();

        $this->getJson('/api/v1/admin/students?all=1&per_page=1')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data.items');
    }

    /** `educationType` is now on the shared StudentResource shape. */
    public function test_education_type_is_present_on_the_listing(): void
    {
        $this->disableRateLimiting();
        $this->makeStudentWithCode('STU555555', ['education_type' => 'عام']);

        $this->loginAsAdmin();

        $this->getJson('/api/v1/admin/students')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.educationType', 'عام');
    }
}
