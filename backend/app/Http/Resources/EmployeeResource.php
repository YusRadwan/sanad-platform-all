<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\EmployeeAccountType;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * "إدارة الأدمنز" — the row shape ManageAdmins.tsx (frontend) renders,
 * `permissions` always a plain array (never `null` on the wire, even for an
 * Amin — the frontend badge/summary logic reads it unconditionally the same
 * way every other `?? []` JSON column in this codebase is presented).
 *
 * @mixin Employee
 */
final class EmployeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;

        return [
            'id' => $employee->getKey(),
            'name' => $employee->user?->full_name,
            'phone' => $employee->user?->username,
            'accountType' => $employee->account_type->value,
            'permissions' => $employee->account_type === EmployeeAccountType::Amin
                ? []
                : ($employee->permissions ?? []),
            'isActive' => (bool) $employee->is_active,
            'createdAt' => $employee->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
