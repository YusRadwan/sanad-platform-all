<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness/readiness probe. Ported from
 * backend/app/Http/Controllers/HealthController.php.
 *
 * Deliberately minimal and deliberately UNAUTHENTICATED: a probe that needs a
 * session cannot be used by an external monitor. To keep that safe it exposes
 * NO diagnostic detail — no version, no hostname, no driver version, no
 * connection string, no exception text. A failed DB ping downgrades to
 * database=false with a 503; the underlying PDOException message is never
 * echoed, because it contains the database name, user and host.
 *
 * The DB check is `SELECT 1` — it touches no application table, takes no lock,
 * and cannot be turned into an information-disclosure or DoS lever.
 *
 * THE FAILURE IS CAUGHT HERE, NOT LEFT TO THE EXCEPTION HANDLER, for the same
 * reason the legacy version injected a PDO FACTORY rather than a live handle:
 * a probe's whole job is to REPORT that the database is down, so it must
 * answer 503 + a structured payload rather than bubbling a 500. Laravel's
 * lazy connection resolution means DB::connection() does not open a socket
 * until the first query, so the try/catch below is sufficient — no factory
 * indirection needed.
 */
final class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            $databaseOk = true;
        } catch (Throwable) {
            $databaseOk = false;
        }

        if (!$databaseOk) {
            return ApiResponse::error('الخدمة غير متاحة حالياً', 503, [
                'status' => 'degraded',
                'database' => false,
            ]);
        }

        return ApiResponse::success([
            'status' => 'ok',
            'database' => true,
        ], 'OK');
    }
}
