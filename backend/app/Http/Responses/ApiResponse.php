<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use stdClass;

/**
 * Builds the mandatory API envelope: {success, message, data}.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ `data` MUST BE AN EMPTY JSON **OBJECT** `{}` WHEN THERE IS NOTHING   │
 * │ TO RETURN — never `null`, never `[]`.                                │
 * │                                                                      │
 * │ This is the single easiest thing to break in the whole rewrite,      │
 * │ because PHP's `[]` encodes to a JSON ARRAY, not an object, and the   │
 * │ difference is invisible until a frontend consumer does               │
 * │ `response.data.something` and gets a runtime error on an array.      │
 * │ normalise() below is the one place that decision lives.              │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * ENCODING FLAGS are equally load-bearing:
 *   JSON_UNESCAPED_UNICODE  Arabic must ship as literal UTF-8, not \uXXXX
 *   JSON_UNESCAPED_SLASHES  URLs must not come back as http:\/\/...
 * Laravel's default JsonResponse uses neither, so they are set explicitly on
 * every response this class builds AND re-applied by the envelope middleware
 * to responses built elsewhere (validation failures, aborts, etc.).
 */
final class ApiResponse
{
    /** The exact flag set required by the API contract. */
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return self::make(true, $message, $data, $status);
    }

    public static function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return self::make(true, $message, $data, 201);
    }

    public static function error(string $message = 'Error', int $status = 400, mixed $data = null): JsonResponse
    {
        return self::make(false, $message, $data, $status);
    }

    public static function make(bool $success, string $message, mixed $data, int $status): JsonResponse
    {
        return new JsonResponse(
            [
                'success' => $success,
                'message' => $message,
                'data' => self::normalise($data),
            ],
            $status,
            [],
            self::JSON_FLAGS
        );
    }

    /**
     * Coerces a payload into something that always encodes as a JSON OBJECT
     * when it is "nothing", while leaving real content untouched.
     *
     *   null      -> {}   (an empty stdClass)
     *   []        -> {}   (PHP cannot tell an empty list from an empty map,
     *                      and the contract says object)
     *   ['a'=>1]  -> {"a":1}   unchanged
     *   [1,2,3]   -> [1,2,3]   unchanged — a genuinely LIST-shaped payload
     *                          stays a list; only the EMPTY case is coerced.
     */
    public static function normalise(mixed $data): mixed
    {
        if ($data === null) {
            return new stdClass();
        }

        if (is_array($data) && $data === []) {
            return new stdClass();
        }

        return $data;
    }
}
