<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\SubscriptionStatus;
use App\Models\RevenueTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/admin/subscriptions — freeze-table row 53.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE SHAPE IS THE CONTRACT, AND THE SHAPE IS WHERE THE HISTORIC BUG WAS. ║
 * ║                                                                          ║
 * ║ An earlier version of this endpoint emitted a SUBSCRIPTION id under      ║
 * ║ `student.id`. It rendered fine; what broke was the account-status button ║
 * ║ (which PATCHes /admin/students/{id}) and `student.subscriptions.map()`.  ║
 * ║ The first test below is the regression test that construction cannot     ║
 * ║ pass by accident.                                                        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class AdminSubscriptionsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_the_payload_groups_by_student_with_a_nested_subscriptions_array(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile(user: ['full_name' => 'أستاذ الرياضيات']);
        $first = $this->makeCourseFor($instructor, ['title' => 'كورس أ']);
        $second = $this->makeCourseFor($instructor, ['title' => 'كورس ب']);
        [$student] = $this->makeStudentWithCode('STU123456', [
            'phone' => '01111111111',
            'parent_phone' => '01222222222',
            'governorate' => 'الجيزة',
            'educational_stage' => 'الثانوية',
            'grade_level' => 'الصف الثاني الثانوي',
        ]);

        $this->makeSubscription($student, $first);
        $this->makeSubscription($student, $second, SubscriptionStatus::Cancelled);

        $this->loginAsAdmin();

        $response = $this->getJson('/api/v1/admin/subscriptions')->assertStatus(200);

        $rows = $response->json('data.subscriptions');
        self::assertCount(1, $rows, 'Two enrolments for one student are ONE row, not two.');

        $row = $rows[0];
        self::assertSame((string) $student->getKey(), $row['id']);
        self::assertSame((string) $student->getKey(), $row['student_id']);
        self::assertSame('STU123456', $row['studentCode']);
        self::assertSame('01111111111', $row['phone']);
        self::assertSame('01222222222', $row['parentPhone']);
        self::assertSame('الجيزة', $row['governorate']);
        self::assertSame('الثانوية', $row['educationalStage']);
        self::assertSame('الصف الثاني الثانوي', $row['gradeLevel']);
        self::assertSame('active', $row['status'], "Top-level status is the ACCOUNT's state.");
        self::assertCount(2, $row['subscriptions']);

        $titles = array_column($row['subscriptions'], 'courseName');
        sort($titles);
        self::assertSame(['كورس أ', 'كورس ب'], $titles);

        foreach ($row['subscriptions'] as $sub) {
            self::assertSame($sub['course_id'], $sub['courseId'], 'Both spellings ship.');
            self::assertSame('أستاذ الرياضيات', $sub['teacherName']);
            self::assertSame((string) $instructor->getKey(), $sub['teacherId']);
            self::assertSame($sub['status'] === 'active', $sub['isActive']);
            self::assertArrayHasKey('expires_at', $sub);
            self::assertArrayHasKey('subscribed_at', $sub);
            self::assertArrayHasKey('paymentMethod', $sub);
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE HISTORIC BUG, ASSERTED DIRECTLY: `id` IS THE STUDENT'S USER ID   ║
     * ║ AND IS NOT ANY SUBSCRIPTION'S ID.                                    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_top_level_id_is_the_student_never_a_subscription_id(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$student] = $this->makeStudentWithCode();
        $subscription = $this->makeSubscription($student, $course);

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/admin/subscriptions')->json('data.subscriptions.0');

        self::assertSame((string) $student->getKey(), $row['id']);
        self::assertNotSame((string) $subscription->getKey(), $row['id']);
        self::assertNotSame((string) $subscription->getKey(), $row['student_id']);
        self::assertIsList($row['subscriptions']);
    }

    /**
     * TWO DIFFERENT "STATUS" VOCABULARIES IN ONE PAYLOAD, PRESERVED. A
     * suspended ACCOUNT holding an ACTIVE subscription is an ordinary state,
     * and the two fields must disagree without either being wrong.
     */
    public function test_the_account_status_and_the_subscription_status_are_independent(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$student] = $this->makeStudentWithCode();
        $student->forceFill(['is_active' => false])->save();
        $this->makeSubscription($student, $course);

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/admin/subscriptions')->json('data.subscriptions.0');

        self::assertSame('suspended', $row['status']);
        self::assertSame('active', $row['subscriptions'][0]['status']);
        self::assertTrue($row['subscriptions'][0]['isActive']);
    }

    /** `paymentMethod` is the MOST RECENT transaction for THAT subscription. */
    public function test_the_payment_method_is_the_latest_transaction_for_that_subscription(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();
        $subscription = $this->makeSubscription($student, $course);

        $older = $this->recordRevenue(
            $student,
            $course,
            100,
            RevenueTransaction::METHOD_ENROLLMENT_CODE,
            $subscription
        );
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $this->recordRevenue(
            $student,
            $course,
            100,
            RevenueTransaction::METHOD_MANUAL_ADMIN,
            $subscription
        );

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/admin/subscriptions')->json('data.subscriptions.0');

        self::assertSame(
            RevenueTransaction::METHOD_MANUAL_ADMIN,
            $row['subscriptions'][0]['paymentMethod']
        );
    }

    /**
     * A transaction belonging to ANOTHER subscription of the SAME student must
     * not bleed across. This is what the correlated subquery's
     * `whereColumn(subscription_id, s.id)` buys, and a naive "latest
     * transaction for this student" would fail it.
     */
    public function test_a_sibling_subscriptions_payment_method_does_not_bleed_across(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $paid = $this->makeCourseFor($instructor, ['price' => 100, 'title' => 'مدفوع']);
        $free = $this->makeCourseFor($instructor, ['price' => 0, 'title' => 'مجاني']);
        [$student] = $this->makeStudentWithCode();

        $paidSub = $this->makeSubscription($student, $paid);
        $this->makeSubscription($student, $free);
        $this->recordRevenue($student, $paid, 100, RevenueTransaction::METHOD_MANUAL_ADMIN, $paidSub);

        $this->loginAsAdmin();

        $subs = collect($this->getJson('/api/v1/admin/subscriptions')->json('data.subscriptions.0.subscriptions'))
            ->keyBy('courseName');

        self::assertSame(RevenueTransaction::METHOD_MANUAL_ADMIN, $subs['مدفوع']['paymentMethod']);
        self::assertNull($subs['مجاني']['paymentMethod']);
    }

    /** Each of the five filters, alone. */
    public function test_every_filter_narrows_the_listing_on_its_own(): void
    {
        $this->disableRateLimiting();
        [$teacherA] = $this->makeTeacherWithProfile();
        [$teacherB] = $this->makeTeacherWithProfile();
        $courseA = $this->makeCourseFor($teacherA);
        $courseB = $this->makeCourseFor($teacherB);

        [$alice] = $this->makeStudentWithCode('STU111111', [
            'phone' => '01000000001',
            'parent_phone' => '01099999991',
        ]);
        [$bob] = $this->makeStudentWithCode('STU222222', [
            'phone' => '01000000002',
            'parent_phone' => '01099999992',
        ]);

        $this->makeSubscription($alice, $courseA);
        $this->makeSubscription($bob, $courseB);

        $this->loginAsAdmin();

        $ids = fn (array $query): array => array_column(
            $this->getJson('/api/v1/admin/subscriptions?' . http_build_query($query))
                ->json('data.subscriptions'),
            'id'
        );

        self::assertSame([(string) $alice->getKey()], $ids(['phone' => '0000001']));
        self::assertSame([(string) $bob->getKey()], $ids(['parent_phone' => '9999992']));
        // The bare six digits match the stored 'STU…' spelling — the LIKE is
        // what makes that work, and it is inherited on purpose.
        self::assertSame([(string) $alice->getKey()], $ids(['student_code' => '111111']));
        self::assertSame([(string) $bob->getKey()], $ids(['student_code' => 'STU222222']));
        self::assertSame([(string) $alice->getKey()], $ids(['teacher_id' => (string) $teacherA->getKey()]));
        self::assertSame([(string) $bob->getKey()], $ids(['course_id' => (string) $courseB->getKey()]));

        // An absent/blank filter is inert, not a filter matching nothing.
        self::assertCount(2, $ids([]));
        self::assertCount(2, $ids(['phone' => '', 'course_id' => '']));
    }

    /** Combined filters are ANDed, not ORed. */
    public function test_combined_filters_are_anded(): void
    {
        $this->disableRateLimiting();
        [$teacherA] = $this->makeTeacherWithProfile();
        [$teacherB] = $this->makeTeacherWithProfile();
        $courseA = $this->makeCourseFor($teacherA);
        $courseB = $this->makeCourseFor($teacherB);
        [$alice] = $this->makeStudentWithCode('STU111111', ['phone' => '01000000001']);

        $this->makeSubscription($alice, $courseA);
        $this->makeSubscription($alice, $courseB);

        $this->loginAsAdmin();

        $rows = $this->getJson('/api/v1/admin/subscriptions?' . http_build_query([
            'phone' => '0000001',
            'teacher_id' => (string) $teacherA->getKey(),
        ]))->json('data.subscriptions');

        self::assertCount(1, $rows);
        // The filter narrows the SUBSCRIPTION rows too, not just the students.
        self::assertCount(1, $rows[0]['subscriptions']);
        self::assertSame((string) $courseA->getKey(), $rows[0]['subscriptions'][0]['course_id']);

        // A pair that no single row satisfies returns nothing, proving AND.
        self::assertSame([], $this->getJson('/api/v1/admin/subscriptions?' . http_build_query([
            'phone' => '0000001',
            'student_code' => 'STU999999',
        ]))->json('data.subscriptions'));
    }

    /** An unknown query parameter is inert — it is not a filter. */
    public function test_an_unrecognised_query_parameter_is_ignored(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$student] = $this->makeStudentWithCode();
        $this->makeSubscription($student, $course);

        $this->loginAsAdmin();

        $this->getJson('/api/v1/admin/subscriptions?full_name=nobody&limit=0')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.subscriptions');
    }

    /**
     * A SOFT-DELETED STUDENT DISAPPEARS. backend/ had no such clause and would
     * have rendered a deleted account with a working suspend button. This is
     * the accepted correction recorded in docs/open-decisions.md 7.3, asserted
     * rather than assumed.
     */
    public function test_a_soft_deleted_student_is_excluded(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$alive] = $this->makeStudentWithCode();
        [$deleted] = $this->makeStudentWithCode();
        $this->makeSubscription($alive, $course);
        $this->makeSubscription($deleted, $course);

        $deleted->delete();

        $this->loginAsAdmin();

        $rows = $this->getJson('/api/v1/admin/subscriptions')->json('data.subscriptions');

        self::assertCount(1, $rows);
        self::assertSame((string) $alive->getKey(), $rows[0]['id']);
    }

    /** Likewise a soft-deleted COURSE — invisible everywhere else, invisible here. */
    public function test_a_soft_deleted_course_is_excluded(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $live = $this->makeCourseFor($instructor, ['title' => 'حيّ']);
        $gone = $this->makeCourseFor($instructor, ['title' => 'محذوف']);
        [$student] = $this->makeStudentWithCode();
        $this->makeSubscription($student, $live);
        $this->makeSubscription($student, $gone);

        $gone->delete();

        $this->loginAsAdmin();

        $subs = $this->getJson('/api/v1/admin/subscriptions')->json('data.subscriptions.0.subscriptions');

        self::assertCount(1, $subs);
        self::assertSame('حيّ', $subs[0]['courseName']);
    }

    /** No enrolments at all is an empty LIST, not an object or a null. */
    public function test_an_empty_result_is_an_empty_list(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->getJson('/api/v1/admin/subscriptions')
            ->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'message' => 'OK',
                'data' => ['subscriptions' => []],
            ]);
    }
}
