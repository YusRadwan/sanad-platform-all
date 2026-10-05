<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * subscriptions.status — matches frontend/src/types/payment.ts Subscription
 * literally.
 *
 * 'expired' is a STORED state, not a derived one: a subscription is not
 * considered expired merely because expires_at is in the past. Whatever moves
 * a row from active to expired (a scheduled job, or a lazy check on read) is a
 * later-stage decision — Stage 0 only guarantees the vocabulary.
 */
enum SubscriptionStatus: string
{
    use HasValues;

    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
