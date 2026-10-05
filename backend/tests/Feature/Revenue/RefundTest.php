<?php

declare(strict_types=1);

namespace Tests\Feature\Revenue;

use App\Actions\Revenue\ProcessRefund;
use App\Enums\RefundStatus;
use App\Http\Requests\Revenue\SubmitRefundRequest;
use App\Models\Refund;
use App\Models\RevenueTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /revenue/refunds (row 69) and POST /revenue/refunds (row 70) — the
 * branching endpoint plus Session B's new double-refund guard.
 */
final class RefundTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** @return array{0: RevenueTransaction, 1: string} */
    private function saleOf(float $gross): array
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => $gross]);
        [$student] = $this->makeStudentWithCode();

        $tx = $this->recordRevenue($student, $course, $gross);

        return [$tx, (string) $tx->getKey()];
    }

    // ── THE REQUEST BRANCH ───────────────────────────────────────────────

    public function test_requesting_a_refund_creates_a_pending_row_for_the_full_gross(): void
    {
        $this->disableRateLimiting();
        [, $txId] = $this->saleOf(250.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', [
            'transactionId' => $txId,
            'reason' => 'الطالب لم يحضر',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'تم إرسال طلب الاسترداد')
            ->assertJsonPath('data.refund.status', 'pending')
            ->assertJsonPath('data.refund.revenue_transaction_id', $txId)
            ->assertJsonPath('data.refund.reason', 'الطالب لم يحضر');

        $refund = Refund::query()->sole();
        // FULL GROSS, ALWAYS. There are no partial refunds and the caller
        // cannot supply an amount — see RequestRefund's banner.
        self::assertSame('250.00', (string) $refund->amount);
        self::assertSame(RefundStatus::Pending, $refund->status);

        // AND THE LEDGER HAS NOT MOVED. Requesting is not approving.
        self::assertSame(
            RevenueTransaction::STATUS_PAID,
            RevenueTransaction::query()->sole()->status
        );
    }

    /** An amount in the body is ignored — the transaction decides. */
    public function test_a_caller_supplied_amount_cannot_override_the_gross(): void
    {
        $this->disableRateLimiting();
        [, $txId] = $this->saleOf(250.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId, 'amount' => 1.00])
            ->assertCreated();

        self::assertSame('250.00', (string) Refund::query()->sole()->amount);
    }

    /** '' rather than null when no reason is given — backend/'s shape. */
    public function test_an_absent_reason_is_stored_as_an_empty_string(): void
    {
        $this->disableRateLimiting();
        [, $txId] = $this->saleOf(100.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])->assertCreated();

        self::assertSame('', Refund::query()->sole()->reason);
    }

    /** 404 WITH ITS LITERAL, not the 422 an `exists:` rule would give. */
    public function test_an_unknown_transaction_is_a_404_with_the_pinned_string(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => (string) Str::uuid()])
            ->assertNotFound()
            ->assertJsonPath('message', 'Transaction not found');

        self::assertSame(0, Refund::query()->count());
    }

    // ── THE DOUBLE-REFUND GUARD — Session B decision #1 ──────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE GAP backend/ HAS. Without this guard an admin can stack five     ║
     * ║ pending requests against one sale; approving them later adds the     ║
     * ║ same gross to `refunds_total` five times while the ledger row moves  ║
     * ║ to 'refunded' exactly once. The platform then reports having         ║
     * ║ refunded more than it ever earned, with nothing failing anywhere.    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_second_request_while_one_is_pending_is_refused(): void
    {
        $this->disableRateLimiting();
        [, $txId] = $this->saleOf(300.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])->assertCreated();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.transactionId.0', SubmitRefundRequest::ALREADY_REFUNDING);

        self::assertSame(1, Refund::query()->count(), 'The second request wrote nothing.');
    }

    /** An APPROVED refund blocks too — Refund::scopeBlocking is both states. */
    public function test_a_request_against_an_already_approved_refund_is_refused(): void
    {
        $this->disableRateLimiting();
        [, $txId] = $this->saleOf(300.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])->assertCreated();
        $refundId = (string) Refund::query()->sole()->getKey();
        $this->postJson('/api/v1/revenue/refunds', ['id' => $refundId, 'status' => 'approved'])->assertOk();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.transactionId.0', SubmitRefundRequest::ALREADY_REFUNDING);

        self::assertSame(1, Refund::query()->count());
    }

    /**
     * A **REJECTED** REFUND MUST NOT BLOCK, and that is the half of
     * scopeBlocking() a naive "any refund exists" guard would get wrong.
     * Rejection is precisely the signal that the request was not legitimate,
     * so a corrected re-request is the intended flow.
     */
    public function test_a_rejected_refund_does_not_block_a_fresh_request(): void
    {
        $this->disableRateLimiting();
        [, $txId] = $this->saleOf(300.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])->assertCreated();
        $refundId = (string) Refund::query()->sole()->getKey();
        $this->postJson('/api/v1/revenue/refunds', ['id' => $refundId, 'status' => 'rejected'])->assertOk();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])->assertCreated();

        self::assertSame(2, Refund::query()->count());
    }

    /** The guard is per TRANSACTION, not global. */
    public function test_a_pending_refund_on_one_sale_does_not_block_another(): void
    {
        $this->disableRateLimiting();
        [, $first] = $this->saleOf(100.00);
        [, $second] = $this->saleOf(200.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $first])->assertCreated();
        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $second])->assertCreated();

        self::assertSame(2, Refund::query()->count());
    }

    // ── THE PROCESS BRANCH ───────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ APPROVAL MOVES TWO ROWS IN TWO TABLES, AND BOTH ARE READ BACK FROM   ║
     * ║ THE DATABASE — not from the response body, which would pass just as  ║
     * ║ happily for a handler that returned the right JSON and wrote one     ║
     * ║ row. If only `refunds.status` moved, PlatformSummary would count the ║
     * ║ same money as BOTH earned and refunded.                              ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_approving_moves_both_rows_together(): void
    {
        $this->disableRateLimiting();
        [$tx, $txId] = $this->saleOf(400.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])->assertCreated();
        $refundId = (string) Refund::query()->sole()->getKey();

        $this->postJson('/api/v1/revenue/refunds', ['id' => $refundId, 'status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث حالة الاسترداد')
            ->assertJsonPath('data.refund.status', 'approved');

        self::assertSame(RefundStatus::Approved, Refund::query()->sole()->status);
        self::assertSame(RevenueTransaction::STATUS_REFUNDED, $tx->fresh()?->status);

        // THE MONEY COLUMNS ARE UNTOUCHED. The snapshot is what an auditor
        // reconciles against; the STATUS is what removes it from reports.
        self::assertSame('400.00', (string) $tx->fresh()?->gross_amount);
        self::assertSame('280.00', (string) $tx->fresh()?->teacher_earnings);

        // And the summary now counts it in exactly one direction.
        $summary = $this->getJson('/api/v1/revenue/platform')->json('data.summary');
        self::assertSame(0.0, (float) $summary['gross_revenue']);
        self::assertSame(400.0, (float) $summary['refunds_total']);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE ATOMICITY, PROVEN AT THE DATABASE — NOT BY READING THE CODE.     ║
     * ║                                                                      ║
     * ║ The failure is injected BETWEEN the two writes: a `DB::listen`       ║
     * ║ handler throws the instant the `update "refunds"` statement has been ║
     * ║ executed and before control returns to ProcessRefund, so the ledger  ║
     * ║ UPDATE is never issued at all. That is the exact shape of the crash  ║
     * ║ backend/'s own comment describes.                                    ║
     * ║                                                                      ║
     * ║ WHY NOT A MODEL EVENT: `RevenueTransaction::query()->update()` is a  ║
     * ║ QUERY-BUILDER write and fires NO Eloquent events, so an `updating`   ║
     * ║ hook would never run and the test would pass while proving nothing.  ║
     * ║ Measured, not assumed — that is precisely how this test failed on    ║
     * ║ its first draft.                                                     ║
     * ║                                                                      ║
     * ║ WITHOUT DB::transaction() the first assertion below reads            ║
     * ║ 'approved': the refunds row would already be committed and only the  ║
     * ║ ledger row would be missing — money counted as both earned AND       ║
     * ║ refunded, exactly the state PlatformSummary must never see.          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_failure_between_the_two_writes_leaves_neither_changed(): void
    {
        $this->disableRateLimiting();
        [$tx, $txId] = $this->saleOf(400.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])->assertCreated();
        $refund = Refund::query()->sole();

        /*
         * THE BASELINE IS NOT ZERO, AND PRETENDING IT IS MAKES THIS TEST LIE.
         * RefreshDatabase holds the whole test inside its own transaction, so
         * DB::transactionLevel() is ALREADY 1 here and ProcessRefund's
         * DB::transaction() nests as a SAVEPOINT rather than as a fresh BEGIN.
         * Every level assertion below is therefore RELATIVE to this baseline —
         * measured, not assumed, and the reason the first draft of this test
         * failed on an absolute `assertSame(0, …)`.
         *
         * The savepoint nesting does not weaken what is proven: a ROLLBACK TO
         * SAVEPOINT undoes exactly the statements issued after it, which is
         * the same all-or-nothing guarantee, and it is the shape production
         * gets too whenever a caller already owns a transaction.
         */
        $baseline = DB::transactionLevel();

        $ledgerWriteWasIssued = false;

        DB::listen(static function ($query) use (&$ledgerWriteWasIssued): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'update') && str_contains($sql, 'revenue_transactions')) {
                $ledgerWriteWasIssued = true;
            }

            if (str_contains($sql, 'update') && str_contains($sql, 'refunds')) {
                throw new RuntimeException('simulated failure between the two writes');
            }
        });

        try {
            app(ProcessRefund::class)((string) $refund->getKey(), RefundStatus::Approved);
            self::fail('The simulated failure should have propagated out of the Action.');
        } catch (RuntimeException $e) {
            self::assertSame('simulated failure between the two writes', $e->getMessage());
        }

        self::assertFalse($ledgerWriteWasIssued, 'The failure landed BETWEEN the two writes.');

        // NEITHER ROW MOVED.
        self::assertSame(RefundStatus::Pending, $refund->fresh()?->status);
        self::assertSame(RevenueTransaction::STATUS_PAID, $tx->fresh()?->status);
        self::assertSame($baseline, DB::transactionLevel(), 'The rollback unwound the savepoint.');
    }

    /**
     * THE COMMIT PATH, PINNED AT THE SAME LAYER as the rollback path above:
     * the two writes are observed to be issued INSIDE an open transaction
     * (transactionLevel > 0), which is the property the rollback depends on.
     * Rejection, by contrast, is issued at level 0 — the deliberate asymmetry
     * documented on ProcessRefund.
     */
    public function test_approval_writes_inside_a_transaction_and_rejection_does_not(): void
    {
        $this->disableRateLimiting();
        [, $firstTx] = $this->saleOf(100.00);
        [, $secondTx] = $this->saleOf(200.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $firstTx])->assertCreated();
        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $secondTx])->assertCreated();

        $approve = (string) Refund::query()->where('revenue_transaction_id', $firstTx)->sole()->getKey();
        $reject = (string) Refund::query()->where('revenue_transaction_id', $secondTx)->sole()->getKey();

        // RELATIVE TO THE BASELINE — RefreshDatabase already holds one level.
        // See the rollback test above for why this is measured, not assumed.
        $baseline = DB::transactionLevel();

        /** @var list<int> $levels */
        $levels = [];

        DB::listen(static function ($query) use (&$levels): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'update') && (str_contains($sql, 'refunds') || str_contains($sql, 'revenue_transactions'))) {
                $levels[] = DB::transactionLevel();
            }
        });

        app(ProcessRefund::class)($approve, RefundStatus::Approved);
        $approvalLevels = $levels;
        $levels = [];

        app(ProcessRefund::class)($reject, RefundStatus::Rejected);
        $rejectionLevels = $levels;

        self::assertCount(2, $approvalLevels, 'Approval issues exactly two writes.');
        foreach ($approvalLevels as $level) {
            self::assertGreaterThan(
                $baseline,
                $level,
                'BOTH approval writes are issued one level DEEPER than the caller — i.e. inside DB::transaction().'
            );
        }

        self::assertSame(
            [$baseline],
            $rejectionLevels,
            'Rejection is ONE write, issued at the CALLER\'s level — it opens no transaction of its own.'
        );
    }

    /** Rejection touches ONE row. The ledger is not consulted at all. */
    public function test_rejecting_changes_only_the_refund_row(): void
    {
        $this->disableRateLimiting();
        [$tx, $txId] = $this->saleOf(400.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])->assertCreated();
        $refundId = (string) Refund::query()->sole()->getKey();

        $this->postJson('/api/v1/revenue/refunds', ['id' => $refundId, 'status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'rejected');

        self::assertSame(RefundStatus::Rejected, Refund::query()->sole()->status);
        self::assertSame(RevenueTransaction::STATUS_PAID, $tx->fresh()?->status, 'The sale still stands.');

        // A rejected refund is not money that moved.
        self::assertSame(
            0.0,
            (float) $this->getJson('/api/v1/revenue/platform')->json('data.summary.refunds_total')
        );
    }

    public function test_an_invalid_status_is_refused_with_the_pinned_string(): void
    {
        $this->disableRateLimiting();
        [, $txId] = $this->saleOf(100.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId])->assertCreated();
        $refundId = (string) Refund::query()->sole()->getKey();

        foreach (['approved_maybe', 'pending', '', 'APPROVED'] as $bad) {
            $this->postJson('/api/v1/revenue/refunds', ['id' => $refundId, 'status' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('data.errors.status.0', SubmitRefundRequest::INVALID_STATUS);
        }

        self::assertSame(RefundStatus::Pending, Refund::query()->sole()->status, 'Nothing moved.');
    }

    public function test_an_unknown_refund_id_is_a_404_with_the_pinned_string(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', [
            'id' => (string) Str::uuid(),
            'status' => 'approved',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Refund not found');
    }

    /**
     * THREE SHAPES THAT WERE 500s IN backend/ ARE NOW 422s. No request that
     * previously SUCCEEDED behaves differently — backend/ read `$data['id']`
     * and `$data['status']` with no null-coalescing and crashed.
     */
    public function test_a_body_with_neither_discriminator_is_a_422_not_a_500(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', [])->assertStatus(422);
        $this->postJson('/api/v1/revenue/refunds', ['id' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/revenue/refunds', ['status' => 'approved'])->assertStatus(422);
    }

    // ── THE LISTING ──────────────────────────────────────────────────────

    public function test_the_listing_filters_by_status_and_is_newest_first(): void
    {
        $this->disableRateLimiting();
        [, $a] = $this->saleOf(100.00);
        [, $b] = $this->saleOf(200.00);
        $this->loginAsAdmin();

        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $a])->assertCreated();
        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $b])->assertCreated();

        $first = (string) Refund::query()->where('revenue_transaction_id', $a)->sole()->getKey();
        $this->postJson('/api/v1/revenue/refunds', ['id' => $first, 'status' => 'rejected'])->assertOk();

        self::assertCount(2, $this->getJson('/api/v1/revenue/refunds')->assertOk()->json('data.refunds'));
        self::assertCount(1, $this->getJson('/api/v1/revenue/refunds?status=pending')->json('data.refunds'));
        self::assertCount(1, $this->getJson('/api/v1/revenue/refunds?status=rejected')->json('data.refunds'));

        // An UNKNOWN status is an empty list, not a 422 — the parameter is a
        // filter, and a filter that matches nothing is a legitimate answer.
        $this->getJson('/api/v1/revenue/refunds?status=nonsense')
            ->assertOk()
            ->assertJsonPath('data.refunds', []);
    }

    public function test_the_listing_carries_the_six_columns_backend_returned(): void
    {
        $this->disableRateLimiting();
        [, $txId] = $this->saleOf(100.00);
        $this->loginAsAdmin();
        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => $txId, 'reason' => 'س'])->assertCreated();

        $row = $this->getJson('/api/v1/revenue/refunds')->json('data.refunds.0');

        self::assertSame([
            'id',
            'revenue_transaction_id',
            'amount',
            'reason',
            'status',
            'created_at',
        ], array_keys($row));
        self::assertSame($txId, $row['revenue_transaction_id']);
        self::assertSame('pending', $row['status']);
        self::assertNotNull($row['created_at']);
    }
}
