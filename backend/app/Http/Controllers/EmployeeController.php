<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Employee\CreateEmployee;
use App\Actions\Employee\DeleteEmployee;
use App\Actions\Employee\UpdateEmployee;
use App\Exceptions\NotFoundException;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "إدارة الأدمنز" (decisions 10.37 + 10.40) — CRUD for the CALLING owner's
 * own employees/amins only. ONE route family serves BOTH owner kinds:
 * a teacher managing their own staff (10.37), and an admin managing THEIR
 * own staff, isolated per admin (10.40, "موظفو الأدمن منفصلون عن المدرّسين
 * كلياً"). Nothing below branches on which — it never needs to.
 *
 * ┌── `$request->user()` IS ALWAYS THE REAL OWNER (TEACHER OR ADMIN). ────────┐
 * │ Whether the caller is the owner themselves, an Amin, or a limited        │
 * │ Employee holding `admin_management.manage`, App\Actions\Auth\LoginUser's │
 * │ identity swap already made `$request->user()->id` the owner's own id —   │
 * │ so `Employee::scopeOwnedBy($request->user())` is correct UNCONDITIONALLY,│
 * │ with no branching on who is actually signed in OR which role owns this   │
 * │ particular employee. `EmployeeSession::actingEmployee($request)` is      │
 * │ consulted SEPARATELY, only to enforce the privilege-ceiling rules        │
 * │ (App\Support\EmployeePrivilegeCeiling) — never to change which rows are   │
 * │ in scope.                                                                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Route access itself (`role:admin,instructor` + `employee.permission:
 * admin_management.manage`) is the coarse gate; everything below is the
 * fine-grained "which employee, which fields" layer on top of it.
 */
final class EmployeeController extends Controller
{
    /** GET /api/v1/employees — every employee/amin belonging to this owner. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        $employees = Employee::query()
            ->ownedBy($owner)
            ->with('user')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success([
            'employees' => EmployeeResource::collection($employees),
        ]);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployee $create): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        $employee = $create(
            $owner,
            EmployeeSession::actingEmployee($request),
            $request->validated(),
            $request->accountType(),
            $request->permissions(),
        );

        $employee->loadMissing('user');

        return ApiResponse::created(
            new EmployeeResource($employee),
            'تم إضافة الموظف بنجاح'
        );
    }

    public function update(UpdateEmployeeRequest $request, UpdateEmployee $update): JsonResponse
    {
        $employee = $update(
            $request->employee(),
            EmployeeSession::actingEmployee($request),
            $request->validated(),
            $request->has('name'),
            $request->has('phone'),
            $request->has('password'),
            $request->accountType(),
            $request->permissions(),
            $request->has('isActive'),
        );

        $employee->loadMissing('user');

        return ApiResponse::success(
            new EmployeeResource($employee),
            'تم تحديث بيانات الموظف بنجاح'
        );
    }

    public function destroy(Request $request, string $id, DeleteEmployee $delete): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        $employee = Employee::query()
            ->ownedBy($owner)
            ->whereKey($id)
            ->first()
            ?? throw new NotFoundException('الموظف غير موجود');

        $delete($employee, EmployeeSession::actingEmployee($request));

        return ApiResponse::success(null, 'تم حذف الموظف بنجاح');
    }
}
