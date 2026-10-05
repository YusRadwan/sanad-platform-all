<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;

/**
 * "What did this enrollment actually cost?" — asked once, answered once.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE RULE: `discount_price` WHEN IT IS NOT NULL, OTHERWISE `price`.       │
 * │                                                                          │
 * │ Never the catalogue price while a discount is live. The number this      │
 * │ returns is `revenue_transactions.gross_amount`, and the instructor's     │
 * │ share is a PERCENTAGE OF IT — so reading the wrong column overstates     │
 * │ platform revenue and overpays the teacher in the same stroke, on every   │
 * │ discounted sale, silently.                                               │
 * │                                                                          │
 * │ `?? ` IS THE CORRECT OPERATOR AND `?:` IS NOT. `discount_price` is       │
 * │ DECIMAL(10,2) NULL, and `chk_courses_discount_range` explicitly permits  │
 * │ 0 (`discount_price >= 0 AND <= price`). A course genuinely discounted to │
 * │ FREE therefore stores 0.00 — which `?:` would treat as "no discount" and │
 * │ charge full price for. Only NULL means "no discount".                    │
 * │                                                                          │
 * │ It lives here rather than in each Action because BOTH enrollment paths   │
 * │ (code redemption and the admin's manual grant) need it and backend/      │
 * │ wrote the ternary out twice — EnrollmentCodeService::redeem() and        │
 * │ AdminDashboardService::toggleEnrollment(). Two copies of a pricing rule  │
 * │ is one copy too many.                                                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * RETURNS A FLOAT, DELIBERATELY, EVEN THOUGH THE MODEL KEEPS DECIMAL STRINGS.
 * The only consumers are a `> 0` test and one multiplication inside
 * App\Actions\Revenue\RecordRevenueTransaction, whose result is immediately
 * round()ed to the column's two decimals. The value never round-trips through
 * this type on its way back to the database.
 */
final class CoursePricing
{
    public static function amountPaid(Course $course): float
    {
        return (float) ($course->discount_price ?? $course->price ?? 0);
    }
}
