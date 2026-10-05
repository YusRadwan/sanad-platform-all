<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BroadcastAudience;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Port of backend/BroadcastNotificationService::resolveRecipients()
 * (~lines 298-337) — an audience turned into a concrete, VERIFIED list of
 * `users.id`.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS RUNS **BEFORE** THE WRITE TRANSACTION OPENS. THAT IS THE DESIGN.   ║
 * ║                                                                          ║
 * ║ Every rejection below is a 422 with NOTHING written — no broadcast row,   ║
 * ║ no notification rows. Resolving inside the transaction would technically  ║
 * ║ roll back, but it would also mean a request that is knowably invalid      ║
 * ║ acquires row locks on `broadcast_notifications` and fans out thousands of ║
 * ║ inserts before discovering it. Validate first, then write.                ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── STRICT, NOT BEST-EFFORT, FOR THE TWO EXPLICIT-TARGET AUDIENCES. ───────┐
 * │ A single unknown, inactive or wrong-role id in `selected_teachers`       │
 * │ rejects the WHOLE request. The alternative — deliver to the ids that     │
 * │ resolved and quietly drop the rest — is worse than it sounds: the admin  │
 * │ sees a success message, the broadcast reports a recipients_count they    │
 * │ have no reason to check, and the teachers who were silently dropped are  │
 * │ exactly the ones an announcement was aimed at.                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class BroadcastAudienceResolver
{
    /** Which user role each audience is allowed to resolve to. */
    private const AUDIENCE_ROLES = [
        BroadcastAudience::AllTeachers->value => [UserRole::Instructor],
        BroadcastAudience::AllStaff->value => [UserRole::Admin],
        BroadcastAudience::SelectedTeachers->value => [UserRole::Instructor],
        BroadcastAudience::SpecificUser->value => [UserRole::Admin, UserRole::Instructor, UserRole::Student],
    ];

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE CAP APPLIES TO `selected_teachers` ONLY. THE ASYMMETRY IS        ║
     * ║ backend/'s AND IT IS CARRIED FORWARD DELIBERATELY. (Open decision    ║
     * ║ 8.8.)                                                                ║
     * ║                                                                      ║
     * ║ `all_teachers` and `all_staff` have NO cap — a platform with 5,000    ║
     * ║ instructors fans out 5,000 inserts and backend/ never objected.       ║
     * ║ `selected_teachers` is capped at 500 because it is the only audience  ║
     * ║ whose size the CALLER controls: the id list arrives in the request    ║
     * ║ body, so an unbounded array is a request-size and memory question     ║
     * ║ before it is a delivery question. The "all" audiences are bounded by  ║
     * ║ the platform's own user count, which no request can inflate.          ║
     * ║                                                                      ║
     * ║ That is the honest reading of why the asymmetry is defensible; it is  ║
     * ║ NOT a claim that an uncapped 50,000-instructor fan-out is fine.       ║
     * ║ Recorded as a scaling risk, not silently "fixed" by inventing a cap   ║
     * ║ that would start rejecting a legitimate platform-wide announcement.   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public const MAX_SELECTED_TEACHERS = 500;

    public const EMPTY_AUDIENCE = 'لا يوجد أي مستخدم ضمن الفئة المستهدفة حالياً';

    public const MISSING_TEACHERS_PREFIX = 'بعض المعرفات المختارة غير موجودة أو ليست لمعلمين نشطين: ';

    /**
     * backend/ joins the offending ids with an ARABIC COMMA followed by a
     * space (U+060C, ' '), not a Latin ', '. Pinned as a constant because it
     * is invisible in a diff and the admin screen prints the string verbatim.
     */
    public const MISSING_SEPARATOR = '، ';

    public const NO_SUCH_USER = 'لا يوجد مستخدم نشط بهذا المعرف أو الكنية';

    /**
     * @param  list<string>  $targetUserIds
     * @return array{recipients: list<string>, targetUserIds: ?list<string>}
     *
     * @throws ValidationException
     */
    public static function resolve(
        BroadcastAudience $audience,
        array $targetUserIds,
        ?string $specificUserInput,
    ): array {
        return match ($audience) {
            BroadcastAudience::AllTeachers,
            BroadcastAudience::AllStaff => [
                'recipients' => self::byRoles($audience),
                // NULL for the two "all" audiences: the recipient set is a
                // QUERY, not a stored list, so freezing today's ids into the
                // column would make the row lie the moment a teacher is added.
                'targetUserIds' => null,
            ],

            BroadcastAudience::SelectedTeachers => (function () use ($targetUserIds): array {
                $found = self::verifySelected($targetUserIds);

                return ['recipients' => $found, 'targetUserIds' => $found];
            })(),

            BroadcastAudience::SpecificUser => (function () use ($specificUserInput): array {
                $id = self::findSpecific((string) $specificUserInput);

                return ['recipients' => [$id], 'targetUserIds' => [$id]];
            })(),
        };
    }

    /**
     * Every ACTIVE, non-deleted user holding the audience's role.
     *
     * `whereNull('deleted_at')` is applied by the model's SoftDeletes trait, so
     * a soft-deleted instructor is never a recipient — backend/ has no soft
     * deletes on `users` and relied on `is_active = 1` alone. The stricter
     * predicate is not a behaviour change in any state backend/ can reach; it
     * closes a state backend-v2 CAN.
     *
     * @return list<string>
     */
    private static function byRoles(BroadcastAudience $audience): array
    {
        $recipients = self::activeUsersWithRoles(self::AUDIENCE_ROLES[$audience->value])
            ->orderBy('created_at')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if ($recipients === []) {
            // A broadcast to nobody is not a broadcast. backend/ rejects it
            // rather than storing an addressed-to-no-one row that would report
            // recipients_count 0 and look like a delivery failure forever.
            throw ValidationException::withMessages(['audience' => [self::EMPTY_AUDIENCE]]);
        }

        return $recipients;
    }

    /**
     * The strict path. Ports backend/'s existingUserIdsWithRoles() + array_diff.
     *
     * ┌── THE ORDER OF THE RETURNED LIST IS THE **CALLER'S**, NOT THE DB's. ─┐
     * │ backend/ returns whatever order MySQL gave the `IN (…)` lookup, and   │
     * │ that order is then json_encoded into target_user_ids and rendered by  │
     * │ the admin card. Preserving the caller's order instead is the one      │
     * │ difference here, and it is invisible on the wire except that the      │
     * │ picker's checkboxes come back in the order they were ticked.          │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function verifySelected(array $ids): array
    {
        $found = self::activeUsersWithRoles(self::AUDIENCE_ROLES[BroadcastAudience::SelectedTeachers->value])
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $missing = array_values(array_diff($ids, $found));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'target_user_ids' => [
                    self::MISSING_TEACHERS_PREFIX . implode(self::MISSING_SEPARATOR, $missing),
                ],
            ]);
        }

        // array_values(array_intersect(...)) — the caller's order, deduplicated
        // upstream by the Form Request.
        return array_values(array_intersect($ids, $found));
    }

    /**
     * The free-text box accepts EITHER a username OR a raw user id, because
     * the admin screen's field says so ("اسم المعرف أو كنية المستخدم").
     *
     * Any active user of any role qualifies — this is the one audience that can
     * target a STUDENT, which is why AUDIENCE_ROLES lists all three.
     */
    private static function findSpecific(string $needle): string
    {
        $user = self::activeUsersWithRoles(self::AUDIENCE_ROLES[BroadcastAudience::SpecificUser->value])
            ->where(function ($query) use ($needle): void {
                $query->where('username', $needle)->orWhere('id', $needle);
            })
            ->first();

        if ($user === null) {
            throw ValidationException::withMessages(['specific_user' => [self::NO_SUCH_USER]]);
        }

        return (string) $user->getKey();
    }

    /**
     * @param  list<UserRole>  $roles
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private static function activeUsersWithRoles(array $roles): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->whereIn('role', array_map(static fn (UserRole $r): string => $r->value, $roles))
            ->where('is_active', true);
    }
}
