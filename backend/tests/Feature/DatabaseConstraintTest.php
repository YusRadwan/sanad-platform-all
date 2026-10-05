<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * DEFENCE-IN-DEPTH PROOF, ported from
 * backend/tests/concurrency/db_constraint_bypass_test.php.
 *
 * Every forbidden write is attempted with a RAW SQL statement that never
 * touches a model, service or validator. If MySQL itself rejects them, the
 * protection survives any future code path that forgets to validate — which is
 * the entire point of having the constraints in the schema rather than only in
 * PHP.
 *
 * Verified environment: MySQL 8.4.3 (SELECT VERSION()). MySQL enforces CHECK
 * constraints from 8.0.16 onward, so every CHECK here is a REAL constraint,
 * not the silently-ignored no-op that 5.7 parsed.
 */
final class DatabaseConstraintTest extends TestCase
{
    use RefreshDatabase;

    private string $teacherId;
    private string $studentId;
    private string $courseId;
    private string $sectionId;
    private string $groupId;
    private string $contentItemId;
    private string $examId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixtures();
    }

    /** Minimal valid graph the forbidden writes below can hang off. */
    private function seedFixtures(): void
    {
        $this->teacherId = (string) \Illuminate\Support\Str::uuid();
        $this->studentId = (string) \Illuminate\Support\Str::uuid();
        $this->courseId = (string) \Illuminate\Support\Str::uuid();
        $this->sectionId = (string) \Illuminate\Support\Str::uuid();
        $this->groupId = (string) \Illuminate\Support\Str::uuid();
        $this->contentItemId = (string) \Illuminate\Support\Str::uuid();
        $this->examId = (string) \Illuminate\Support\Str::uuid();

        DB::table('users')->insert([
            ['id' => $this->teacherId, 'username' => 't1', 'email' => 't1@x.test', 'password_hash' => 'x', 'full_name' => 'T', 'role' => UserRole::Instructor->value],
            ['id' => $this->studentId, 'username' => 's1', 'email' => 's1@x.test', 'password_hash' => 'x', 'full_name' => 'S', 'role' => UserRole::Student->value],
        ]);
        DB::table('teachers')->insert(['user_id' => $this->teacherId]);
        DB::table('students')->insert([
            'user_id' => $this->studentId,
            'student_code' => 'STU-1',
            'phone' => '01000000000',
            'parent_phone' => '01000000001',
            'governorate' => 'القاهرة',
            'grade_level' => 'الصف الأول الثانوي',
        ]);
        DB::table('courses')->insert([
            'id' => $this->courseId, 'title' => 'C', 'price' => 100, 'teacher_id' => $this->teacherId,
        ]);
        DB::table('sections')->insert(['id' => $this->sectionId, 'course_id' => $this->courseId, 'title' => 'S']);
        DB::table('course_groups')->insert(['id' => $this->groupId, 'course_id' => $this->courseId, 'title' => 'G']);
        DB::table('content_items')->insert([
            'id' => $this->contentItemId, 'section_id' => $this->sectionId, 'course_id' => $this->courseId,
            'title' => 'CI', 'type' => 'exam',
        ]);
        DB::table('exams')->insert(['id' => $this->examId, 'content_item_id' => $this->contentItemId]);
    }

    /** Asserts MySQL refuses a statement. */
    private function assertRejected(string $sql, array $bindings, string $why): void
    {
        try {
            DB::statement($sql, $bindings);
            $this->fail("THE DATABASE ACCEPTED IT — {$why}");
        } catch (PDOException|\Illuminate\Database\QueryException $e) {
            $this->assertTrue(true);
        }
    }

    /* ─────────────── courses: price / discount bounds ─────────────── */

    public function test_rejects_negative_course_price(): void
    {
        $this->assertRejected(
            'INSERT INTO courses (id, title, price, teacher_id) VALUES (UUID(), "neg price", -10, ?)',
            [$this->teacherId],
            'chk_courses_price_non_negative'
        );
    }

    public function test_rejects_discount_above_price(): void
    {
        $this->assertRejected(
            'INSERT INTO courses (id, title, price, discount_price, teacher_id) VALUES (UUID(), "bad discount", 100, 500, ?)',
            [$this->teacherId],
            'chk_courses_discount_range'
        );
    }

    public function test_rejects_negative_discount(): void
    {
        $this->assertRejected(
            'INSERT INTO courses (id, title, price, discount_price, teacher_id) VALUES (UUID(), "neg discount", 100, -1, ?)',
            [$this->teacherId],
            'chk_courses_discount_range'
        );
    }

    /* ─────────────── money: payouts / refunds / revenue ─────────────── */

    public function test_rejects_negative_payout(): void
    {
        $this->assertRejected(
            'INSERT INTO payouts (id, teacher_id, amount, payment_method) VALUES (UUID(), ?, -50, "cash")',
            [$this->teacherId],
            'chk_payouts_amount_non_negative'
        );
    }

    public function test_rejects_negative_revenue_gross_amount(): void
    {
        $this->assertRejected(
            'INSERT INTO revenue_transactions (id, student_id, course_id, teacher_id, gross_amount, payment_method)
             VALUES (UUID(), ?, ?, ?, -1, "manual")',
            [$this->studentId, $this->courseId, $this->teacherId],
            'chk_revenue_tx_amounts'
        );
    }

    public function test_rejects_teacher_profit_percentage_above_100(): void
    {
        $this->assertRejected(
            'INSERT INTO revenue_transactions (id, student_id, course_id, teacher_id, gross_amount, payment_method, teacher_profit_percentage)
             VALUES (UUID(), ?, ?, ?, 100, "manual", 150)',
            [$this->studentId, $this->courseId, $this->teacherId],
            'chk_revenue_tx_percentage'
        );
    }

    public function test_rejects_profit_setting_above_100(): void
    {
        $this->assertRejected(
            'INSERT INTO teacher_profit_settings (teacher_id, profit_percentage) VALUES (?, 120)',
            [$this->teacherId],
            'chk_profit_settings_percentage'
        );
    }

    public function test_rejects_negative_refund_amount(): void
    {
        $txId = (string) \Illuminate\Support\Str::uuid();
        DB::table('revenue_transactions')->insert([
            'id' => $txId, 'student_id' => $this->studentId, 'course_id' => $this->courseId,
            'teacher_id' => $this->teacherId, 'gross_amount' => 100, 'payment_method' => 'manual',
        ]);

        $this->assertRejected(
            'INSERT INTO refunds (id, revenue_transaction_id, amount) VALUES (UUID(), ?, -5)',
            [$txId],
            'chk_refunds_amount_non_negative'
        );
    }

    /* ─────────────── content_items: the XOR single-parent rule ─────────────── */

    public function test_rejects_content_item_with_both_parents(): void
    {
        $this->assertRejected(
            'INSERT INTO content_items (id, section_id, group_id, course_id, title, type)
             VALUES (UUID(), ?, ?, ?, "both parents", "note")',
            [$this->sectionId, $this->groupId, $this->courseId],
            'chk_content_items_single_parent'
        );
    }

    public function test_rejects_content_item_with_neither_parent(): void
    {
        $this->assertRejected(
            'INSERT INTO content_items (id, course_id, title, type) VALUES (UUID(), ?, "no parent", "note")',
            [$this->courseId],
            'chk_content_items_single_parent'
        );
    }

    /**
     * The XOR check is only satisfiable because BOTH parent FKs cascade.
     * If fk_content_items_group were ON DELETE SET NULL, deleting a group
     * would try to null group_id on a row whose section_id is already NULL,
     * violate the CHECK, and make group deletion impossible.
     */
    public function test_deleting_a_group_cascades_its_content_items(): void
    {
        $itemId = (string) \Illuminate\Support\Str::uuid();
        DB::table('content_items')->insert([
            'id' => $itemId, 'group_id' => $this->groupId, 'course_id' => $this->courseId,
            'title' => 'in group', 'type' => 'note',
        ]);

        DB::table('course_groups')->where('id', $this->groupId)->delete();

        $this->assertDatabaseMissing('content_items', ['id' => $itemId]);
    }

    /* ─────────────── exams ─────────────── */

    public function test_rejects_exam_score_above_100(): void
    {
        $this->assertRejected(
            'INSERT INTO exam_attempts (id, exam_id, student_id, attempt_number, score) VALUES (UUID(), ?, ?, 900, 250)',
            [$this->examId, $this->studentId],
            'chk_exam_attempts_score'
        );
    }

    public function test_rejects_duplicate_attempt_ordinal(): void
    {
        $this->assertRejected(
            'INSERT INTO exam_attempts (id, exam_id, student_id, attempt_number, score) VALUES (UUID(), ?, ?, 1, 50),
                                                                                              (UUID(), ?, ?, 1, 50)',
            [$this->examId, $this->studentId, $this->examId, $this->studentId],
            'uq_exam_attempts_ordinal'
        );
    }

    public function test_rejects_zero_allowed_attempts(): void
    {
        $this->assertRejected(
            'UPDATE exams SET allowed_attempts = 0 WHERE id = ?',
            [$this->examId],
            'chk_exams_scores'
        );
    }

    public function test_rejects_pass_score_above_100(): void
    {
        $this->assertRejected(
            'UPDATE exams SET pass_score = 500 WHERE id = ?',
            [$this->examId],
            'chk_exams_scores'
        );
    }

    public function test_rejects_zero_duration_minutes(): void
    {
        $this->assertRejected(
            'UPDATE exams SET duration_minutes = 0 WHERE id = ?',
            [$this->examId],
            'chk_exams_scores'
        );
    }

    public function test_rejects_second_exam_for_the_same_content_item(): void
    {
        $this->assertRejected(
            'INSERT INTO exams (id, content_item_id) VALUES (UUID(), ?)',
            [$this->contentItemId],
            'uq_exams_content_item — a strict 1:1'
        );
    }

    /* ─────────────── uniqueness ─────────────── */

    public function test_rejects_duplicate_subscription_for_the_same_student_and_course(): void
    {
        DB::table('subscriptions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'student_id' => $this->studentId,
            'course_id' => $this->courseId,
        ]);

        $this->assertRejected(
            'INSERT INTO subscriptions (id, student_id, course_id) VALUES (UUID(), ?, ?)',
            [$this->studentId, $this->courseId],
            'uq_subscription_student_course — the double-charge guard'
        );
    }

    /**
     * The one index name application code parses out of a driver error message.
     */
    public function test_duplicate_enrollment_code_is_rejected_and_names_the_index(): void
    {
        DB::table('enrollment_codes')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'code' => '12345678',
            'course_id' => $this->courseId,
        ]);

        try {
            DB::statement(
                'INSERT INTO enrollment_codes (id, code, course_id) VALUES (UUID(), "12345678", ?)',
                [$this->courseId]
            );
            $this->fail('Duplicate enrollment code was accepted.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23000', $e->getCode());
            $this->assertStringContainsString(
                'uq_enrollment_codes_code',
                $e->getMessage(),
                'backend/EnrollmentCodeRepository.php matches this literal string in the driver message.'
            );
        }
    }

    public function test_rejects_duplicate_username_and_email(): void
    {
        $this->assertRejected(
            'INSERT INTO users (id, username, email, password_hash, full_name, role) VALUES (UUID(), "t1", "other@x.test", "x", "X", "student")',
            [],
            'uq_users_username'
        );

        $this->assertRejected(
            'INSERT INTO users (id, username, email, password_hash, full_name, role) VALUES (UUID(), "other", "t1@x.test", "x", "X", "student")',
            [],
            'uq_users_email'
        );
    }

    public function test_rejects_invalid_role_enum_value(): void
    {
        $this->assertRejected(
            'INSERT INTO users (id, username, email, password_hash, full_name, role) VALUES (UUID(), "z", "z@x.test", "x", "Z", "teacher")',
            [],
            "'teacher' is NOT a valid role — only student|instructor|admin"
        );
    }

    /* ─────────────── referential actions ─────────────── */

    /**
     * courses.teacher_id must reference teachers.user_id, NOT users.id — a
     * student account must never be able to own a course.
     */
    public function test_course_teacher_id_cannot_be_a_non_teacher_user(): void
    {
        $this->assertRejected(
            'INSERT INTO courses (id, title, price, teacher_id) VALUES (UUID(), "student-owned", 0, ?)',
            [$this->studentId],
            'fk_courses_teacher targets teachers.user_id'
        );
    }
}
