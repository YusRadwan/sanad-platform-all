<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\CenterGroup;
use App\Models\CenterSession;
use Illuminate\Support\Facades\Gate;

/**
 * The find/authorize half of the Center System, mirroring App\Support\
 * CourseAccess's shape: policies decide yes/no, this decides which exception
 * carries which message, and every route resolves through here rather than
 * re-typing the ownership check.
 */
final class CenterAccess
{
    public const GROUP_NOT_FOUND = 'المجموعة غير موجودة';

    public const SESSION_NOT_FOUND = 'الحصة غير موجودة';

    public const NOT_OWNER = 'هذه المجموعة لا تخصك';

    public const SESSION_NOT_OWNER = 'هذه الحصة لا تخصك';

    public static function findGroupOrFail(?string $id): CenterGroup
    {
        return CenterGroup::query()->whereKey((string) $id)->first()
            ?? throw new NotFoundException(self::GROUP_NOT_FOUND);
    }

    public static function assertOwnsGroup(CenterGroup $group): void
    {
        if (Gate::denies('manage', $group)) {
            throw new ForbiddenException(self::NOT_OWNER);
        }
    }

    public static function findSessionOrFail(?string $id): CenterSession
    {
        return CenterSession::query()->whereKey((string) $id)->first()
            ?? throw new NotFoundException(self::SESSION_NOT_FOUND);
    }

    public static function assertOwnsSession(CenterSession $session): void
    {
        if (Gate::denies('manage', $session)) {
            throw new ForbiddenException(self::SESSION_NOT_OWNER);
        }
    }
}
