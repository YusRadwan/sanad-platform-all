import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import {
  Search, ScanSearch, User, Hash,
  RefreshCw, X, Unlink,
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import { centerService } from '../../services/centerService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  PageHeader, Card, CardHeader, Button, Input, Select, FormField, Badge, ConfirmDialog,
  Table, TableHeader, TableBody, TableRow, TableHead, TableCell, TableEmptyState, TableSkeleton,
} from '../UI';

/**
 * صفحة تفاصيل كود السنتر (admin only).
 *
 * جدول مسطّح — صفّ واحد لكل طالب مسجَّل بكل كود (وصفّ واحد بشرطات للكود غير
 * المستخدم بعد)، وليس صفّاً لكل كود مع Dialog تفاصيل منفصل: `CenterCodeResource`
 * (المستخدَمة في GET /admin/center-codes/search) تحمل الآن مصفوفة `students`
 * كاملة (بما فيها الهاتف وهاتف ولي الأمر) لكل كود، فلا حاجة لطلب تفاصيل ثانٍ
 * عند فتح أي صفّ. "فك الربط" يحذف صفّ العضوية (center_memberships) فقط —
 * حساب الطالب نفسه لا يُمَس — فيستطيع استخدام نفس الكود أو أي كود آخر لهذا
 * المدرس من جديد.
 */

interface TeacherOption { id: string; name?: string; full_name?: string; username?: string }

interface CenterCodeStudent {
  membership_id: string;
  student_id: string;
  name: string | null;
  student_code: string | null;
  phone: string | null;
  parent_phone: string | null;
  joined_at: string | null;
}

interface CenterCodeRow {
  id: string;
  code: string;
  teacher_id: string;
  teacher_name: string | null;
  is_active: boolean;
  created_at: string | null;
  students: CenterCodeStudent[];
}

/** One rendered table row — either a registered student, or the bare code if unused. */
interface FlatRow {
  key: string;
  code: string;
  teacherName: string | null;
  isActive: boolean;
  createdAt: string | null;
  student: CenterCodeStudent | null;
}

