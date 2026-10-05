<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Upload\StoreUpload;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/uploads — freeze-table row 71.
 *
 * Any AUTHENTICATED user. The rules that matter are per-KIND, not per-role
 * (a student uploading an assignment attachment and an instructor uploading a
 * course banner both come through here), so there is no `role:` middleware and
 * no policy — the resource being created has no owner row to have a policy
 * about. See open decision 9.1: uploads carry no owner column at all.
 *
 * ┌── WHY THERE IS NO FormRequest. ──────────────────────────────────────────┐
 * │ A FormRequest's `rules()` would have to express "the declared extension  │
 * │ is in this kind's allow-list AND the sniffed mime agrees with THAT       │
 * │ extension" — a cross-field rule over a map selected by another field.    │
 * │ Written as rules it is a closure rule that re-implements                 │
 * │ App\Actions\Upload\StoreUpload anyway, and it would lose the ordered,    │
 * │ individually-logged rejection reasons that are the actual security       │
 * │ product of this endpoint (Laravel runs rules in an order it chooses and  │
 * │ reports all failures at once, which is precisely the oracle StoreUpload  │
 * │ refuses to give). The only thing a FormRequest is genuinely better at    │
 * │ here — "is a file present at all" — is two lines below.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class UploadController extends Controller
{
    public function __construct(private readonly StoreUpload $storeUpload)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $file = $request->file('file');

        /*
         * `is_array` guards the multi-file form of the field: `file[]` makes
         * Laravel hand back an ARRAY of UploadedFile, which would otherwise
         * reach StoreUpload as a type error (a 500 where a 422 is owed). One
         * file per request is the contract; backend/'s Request::file()
         * returned a single $_FILES entry and had no array shape to hit.
         */
        if (!$file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => ['الملف مطلوب']]);
        }

        // A MISSING `type` is NOT an error — it defaults to `document`. See
        // App\Enums\UploadKind::fromRequestType() and open decision 9.3.
        $result = $this->storeUpload->handle($request->input('type'), $file);

        return ApiResponse::created(['file' => $result], 'تم رفع الملف بنجاح');
    }
}
