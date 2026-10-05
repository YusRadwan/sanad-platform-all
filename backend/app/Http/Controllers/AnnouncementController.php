<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Announcement\DeleteAnnouncementRequest;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Http\Responses\ApiResponse;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Announcements — freeze-table rows 109-112. `role:admin,instructor`.
 *
 * Every refusal (404 announcement, 404 course, 403 owner) is raised by the
 * Form Requests before these methods are entered; see
 * App\Http\Requests\Announcement\* for the ladders and
 * App\Policies\AnnouncementPolicy for the ownership rule itself.
 */
final class AnnouncementController extends Controller
{
    /**
     * GET /api/v1/announcements. Row 109.
     *
     * ┌── ADMIN SEES ALL; AN INSTRUCTOR SEES ONLY THEIR OWN. ────────────────┐
     * │ Ported from AnnouncementService::listForUser(), which branches on the │
     * │ role and calls listAll() or listByTeacher($userId).                   │
     * │                                                                       │
     * │ THE BRANCH IS A ROLE TEST AND NOT A Gate CALL, AND IT HAS TO BE:      │
     * │ `Gate::before` answers per-ROW abilities, and there is no ability     │
     * │ that means "widen a collection". A policy cannot express the          │
     * │ difference between two QUERIES. This is the same shape as             │
     * │ RevenueController's own role branch and is argued there at length.    │
     * │                                                                       │
     * │ NOTE what the instructor branch cannot see: PLATFORM announcements    │
     * │ (teacher_id NULL). `where teacher_id = <id>` excludes NULL in SQL,    │
     * │ which is correct — an admin's platform notice is not one of the       │
     * │ instructor's own drafts and must not appear in their management list. │
     * │ backend/'s listByTeacher() behaves identically.                       │
     * └───────────────────────────────────────────────────────────────────────┘
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Announcement::query()->orderByDesc('created_at');

        if ($user->role !== UserRole::Admin) {
            $query->where('teacher_id', (string) $user->getKey());
        }

        return ApiResponse::success([
            'announcements' => AnnouncementResource::collection($query->get()),
        ]);
    }

    /**
     * POST /api/v1/announcements -> 201. Row 110.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ AN ADMIN POSTING HERE IS A **201**, NOT A 500. THAT IS THE FIX.      ║
     * ║ Approved decision 3.2, implemented in                                 ║
     * ║ StoreAnnouncementRequest::authorTeacherId(): an admin author writes   ║
     * ║ `teacher_id = NULL` — a platform announcement — instead of their own  ║
     * ║ user id, which backend/ writes into a FK that has no matching         ║
     * ║ `teachers` row.                                                       ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * `is_published` DEFAULTS TO TRUE when the key is absent, matching
     * backend/'s `?? true` — NOT to the column default. The two agree today
     * (migration 30 also defaults true) and are written out anyway, because a
     * contract that lives only in a DDL default is one migration from changing
     * silently.
     */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $announcement = DB::transaction(function () use ($request): Announcement {
            $announcement = new Announcement();

            // setAttribute throughout, no $fillable, no create([...]): every
            // column written here has been audited, and no request key can
            // reach one that has not.
            $announcement->setAttribute('course_id', $request->course()?->getKey());
            $announcement->setAttribute('teacher_id', $request->authorTeacherId());
            $announcement->setAttribute('title', (string) $request->input('title'));
            $announcement->setAttribute('body', (string) $request->input('content'));
            $announcement->setAttribute('link_url', $request->normalisedLinkUrl());
            $announcement->setAttribute('start_date', $request->normalisedStartDate());
            $announcement->setAttribute('end_date', $request->normalisedEndDate());
            $announcement->setAttribute(
                'is_published',
                $request->boolean('is_published', true)
            );

            $announcement->save();

            return $announcement;
        });

        return ApiResponse::created(
            ['announcement' => new AnnouncementResource($announcement)],
            'تم إنشاء الإشعار بنجاح'
        );
    }

    /**
     * PATCH /api/v1/announcements/{id}. Row 111.
     *
     * ┌── PRESENCE, FIELD BY FIELD — backend/'s SIX array_key_exists TESTS. ─┐
     * │ An absent field is untouched. A present one is written even when its  │
     * │ value is falsy: `isPublished: false` unpublishes, `linkUrl: ""`       │
     * │ clears. `has()` is the Laravel spelling of array_key_exists on the    │
     * │ request payload, and it is what the whole PATCH contract rests on —   │
     * │ which is why the rules are `sometimes` and nothing reads a default.   │
     * │                                                                       │
     * │ `title` and `content` cannot arrive blank at all: decision 4's        │
     * │ `sometimes|required` rejected that at the Form Request. So this loop  │
     * │ never writes an empty title, and the branch that used to is gone.     │
     * └───────────────────────────────────────────────────────────────────────┘
     */
    public function update(UpdateAnnouncementRequest $request): JsonResponse
    {
        $announcement = $request->announcement();

        DB::transaction(function () use ($request, $announcement): void {
            if ($request->has('title')) {
                $announcement->setAttribute('title', (string) $request->input('title'));
            }

            if ($request->has('content')) {
                $announcement->setAttribute('body', (string) $request->input('content'));
            }

            if ($request->has('link_url')) {
                $announcement->setAttribute('link_url', $request->normalisedLinkUrl());
            }

            if ($request->has('start_date')) {
                $announcement->setAttribute('start_date', $request->normalisedStartDate());
            }

            if ($request->has('end_date')) {
                $announcement->setAttribute('end_date', $request->normalisedEndDate());
            }

            if ($request->has('is_published')) {
                $announcement->setAttribute('is_published', $request->boolean('is_published'));
            }

            $announcement->save();
        });

        return ApiResponse::success(
            ['announcement' => new AnnouncementResource($announcement)],
            'تم تحديث الإشعار بنجاح'
        );
    }

    /** DELETE /api/v1/announcements/{id} — HARD delete. Row 112. */
    public function destroy(DeleteAnnouncementRequest $request): JsonResponse
    {
        $request->announcement()->delete();

        return ApiResponse::success(null, 'تم حذف الإشعار بنجاح');
    }
}
