<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Settings\PublicSettingsMap;
use App\Actions\Settings\UpdateSettings;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Freeze-table rows 126 and 127 — the LAST two rows in the table.
 *
 * ┌── THE ASYMMETRY IS THE WHOLE DESIGN OF THIS PAIR. ───────────────────────┐
 * │ GET  /settings   fully public, NO auth, NO role                          │
 * │ POST /settings   auth + active + role:admin                              │
 * │                                                                          │
 * │ The read is anonymous because Footer.tsx and WhatsAppWidget.tsx render   │
 * │ on EVERY page for logged-out visitors and need these values without a    │
 * │ session. Adding `auth` here is an outage on the public storefront, not   │
 * │ a tightening — the same reasoning as rows 32 (/teachers) and 72          │
 * │ (/files/…).                                                              │
 * │                                                                          │
 * │ That is only acceptable while the store can hold NOTHING BUT public      │
 * │ display data, which is now guaranteed twice over: the write allow-list   │
 * │ in UpdateSettingsRequest, and the read allow-list in PublicSettingsMap.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO POLICY IS CONSULTED BY EITHER METHOD. `settings` is a singleton global
 * key-value bag with no owner column and no per-user dimension, so a Policy
 * could only ever restate `role:admin`, which Gate::before answers before any
 * Policy method is entered. Same ruling as the whole admin block.
 */
final class SettingsController extends Controller
{
    /**
     * GET /api/v1/settings — row 126. Public.
     *
     * `data` IS the map. See PublicSettingsMap for the shape and for why the
     * read is allow-list filtered (a user-approved change from backend/).
     */
    public function index(PublicSettingsMap $map): JsonResponse
    {
        return ApiResponse::success($map());
    }

    /**
     * POST /api/v1/settings — row 127. Admin only.
     *
     * ┌── `$this->all()`, NOT `$request->validated()`. ──────────────────────┐
     * │ Not a shortcut, and not a hole. UpdateSettingsRequest REJECTS every  │
     * │ unknown key at all three nesting levels with a 422 before this line  │
     * │ is reached, so by the time the controller runs, `all()` and          │
     * │ `validated()` contain the same keys.                                  │
     * │                                                                      │
     * │ `all()` is used because validated()'s extraction of nested wildcard  │
     * │ rules is shape-sensitive in ways that matter here — specifically the │
     * │ present-with-null `supportNumbers.*.values`, whose absent-vs-null-   │
     * │ vs-[] distinction the Action depends on (see UpdateSettings). Taking │
     * │ the body as sent keeps that distinction in one place instead of      │
     * │ relying on a framework extraction detail to preserve it. backend/    │
     * │ passes `$request->all()` for the same reason.                        │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * The response is the FULL updated map, not the changed keys — the admin
     * form re-seeds its entire state from it. See UpdateSettings.
     */
    public function update(UpdateSettingsRequest $request, UpdateSettings $update): JsonResponse
    {
        return ApiResponse::success(
            $update($request->all()),
            'تم تحديث الإعدادات بنجاح'
        );
    }
}
