<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Enrollment\GrantManualActivation;
use App\Http\Requests\Enrollment\ManualActivationRequest;
use App\Http\Resources\SubscriptionResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Admin-only complimentary course access; isolated from the sales toggle. */
final class ManualActivationController extends Controller
{
    public function store(ManualActivationRequest $request, GrantManualActivation $grant): JsonResponse
    {
        $subscription = $grant(
            $request->studentId(),
            $request->courseId(),
            $request->accessType(),
            $request->expiresAt(),
            (string) $request->user()->getAuthIdentifier(),
        );

        return ApiResponse::success(
            ['enrollment' => new SubscriptionResource($subscription)],
            'تم تفعيل الكورس للطالب بنجاح دون تسجيل إيرادات'
        );
    }
}
