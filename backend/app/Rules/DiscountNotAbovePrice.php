<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ WHY LARAVEL'S BUILT-IN `lte:price` CANNOT BE USED HERE.                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `lte:price` compares against ANOTHER FIELD OF THE SAME REQUEST. A partial
 * course update legitimately carries only `discount_price`:
 *
 *     PUT /api/v1/courses/{id}   { "discount_price": 900 }
 *
 * There is no `price` in that payload to compare with. Laravel's `lte` rule,
 * handed a missing "other" field, resolves it to null and the comparison is
 * meaningless — the rule cannot express "compare against what is ALREADY
 * STORED", which is exactly the question backend/CourseService::
 * assertValidPricing() asks:
 *
 *     $price = <submitted price>  ??  (float) $existing['price'];
 *
 * So the baseline is injected at construction, from the row being edited, and
 * the submitted `price` (when there IS one) overrides it. One rule, both cases,
 * with no conditional rule-building in the Form Request.
 *
 * ┌── THE SECOND LINE OF DEFENCE IS THE DATABASE, NOT THIS CLASS. ───────────┐
 * │ migration 000011 declares                                                │
 * │   chk_courses_discount_range                                             │
 * │     CHECK (discount_price IS NULL OR                                     │
 * │            (discount_price >= 0 AND discount_price <= price))            │
 * │ so a direct SQL write, a future code path that skips this Form Request,  │
 * │ or a race between validation and persistence is still rejected. This     │
 * │ rule exists to turn that 500-shaped constraint violation into the 422    │
 * │ the frontend's course form knows how to render.                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── WHY CREATE PASSES 0.0 AND NOT null. ───────────────────────────────────┐
 * │ backend/'s assertValidPricing() skips the comparison entirely on create  │
 * │ when no `price` was submitted ($existing is null → $price is null). That │
 * │ is a real hole: `courses.price` DEFAULTS TO 0, so                        │
 * │ `POST /courses {title, discount_price: 50}` passed the service check and │
 * │ then hit chk_courses_discount_range at INSERT time — a 500 with a raw    │
 * │ constraint name, not a validation message. Stage 5 closes it by using    │
 * │ the column default as the create-time baseline, which turns that 500     │
 * │ into a 422 and changes NOTHING about any request that previously         │
 * │ succeeded. Recorded in docs/open-decisions.md (5.2).                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The message is backend/'s literal string and is rendered by the course form.
 */
final class DiscountNotAbovePrice implements ValidationRule
{
    public const MESSAGE = 'سعر الخصم يجب ألا يزيد عن السعر الأصلي';

    /**
     * @param float       $baselinePrice the STORED price (update) or the column
     *                                   default 0.0 (create)
     * @param string|null $submittedPrice the request's own `price`, already
     *                                    normalised to null when absent/blank
     */
    public function __construct(
        private readonly float $baselinePrice,
        private readonly ?string $submittedPrice = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Absent / blank is handled upstream: App\Http\Requests\Course\
        // CourseWriteRequest strips both money keys when they are '' or null,
        // so this rule only ever sees a value the caller actually sent. The
        // guard stays anyway — a rule must be correct on its own terms.
        if ($value === null || $value === '' || !is_numeric($value)) {
            return;
        }

        if ((float) $value > $this->effectivePrice()) {
            $fail(self::MESSAGE);
        }
    }

    /**
     * The price this discount is measured against.
     *
     * A `price` in the SAME request wins — the caller is changing both at once
     * and the new pair must be internally consistent. Otherwise the stored
     * value is the baseline, which is the whole point of this class.
     */
    private function effectivePrice(): float
    {
        if ($this->submittedPrice !== null && is_numeric($this->submittedPrice)) {
            return (float) $this->submittedPrice;
        }

        return $this->baselinePrice;
    }
}
