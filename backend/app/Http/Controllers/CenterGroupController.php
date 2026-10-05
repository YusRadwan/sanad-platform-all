<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Center\ManageCenterGroupRequest;
use App\Http\Requests\Center\StoreCenterGroupRequest;
use App\Http\Resources\CenterGroupResource;
use App\Http\Resources\CenterSessionResource;
use App\Http\Responses\ApiResponse;
use App\Models\CenterGroup;
use App\Models\User;
use App\Support\CenterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "إنشاء مجموعة" — instructor's own center groups. `ownedBy()` scopes every
 * list; the caller's identity is always `$request->user()`, never a route
 * parameter or a body key — the same tenant-isolation shape as GroupController.
 */
final class CenterGroupController extends Controller
{
    /** GET /center/groups — the caller's own groups. Cascading selects (center -> grade -> group) are built client-side from this one flat list. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $groups = CenterGroup::query()
            ->ownedBy($user)
            ->with('schedules')
            ->orderBy('center_name')
            ->orderBy('grade_label')
            ->get();

        return ApiResponse::success(['groups' => CenterGroupResource::collection($groups)]);
    }

    public function store(StoreCenterGroupRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $group = DB::transaction(function () use ($request, $user): CenterGroup {
            $group = CenterGroup::query()->create(
                $request->validatedGroupData()
                + ['teacher_id' => $user->getKey()]
                + ['schedule_text' => $this->scheduleTextFrom($request->schedules())]
            );

            $this->syncSchedules($group, $request->schedules());

            return $group;
        });

        return ApiResponse::created(['group' => new CenterGroupResource($group->load('schedules'))], 'تم إنشاء المجموعة بنجاح');
    }

    public function update(ManageCenterGroupRequest $request): JsonResponse
    {
        $group = $request->group();

        DB::transaction(function () use ($request, $group): void {
            foreach (['center_name', 'grade_label', 'group_name', 'billing_type'] as $field) {
                if ($request->has($field)) {
                    $group->setAttribute($field, (string) $request->input($field));
                }
            }

            $schedules = $request->schedules();

            if ($schedules !== null) {
                $group->setAttribute('schedule_text', $this->scheduleTextFrom($schedules));
                $this->syncSchedules($group, $schedules);
            }

            $group->save();
        });

        return ApiResponse::success(['group' => new CenterGroupResource($group->load('schedules'))], 'تم تحديث المجموعة بنجاح');
    }

    public function destroy(ManageCenterGroupRequest $request): JsonResponse
    {
        $request->group()->delete();

        return ApiResponse::success(null, 'تم حذف المجموعة بنجاح');
    }

    /** GET /center/groups/{id}/sessions — for the "تقرير حصة" session picker. */
    public function sessions(Request $request, string $id): JsonResponse
    {
        $group = CenterAccess::findGroupOrFail($id);
        CenterAccess::assertOwnsGroup($group);

        return ApiResponse::success([
            'sessions' => CenterSessionResource::collection($group->sessions),
        ]);
    }

    /**
     * Replaces every `center_group_schedules` row for this group — small,
     * teacher-authored dataset (a handful of weekly slots at most), so a
     * delete-then-reinsert is simpler and just as correct as a diff/patch.
     *
     * @param list<array{day_of_week: string, time: string}> $slots
     */
    private function syncSchedules(CenterGroup $group, array $slots): void
    {
        DB::table('center_group_schedules')->where('center_group_id', $group->getKey())->delete();

        foreach ($slots as $index => $slot) {
            DB::table('center_group_schedules')->insert([
                'id' => (string) Str::uuid7(),
                'center_group_id' => $group->getKey(),
                'day_of_week' => $slot['day_of_week'],
                'time_of_day' => $slot['time'] . ':00',
                'order_index' => $index,
            ]);
        }
    }

    /**
     * "السبت 6:00 مساءً، الثلاثاء 4:00 مساءً" — the same shape `schedule_text`
     * always had, now built from real slots instead of admin-typed free
     * text. Every existing reader of `schedule_text` needs no change.
     *
     * @param list<array{day_of_week: string, time: string}> $slots
     */
    private function scheduleTextFrom(array $slots): string
    {
        return collect($slots)
            ->map(fn (array $slot): string => $slot['day_of_week'] . ' ' . $this->formatTimeArabic($slot['time']))
            ->implode('، ');
    }

    /** "18:00" -> "6:00 مساءً". "00:05" -> "12:05 صباحاً". */
    private function formatTimeArabic(string $time24): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time24));

        $period = $hour >= 12 ? 'مساءً' : 'صباحاً';
        $hour12 = $hour % 12;

        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return sprintf('%d:%02d %s', $hour12, $minute, $period);
    }
}