export default function CenterCodeDetails() {
  const [rows, setRows] = useState<CenterCodeRow[]>([]);
  const [loading, setLoading] = useState(true);

  // --- filters (server-side) ---
  const [code, setCode] = useState('');
  const [teacherId, setTeacherId] = useState('');
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);

  // --- unlink confirmation ---
  const [unlinkTarget, setUnlinkTarget] = useState<{ membershipId: string; studentName: string } | null>(null);
  const [unlinking, setUnlinking] = useState(false);

  const { notify } = useCenteredNotification();

  const teacherLabel = (t: TeacherOption) => t.full_name || t.name || t.username || t.id;

  const fetchCodes = async () => {
    try {
      setLoading(true);
      const data = await centerService.searchCenterCodes({ code, teacher_id: teacherId });
      setRows(Array.isArray(data) ? data : []);
    } catch {
      setRows([]);
      notify('تعذر تحميل بيانات أكواد السنتر من الخادم', 'error');
    } finally {
      setLoading(false);
    }
  };

  // Debounced refetch whenever a filter changes.
  useEffect(() => {
    const timer = setTimeout(() => { void fetchCodes(); }, 350);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code, teacherId]);

  // Teacher list for the filter picker.
  useEffect(() => {
    (async () => {
      try {
        setTeachers(await adminService.getAllTeachers());
      } catch {
        setTeachers([]);
      }
    })();
  }, []);

  const clearFilters = () => { setCode(''); setTeacherId(''); };
  const hasFilters = !!(code || teacherId);

  // Flatten: one row per (code, student), one row per unused code.
  const flatRows: FlatRow[] = rows.flatMap((row): FlatRow[] =>
    row.students.length === 0
      ? [{
          key: row.id,
          code: row.code,
          teacherName: row.teacher_name,
          isActive: row.is_active,
          createdAt: row.created_at,
          student: null,
        }]
      : row.students.map((s): FlatRow => ({
          key: `${row.id}-${s.membership_id}`,
          code: row.code,
          teacherName: row.teacher_name,
          isActive: row.is_active,
          createdAt: row.created_at,
          student: s,
        }))
  );

  const confirmUnlink = async () => {
    if (!unlinkTarget) return;
    setUnlinking(true);
    try {
      await centerService.unlinkMembership(unlinkTarget.membershipId);
      notify('تم فك ارتباط الطالب بنجاح، ويمكنه استخدام الكود مرة أخرى', 'success');
      setUnlinkTarget(null);
      await fetchCodes();
    } catch {
      notify('حدث خطأ أثناء فك الربط، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setUnlinking(false);
    }
  };

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-indigo-600/10 dark:bg-indigo-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-blue-500/8 dark:bg-blue-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* HEADER */}
      <PageHeader
        icon={ScanSearch}
        title="تفاصيل كود سنتر"
        breadcrumbs={[{ label: 'منظومة السنتر' }, { label: 'تفاصيل كود سنتر' }]}
        description="بحث وعرض كل طالب سجّل بكل كود سنتر، مع إمكانية فك ارتباطه"
        className="mb-0"
      />

      {/* FILTERS */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0, duration: 0.35 }}
      >
      <Card className="space-y-4">
        <CardHeader
          title="أدوات البحث والفلترة"
          icon={Search}
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
          <div className="md:col-span-6">
            <FormField label={<span className="inline-flex items-center gap-1.5"><Hash className="w-3 h-3" /> كود السنتر:</span>}>
              {({ id }) => (
                <Input
                  id={id} icon={Search} type="text" value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="ابحث بجزء من الكود"
                />
              )}
            </FormField>
          </div>

          <div className="md:col-span-6">
            <FormField label={<span className="inline-flex items-center gap-1.5"><User className="w-3 h-3" /> المدرس:</span>}>
              {({ id }) => (
                <Select id={id} value={teacherId} onChange={(e) => setTeacherId(e.target.value)}>
                  <option value="">كل المدرسين</option>
                  {teachers.map(t => <option key={t.id} value={t.id}>{teacherLabel(t)}</option>)}
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
        <Table zebra className="min-w-[1180px] table-fixed">
          <TableHeader>
            <TableRow hoverable={false}>
              <TableHead className="w-[150px]">كود السنتر</TableHead>
              <TableHead className="w-[110px] text-center">الحالة</TableHead>
              <TableHead className="w-[180px]">اسم الطالب</TableHead>
              <TableHead className="w-[150px] text-center">هاتف الطالب</TableHead>
              <TableHead className="w-[150px] text-center">هاتف ولي الأمر</TableHead>
              <TableHead className="w-[170px]">اسم المدرس</TableHead>
              <TableHead className="w-[160px] text-center">تاريخ الإنشاء</TableHead>
              <TableHead className="w-[160px] text-center">تاريخ التسجيل</TableHead>
              <TableHead align="end" className="w-[130px]">إجراء</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              <TableSkeleton columns={9} rows={6} />
            ) : flatRows.length === 0 ? (
              <TableEmptyState
                colSpan={9}
                icon={ScanSearch}
                title={hasFilters ? 'لا توجد أكواد مطابقة للفلاتر الحالية' : 'لا توجد أكواد سنتر مسجلة بعد'}
                description={hasFilters ? 'جرّب تعديل الفلاتر أو امسحها لعرض كل الأكواد.' : 'ستظهر الأكواد هنا بمجرد توليد أول كود سنتر.'}
              />
            ) : flatRows.map((row) => (
              <TableRow key={row.key}>
                <TableCell emphasis className="font-mono tracking-wide truncate" dir="ltr">
                  {row.code}
                </TableCell>
                <TableCell className="text-center">
                  <Badge
                    dot
                    size="sm"
                    tone={row.isActive ? 'success' : 'neutral'}
                    className="mx-auto"
                  >
                    {row.isActive ? 'نشط' : 'غير نشط'}
                  </Badge>
                </TableCell>
                <TableCell className="truncate" title={row.student?.name ?? undefined}>
                  {row.student?.name ?? <Dash />}
                </TableCell>
                <TableCell className="font-mono text-center truncate" dir="ltr">
                  {row.student?.phone ?? <Dash />}
                </TableCell>
                <TableCell className="font-mono text-center truncate" dir="ltr">
                  {row.student?.parent_phone ?? <Dash />}
                </TableCell>
                <TableCell className="truncate" title={row.teacherName ?? undefined}>
                  {row.teacherName ?? <Dash />}
                </TableCell>
                <TableCell className="font-mono text-[11px] text-center text-slate-500 dark:text-slate-400 truncate">
                  {row.createdAt ?? <Dash />}
                </TableCell>
                <TableCell className="font-mono text-[11px] text-center text-slate-500 dark:text-slate-400 truncate">
                  {row.student?.joined_at ?? <Dash />}
                </TableCell>
                <TableCell align="end">
                  {row.student ? (
                    <Button
                      variant="ghost"
                      size="sm"
                      icon={Unlink}
                      className="text-rose-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 whitespace-nowrap"
                      onClick={() => setUnlinkTarget({ membershipId: row.student!.membership_id, studentName: row.student!.name ?? row.student!.student_code ?? 'الطالب' })}
                    >
                      فك الربط
                    </Button>
                  ) : (
                    <Dash />
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>
      </motion.div>

      <ConfirmDialog
        open={!!unlinkTarget}
        onClose={() => setUnlinkTarget(null)}
        onConfirm={confirmUnlink}
        title="فك ربط الطالب"
        message={unlinkTarget ? `هل أنت متأكد من فك ربط "${unlinkTarget.studentName}" بهذا الكود؟ سيتمكن الطالب من استخدام الكود (أو أي كود آخر لهذا المدرس) من جديد.` : ''}
        confirmLabel="فك الربط"
        tone="danger"
        isLoading={unlinking}
      />
    </div>
  );
}

/** A consistent, muted placeholder for an empty cell — never a bare "—" scattered inline. */
function Dash() {
  return <span className="text-slate-300 dark:text-slate-700 select-none">—</span>;
}
