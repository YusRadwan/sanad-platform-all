import { useState, useEffect, useMemo } from 'react';
import { motion } from 'motion/react';
import {
  ListFilter, Search, Filter, RefreshCw, X, KeyRound,
  CheckCircle2, Circle,
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import { formatNumber } from '../../utils/formatMoney';
import StatCard from '../Layout/StatCard';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  PageHeader, Card, CardHeader, Button, Input, Select, FormField, Badge,
  Table, TableHeader, TableBody, TableRow, TableHead, TableCell, TableEmptyState, TableSkeleton,
  TablePagination,
} from '../UI';

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;

/**
 * فلتر أكواد الاشتراك (admin only) — GET /admin/enrollment-codes/search.
 *
 * Presentation and data-fetching follow ManageSubscriptions.tsx's already
 * "design-system pass" pattern deliberately: same filter-card shape, same
 * stat-pill placement in PageHeader, same Table/TableSkeleton/TableEmptyState/
 * TablePagination kit, same 350ms debounce on the five text filters. All
 * filtering happens SERVER-side, so the three stat cards below reflect the
 * complete filtered set, not just the current page.
 *
 * ONE ROW PER CODE — EnrollmentCodeResource already carries every column the
 * table needs (code, course_title, teacher_name, used_by_name, used_at,
 * created_at, is_used), so no client-side reshaping is required.
 */

interface TeacherOption { id: string; name?: string; full_name?: string; username?: string }

interface EnrollmentCodeRow {
  id: string;
  code: string;
  course_title: string | null;
  teacher_name: string | null;
  is_used: boolean;
  used_by_name: string | null;
  used_at: string | null;
  created_at: string | null;
}

