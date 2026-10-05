<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The `?page=` / `?per_page=` clamping that backend/'s three admin listing
 * controllers each open with, verbatim and identically:
 *
 *     $page    = max(1, (int) $request->query('page', 1));
 *     $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
 *
 * Written once here instead of three times, because the CLAMP is the security
 * property: without the `min(100, …)` a caller can ask for `per_page=1000000`
 * and turn any admin listing into a memory-exhaustion lever, and without the
 * `max(1, …)` a `per_page=0` or a negative value produces either a division by
 * zero or a LIMIT that MySQL rejects. Three copies is three chances for one of
 * them to lose a bound.
 *
 * PHP's `(int)` cast is what makes this total: it turns 'abc' into 0 and '' into
 * 0, both of which the max() then lifts to the floor. So there is no input —
 * missing, malformed, hostile — that escapes the range.
 *
 * NOT Laravel's paginate(): the response envelope is {items, total, page,
 * per_page}, which is neither LengthAwarePaginator's shape nor a subset of it,
 * and the frontend reads those four keys directly. Building the envelope by
 * hand from a clamped page/per_page is closer to the contract than reshaping a
 * paginator would be.
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @return array{0: int, 1: int} [$page, $perPage]
     */
    public static function fromRequest(Request $request): array
    {
        $page = max(1, (int) $request->query('page', '1'));

        $perPage = min(
            self::MAX_PER_PAGE,
            max(1, (int) $request->query('per_page', (string) self::DEFAULT_PER_PAGE))
        );

        return [$page, $perPage];
    }
}
