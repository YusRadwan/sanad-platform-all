<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Lesson\BuildVideoWatchStatisticsReport;
use App\Enums\ContentItemType;
use App\Exceptions\NotFoundException;
use App\Http\Responses\ApiResponse;
use App\Support\ContentItemAccess;
use Illuminate\Http\JsonResponse;

/**
 * "إحصائيات المشاهدات" — the instructor-only, owner-only twin of
 * ExamStatisticsController, for one video content item instead of one exam.
 * See that controller's banner for why this is a separate class rather than
 * a new method on ContentItemController.
 */
final class VideoStatisticsController extends Controller
{
    /**
     * GET /api/v1/content-items/{id}/watch-statistics.
     *
     * `assertItemManageable()` is the SAME owner-only gate PUT/DELETE
     * /content-items/{id} already use (rows 24/26) — deliberately narrower
     * than `assertViewable()`, which also admits a subscribed student.
     */
    public function show(string $id, BuildVideoWatchStatisticsReport $report): JsonResponse
    {
        $item = ContentItemAccess::findOrFail($id);

        ContentItemAccess::assertItemManageable($item);

        if ($item->getAttribute('type') !== ContentItemType::Video) {
            throw new NotFoundException(ContentItemAccess::ITEM_NOT_FOUND);
        }

        return ApiResponse::success($report($item));
    }
}
