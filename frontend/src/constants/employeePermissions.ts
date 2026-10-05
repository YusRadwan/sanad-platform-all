/**
 * "إدارة الأدمنز" (decisions 10.37 + 10.40) — mirrors backend
 * `App\Support\EmployeePermissions::CATALOG` EXACTLY (same keys, same
 * labels). This is the SINGLE frontend source of truth used by both
 * `Instructor/AdminManagement.tsx` + `InstructorSystem.tsx` (teacher-owned
 * employees, decision 10.37) and `Admin/ManageEmployees.tsx` +
 * `AdminSystem.tsx` (admin-owned employees, decision 10.40) — a future page
 * on either side gains a permission key by adding ONE row to the matching
 * list below (plus its backend twin), nothing else.
 *
 * TWO LISTS, NOT ONE: `admin_management.manage` is the one key that
 * legitimately appears in BOTH — it gates the SAME shared `/employees`
 * routes for either owner kind — but its sidebar target differs by context
 * (`/instructor/admin-management` vs `/admin/manage-employees`), so it
 * cannot be a single flat array keyed by permission key alone.
 *
 * `sidebarPath` is `null` for `dashboard.view` (the home page has no
 * protected data behind it and is always shown, matching
 * InstructorHome.tsx/AdminHome.tsx reading only the session payload already
 * in hand).
 */
export interface EmployeePermissionDef {
  key: string;
  label: string;
  sidebarPath: string | null;
}

/** Teacher-owned employees (decision 10.37) — mirrors INSTRUCTOR_NAV_GROUPS/INSTRUCTOR_NAV_CENTER in InstructorSystem.tsx. */
export const INSTRUCTOR_EMPLOYEE_PERMISSIONS: EmployeePermissionDef[] = [
  { key: 'dashboard.view', label: 'الصفحة الرئيسية', sidebarPath: null },
  { key: 'statistics.view', label: 'الإحصائيات', sidebarPath: '/instructor/statistics' },
  { key: 'courses.manage', label: 'إدارة الكورسات', sidebarPath: '/instructor/course-structure' },
  { key: 'groups.manage', label: 'المجموعات', sidebarPath: '/instructor/groups-management' },
  { key: 'videos.manage', label: 'الفيديوهات', sidebarPath: '/instructor/video-management' },
  { key: 'notes.manage', label: 'المذكرات', sidebarPath: '/instructor/notes-management' },
  { key: 'links.manage', label: 'اللينكات', sidebarPath: '/instructor/links-management' },
  { key: 'exams.manage', label: 'الامتحانات', sidebarPath: '/instructor/exams-assignments-management' },
  { key: 'exam_statistics.view', label: 'إحصائيات الامتحانات', sidebarPath: '/instructor/exam-statistics' },
  { key: 'watch_statistics.view', label: 'إحصائيات المشاهدات', sidebarPath: '/instructor/watch-statistics' },
  { key: 'question_groups.manage', label: 'مجاميع الأسئلة', sidebarPath: '/instructor/question-groups-management' },
  { key: 'question_bank.manage', label: 'بنك الأسئلة', sidebarPath: '/instructor/question-bank' },
  { key: 'subscriptions.view', label: 'جدول الاشتراكات', sidebarPath: '/instructor/subscriptions' },
  { key: 'revenue.view', label: 'إيراداتي', sidebarPath: '/instructor/revenue' },
  { key: 'center_groups.manage', label: 'إنشاء مجموعة سنتر', sidebarPath: '/instructor/center-groups' },
  { key: 'center_attendance.manage', label: 'حضور السنتر', sidebarPath: '/instructor/center-attendance' },
  { key: 'center_reports.view', label: 'تقارير طلاب السنتر', sidebarPath: '/instructor/center-reports' },
  { key: 'center_course_activation.manage', label: 'تفعيل كورس سنتر', sidebarPath: '/instructor/center-course-activation' },
  { key: 'center_payments.manage', label: 'متابعة مدفوعات السنتر', sidebarPath: '/instructor/center-payments' },
  { key: 'admin_management.manage', label: 'إدارة الأدمنز', sidebarPath: '/instructor/admin-management' },
];

/** Admin-owned employees (decision 10.40) — mirrors ADMIN_NAV in AdminSystem.tsx. */
export const ADMIN_EMPLOYEE_PERMISSIONS: EmployeePermissionDef[] = [
  { key: 'dashboard.view', label: 'الرئيسية', sidebarPath: null },
  { key: 'admin_statistics.view', label: 'إحصائيات المنصة', sidebarPath: '/admin/statistics' },
  { key: 'admin_broadcast.manage', label: 'بث الإشعارات العامة', sidebarPath: '/admin/broadcast-notifications' },
  { key: 'admin_add_teacher.manage', label: 'إضافة مدرس', sidebarPath: '/admin/add-teacher' },
  { key: 'admin_teacher_applications.view', label: 'طلب مدرس جديد', sidebarPath: '/admin/teacher-applications' },
  { key: 'admin_teachers.manage', label: 'إدارة المدرسين', sidebarPath: '/admin/manage-teachers' },
  { key: 'admin_management.manage', label: 'إدارة الأدمنز', sidebarPath: '/admin/manage-employees' },
  { key: 'admin_enrollment_codes.manage', label: 'إنشاء أكواد الاشتراك', sidebarPath: '/admin/enrollment-codes' },
  { key: 'admin_enrollment_code_details.view', label: 'تفاصيل كود الاشتراك', sidebarPath: '/admin/enrollment-code-details' },
  { key: 'admin_filter_enrollment_codes.view', label: 'فلتر أكواد الاشتراك', sidebarPath: '/admin/filter-enrollment-codes' },
  { key: 'admin_users.view', label: 'جدول المستخدمين', sidebarPath: '/admin/users' },
  { key: 'admin_subscriptions.manage', label: 'جداول الاشتراكات', sidebarPath: '/admin/manage-subscriptions' },
  { key: 'admin_student_code_details.view', label: 'تفاصيل كود الطالب', sidebarPath: '/admin/student-code-details' },
  { key: 'admin_reset_student_password.manage', label: 'تعيين كلمة سر الطالب', sidebarPath: '/admin/reset-student-password' },
  { key: 'admin_add_student.manage', label: 'إضافة طالب', sidebarPath: '/admin/add-student' },
  { key: 'admin_center_codes.manage', label: 'توليد أكواد السنتر', sidebarPath: '/admin/generate-center-codes' },
  { key: 'admin_center_code_details.view', label: 'تفاصيل كود سنتر', sidebarPath: '/admin/center-code-details' },
  { key: 'admin_profit_engine.view', label: 'محرك الأرباح', sidebarPath: '/admin/profit-engine' },
  { key: 'admin_teacher_payouts.manage', label: 'دفعات المدرسين', sidebarPath: '/admin/teacher-payouts' },
  { key: 'admin_instructor_statement.view', label: 'كشف حساب مدرس', sidebarPath: '/admin/instructor-statement' },
  { key: 'admin_refund_management.manage', label: 'إدارة الاسترجاع', sidebarPath: '/admin/refund-management' },
  { key: 'admin_revenue.view', label: 'إيرادات المنصة', sidebarPath: '/admin/revenue' },
  { key: 'admin_login_activity.view', label: 'مراقبة الدخول', sidebarPath: '/admin/login-activity' },
  { key: 'admin_platform_settings.manage', label: 'إعدادات المنصة', sidebarPath: '/admin/platform-settings' },
];
