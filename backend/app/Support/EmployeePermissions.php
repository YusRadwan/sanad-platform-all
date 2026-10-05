<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ContentItemType;
use App\Enums\EmployeeAccountType;
use App\Models\Employee;

/**
 * "إدارة الأدمنز" (decision 10.37) — the ONE catalog of permission keys, a
 * PHP constant rather than a database table so a future instructor page can
 * gain a permission key with a one-line addition here (plus its mirror in
 * `frontend/src/constants/employeePermissions.ts`) and NOTHING else — no
 * migration, no seed data, and — the whole point of this being a static
 * catalog — an EXISTING employee's `permissions` JSON array is untouched by
 * the addition, so they never acquire the new page automatically. That is
 * the literal mechanism behind "a future page must not grant itself".
 *
 * Every key is `<area>.<verb>`, matching one row of
 * `frontend/src/components/InstructorSystem.tsx`'s `INSTRUCTOR_NAV_MAIN`/
 * `INSTRUCTOR_NAV_CENTER` — see the CATALOG array below for the exact
 * mapping. Multiple sidebar entries that hit the SAME underlying route
 * family (e.g. "إدارة الكورسات" and "إضافة وتعديل الكورسات" both write
 * through `CourseController`) share one key on purpose: splitting them
 * would invent a distinction the API itself does not have.
 */
