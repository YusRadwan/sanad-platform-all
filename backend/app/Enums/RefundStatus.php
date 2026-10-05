<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * refunds.status — the moderation state of a refund request.
 *
 * Deliberately NOT reused for revenue_transactions.status, which is a plain
 * VARCHAR(30) because payment-processor statuses are an open vocabulary that
 * grows with each gateway integration. These three are a closed set the
 * platform itself owns.
 */
enum RefundStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
