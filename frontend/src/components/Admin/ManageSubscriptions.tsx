import { useState, useEffect, useMemo } from 'react';
import { motion } from 'motion/react';
import {
  CreditCard, Search, Filter, RefreshCw, FileSpreadsheet, X,
  UserCheck, ToggleLeft, ToggleRight, Users
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { downloadXlsx, XlsxColumn } from '../../utils/xlsxExport';
import { paymentMethodLabel } from '../../utils/paymentMethod';
import { formatNumber } from '../../utils/formatMoney';
import StatCard from '../Layout/StatCard';
import {
  PageHeader, Card, CardHeader, Button, Input, Select, FormField, Badge,
  Table, TableHeader, TableBody, TableRow, TableHead, TableCell, TableEmptyState, TableSkeleton,
  TablePagination,
} from '../UI';

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;

/**
 * جدول الاشتراكات (admin only).
 *
 * Design-system pass — presentation only. `flatten`, `activeFilters`,
 * `fetchSubscriptions`, `handleToggle`, `handleExport`, `clearFilters`,
 * `hasFilters`, the `stats` useMemo and ALL THREE `useEffect` bodies and
 * dependency arrays (`[phone, parentPhone, studentCode, teacherId, courseId]`
 * with its 350 ms debounce, `[]`, `[teacherId]`) are unchanged, as is the
 * `eslint-disable-next-line` above the debounced effect.
 * Non-existent palette shades used here (`slate-450`, `slate-505`,
 * `slate-850`, `slate-350`, `blue-550`, `emerald-850`, `emerald-350`,
 * `amber-850`) emitted no CSS and were replaced with real ones.
 *
 * ONE ROW PER SUBSCRIPTION — deliberately flat, matching the requested
 * column list. GET /admin/subscriptions answers student-centric
 * (`students[].subscriptions[]`, the shape AdminDashboardService::subscriptions
 * builds), so the rows are flattened here rather than changing that contract.
 *
 * All five filters are applied SERVER-side (query params on
 * /admin/subscriptions) so the Excel export always covers the complete
 * filtered set, never just what happens to be rendered.
 *
 * Note on "الصف الدراسي": this column shows the STUDENT's own grade level
 * (students.grade_level, captured at signup and reliably populated). The
 * course-side grade metadata (courses.grade_id -> grades.name) is NOT used
 * because the `grades` table has no write path anywhere in the system and is
 * empty, so it would render blank for every row.
 */

interface SubscriptionRow {
  studentId: string;
  studentCode: string;
  studentName: string;
  phone: string;
  parentPhone: string;
  gradeLevel: string;
  educationalStage: string;
  courseId: string;
  courseName: string;
  teacherName: string;
  subscribedAt: string;
  paymentMethod: string | null;
  isActive: boolean;
}

interface TeacherOption { id: string; name?: string; full_name?: string; username?: string }
interface CourseOption { id: string; title: string }


const EXPORT_COLUMNS: XlsxColumn[] = [
  { header: 'كود الطالب', width: 14 },
  { header: 'اسم الطالب', width: 24 },
  { header: 'رقم هاتف الطالب', width: 18 },
  { header: 'رقم هاتف ولي الأمر', width: 18 },
  { header: 'الصف الدراسي', width: 22 },
  { header: 'الكورس المشترك به', width: 32 },
  { header: 'المدرس', width: 22 },
  { header: 'تاريخ الاشتراك', width: 20 },
  { header: 'طريقة الدفع', width: 18 },
  { header: 'الحالة', width: 12 },
];

export default function ManageSubscriptions() {
  const [rows, setRows] = useState<SubscriptionRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);

  // --- filters (all server-side) ---
  const [phone, setPhone] = useState('');
  const [parentPhone, setParentPhone] = useState('');
  const [studentCode, setStudentCode] = useState('');
  const [teacherId, setTeacherId] = useState('');
  const [courseId, setCourseId] = useState('');

  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  const [courses, setCourses] = useState<CourseOption[]>([]);

  const { notify } = useCenteredNotification();
  const showToast = (text: string, type: 'success' | 'info' | 'warning' = 'success') => {
    notify(text, type === 'warning' ? 'error' : 'success');
  };

  const teacherLabel = (t: TeacherOption) => t.full_name || t.name || t.username || t.id;

  /** Flattens the student-centric response into one row per subscription. */
  const flatten = (students: any[]): SubscriptionRow[] => {
    const out: SubscriptionRow[] = [];
    (students ?? []).forEach((s: any) => {
      (s.subscriptions ?? []).forEach((sub: any) => {
        out.push({
          studentId: s.id ?? s.student_id,
          studentCode: s.studentCode ?? '—',
          studentName: s.name ?? '—',
          phone: s.phone ?? '—',
          parentPhone: s.parentPhone ?? '—',
          gradeLevel: s.gradeLevel || s.educationalStage || '—',
          educationalStage: s.educationalStage ?? '',
          courseId: sub.course_id ?? sub.courseId,
          courseName: sub.courseName ?? '—',
          teacherName: sub.teacherName ?? '—',
          subscribedAt: sub.subscribed_at ?? '—',
          paymentMethod: sub.paymentMethod ?? null,
          isActive: !!sub.isActive,
        });
      });
    });
    return out;
  };

  const activeFilters = () => ({
    phone,
    parent_phone: parentPhone,
    student_code: studentCode,
    teacher_id: teacherId,
    course_id: courseId,
  });

  const fetchSubscriptions = async () => {
    try {
      setLoading(true);
      const data = await adminService.getSubscriptions(activeFilters());
      setRows(flatten(data));
    } catch (err) {
      console.error('Failed to fetch subscriptions from backend:', err);
      setRows([]);
      showToast('تعذر تحميل بيانات الاشتراكات من الخادم', 'warning');
    } finally {
      setLoading(false);
    }
  };

  // Debounced refetch whenever any filter changes — the text inputs would
  // otherwise fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => { void fetchSubscriptions(); }, 350);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [phone, parentPhone, studentCode, teacherId, courseId]);

  // A filter change refetches from the server and can shrink the result set
  // below the current page number — reset to page 1 whenever the filters
  // change so the table never renders empty while real matches exist earlier.
  useEffect(() => {
    setPage(1);
  }, [phone, parentPhone, studentCode, teacherId, courseId]);

  // Teacher list for the picker.
  useEffect(() => {
    (async () => {
      try {
        setTeachers(await adminService.getAllTeachers());
      } catch {
        setTeachers([]);
      }
    })();
  }, []);

  // Course list: scoped to the chosen teacher, otherwise every course.
  useEffect(() => {
    (async () => {
      try {
        if (teacherId) {
          const data = await adminService.getTeacherCourses(teacherId);
          setCourses((data ?? []).map((c: any) => ({ id: c.id, title: c.title })));
        } else {
          const overview = await adminService.getCoursesOverview();
          setCourses((overview ?? []).map((c: any) => ({ id: c.id, title: c.title })));
        }
      } catch {
        setCourses([]);
      }
      setCourseId('');
    })();
  }, [teacherId]);

  /** Flips one subscription's active state via PUT /admin/enrollments/toggle. */
  const handleToggle = async (row: SubscriptionRow) => {
    const next = !row.isActive;
    try {
      await adminService.toggleEnrollmentStatus(row.studentId, row.courseId, next);
      setRows(prev => prev.map(r =>
        r.studentId === row.studentId && r.courseId === row.courseId ? { ...r, isActive: next } : r
      ));
      showToast(
        next
          ? `تم تفعيل اشتراك "${row.studentName}" في كورس "${row.courseName}"`
          : `تم إيقاف اشتراك "${row.studentName}" في كورس "${row.courseName}"`,
        next ? 'success' : 'info'
      );
    } catch (err) {
      console.error('Failed to toggle enrollment on server:', err);
      showToast('فشل في تحديث حالة الاشتراك بالخادم', 'warning');
    }
  };

  /**
   * Exports exactly what the current filters select. `rows` already IS the
   * complete filtered set (the server does the filtering and the table is
   * not paginated), so no second request is needed.
   */
  const handleExport = () => {
    if (rows.length === 0) {
      showToast('لا توجد بيانات لتصديرها بالفلاتر الحالية', 'warning');
      return;
    }
    const data = rows.map(r => [
      r.studentCode, r.studentName, r.phone, r.parentPhone, r.gradeLevel,
      r.courseName, r.teacherName, r.subscribedAt, paymentMethodLabel(r.paymentMethod),
      r.isActive ? 'نشط' : 'موقوف',
    ]);
    const stamp = new Date().toISOString().slice(0, 10);
    downloadXlsx(`جدول-الاشتراكات-${stamp}.xlsx`, EXPORT_COLUMNS, data, 'الاشتراكات');
    showToast(`تم تصدير ${rows.length} صفاً إلى ملف إكسيل`);
  };

  const clearFilters = () => {
    setPhone(''); setParentPhone(''); setStudentCode(''); setTeacherId(''); setCourseId('');
  };

  const hasFilters = !!(phone || parentPhone || studentCode || teacherId || courseId);

  const stats = useMemo(() => ({
    total: rows.length,
    active: rows.filter(r => r.isActive).length,
    students: new Set(rows.map(r => r.studentId)).size,
  }), [rows]);

  const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  const pagedRows = rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-44 left-1/4 w-[380px] h-[380px] bg-emerald-500/8 dark:bg-emerald-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-violet-500/6 dark:bg-violet-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* HEADER */}
      <PageHeader
        icon={CreditCard}
        title="جدول اشتراكات الطلاب"
        breadcrumbs={[{ label: 'منظومة تنظّيم وضبط المنصة العُليا' }, { label: 'جدول الاشتراكات' }]}
        description="إدارة اشتراكات الطلاب والكورسات"
        className="mb-0"
      />

      {/* STAT CARDS */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <StatCard
          index={0}
          icon={CreditCard}
          label="عدد الاشتراكات"
          value={formatNumber(stats.total)}
          color="blue"
        />
        <StatCard
          index={1}
          icon={UserCheck}
          label="الاشتراكات النشطة"
          value={formatNumber(stats.active)}
          color="emerald"
        />
        <StatCard
          index={2}
          icon={Users}
          label="عدد الطلاب"
          value={formatNumber(stats.students)}
          color="violet"
        />
      </div>


      {/* FILTERS — export/refresh now live in the filter card's own header,
          alongside "مسح الفلاتر", all in the same ghost/sm shape, instead of
          a separate action row above the card (same pattern as ManageUsers). */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0, duration: 0.35 }}
      >
      <Card className="space-y-4">
        <CardHeader
          title="أدوات البحث والفلترة"
          icon={Filter}
          action={
            <div className="flex flex-wrap items-center gap-2">
              {hasFilters && (
                <Button variant="ghost" size="sm" icon={X} onClick={clearFilters}>
                  مسح الفلاتر
                </Button>
              )}
              <Button variant="ghost" size="sm" icon={FileSpreadsheet} onClick={handleExport}>
                تصدير البيانات
              </Button>
              <Button variant="ghost" size="sm" onClick={() => void fetchSubscriptions()}>
                <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
                <span>تحديث</span>
              </Button>
            </div>
          }
        />

        <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
          <div className="md:col-span-3">
            <FormField label="رقم هاتف الطالب:">
              {({ id }) => (
                <Input
                  id={id} icon={Search} type="text" value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  placeholder="مثال: 01012345678"
                />
              )}
            </FormField>
          </div>

          <div className="md:col-span-3">
            <FormField label="رقم هاتف ولي الأمر:">
              {({ id }) => (
                <Input
                  id={id} icon={Search} type="text" value={parentPhone}
                  onChange={(e) => setParentPhone(e.target.value)}
                  placeholder="مثال: 01098765432"
                />
              )}
            </FormField>
          </div>

          <div className="md:col-span-2">
            <FormField label="كود الطالب:">
              {({ id }) => (
                <Input
                  id={id} icon={Search} type="text" value={studentCode}
                  onChange={(e) => setStudentCode(e.target.value)}
                  placeholder="مثال: 902900"
                />
              )}
            </FormField>
          </div>

          <div className="md:col-span-2">
            <FormField label="المدرس:">
              {({ id }) => (
                <Select id={id} value={teacherId} onChange={(e) => setTeacherId(e.target.value)}>
                  <option value="">كل المدرسين</option>
                  {teachers.map(t => <option key={t.id} value={t.id}>{teacherLabel(t)}</option>)}
                </Select>
              )}
            </FormField>
          </div>

          <div className="md:col-span-2">
            <FormField label="الكورس:">
              {({ id }) => (
                <Select id={id} value={courseId} onChange={(e) => setCourseId(e.target.value)}>
                  <option value="">كل الكورسات</option>
                  {courses.map(c => <option key={c.id} value={c.id}>{c.title}</option>)}
                </Select>
              )}
            </FormField>
          </div>
        </div>
      </Card>
      </motion.div>

      {/* TABLE */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.16, duration: 0.35 }}
      >
      <Card padding="none" className="overflow-hidden">
        <Table className="min-w-[1100px]">
          <TableHeader>
            <TableRow hoverable={false}>
              <TableHead>كود الطالب</TableHead>
              <TableHead>اسم الطالب</TableHead>
              <TableHead>رقم هاتف الطالب</TableHead>
              <TableHead>رقم هاتف ولي الأمر</TableHead>
              <TableHead>الصف الدراسي</TableHead>
              <TableHead>الكورس المشترك به</TableHead>
              <TableHead>المدرس</TableHead>
              <TableHead>تاريخ الاشتراك</TableHead>
              <TableHead>طريقة الدفع</TableHead>
              <TableHead>الحالة</TableHead>
              <TableHead>إجراء</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              <TableSkeleton columns={11} rows={6} />
            ) : rows.length === 0 ? (
              <TableEmptyState
                colSpan={11}
                icon={Users}
                title={hasFilters ? 'لا توجد اشتراكات مطابقة للفلاتر الحالية' : 'لا توجد اشتراكات مسجلة بعد'}
                description={hasFilters ? 'جرّب تعديل الفلاتر أو امسحها لعرض كل الاشتراكات.' : 'ستظهر الاشتراكات هنا بمجرد تسجيل أول طالب في كورس.'}
              />
            ) : pagedRows.map(row => (
              <TableRow key={`${row.studentId}-${row.courseId}`}>
                <TableCell emphasis className="font-mono whitespace-nowrap">{row.studentCode}</TableCell>
                <TableCell emphasis className="whitespace-nowrap">{row.studentName}</TableCell>
                <TableCell className="font-mono whitespace-nowrap" dir="ltr">{row.phone}</TableCell>
                <TableCell className="font-mono whitespace-nowrap" dir="ltr">{row.parentPhone}</TableCell>
                <TableCell className="whitespace-nowrap">{row.gradeLevel}</TableCell>
                <TableCell>{row.courseName}</TableCell>
                <TableCell className="whitespace-nowrap">{row.teacherName}</TableCell>
                <TableCell className="font-mono text-slate-500 dark:text-slate-400 whitespace-nowrap">{row.subscribedAt}</TableCell>
                <TableCell className="whitespace-nowrap">
                  <Badge tone="neutral">{paymentMethodLabel(row.paymentMethod)}</Badge>
                </TableCell>
                <TableCell className="whitespace-nowrap">
                  <Badge dot tone={row.isActive ? 'success' : 'neutral'}>
                    {row.isActive ? 'نشط' : 'موقوف'}
                  </Badge>
                </TableCell>
                <TableCell className="whitespace-nowrap">
                  <Button
                    size="sm"
                    variant={row.isActive ? 'warning' : 'success'}
                    icon={row.isActive ? ToggleLeft : ToggleRight}
                    onClick={() => void handleToggle(row)}
                  >
                    {row.isActive ? 'إيقاف' : 'تفعيل'}
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>

        {rows.length > 0 && (
          <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
            <TablePagination
              page={page}
              totalPages={totalPages}
              onPageChange={setPage}
              totalItems={rows.length}
              pageSize={PAGE_SIZE}
            />
          </div>
        )}
      </Card>
      </motion.div>
    </div>
  );
}