final class EmployeePermissions
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const STATISTICS_VIEW = 'statistics.view';

    public const COURSES_MANAGE = 'courses.manage';

    public const GROUPS_MANAGE = 'groups.manage';

    public const VIDEOS_MANAGE = 'videos.manage';

    public const NOTES_MANAGE = 'notes.manage';

    public const LINKS_MANAGE = 'links.manage';

    public const EXAMS_MANAGE = 'exams.manage';

    public const EXAM_STATISTICS_VIEW = 'exam_statistics.view';

    public const WATCH_STATISTICS_VIEW = 'watch_statistics.view';

    public const QUESTION_GROUPS_MANAGE = 'question_groups.manage';

    public const QUESTION_BANK_MANAGE = 'question_bank.manage';

    public const SUBSCRIPTIONS_VIEW = 'subscriptions.view';

    public const REVENUE_VIEW = 'revenue.view';

    public const CENTER_GROUPS_MANAGE = 'center_groups.manage';

    public const CENTER_ATTENDANCE_MANAGE = 'center_attendance.manage';

    public const CENTER_REPORTS_VIEW = 'center_reports.view';

    public const CENTER_COURSE_ACTIVATION_MANAGE = 'center_course_activation.manage';

    public const CENTER_PAYMENTS_MANAGE = 'center_payments.manage';

    public const ADMIN_MANAGEMENT_MANAGE = 'admin_management.manage';

    // ── ADMIN-DOMAIN KEYS (decision 10.40) — mirror AdminSystem.tsx's own
    // `ADMIN_NAV`, the same way the keys above mirror InstructorSystem.tsx's.
    // `ADMIN_MANAGEMENT_MANAGE` above is REUSED as-is for the admin section's
    // own "إدارة الأدمنز" entry — it already gates the shared `/employees`
    // routes for either owner kind, so a second key would just be another
    // name for the same gate. `admin.` (dashboard, no gate — see
    // AdminHome.tsx, which like InstructorHome.tsx reads only the session
    // payload already in hand) is likewise absent on purpose, matching
    // `DASHBOARD_VIEW`'s own precedent.

    public const ADMIN_STATISTICS_VIEW = 'admin_statistics.view';

    public const ADMIN_BROADCAST_MANAGE = 'admin_broadcast.manage';

    public const ADMIN_ADD_TEACHER_MANAGE = 'admin_add_teacher.manage';

    public const ADMIN_TEACHER_APPLICATIONS_VIEW = 'admin_teacher_applications.view';

    public const ADMIN_TEACHERS_MANAGE = 'admin_teachers.manage';

    public const ADMIN_ENROLLMENT_CODES_MANAGE = 'admin_enrollment_codes.manage';

    public const ADMIN_ENROLLMENT_CODE_DETAILS_VIEW = 'admin_enrollment_code_details.view';

    public const ADMIN_FILTER_ENROLLMENT_CODES_VIEW = 'admin_filter_enrollment_codes.view';

    public const ADMIN_USERS_VIEW = 'admin_users.view';

    public const ADMIN_SUBSCRIPTIONS_MANAGE = 'admin_subscriptions.manage';

    public const ADMIN_STUDENT_CODE_DETAILS_VIEW = 'admin_student_code_details.view';

    public const ADMIN_RESET_STUDENT_PASSWORD_MANAGE = 'admin_reset_student_password.manage';

    public const ADMIN_ADD_STUDENT_MANAGE = 'admin_add_student.manage';

    public const ADMIN_CENTER_CODES_MANAGE = 'admin_center_codes.manage';

    public const ADMIN_CENTER_CODE_DETAILS_VIEW = 'admin_center_code_details.view';

    public const ADMIN_PROFIT_ENGINE_VIEW = 'admin_profit_engine.view';

    public const ADMIN_TEACHER_PAYOUTS_MANAGE = 'admin_teacher_payouts.manage';

    public const ADMIN_INSTRUCTOR_STATEMENT_VIEW = 'admin_instructor_statement.view';

    public const ADMIN_REFUND_MANAGEMENT_MANAGE = 'admin_refund_management.manage';

    public const ADMIN_REVENUE_VIEW = 'admin_revenue.view';

    public const ADMIN_LOGIN_ACTIVITY_VIEW = 'admin_login_activity.view';

    public const ADMIN_PLATFORM_SETTINGS_MANAGE = 'admin_platform_settings.manage';

    /**
     * The full, ordered catalog — `key => Arabic label`, ordered to match the
     * sidebar's own top-to-bottom order. `hasPermission()`/the Store/Update
     * Employee Form Requests validate every incoming key against this list —
     * an unknown key is always rejected, never silently stored.
     *
     * @var array<string, string>
     */
    public const CATALOG = [
        self::DASHBOARD_VIEW => 'الصفحة الرئيسية',
        self::STATISTICS_VIEW => 'الإحصائيات',
        self::COURSES_MANAGE => 'إدارة الكورسات',
        self::GROUPS_MANAGE => 'المجموعات',
        self::VIDEOS_MANAGE => 'الفيديوهات',
        self::NOTES_MANAGE => 'المذكرات',
        self::LINKS_MANAGE => 'اللينكات',
        self::EXAMS_MANAGE => 'الامتحانات',
        self::EXAM_STATISTICS_VIEW => 'إحصائيات الامتحانات',
        self::WATCH_STATISTICS_VIEW => 'إحصائيات المشاهدات',
        self::QUESTION_GROUPS_MANAGE => 'مجاميع الأسئلة',
        self::QUESTION_BANK_MANAGE => 'بنك الأسئلة',
        self::SUBSCRIPTIONS_VIEW => 'جدول الاشتراكات',
        self::REVENUE_VIEW => 'إيراداتي',
        self::CENTER_GROUPS_MANAGE => 'إنشاء مجموعة سنتر',
        self::CENTER_ATTENDANCE_MANAGE => 'حضور السنتر',
        self::CENTER_REPORTS_VIEW => 'تقارير طلاب السنتر',
        self::CENTER_COURSE_ACTIVATION_MANAGE => 'تفعيل كورس سنتر',
        self::CENTER_PAYMENTS_MANAGE => 'متابعة مدفوعات السنتر',
        self::ADMIN_MANAGEMENT_MANAGE => 'إدارة الأدمنز',

        // ── ADMIN-DOMAIN (decision 10.40) ──────────────────────────────────
        self::ADMIN_STATISTICS_VIEW => 'إحصائيات المنصة',
        self::ADMIN_BROADCAST_MANAGE => 'بث الإشعارات العامة',
        self::ADMIN_ADD_TEACHER_MANAGE => 'إضافة مدرس',
        self::ADMIN_TEACHER_APPLICATIONS_VIEW => 'طلب مدرس جديد',
        self::ADMIN_TEACHERS_MANAGE => 'إدارة المدرسين',
        self::ADMIN_ENROLLMENT_CODES_MANAGE => 'إنشاء أكواد الاشتراك',
        self::ADMIN_ENROLLMENT_CODE_DETAILS_VIEW => 'تفاصيل كود الاشتراك',
        self::ADMIN_FILTER_ENROLLMENT_CODES_VIEW => 'فلتر أكواد الاشتراك',
        self::ADMIN_USERS_VIEW => 'جدول المستخدمين',
        self::ADMIN_SUBSCRIPTIONS_MANAGE => 'جداول الاشتراكات',
        self::ADMIN_STUDENT_CODE_DETAILS_VIEW => 'تفاصيل كود الطالب',
        self::ADMIN_RESET_STUDENT_PASSWORD_MANAGE => 'تعيين كلمة سر الطالب',
        self::ADMIN_ADD_STUDENT_MANAGE => 'إضافة طالب',
        self::ADMIN_CENTER_CODES_MANAGE => 'توليد أكواد السنتر',
        self::ADMIN_CENTER_CODE_DETAILS_VIEW => 'تفاصيل كود سنتر',
        self::ADMIN_PROFIT_ENGINE_VIEW => 'محرك الأرباح',
        self::ADMIN_TEACHER_PAYOUTS_MANAGE => 'دفعات المدرسين',
        self::ADMIN_INSTRUCTOR_STATEMENT_VIEW => 'كشف حساب مدرس',
        self::ADMIN_REFUND_MANAGEMENT_MANAGE => 'إدارة الاسترجاع',
        self::ADMIN_REVENUE_VIEW => 'إيرادات المنصة',
        self::ADMIN_LOGIN_ACTIVITY_VIEW => 'مراقبة الدخول',
        self::ADMIN_PLATFORM_SETTINGS_MANAGE => 'إعدادات المنصة',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * The permission key that gates ONE content item's type. Content items
     * (videos/notes/links/exams/files) share ONE generic route family
     * (`/content-items/{id}`, `/groups/{groupId}/content-items`, …) — the
     * route itself cannot distinguish "the فيديوهات page" from "the
     * المذكرات page" the way every other permission key can, because that
     * is genuinely the SAME endpoint for all four. This is why
     * ContentItemController calls this directly (via
     * EmployeeSession::hasPermission()) instead of a blanket
     * `employee.permission:<key>` route middleware — the key can only be
     * known once the item's OWN `type` is in hand.
     *
     * `File` rides along with `Note` — the schema itself has no detail table
     * for it (a plain file IS a note row with no body), and NotesManagement.
     * tsx is the one frontend page that manages both.
     */
    public static function keyForContentType(ContentItemType $type): string
    {
        return match ($type) {
            ContentItemType::Video => self::VIDEOS_MANAGE,
            ContentItemType::Note, ContentItemType::File => self::NOTES_MANAGE,
            ContentItemType::Link => self::LINKS_MANAGE,
            ContentItemType::Exam => self::EXAMS_MANAGE,
        };
    }

    /**
     * Does this employee row grant `$key`? An Amin is `true` for EVERY key —
     * the ONLY place that rule is expressed — because an Amin's access is
     * "full teacher permissions", never an enumerable list; its own
     * `permissions` column is `null` and is never consulted here.
     */
    public static function grants(Employee $employee, string $key): bool
    {
        if ($employee->account_type === EmployeeAccountType::Amin) {
            return true;
        }

        $permissions = $employee->permissions ?? [];

        return \in_array($key, $permissions, true);
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
