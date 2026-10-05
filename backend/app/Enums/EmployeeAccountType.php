<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * employees.account_type — "أمين" (Amin, full teacher permissions within the
 * owning teacher's own scope) vs "موظف" (Employee, only the page-level
 * permission keys explicitly granted). See App\Support\EmployeePermissions
 * for how these two are actually consulted — an Amin row's own `permissions`
 * column is `null` and MUST NEVER be read for one; this enum value is the
 * ONLY thing that decides "does this account bypass the permission list".
 */
enum EmployeeAccountType: string
{
    use HasValues;

    case Amin = 'amin';
    case Employee = 'employee';
}