export default function FilterEnrollmentCodes() {
  const [rows, setRows] = useState<EnrollmentCodeRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);

  // --- filters (all server-side) ---
  const [code, setCode] = useState('');
  const [teacherId, setTeacherId] = useState('');
  const [studentName, setStudentName] = useState('');
  const [studentPhone, setStudentPhone] = useState('');
  const [parentPhone, setParentPhone] = useState('');

  const [teachers, setTeachers] = useState<TeacherOption[]>([]);

  const { notify } = useCenteredNotification();

  const teacherLabel = (t: TeacherOption) => t.full_name || t.name || t.username || t.id;

  const activeFilters = () => ({
    code,
    teacher_id: teacherId,
    student_name: studentName,
    student_phone: studentPhone,
    parent_phone: parentPhone,
  });

  const fetchCodes = async () => {
    try {
      setLoading(true);
      const data = await adminService.searchEnrollmentCodes(activeFilters());
      setRows(data ?? []);
    } catch (err) {
      console.error('Failed to fetch enrollment codes from backend:', err);
      setRows([]);
      notify('تعذر تحميل بيانات أكواد الاشتراك من الخادم', 'error');
    } finally {
      setLoading(false);
    }
  };

  // Debounced refetch whenever any filter changes — the text inputs would
  // otherwise fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => { void fetchCodes(); }, 350);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code, teacherId, studentName, studentPhone, parentPhone]);

  // A filter change refetches from the server and can shrink the result set
  // below the current page number — reset to page 1 whenever the filters
  // change so the table never renders empty while real matches exist earlier.
  useEffect(() => {
    setPage(1);
  }, [code, teacherId, studentName, studentPhone, parentPhone]);

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

  const clearFilters = () => {
    setCode(''); setTeacherId(''); setStudentName(''); setStudentPhone(''); setParentPhone('');
  };

  const hasFilters = !!(code || teacherId || studentName || studentPhone || parentPhone);

  const stats = useMemo(() => ({
    total: rows.length,
    used: rows.filter(r => r.is_used).length,
    unused: rows.filter(r => !r.is_used).length,
  }), [rows]);

  const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  const pagedRows = rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-amber-500/8 dark:bg-amber-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* HEADER */}
      <PageHeader
        icon={ListFilter}
        title="فلتر أكواد الاشتراك"
        breadcrumbs={[{ label: 'المستخدمون والاشتراكات' }, { label: 'فلتر أكواد الاشتراك' }]}
        description="بحث وفلترة أكواد الاشتراك"
        className="mb-0"
      />

      {/* STAT CARDS */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <StatCard
          index={0}
          icon={Filter}
          label="عدد الأكواد المفلترة"
          value={formatNumber(stats.total)}
          color="blue"
        />
        <StatCard
          index={1}
          icon={CheckCircle2}
          label="الأكواد المستخدمة"
          value={formatNumber(stats.used)}
          color="emerald"
        />
        <StatCard
          index={2}
          icon={Circle}
          label="الأكواد غير المستخدمة"
          value={formatNumber(stats.unused)}
          color="amber"
        />
      </div>

      {/* FILTERS — the "تحديث" button now lives here too, alongside "مسح
          الفلاتر", both in the same ghost/sm shape, instead of standing alone
          in its own row above the card. */}
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
            <div className="flex items-center gap-2">
              {hasFilters && (
                <Button variant="ghost" size="sm" icon={X} onClick={clearFilters}>
                  مسح الفلاتر
                </Button>
              )}
              <Button variant="ghost" size="sm" onClick={() => void fetchCodes()}>
                <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
                <span>تحديث</span>
              </Button>
            </div>
          }
        />

        <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
          <div className="md:col-span-3">
            <FormField label="رقم الكود:">
              {({ id }) => (
                <Input
                  id={id} icon={Search} type="text" value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="مثال: 12345678"
                />
              )}
            </FormField>
          </div>

          <div className="md:col-span-3">
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
            <FormField label="اسم الطالب:">
              {({ id }) => (
                <Input
                  id={id} icon={Search} type="text" value={studentName}
                  onChange={(e) => setStudentName(e.target.value)}
                  placeholder="اسم الطالب"
                />
              )}
            </FormField>
          </div>

          <div className="md:col-span-2">
            <FormField label="رقم الطالب:">
              {({ id }) => (
                <Input
                  id={id} icon={Search} type="text" value={studentPhone}
                  onChange={(e) => setStudentPhone(e.target.value)}
                  placeholder="مثال: 01012345678"
                />
              )}
            </FormField>
          </div>

          <div className="md:col-span-2">
            <FormField label="رقم ولي الأمر:">
              {({ id }) => (
                <Input
                  id={id} icon={Search} type="text" value={parentPhone}
                  onChange={(e) => setParentPhone(e.target.value)}
                  placeholder="مثال: 01098765432"
                />
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
        <Table className="min-w-[1000px]">
          <TableHeader>
            <TableRow hoverable={false}>
              <TableHead>الكود</TableHead>
              <TableHead>اسم الطالب</TableHead>
              <TableHead>الكورس</TableHead>
              <TableHead>اسم المدرس</TableHead>
              <TableHead>تاريخ الإنشاء</TableHead>
              <TableHead>تاريخ الاستخدام</TableHead>
              <TableHead>الحالة</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              <TableSkeleton columns={7} rows={6} />
            ) : rows.length === 0 ? (
              <TableEmptyState
                colSpan={7}
                icon={KeyRound}
                title={hasFilters ? 'لا توجد أكواد مطابقة للفلاتر الحالية' : 'لا توجد أكواد اشتراك مسجلة بعد'}
                description={hasFilters ? 'جرّب تعديل الفلاتر أو امسحها لعرض كل الأكواد.' : 'ستظهر الأكواد هنا بمجرد توليد أول كود اشتراك.'}
              />
            ) : pagedRows.map(row => (
              <TableRow key={row.id}>
                <TableCell emphasis className="font-mono whitespace-nowrap" dir="ltr">{row.code}</TableCell>
                <TableCell className="whitespace-nowrap">{row.used_by_name ?? '—'}</TableCell>
                <TableCell>{row.course_title ?? '—'}</TableCell>
                <TableCell className="whitespace-nowrap">{row.teacher_name ?? '—'}</TableCell>
                <TableCell className="font-mono text-slate-500 dark:text-slate-400 whitespace-nowrap">{row.created_at ?? '—'}</TableCell>
                <TableCell className="font-mono text-slate-500 dark:text-slate-400 whitespace-nowrap">{row.used_at ?? '—'}</TableCell>
                <TableCell className="whitespace-nowrap">
                  <Badge dot tone={row.is_used ? 'success' : 'neutral'} icon={row.is_used ? CheckCircle2 : Circle}>
                    {row.is_used ? 'مستخدم' : 'غير مستخدم'}
                  </Badge>
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
