<?php

declare(strict_types=1);

namespace Tests\Feature\Revenue;

use App\Http\Requests\Revenue\UpdateTeacherPercentageRequest;
use App\Models\TeacherProfitSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * PUT /revenue/teachers/{teacherId}/percentage — freeze-table row 67.
 * ProfitEngine.tsx's only write.
 */
final class TeacherPercentageUpdateTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ SNAPSHOT IMMUTABILITY, PROVEN AT THE **HTTP** LAYER.                 ║
     * ║                                                                      ║
     * ║ Session A already pinned this at the Action layer                    ║
     * ║ (RevenueRecordingTest::                                              ║
     * ║ test_changing_the_percentage_afterwards_never_touches_a_recorded_    ║
     * ║ row). It is re-proven here, and that is NOT redundant: THIS ROUTE is ║
     * ║ the actual mutation an admin performs. A unit-level guarantee about  ║
     * ║ a method nobody reaches from outside does not cover a controller     ║
     * ║ that might, one day, "helpfully" re-run the split over historical    ║
     * ║ rows after saving the new rate.                                      ║
     * ║                                                                      ║
     * ║ 60% -> 40% over 1000.00 is chosen so a retroactive rewrite would     ║
     * ║ show up as 400.00 where 600.00 belongs — a plainly different number, ║
     * ║ not a rounding artefact.                                             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_updating_the_percentage_over_http_leaves_recorded_rows_untouched(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->makeProfitSetting($instructor, 60);
        $course = $this->makeCourseFor($instructor, ['price' => 1000]);
        [$student] = $this->makeStudentWithCode();

        $sale = $this->recordRevenue($student, $course, 1000.00);
        self::assertSame('600.00', (string) $sale->teacher_earnings);
        self::assertSame('60.00', (string) $sale->teacher_profit_percentage);

        $this->loginAsAdmin();

        $this->putJson('/api/v1/revenue/teachers/' . $instructor->getKey() . '/percentage', [
            'percentage' => 40,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث نسبة الأرباح')
            ->assertJsonPath('data.result.teacher_id', (string) $instructor->getKey())
            ->assertJsonPath('data.result.profit_percentage', 40);

        // THE HISTORICAL ROW IS BYTE-FOR-BYTE WHAT IT WAS.
        $reloaded = $sale->fresh();
        self::assertSame('600.00', (string) $reloaded?->teacher_earnings);
        self::assertSame('60.00', (string) $reloaded?->teacher_profit_percentage);
        self::assertSame('1000.00', (string) $reloaded?->gross_amount);

        // The setting itself DID move — the next sale pays 40%.
        self::assertSame(
            '40.00',
            (string) TeacherProfitSetting::query()->whereKey($instructor->getKey())->value('profit_percentage')
        );

        // And the statement reflects BOTH facts at once: the new forward rate
        // over the old, frozen earnings.
        $statement = $this->getJson('/api/v1/revenue/statement/' . $instructor->getKey())
            ->json('data.statement');
        self::assertSame(40.0, (float) $statement['profit_percentage']);
        self::assertSame(600.0, (float) $statement['total_earnings']);
    }

    /** The NEXT sale after the change uses the NEW rate. The other half. */
    public function test_the_next_sale_after_the_change_uses_the_new_rate(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        $this->loginAsAdmin();
        $this->putJson('/api/v1/revenue/teachers/' . $instructor->getKey() . '/percentage', [
            'percentage' => 25,
        ])->assertOk();

        $sale = $this->recordRevenue($student, $course, 100.00);

        self::assertSame('25.00', (string) $sale->teacher_profit_percentage);
        self::assertSame('25.00', (string) $sale->teacher_earnings);
    }

    /**
     * `updateOrCreate` — THE ROW MAY NOT EXIST YET. A teacher has none until
     * this endpoint is first called for them; the 70% default is implicit. A
     * plain `->update()` would answer 200 and change nothing, forever, for
     * every teacher who has never been edited.
     */
    public function test_the_first_call_for_a_teacher_creates_the_row(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();

        self::assertSame(0, TeacherProfitSetting::query()->count());

        $this->loginAsAdmin();
        $this->putJson('/api/v1/revenue/teachers/' . $instructor->getKey() . '/percentage', [
            'percentage' => 55.5,
        ])->assertOk();

        self::assertSame(1, TeacherProfitSetting::query()->count());
        self::assertSame(
            '55.50',
            (string) TeacherProfitSetting::query()->whereKey($instructor->getKey())->value('profit_percentage')
        );
    }

    /** A second call UPDATES rather than duplicating — teacher_id is the PK. */
    public function test_a_second_call_updates_the_same_row(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $url = '/api/v1/revenue/teachers/' . $instructor->getKey() . '/percentage';

        $this->loginAsAdmin();
        $this->putJson($url, ['percentage' => 10])->assertOk();
        $this->putJson($url, ['percentage' => 90])->assertOk();

        self::assertSame(1, TeacherProfitSetting::query()->count());
        self::assertSame(
            '90.00',
            (string) TeacherProfitSetting::query()->whereKey($instructor->getKey())->value('profit_percentage')
        );
    }

    /** BOTH BOUNDS ARE INCLUSIVE, matching backend/'s `< 0 || > 100`. */
    public function test_zero_and_one_hundred_are_both_accepted(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $url = '/api/v1/revenue/teachers/' . $instructor->getKey() . '/percentage';

        $this->loginAsAdmin();

        $this->putJson($url, ['percentage' => 0])->assertOk();
        self::assertSame(
            '0.00',
            (string) TeacherProfitSetting::query()->whereKey($instructor->getKey())->value('profit_percentage')
        );

        $this->putJson($url, ['percentage' => 100])->assertOk();
        self::assertSame(
            '100.00',
            (string) TeacherProfitSetting::query()->whereKey($instructor->getKey())->value('profit_percentage')
        );
    }

    public function test_an_out_of_range_percentage_is_refused_with_the_pinned_string(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $url = '/api/v1/revenue/teachers/' . $instructor->getKey() . '/percentage';

        $this->loginAsAdmin();

        foreach ([-0.01, -50, 100.01, 250] as $bad) {
            $this->putJson($url, ['percentage' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('data.errors.percentage.0', UpdateTeacherPercentageRequest::OUT_OF_RANGE);
        }

        self::assertSame(0, TeacherProfitSetting::query()->count(), 'Not one row was written.');
    }

    /** A missing or non-numeric percentage is the SAME complaint. Was a 500. */
    public function test_a_missing_or_non_numeric_percentage_carries_the_same_string(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $url = '/api/v1/revenue/teachers/' . $instructor->getKey() . '/percentage';

        $this->loginAsAdmin();

        foreach ([[], ['percentage' => 'نصف']] as $body) {
            $this->putJson($url, $body)
                ->assertStatus(422)
                ->assertJsonPath('data.errors.percentage.0', UpdateTeacherPercentageRequest::OUT_OF_RANGE);
        }
    }

    /**
     * ⚠ A 500 IN backend/ IS NOW A 422 — an unknown id in the PATH reached the
     * INSERT and died on the FK. The route segment is lifted into the
     * validated payload by prepareForValidation() so one `exists:` covers it.
     */
    public function test_an_unknown_teacher_in_the_path_is_a_422_with_a_real_arabic_message(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->putJson('/api/v1/revenue/teachers/' . Str::uuid() . '/percentage', ['percentage' => 50])
            ->assertStatus(422)
            // NOT the raw 'validation.exists' key — see the constant's banner.
            ->assertJsonPath('data.errors.teacherId.0', UpdateTeacherPercentageRequest::TEACHER_NOT_FOUND);

        self::assertSame(0, TeacherProfitSetting::query()->count());
    }

    /** A real account with no `teachers` row is refused for the same reason. */
    public function test_a_real_user_who_is_not_a_teacher_is_refused(): void
    {
        $this->disableRateLimiting();
        [$student] = $this->makeStudentWithCode();
        $this->loginAsAdmin();

        $this->putJson('/api/v1/revenue/teachers/' . $student->getKey() . '/percentage', ['percentage' => 50])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.teacherId.0', UpdateTeacherPercentageRequest::TEACHER_NOT_FOUND);
    }

    /**
     * THE ECHOED VALUE IS THE SUBMITTED ONE, NOT A RE-READ. Re-reading would
     * return the DECIMAL(5,2) rounding of the input and make the field appear
     * to change under the user's cursor.
     */
    public function test_the_response_echoes_the_submitted_value(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        $this->putJson('/api/v1/revenue/teachers/' . $instructor->getKey() . '/percentage', [
            'percentage' => 33.333,
        ])
            ->assertOk()
            ->assertJsonPath('data.result.profit_percentage', 33.333);

        // …while the COLUMN holds the rounded value, as it must.
        self::assertSame(
            '33.33',
            (string) TeacherProfitSetting::query()->whereKey($instructor->getKey())->value('profit_percentage')
        );
    }
}
