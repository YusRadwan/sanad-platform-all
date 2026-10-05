<?php

declare(strict_types=1);

namespace Tests\Feature\Revenue;

use App\Http\Requests\Revenue\CreatePayoutRequest;
use App\Models\Payout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * POST /revenue/payouts (row 68) and the two read routes that show the result
 * (rows 66 and 101).
 */
final class PayoutTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_recording_a_payout_returns_201_and_the_three_backend_keys(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $admin = $this->loginAsAdmin();

        $response = $this->postJson('/api/v1/revenue/payouts', [
            'teacherId' => (string) $instructor->getKey(),
            'amount' => 250.75,
            'paymentMethod' => 'bank_transfer',
            'note' => 'دفعة مارس',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'تم تسجيل الدفعة بنجاح');

        // backend/ returned EXACTLY these three keys — no created_at, no notes.
        self::assertSame(
            ['id', 'teacher_id', 'amount'],
            array_keys($response->json('data.payout'))
        );

        $payout = Payout::query()->sole();
        self::assertSame('250.75', (string) $payout->amount);
        self::assertSame('bank_transfer', $payout->payment_method);
        self::assertSame('دفعة مارس', $payout->notes);
        // `created_by` IS THE SESSION, never the body. There is no field for it.
        self::assertSame((string) $admin->getKey(), $payout->created_by);
    }

    /** An absent method defaults to the literal 'manual' — backend/'s value. */
    public function test_an_absent_payment_method_defaults_to_manual(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/payouts', [
            'teacherId' => (string) $instructor->getKey(),
            'amount' => 10,
        ])->assertCreated();

        self::assertSame(Payout::METHOD_MANUAL, Payout::query()->sole()->payment_method);
        self::assertNull(Payout::query()->sole()->notes);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE PAYOUT WRITE OPENS NO TRANSACTION, AND THAT IS ASSERTED RATHER   ║
     * ║ THAN LEFT TO A CODE COMMENT.                                         ║
     * ║                                                                      ║
     * ║ It is ONE INSERT into ONE table; there is no denormalised balance    ║
     * ║ column for it to keep in sync, because balance is derived on read as ║
     * ║ SUM(earnings) - SUM(payouts). Wrapping it would claim a second write ║
     * ║ exists and send the next reader looking for it.                      ║
     * ║                                                                      ║
     * ║ Measured the same way RefundTest measures the opposite property:     ║
     * ║ observe the transaction level AT THE MOMENT THE WRITE IS ISSUED and  ║
     * ║ compare it to the caller's baseline. (RefreshDatabase already holds  ║
     * ║ one level, so an absolute `assertSame(0, …)` would be wrong.) One    ║
     * ║ INSERT, at the caller's own level, and no deeper.                    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_recording_a_payout_opens_no_transaction(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $admin = $this->makeAdmin();

        $baseline = DB::transactionLevel();

        /** @var list<int> $levels */
        $levels = [];

        DB::listen(static function ($query) use (&$levels): void {
            if (str_contains(strtolower($query->sql), 'insert into `payouts`')) {
                $levels[] = DB::transactionLevel();
            }
        });

        app(\App\Actions\Revenue\CreatePayout::class)(
            (string) $instructor->getKey(),
            100.00,
            null,
            null,
            (string) $admin->getKey(),
        );

        self::assertSame([$baseline], $levels, 'One INSERT, at the caller\'s level, in no transaction of its own.');
        self::assertSame($baseline, DB::transactionLevel());
    }

    // ── VALIDATION ───────────────────────────────────────────────────────

    /**
     * ZERO AND NEGATIVE BOTH CARRY THE PINNED STRING. backend/ refused
     * `$amount <= 0` with this literal; `min:0` would have admitted 0.00, a row
     * that settles nothing and exists only to confuse a reconciliation.
     */
    public function test_a_non_positive_amount_is_refused_with_the_pinned_string(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        foreach ([0, 0.0, -1, -250.75] as $bad) {
            $this->postJson('/api/v1/revenue/payouts', [
                'teacherId' => (string) $instructor->getKey(),
                'amount' => $bad,
            ])
                ->assertStatus(422)
                ->assertJsonPath('data.errors.amount.0', CreatePayoutRequest::AMOUNT_NOT_POSITIVE);
        }

        self::assertSame(0, Payout::query()->count(), 'Not one row was written.');
    }

    /** A missing or non-numeric amount is the SAME complaint about the SAME field. */
    public function test_a_missing_or_non_numeric_amount_carries_the_same_string(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        foreach ([[], ['amount' => 'كثير']] as $body) {
            $this->postJson('/api/v1/revenue/payouts', array_merge(
                ['teacherId' => (string) $instructor->getKey()],
                $body
            ))
                ->assertStatus(422)
                ->assertJsonPath('data.errors.amount.0', CreatePayoutRequest::AMOUNT_NOT_POSITIVE);
        }
    }

    /**
     * ⚠ A 500 IN backend/ IS NOW A 422. An unknown `teacherId` went straight
     * into the INSERT and died on `fk_payouts_teacher` — SQLSTATE 23000, an
     * unhandled exception. There is no contract to preserve in a crash.
     */
    public function test_an_unknown_teacher_is_a_422_rather_than_the_old_fk_crash(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/payouts', [
            'teacherId' => (string) Str::uuid(),
            'amount' => 100,
        ])->assertStatus(422);

        self::assertSame(0, Payout::query()->count());
    }

    /**
     * THE CHECK IS AGAINST `teachers.user_id`, NOT `users.id`. A perfectly
     * real account with no `teachers` row still violates the FK, so a rule
     * pointing at `users` would let it through to the same 500.
     */
    public function test_a_real_user_who_is_not_a_teacher_is_refused(): void
    {
        $this->disableRateLimiting();
        [$student] = $this->makeStudentWithCode();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/payouts', [
            'teacherId' => (string) $student->getKey(),
            'amount' => 100,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.teacherId.0', CreatePayoutRequest::TEACHER_NOT_FOUND);

        self::assertSame(0, Payout::query()->count());
    }

    // ── EFFECT ON THE BALANCE ────────────────────────────────────────────

    /**
     * A PAYOUT IS NOT VALIDATED AGAINST THE AVAILABLE BALANCE, matching
     * backend/: an over-payment simply drives the balance negative, which is a
     * real operational state (an advance, or a correction) and not an error
     * this API refuses. The DB's CHECK bounds the ROW; nothing bounds the
     * running TOTAL, on purpose.
     */
    public function test_over_paying_a_teacher_drives_the_balance_negative_rather_than_failing(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();
        $this->recordRevenue($student, $course, 100.00); // 70.00 earned.

        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/payouts', [
            'teacherId' => (string) $instructor->getKey(),
            'amount' => 500,
        ])->assertCreated();

        self::assertSame(
            -430.0,
            (float) $this->getJson('/api/v1/revenue/statement/' . $instructor->getKey())
                ->json('data.statement.balance')
        );
        self::assertSame(
            -430.0,
            (float) $this->getJson('/api/v1/revenue/teachers')->json('data.teachers.0.available_balance'),
            'And the admin list agrees — decision #2 holds for negatives too.'
        );
    }

    /** The payout listing carries the seven columns backend/'s SELECT * did. */
    public function test_the_payout_listing_carries_the_seven_columns(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/payouts', [
            'teacherId' => (string) $instructor->getKey(),
            'amount' => 12.50,
        ])->assertCreated();

        $row = $this->getJson('/api/v1/revenue/payouts/' . $instructor->getKey())
            ->assertOk()->json('data.payouts.0');

        self::assertSame([
            'id',
            'teacher_id',
            'amount',
            'payment_method',
            'notes',
            'created_by',
            'created_at',
        ], array_keys($row));
        self::assertSame('12.50', (string) $row['amount']);
    }

    /** One teacher's payouts never appear under another's id. */
    public function test_payouts_are_scoped_to_one_teacher(): void
    {
        $this->disableRateLimiting();
        [$mine] = $this->makeTeacherWithProfile();
        [$theirs] = $this->makeTeacherWithProfile();
        $this->makePayout($theirs, 99.00);

        $this->loginAsAdmin();

        self::assertSame([], $this->getJson('/api/v1/revenue/payouts/' . $mine->getKey())->json('data.payouts'));
        self::assertCount(1, $this->getJson('/api/v1/revenue/payouts/' . $theirs->getKey())->json('data.payouts'));
    }
}
