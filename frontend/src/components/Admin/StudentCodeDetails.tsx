import { useState } from 'react';
import { motion } from 'motion/react';
import {
  Search, UserSearch, CheckCircle, XCircle,
  Phone, GraduationCap, MapPin, CalendarClock, Hash, Layers, BadgeCheck, Ban,
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import {
  PageHeader, Card, CardHeader, Button, Input, FormField, Badge, EmptyState,
  Table, TableHeader, TableBody, TableRow, TableHead, TableCell, TableEmptyState,
  TablePagination,
} from '../UI';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;

/**
 * صفحة تفاصيل كود الطالب (admin only).
 *
 * Design-system pass — presentation only. `handleSearch`, the
 * `CODE_PATTERN` gate, `isValid`, `activeCount` and every conditional are
 * unchanged. The subscription pill keeps its ORIGINAL three-way tone mapping
 * (active → success, expired → warning/amber, anything else → neutral) rather
 * than going through `statusTone()`, which classes `expired` as danger/red —
 * using it here would have silently changed this page's colours.
 * Non-existent palette shades used here (`slate-450`, `slate-505`, `blue-550`,
 * `emerald-550`, `indigo-550`, `slate-350`, `emerald-850`, `emerald-350`,
 * `amber-850`) emitted no CSS and were replaced with real ones.
 *
 * The admin types a student code and gets that student's whole picture in one
 * place: profile fields plus EVERY course subscription (any status), from
 * GET /admin/students/by-code/{code}.
 *
 * Accepted input deliberately matches the student_code filter already offered
 * by ManageSubscriptions.tsx: the bare six digits ('510078') OR the full
 * stored form ('STU510078', any casing). Both are sent through unchanged —
 * normalisation is the backend's job (StudentService::findByStudentCode), so
 * there is exactly one matching rule instead of two that could drift apart.
 */

interface StudentProfile {
  student_id: string;
  id: string;
  name: string | null;
  username: string | null;
  email: string | null;
  studentCode: string | null;
  phone: string | null;
  parentPhone: string | null;
  governorate: string | null;
  gradeLevel: string | null;
  educationalStage: string | null;
  educationType: string | null;
  gender: string | null;
  registeredAt: string | null;
  isActive: boolean;
  status: string;
}

interface StudentSubscription {
  id: string;
  course_id: string;
  course_title: string | null;
  course_price: number;
  teacher_id: string | null;
  teacher_name: string | null;
  status: string;
  expires_at: string | null;
  subscribed_at: string | null;
}

// Both accepted forms, exactly as the backend validates them.
const CODE_PATTERN = /^(STU)?\d{6}$/i;

const SUB_STATUS_LABEL: Record<string, string> = {
  active: 'نشط',
  expired: 'منتهي',
  cancelled: 'ملغي',
};

export default function StudentCodeDetails() {
  const [query, setQuery] = useState('');
  const [searching, setSearching] = useState(false);
  const [student, setStudent] = useState<StudentProfile | null>(null);
  const [subscriptions, setSubscriptions] = useState<StudentSubscription[]>([]);
  // A 404 is an expected lookup outcome, not a failure — it gets its own calm
  // in-panel message rather than the transient warning toast.
  const [notFound, setNotFound] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  const { notify } = useCenteredNotification();

  const isValid = CODE_PATTERN.test(query.trim());

  const handleSearch = async () => {
    const code = query.trim();
    if (!CODE_PATTERN.test(code)) {
      notify('أدخل 6 أرقام أو الكود الكامل بصيغة STU متبوعة بـ 6 أرقام', 'error');
      return;
    }

    setSearching(true);
    setStudent(null);
    setSubscriptions([]);
    setNotFound(null);
    setPage(1);

    try {
      const data = await adminService.getStudentByCode(code);
      setStudent(data.student);
      setSubscriptions(data.subscriptions);
    } catch (err: any) {
      const status = err?.response?.status;
      if (status === 404) {
        setNotFound(err?.response?.data?.message || 'لا يوجد طالب بهذا الكود');
      } else if (status === 422) {
        notify(err?.response?.data?.data?.errors?.code?.[0] || 'صيغة الكود غير صحيحة', 'error');
      } else {
        notify('تعذر الاتصال بالخادم، برجاء المحاولة مرة أخرى', 'error');
      }
    } finally {
      setSearching(false);
    }
  };

  const activeCount = subscriptions.filter(s => s.status === 'active').length;

  const totalPages = Math.max(1, Math.ceil(subscriptions.length / PAGE_SIZE));
  const pagedSubscriptions = subscriptions.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-sky-500/8 dark:bg-sky-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-indigo-500/6 dark:bg-indigo-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* HEADER */}
      <PageHeader
        icon={UserSearch}
        title="تفاصيل كود الطالب"
        breadcrumbs={[{ label: 'منظومة تنظّيم وضبط المنصة العُليا' }, { label: 'تفاصيل كود الطالب' }]}
        description="عرض تفاصيل ملف الطالب"
        className="mb-0"
      />

      {/* SEARCH */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0, duration: 0.35 }}
      >
      <Card className="space-y-5">
        <CardHeader title="البحث عن طالب بالكود" icon={Search} />

        <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
          <div className="md:col-span-9">
            <FormField
              label={<span className="inline-flex items-center gap-1.5"><Hash className="w-3 h-3" /> كود الطالب (6 أرقام أو STU + 6 أرقام):</span>}
              error={query.length > 0 && !isValid
                ? 'الصيغة غير صحيحة — اكتب 6 أرقام (396874) أو الكود الكامل (STU396874)'
                : null}
            >
              {({ id, hasError }) => (
                <Input
                  id={id}
                  size="lg"
                  hasError={hasError}
                  type="text"
                  maxLength={9}
                  value={query}
                  // Only characters that can appear in either accepted form are
                  // kept, so the field can never hold a value the endpoint refuses.
                  onChange={(e) => setQuery(e.target.value.replace(/[^0-9a-zA-Z]/g, '').slice(0, 9).toUpperCase())}
                  onKeyDown={(e) => { if (e.key === 'Enter' && isValid) void handleSearch(); }}
                  placeholder="مثال: 396874 أو STU396874"
                  className="text-lg md:text-xl font-black font-mono tracking-[0.25em] placeholder:text-base placeholder:tracking-normal placeholder:font-bold"
                />
              )}
            </FormField>
          </div>

          <div className="md:col-span-3 md:mt-6">
            <Button
              size="lg"
              fullWidth
              icon={Search}
              isLoading={searching}
              onClick={() => void handleSearch()}
              disabled={!isValid || searching}
              className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
            >
              بحث عن الطالب
            </Button>
          </div>
        </div>
      </Card>
      </motion.div>

      {/* NOT FOUND */}
      {notFound && (
        <Card className="border-amber-200 dark:border-amber-900/50">
          <EmptyState
            icon={XCircle}
            title={notFound}
            description="تأكد من كتابة الكود بشكل صحيح. يمكنك مراجعة أكواد الطلاب كاملةً من صفحة «جداول الاشتراكات»."
          />
        </Card>
      )}

      {/* RESULTS */}
      {student && (
        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} className="space-y-6">

          {/* Identity banner */}
          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.08, duration: 0.35 }}
          >
          <Card className="flex flex-col md:flex-row items-center justify-between gap-5">
            <div className="flex items-center gap-4">
              <div className="w-16 h-16 rounded-2xl bg-blue-600 text-white flex items-center justify-center font-black text-2xl shrink-0">
                {(student.name ?? '؟').trim().charAt(0)}
              </div>
              <div className="space-y-1 text-right">
                <strong className="text-lg font-black text-slate-900 dark:text-white block">{student.name ?? '—'}</strong>
                <span className="text-sm font-black font-mono tracking-widest text-blue-600 dark:text-blue-400 block">
                  {student.studentCode ?? '—'}
                </span>
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-3">
              <Badge
                tone={student.isActive ? 'success' : 'danger'}
                icon={student.isActive ? BadgeCheck : Ban}
              >
                {student.isActive ? 'الحساب نشط' : 'الحساب موقوف'}
              </Badge>
              <div className="bg-slate-50 dark:bg-slate-950/60 border border-slate-200/70 dark:border-slate-800 px-4 py-2 rounded-xl text-center">
                <span className="block text-[11px] font-bold text-slate-500 dark:text-slate-400">الاشتراكات النشطة</span>
                <strong className="text-lg font-black text-blue-600 dark:text-blue-400 font-mono">
                  {activeCount} / {subscriptions.length}
                </strong>
              </div>
            </div>
          </Card>
          </motion.div>

          {/* Profile */}
          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.16, duration: 0.35 }}
          >
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Panel title="بيانات التواصل" icon={<Phone className="w-4 h-4 text-emerald-600 dark:text-emerald-400" />}>
              <Row label="هاتف الطالب" value={student.phone} mono icon={<Phone className="w-3 h-3" />} />
              <Row label="هاتف ولي الأمر" value={student.parentPhone} mono icon={<Phone className="w-3 h-3" />} />
              <Row label="المحافظة" value={student.governorate} icon={<MapPin className="w-3 h-3" />} />
              <Row label="اسم المستخدم" value={student.username} mono />
            </Panel>

            <Panel title="البيانات الدراسية والحساب" icon={<GraduationCap className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />}>
              <Row label="المرحلة التعليمية" value={student.educationalStage} />
              <Row label="الصف الدراسي" value={student.gradeLevel} />
              <Row label="نوع التعليم" value={student.educationType} />
              <Row label="تاريخ التسجيل" value={student.registeredAt} mono icon={<CalendarClock className="w-3 h-3" />} />
            </Panel>
          </div>
          </motion.div>

          {/* Subscriptions */}
          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.24, duration: 0.35 }}
          >
          <Card padding="none" className="overflow-hidden">
            <CardHeader
              title={`اشتراكات الطالب في الكورسات (${subscriptions.length})`}
              icon={Layers}
              className="p-5 pb-4 mb-0"
            />

            <Table>
              <TableHeader>
                <TableRow hoverable={false}>
                  <TableHead>الكورس</TableHead>
                  <TableHead>المدرس</TableHead>
                  <TableHead>السعر</TableHead>
                  <TableHead>الحالة</TableHead>
                  <TableHead>تاريخ الاشتراك</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {subscriptions.length === 0 ? (
                  <TableEmptyState
                    colSpan={5}
                    icon={Layers}
                    title="هذا الطالب غير مشترك في أي كورس حتى الآن"
                    description="ستظهر هنا كل اشتراكاته بمجرد تفعيل أول كورس."
                  />
                ) : pagedSubscriptions.map(s => (
                  <TableRow key={s.id}>
                    <TableCell emphasis>{s.course_title ?? '—'}</TableCell>
                    <TableCell className="whitespace-nowrap">{s.teacher_name ?? '—'}</TableCell>
                    <TableCell className="whitespace-nowrap font-mono">{s.course_price} ج.م</TableCell>
                    <TableCell className="whitespace-nowrap">
                      <Badge
                        tone={s.status === 'active' ? 'success' : s.status === 'expired' ? 'warning' : 'neutral'}
                        icon={s.status === 'active' ? CheckCircle : XCircle}
                      >
                        {SUB_STATUS_LABEL[s.status] ?? s.status}
                      </Badge>
                    </TableCell>
                    <TableCell className="whitespace-nowrap font-mono text-slate-500 dark:text-slate-400">
                      {s.subscribed_at ?? '—'}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            {subscriptions.length > 0 && (
              <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
                <TablePagination
                  page={page}
                  totalPages={totalPages}
                  onPageChange={setPage}
                  totalItems={subscriptions.length}
                  pageSize={PAGE_SIZE}
                />
              </div>
            )}
          </Card>
          </motion.div>
        </motion.div>
      )}
    </div>
  );
}

/** Shared card shell — same surface/border/radius as every other admin panel. */
function Panel({ title, icon, children }: { title: string; icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <Card padding="none" className="overflow-hidden">
      <div className="flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 p-5">
        {icon}
        <h3 className="text-sm font-black text-slate-800 dark:text-slate-200">{title}</h3>
      </div>
      <div className="p-5 divide-y divide-slate-100 dark:divide-slate-800/50">{children}</div>
    </Card>
  );
}

function Row({ label, value, strong, mono, icon }: {
  label: string;
  value: string | number | null | undefined;
  strong?: boolean;
  mono?: boolean;
  icon?: React.ReactNode;
}) {
  return (
    <div className="flex items-center justify-between gap-4 py-2.5">
      <span className="text-[11px] font-black text-slate-400 dark:text-slate-500 flex items-center gap-1.5 shrink-0">
        {icon}{label}
      </span>
      <span className={`text-sm text-left ${mono ? 'font-mono' : ''} ${
        strong ? 'font-black text-slate-900 dark:text-white' : 'font-bold text-slate-600 dark:text-slate-300'
      }`}>
        {value === null || value === undefined || value === '' ? '—' : value}
      </span>
    </div>
  );
}
