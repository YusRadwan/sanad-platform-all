<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * The two admin READ routes — freeze-table rows 58 and 59.
 *
 * Both are read-only: nothing in this file may change `is_used`, and the last
 * test asserts exactly that over the whole table.
 */
final class EnrollmentCodeListingTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_the_listing_shows_the_course_teacher_and_redeemer(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile(user: ['full_name' => 'أ. ليلى']);
        $course = $this->makeCourseFor($instructor, ['title' => 'كورس الكيمياء']);
        [$student] = $this->makeStudentWithCode();
        $student->forceFill(['full_name' => 'طالب مجتهد'])->save();

        $unused = $this->makeEnrollmentCode($course);
        $used = $this->makeEnrollmentCode($course, [
            'is_used' => true,
            'used_by_student_id' => $student->getKey(),
            'used_at' => now(),
        ]);

        $this->loginAsAdmin();

        $rows = collect(
            $this->getJson('/api/v1/admin/enrollment-codes')->assertStatus(200)->json('data.enrollmentCodes')
        )->keyBy('code');

        self::assertCount(2, $rows);

        self::assertFalse($rows[$unused->code]['is_used']);
        self::assertNull($rows[$unused->code]['used_by_name']);
        self::assertNull($rows[$unused->code]['used_at']);
        self::assertSame('كورس الكيمياء', $rows[$unused->code]['course_title']);
        self::assertSame('أ. ليلى', $rows[$unused->code]['teacher_name']);

        self::assertTrue($rows[$used->code]['is_used']);
        self::assertSame('طالب مجتهد', $rows[$used->code]['used_by_name']);
        self::assertNotNull($rows[$used->code]['used_at']);
    }

    public function test_the_course_id_filter_narrows_the_listing(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $wanted = $this->makeCourseFor($instructor);
        $other = $this->makeCourseFor($instructor);

        $mine = $this->makeEnrollmentCode($wanted);
        $this->makeEnrollmentCode($other);

        $this->loginAsAdmin();

        $codes = array_column(
            $this->getJson('/api/v1/admin/enrollment-codes?course_id=' . $wanted->getKey())
                ->json('data.enrollmentCodes'),
            'code'
        );

        self::assertSame([$mine->code], $codes);

        // A blank filter is inert, not a filter matching nothing.
        self::assertCount(2, $this->getJson('/api/v1/admin/enrollment-codes?course_id=')
            ->json('data.enrollmentCodes'));
    }

    public function test_the_detail_route_returns_the_full_nested_shape(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile(user: ['full_name' => 'أ. ليلى']);
        $course = $this->makeCourseFor($instructor, [
            'title' => 'كورس الأحياء',
            'price' => 300,
            'discount_price' => 200,
            'is_published' => true,
        ]);
        [$student] = $this->makeStudentWithCode('STU777777');
        $admin = $this->loginAsAdmin();

        $code = $this->makeEnrollmentCode($course, [
            'created_by' => $admin->getKey(),
            'is_used' => true,
            'used_by_student_id' => $student->getKey(),
            'used_at' => now(),
        ]);

        $payload = $this->getJson('/api/v1/admin/enrollment-codes/' . $code->code)
            ->assertStatus(200)
            ->json('data.enrollmentCode');

        self::assertSame($code->code, $payload['code']);
        self::assertTrue($payload['is_used']);
        self::assertSame('كورس الأحياء', $payload['course']['title']);
        self::assertSame(300.0, (float) $payload['course']['price']);
        self::assertSame(200.0, (float) $payload['course']['discount_price']);
        self::assertTrue($payload['course']['is_published']);
        self::assertFalse($payload['course']['is_deleted']);
        self::assertSame('أ. ليلى', $payload['course']['teacher_name']);
        self::assertSame((string) $admin->getKey(), $payload['created_by']['id']);
        self::assertSame('STU777777', $payload['student']['student_code']);
    }

    /**
     * `student` IS NULL FOR AN UNREDEEMED CODE — not an object of nulls. The
     * frontend uses the block's PRESENCE, not a separate flag, to decide
     * whether to render the redeemer panel.
     */
    public function test_an_unredeemed_code_reports_a_null_student_block(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $code = $this->makeEnrollmentCode($course);

        $this->loginAsAdmin();

        $payload = $this->getJson('/api/v1/admin/enrollment-codes/' . $code->code)
            ->json('data.enrollmentCode');

        self::assertNull($payload['student']);
        self::assertFalse($payload['is_used']);
        self::assertNull($payload['used_at']);
    }

    /**
     * A SOFT-DELETED COURSE STILL RESOLVES ON THIS ROUTE, flagged
     * `is_deleted: true`. This is the ONE place in the session that reads a
     * trashed course on purpose — it is a forensic lookup, and telling the
     * admin "the course is gone" beats rendering a code bound to nothing.
     */
    public function test_a_code_whose_course_was_deleted_still_resolves_and_says_so(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $code = $this->makeEnrollmentCode($course);
        $course->delete();

        $this->loginAsAdmin();

        $payload = $this->getJson('/api/v1/admin/enrollment-codes/' . $code->code)
            ->assertStatus(200)
            ->json('data.enrollmentCode');

        self::assertTrue($payload['course']['is_deleted']);
        self::assertSame((string) $course->getKey(), $payload['course']['id']);
    }

    public function test_a_well_formed_unknown_code_is_404_and_a_malformed_one_is_422(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->getJson('/api/v1/admin/enrollment-codes/99999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'لا يوجد كود اشتراك بهذا الرقم');

        foreach (['banana', '1234567', '123456789'] as $bad) {
            $this->getJson('/api/v1/admin/enrollment-codes/' . $bad)
                ->assertStatus(422)
                ->assertJsonPath('data.errors.code.0', 'الكود يجب أن يتكون من 8 أرقام');
        }
    }

    /** Neither read route mutates anything. Asserted over the whole table. */
    public function test_both_read_routes_change_nothing(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $code = $this->makeEnrollmentCode($course);

        $before = \App\Models\EnrollmentCode::query()->get()->toArray();

        $this->loginAsAdmin();
        $this->getJson('/api/v1/admin/enrollment-codes')->assertStatus(200);
        $this->getJson('/api/v1/admin/enrollment-codes/' . $code->code)->assertStatus(200);

        self::assertEquals($before, \App\Models\EnrollmentCode::query()->get()->toArray());
    }
}
