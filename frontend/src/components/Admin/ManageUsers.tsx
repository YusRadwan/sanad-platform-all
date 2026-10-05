import { useState, useEffect, useCallback } from 'react';
import { motion } from 'motion/react';
import { useNavigate } from 'react-router';
import {
  Users, Trash2, AlertTriangle, Power, Search, Filter, X,
  FileSpreadsheet, ListChecks, Pencil, LogIn, RefreshCw, UserCheck, UserX,
  KeyRound, ShieldAlert,
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { useAuth } from '../../hooks/useAuth';
import { downloadXlsx, XlsxColumn } from '../../utils/xlsxExport';
import { formatNumber } from '../../utils/formatMoney';
import { EGYPT_GOVERNORATES } from '../../constants/egyptLocation';
import StatCard from '../Layout/StatCard';
import {
  PageHeader,
  Card,
  CardHeader,
  Input,
  Select,
  FormField,
  Badge,
  Button,
  Dialog,
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
  TableEmptyState,
  TableSkeleton,
  TablePagination,
} from '../UI';

/**
 * جدول المستخدمين (فعلياً جدول الطلاب — GET /admin/students).
 *
 * Rebuilt on the real server-side filters/pagination added in open decision
 * 10.17 (`adminService.getStudents`), replacing the old client-only search
 * over a single unfiltered page. Adds the "دخول كحساب الطالب" flow (open
 * decision 10.18): a real session swap via useAuth().impersonateUser, not a
 * cosmetic role toggle — the admin's own browser genuinely becomes the
 * student's session until "العودة إلى حساب الأدمن" (the sticky banner in
 * App.tsx) is used.
 */

const PAGE_SIZE = 20;
const ALL_PAGE_SIZE = 50;

interface StudentRow {
  student_id: string;
  username: string;
  name: string;
  status: 'active' | 'inactive';
  studentCode: string;
  governorate: string | null;
  phone: string | null;
  parentPhone: string | null;
  gradeLevel: string | null;
  educationalStage: string | null;
  educationType: string | null;
}

const EXPORT_COLUMNS: XlsxColumn[] = [
  { header: 'كود الطالب', width: 14 },
  { header: 'اسم الطالب', width: 24 },
  { header: 'رقم الطالب', width: 16 },
  { header: 'رقم ولي الأمر', width: 16 },
  { header: 'الصف الدراسي', width: 18 },
  { header: 'المحافظة', width: 16 },
  { header: 'نوع التعليم', width: 14 },
  { header: 'الحالة', width: 10 },
];

export default function ManageUsers() {
  const navigate = useNavigate();
  const { impersonateUser } = useAuth();

  const [students, setStudents] = useState<StudentRow[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [showingAll, setShowingAll] = useState(false);

  // --- filters (all server-side) ---
  const [studentCode, setStudentCode] = useState('');
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [parentPhone, setParentPhone] = useState('');
  const [governorate, setGovernorate] = useState('');

  const [deleteTarget, setDeleteTarget] = useState<StudentRow | null>(null);
  const [deletePassword, setDeletePassword] = useState('');
  const [deleteError, setDeleteError] = useState('');
  const [deletePending, setDeletePending] = useState(false);

  const [impersonatingId, setImpersonatingId] = useState<string | null>(null);
  const [togglingId, setTogglingId] = useState<string | null>(null);

  const { notify } = useCenteredNotification();
  const showToast = (text: string, type: 'success' | 'danger' | 'info' = 'success') => {
    notify(text, type === 'danger' ? 'error' : 'success');
  };

  const hasFilters = !!(studentCode || name || phone || parentPhone || governorate);

  const fetchStudents = useCallback(async () => {
    try {
      setLoading(true);
      const data = await adminService.getStudents({
        student_code: studentCode,
        name,
        phone,
        parent_phone: parentPhone,
        governorate,
        all: showingAll,
        page,
        per_page: showingAll ? ALL_PAGE_SIZE : PAGE_SIZE,
      });
      setStudents(data.items);
      setTotal(data.total);
    } catch (err) {
      console.error('Failed to fetch students:', err);
      showToast('فشل في تحميل قائمة الطلاب', 'danger');
    } finally {
      setLoading(false);
    }
  }, [studentCode, name, phone, parentPhone, governorate, showingAll, page]);

  // Debounced refetch whenever a text filter changes.
  useEffect(() => {
    const timer = setTimeout(() => { void fetchStudents(); }, 350);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [studentCode, name, phone, parentPhone, governorate, showingAll, page]);

  // A filter change can shrink the result set below the current page number —
  // reset to page 1 whenever the filters change.
  useEffect(() => {
    setPage(1);
  }, [studentCode, name, phone, parentPhone, governorate, showingAll]);

  const clearFilters = () => {
    setStudentCode(''); setName(''); setPhone(''); setParentPhone(''); setGovernorate('');
  };

  const handleToggleStatus = async (student: StudentRow) => {
    const newStatus = student.status !== 'active';
    try {
      setTogglingId(student.student_id);
      await adminService.updateStudentStatus(student.student_id, newStatus);
      setStudents(prev => prev.map(s => s.student_id === student.student_id ? { ...s, status: newStatus ? 'active' : 'inactive' } : s));
      showToast(newStatus ? 'تم تفعيل حساب الطالب بنجاح' : 'تم إيقاف حساب الطالب مؤقتاً', newStatus ? 'success' : 'info');
    } catch (err) {
      console.error('Failed to toggle student status:', err);
      showToast('فشل في تحديث حالة الطالب', 'danger');
    } finally {
      setTogglingId(null);
    }
  };

  const openDelete = (student: StudentRow) => {
    setDeleteTarget(student);
    setDeletePassword('');
    setDeleteError('');
  };

  const closeDelete = () => {
    setDeleteTarget(null);
    setDeletePassword('');
    setDeleteError('');
  };

  /**
   * Requires the admin's own password (backend RequiresAdminReauth) and can
   * answer 409 — the student still has an active subscription (open decision
   * 10.19) — which is shown inline rather than as a generic failure toast, so
   * the admin knows exactly what to do next (cancel it from جداول الاشتراكات).
   */
  const confirmDelete = async () => {
    if (!deleteTarget) return;
    if (!deletePassword) {
      setDeleteError('كلمة مرور الإدارة العامة مطلوبة لتأكيد الحذف');
      return;
    }
    try {
      setDeletePending(true);
      await adminService.deleteStudent(deleteTarget.student_id, deletePassword);
      setStudents(prev => prev.filter(s => s.student_id !== deleteTarget.student_id));
      setTotal(t => Math.max(0, t - 1));
      showToast(`تم حذف الطالب "${deleteTarget.name}" بنجاح`, 'success');
      closeDelete();
    } catch (err: any) {
      console.error('Failed to delete student:', err);
      const status = err?.response?.status;
      const message = err?.response?.data?.message;
      if (status === 409) {
        setDeleteError(message || 'لا يمكن حذف هذا الطالب: لديه اشتراك فعّال. يرجى إلغاء اشتراكاته أولاً.');
      } else if (status === 401) {
        setDeleteError(message || 'كلمة مرور الإدارة العامة غير صحيحة');
      } else {
        setDeleteError(message || 'فشل في حذف الطالب');
      }
    } finally {
      setDeletePending(false);
    }
  };

  /** Real session swap (POST /admin/students/{id}/impersonate), not a cosmetic toggle. */
  const handleImpersonate = async (student: StudentRow) => {
    try {
      setImpersonatingId(student.student_id);
      await impersonateUser(student.student_id);
    } catch (err) {
      console.error('Failed to impersonate student:', err);
      showToast('فشل الدخول لحساب الطالب — تأكد أن الحساب نشط', 'danger');
      setImpersonatingId(null);
    }
  };

  /**
   * Exports the rows currently displayed — filtered or the full server page,
   * exactly matching whatever the table shows right now.
   */
  const handleExport = () => {
    if (students.length === 0) {
      showToast('لا توجد بيانات لتصديرها بالفلاتر الحالية', 'danger');
      return;
    }
    const rows = students.map(s => [
      s.studentCode ?? '—',
      s.name ?? '—',
      s.phone ?? '—',
      s.parentPhone ?? '—',
      s.gradeLevel ?? '—',
      s.governorate ?? '—',
      s.educationType ?? '—',
      s.status === 'active' ? 'نشط' : 'موقوف',
    ]);
    const stamp = new Date().toISOString().slice(0, 10);
    downloadXlsx(`جدول-الطلاب-${stamp}.xlsx`, EXPORT_COLUMNS, rows, 'الطلاب');
    showToast(`تم تصدير ${students.length} صفاً إلى ملف إكسيل`);
  };

  const effectivePageSize = showingAll ? ALL_PAGE_SIZE : PAGE_SIZE;
  const totalPages = Math.max(1, Math.ceil(total / effectivePageSize));

  return (
    <div className="relative w-full text-right space-y-6" dir="rtl">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />


      <PageHeader
        icon={Users}
        title="إدارة شؤون الطلاب"
        breadcrumbs={[{ label: 'لوحة التحكم الإدارية' }, { label: 'إدارة الطلاب' }]}
        description="إدارة حسابات الطلاب المسجلين"
        className="mb-0"
      />

      {/* STAT */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <StatCard index={0} icon={Users} label="إجمالي الطلاب المطابقين" value={formatNumber(total)} color="blue" />
        <StatCard index={1} icon={UserCheck} label="نشطون في الصفحة الحالية" value={formatNumber(students.filter(s => s.status === 'active').length)} color="emerald" />
        <StatCard index={2} icon={UserX} label="موقوفون في الصفحة الحالية" value={formatNumber(students.filter(s => s.status !== 'active').length)} color="amber" />
      </div>

      {/* FILTERS — export/refresh/show-all now live in the filter card's own
          header, alongside "مسح الفلاتر", all in the same ghost/sm shape,
          instead of a separate action row above the card. */}
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
                  <Button variant="ghost" size="sm" icon={X} onClick={clearFilters}>مسح الفلاتر</Button>
                )}
                <Button variant="ghost" size="sm" icon={ListChecks} onClick={() => setShowingAll(v => !v)}>
                  {showingAll ? 'عرض بالصفحات' : 'عرض جميع الطلاب'}
                </Button>
                <Button variant="ghost" size="sm" icon={FileSpreadsheet} onClick={handleExport}>
                  تصدير البيانات
                </Button>
                <Button variant="ghost" size="sm" onClick={() => void fetchStudents()}>
                  <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
                  <span>تحديث</span>
                </Button>
              </div>
            }
          />
          <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
            <div className="md:col-span-3">
              <FormField label="كود الطالب:">
                {({ id }) => <Input id={id} icon={Search} value={studentCode} onChange={(e) => setStudentCode(e.target.value)} placeholder="مثال: 902900" />}
              </FormField>
            </div>
            <div className="md:col-span-3">
              <FormField label="اسم الطالب:">
                {({ id }) => <Input id={id} icon={Search} value={name} onChange={(e) => setName(e.target.value)} placeholder="اسم الطالب" />}
              </FormField>
            </div>
            <div className="md:col-span-2">
              <FormField label="رقم هاتف الطالب:">
                {({ id }) => <Input id={id} icon={Search} value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="01012345678" />}
              </FormField>
            </div>
            <div className="md:col-span-2">
              <FormField label="رقم ولي الأمر:">
                {({ id }) => <Input id={id} icon={Search} value={parentPhone} onChange={(e) => setParentPhone(e.target.value)} placeholder="01098765432" />}
              </FormField>
            </div>
            <div className="md:col-span-2">
              <FormField label="المحافظة:">
                {({ id }) => (
                  <Select id={id} value={governorate} onChange={(e) => setGovernorate(e.target.value)}>
                    <option value="">-- كل المحافظات --</option>
                    {EGYPT_GOVERNORATES.map((gov) => (
                      <option key={gov} value={gov}>{gov}</option>
                    ))}
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
          <Table className="min-w-[1150px]">
            <TableHeader>
              <TableRow hoverable={false}>
                <TableHead>كود الطالب</TableHead>
                <TableHead>اسم الطالب</TableHead>
                <TableHead>رقم الطالب</TableHead>
                <TableHead>رقم ولي الأمر</TableHead>
                <TableHead>الصف الدراسي</TableHead>
                <TableHead>المحافظة</TableHead>
                <TableHead>نوع التعليم</TableHead>
                <TableHead align="center">الحالة</TableHead>
                <TableHead align="center">إجراء</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {loading ? (
                <TableSkeleton columns={9} rows={8} />
              ) : students.length === 0 ? (
                <TableEmptyState
                  colSpan={9}
                  icon={Users}
                  title={hasFilters ? 'لا يوجد طلاب مطابقون للفلاتر الحالية' : 'لا يوجد طلاب مسجلون بعد'}
                  description={hasFilters ? 'جرّب تعديل الفلاتر أو امسحها لعرض كل الطلاب.' : 'سيظهر الطلاب هنا بمجرد تسجيل أول حساب.'}
                />
              ) : students.map((student) => (
                <TableRow key={student.student_id}>
                  <TableCell emphasis className="font-mono whitespace-nowrap">{student.studentCode ?? '—'}</TableCell>
                  <TableCell className="whitespace-nowrap">
                    <div className="flex items-center gap-3">
                      <div className="w-9 h-9 shrink-0 rounded-xl bg-blue-50 dark:bg-blue-500/10 flex items-center justify-center text-blue-600 dark:text-blue-400 font-black">
                        {student.name?.charAt(0)}
                      </div>
                      <span className="font-black text-slate-900 dark:text-white">{student.name}</span>
                    </div>
                  </TableCell>
                  <TableCell className="whitespace-nowrap font-mono" dir="ltr">{student.phone || '—'}</TableCell>
                  <TableCell className="whitespace-nowrap font-mono" dir="ltr">{student.parentPhone || '—'}</TableCell>
                  <TableCell className="whitespace-nowrap">{student.gradeLevel || '—'}</TableCell>
                  <TableCell className="whitespace-nowrap">{student.governorate || '—'}</TableCell>
                  <TableCell className="whitespace-nowrap">{student.educationType || '—'}</TableCell>
                  <TableCell align="center" className="whitespace-nowrap">
                    <Badge dot tone={student.status === 'active' ? 'success' : 'danger'}>
                      {student.status === 'active' ? 'نشط' : 'موقوف'}
                    </Badge>
                  </TableCell>
                  <TableCell align="center" className="whitespace-nowrap">
                    <div className="flex items-center justify-center gap-1.5">
                      <Button
                        size="sm" iconOnly variant="outline"
                        onClick={() => navigate(`/admin/students/${student.student_id}/edit`)}
                        title="تعديل بيانات الطالب" aria-label="تعديل بيانات الطالب"
                      >
                        <Pencil className="w-3.5 h-3.5" />
                      </Button>
                      <Button
                        size="sm" iconOnly
                        variant={student.status === 'active' ? 'warning' : 'success'}
                        isLoading={togglingId === student.student_id}
                        onClick={() => void handleToggleStatus(student)}
                        title={student.status === 'active' ? 'حظر الطالب' : 'تفعيل الطالب'}
                        aria-label={student.status === 'active' ? 'حظر الطالب' : 'تفعيل الطالب'}
                      >
                        <Power className="w-3.5 h-3.5" />
                      </Button>
                      <Button
                        size="sm" iconOnly variant="danger"
                        onClick={() => openDelete(student)}
                        title="حذف الطالب" aria-label="حذف الطالب"
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                      </Button>
                      <Button
                        size="sm" iconOnly variant="primary"
                        isLoading={impersonatingId === student.student_id}
                        disabled={student.status !== 'active'}
                        onClick={() => void handleImpersonate(student)}
                        title={student.status === 'active' ? 'الدخول لحساب الطالب' : 'لا يمكن الدخول لحساب موقوف'}
                        aria-label="الدخول لحساب الطالب"
                      >
                        <LogIn className="w-3.5 h-3.5" />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {!loading && students.length > 0 && (
            <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
              <TablePagination
                page={page}
                totalPages={totalPages}
                onPageChange={setPage}
                totalItems={total}
                pageSize={effectivePageSize}
              />
            </div>
          )}
        </Card>
      </motion.div>

      {/* DELETE CONFIRM — requires the admin's own password, and surfaces the
          409 "active subscription" guard inline (open decision 10.19). */}
      <Dialog
        open={!!deleteTarget}
        onClose={closeDelete}
        title="تأكيد مصادقة الحذف الأمنية"
        description="عمليات الحذف تتطلب التحقق من كلمة مرور حسابك"
        icon={ShieldAlert}
        tone="danger"
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={closeDelete}>إلغاء الأمر</Button>
            <Button variant="danger" isLoading={deletePending} onClick={() => void confirmDelete()}>
              تأكيد الحذف نهائياً
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200/70 dark:border-amber-800 p-4 rounded-xl flex items-start gap-3">
            <AlertTriangle className="w-5 h-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
            <div className="space-y-1">
              <h4 className="text-sm font-black text-amber-800 dark:text-amber-300">تنبيه ذو حساسية عالية!</h4>
              <p className="text-xs font-medium text-amber-700/90 dark:text-amber-400/80 leading-relaxed">
                سيتم حذف الطالب "{deleteTarget?.name ?? ''}" وكافة بياناته نهائياً. لا يمكن حذف طالب له اشتراك فعّال — يجب إلغاء اشتراكه أولاً من جداول الاشتراكات.
              </p>
            </div>
          </div>

          <FormField
            label={
              <span className="inline-flex items-center gap-1.5">
                <KeyRound className="w-3.5 h-3.5 text-blue-500" />
                كلمة مرور الإدارة العامة
              </span>
            }
            error={deleteError || null}
          >
            {({ id, hasError }) => (
              <Input
                id={id}
                hasError={hasError}
                type="password"
                value={deletePassword}
                onChange={(e) => { setDeletePassword(e.target.value); setDeleteError(''); }}
                placeholder="أدخل كلمة مرور الإدارة لتأكيد الحذف"
                className="text-center tracking-widest font-mono"
                autoFocus
                onKeyDown={(e) => { if (e.key === 'Enter') void confirmDelete(); }}
              />
            )}
          </FormField>
        </div>
      </Dialog>
    </div>
  );
}
