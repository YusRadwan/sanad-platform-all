import { useState } from 'react';
import { motion } from 'motion/react';
import {
  Search, UserX, Hash, Phone, XCircle, Layers, CheckCircle, CalendarClock, Ban, User,
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import {
  PageHeader, Card, CardHeader, Button, Input, FormField, Badge, EmptyState, Tabs,
  Table, TableHeader, TableBody, TableRow, TableHead, TableCell, TableEmptyState,
  ConfirmDialog,
} from '../UI';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  normalizePhone, normalizeStudentCode as normalizeCode, phoneSearchFragment, toAsciiDigits,
} from '../../utils/studentLookup';

/**
 * صفحة «إلغاء اشتراك» (admin only).
 *
 * NO NEW BACKEND MECHANISM — this page is a focused front-end over two routes
 * that already exist:
 *
 *   GET /admin/subscriptions          search, via `student_code` OR `phone`
 *   PUT /admin/enrollments/toggle     cancel, with `isActive: false`
 *
 * ┌── WHICH SUBSCRIPTIONS ARE OFFERED FOR CANCELLATION. ────────────────────┐
 * │ Only rows whose `isCurrentlyActive` is true. That flag is computed on   │
 * │ the server THROUGH Subscription::scopeActive() (status = 'active' AND   │
 * │ expires_at is NULL or in the future), so an 'active' row past its       │
 * │ expiry is never shown here. The legacy `isActive` flag is status-only   │
 * │ and is deliberately NOT used by this page.                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── SEARCH IS NARROWED TO AN EXACT MATCH ON THE CLIENT. ──────────────────┐
 * │ The listing's text filters are LIKE '%…%' (a search box by design), so  │
 * │ the server can return several students for one query. A cancel page    │
 * │ must act on exactly the student the admin meant, so the server result   │
 * │ is filtered here to an exact code / exact phone match. The phone        │
 * │ filter is `phone` — the STUDENT's own column (st.phone) — never         │
 * │ `parent_phone`.                                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

type SearchMode = 'code' | 'phone';

interface ApiSubscription {
  courseId: string;
  courseName: string | null;
  teacherName: string | null;
  status: string;
  expires_at: string | null;
  subscribed_at: string | null;
  isCurrentlyActive?: boolean;
}

interface ApiStudentRow {
  id: string;
  name: string | null;
  studentCode: string | null;
  phone: string | null;
  subscriptions: ApiSubscription[];
}

interface FoundStudent {
  id: string;
  name: string | null;
  studentCode: string | null;
  phone: string | null;
  /** Already filtered to currently-active (cancellable) rows only. */
  active: ApiSubscription[];
}

interface PendingCancel {
  studentId: string;
  studentName: string | null;
  courseId: string;
  courseName: string;
}

const SUB_STATUS_LABEL: Record<string, string> = {
  active: 'نشط',
  expired: 'منتهي',
  cancelled: 'ملغي',
};

function formatDate(value: string | null): string {
  if (!value) return '—';
  const d = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('ar-EG', { year: 'numeric', month: 'long', day: 'numeric' });
}

export default function CancelSubscription() {
  const [mode, setMode] = useState<SearchMode>('code');
  const [query, setQuery] = useState('');
  const [searching, setSearching] = useState(false);
  const [results, setResults] = useState<FoundStudent[] | null>(null);
  const [pending, setPending] = useState<PendingCancel | null>(null);
  const [cancelling, setCancelling] = useState(false);

  const { notify } = useCenteredNotification();

  const isValid = mode === 'code'
    ? normalizeCode(query) !== null
    : normalizePhone(query) !== null;

  const switchMode = (next: SearchMode) => {
    if (searching) return;
    setMode(next);
    setQuery('');
    setResults(null);
  };

  const handleSearch = async () => {
    if (searching || !isValid) return;

    const code = mode === 'code' ? normalizeCode(query) : null;
    const phone = mode === 'phone' ? normalizePhone(query) : null;

    setSearching(true);
    setResults(null);

    try {
      // The server filter is a contains-match, so send the most permissive
      // fragment that still identifies the value (6 code digits / the last 10
      // phone digits, which matches 01…, +201… and 00201… storage alike — see
      // utils/studentLookup.ts) and then keep only the exact match below.
      // `phone` is students.phone; `parent_phone` is never sent.
      const rows: ApiStudentRow[] = await adminService.getSubscriptions(
        code
          ? { student_code: code.slice(3) }
          : { phone: phoneSearchFragment(phone!) }
      );

      const exact = (Array.isArray(rows) ? rows : []).filter((r) =>
        code
          ? (r.studentCode ?? '').toUpperCase() === code
          : normalizePhone(r.phone) === phone
      );

      setResults(exact.map((r) => ({
        id: String(r.id),
        name: r.name,
        studentCode: r.studentCode,
        phone: r.phone,
        active: (r.subscriptions ?? []).filter((s) => s.isCurrentlyActive === true),
      })));
    } catch (err: any) {
      const status = err?.response?.status;
      if (status === 403) {
        notify('ليس لديك صلاحية لعرض الاشتراكات', 'error');
      } else {
        notify('تعذر الاتصال بالخادم، برجاء المحاولة مرة أخرى', 'error');
      }
    } finally {
      setSearching(false);
    }
  };

  const confirmCancel = async () => {
    if (!pending || cancelling) return;
    const target = pending;
    setCancelling(true);

    try {
      await adminService.toggleEnrollmentStatus(target.studentId, target.courseId, false);

      // Remove ONLY this (student, course) pair from the visible list.
      setResults((prev) => prev?.map((s) =>
        s.id === target.studentId
          ? { ...s, active: s.active.filter((sub) => String(sub.courseId) !== target.courseId) }
          : s
      ) ?? null);

      setPending(null);
      notify(`تم إلغاء اشتراك الطالب في كورس ${target.courseName} بنجاح.`, 'success');
    } catch (err: any) {
      const status = err?.response?.status;
      const serverMessage: string | undefined = err?.response?.data?.message;
      let message: string;
      if (status === 404) {
        message = serverMessage || 'لا يوجد اشتراك لهذا الطالب في هذه الدورة';
      } else if (status === 403) {
        message = 'ليس لديك صلاحية لإلغاء الاشتراكات';
      } else if (status === 422) {
        message = serverMessage || 'بيانات الطلب غير صحيحة';
      } else {
        message = 'تعذر إلغاء الاشتراك، برجاء المحاولة مرة أخرى';
      }
      setPending(null);
      notify(message, 'error');
    } finally {
      setCancelling(false);
    }
  };

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-rose-600/10 dark:bg-rose-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-blue-500/8 dark:bg-blue-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />

      <PageHeader
        icon={UserX}
        title="إلغاء اشتراك"
        breadcrumbs={[{ label: 'منظومة تنظّيم وضبط المنصة العُليا' }, { label: 'إلغاء اشتراك' }]}
        description="ابحث عن الطالب بالكود أو برقم موبايله، ثم ألغِ اشتراكه في كورس محدد"
        className="mb-0"
      />

      {/* SEARCH */}
      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.35 }}>
        <Card className="space-y-5">
          <CardHeader title="البحث عن طالب" icon={Search} />

          <Tabs<SearchMode>
            variant="pill"
            value={mode}
            onChange={switchMode}
            items={[
              { id: 'code', label: 'كود الطالب', icon: Hash, disabled: searching },
              { id: 'phone', label: 'رقم موبايل الطالب', icon: Phone, disabled: searching },
            ]}
          />

          <form
            className="grid grid-cols-1 md:grid-cols-12 gap-4 items-start"
            onSubmit={(e) => { e.preventDefault(); void handleSearch(); }}
          >
            <div className="md:col-span-9">
              {mode === 'code' ? (
                <FormField
                  label={<span className="inline-flex items-center gap-1.5"><Hash className="w-3 h-3" /> كود الطالب (6 أرقام أو STU + 6 أرقام):</span>}
                  error={query.length > 0 && !isValid
                    ? 'الصيغة غير صحيحة — اكتب 6 أرقام (510078) أو الكود الكامل (STU510078)'
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
                      disabled={searching}
                      onChange={(e) => setQuery(toAsciiDigits(e.target.value).replace(/[^0-9a-zA-Z]/g, '').slice(0, 9).toUpperCase())}
                      placeholder="مثال: 510078 أو STU510078"
                      className="text-lg md:text-xl font-black font-mono tracking-[0.25em] placeholder:text-base placeholder:tracking-normal placeholder:font-bold"
                    />
                  )}
                </FormField>
              ) : (
                <FormField
                  label={<span className="inline-flex items-center gap-1.5"><Phone className="w-3 h-3" /> رقم موبايل الطالب نفسه (وليس ولي الأمر):</span>}
                  error={query.length > 0 && !isValid
                    ? 'اكتب رقم موبايل صحيح من 11 رقم يبدأ بـ 01'
                    : null}
                >
                  {({ id, hasError }) => (
                    <Input
                      id={id}
                      size="lg"
                      hasError={hasError}
                      type="tel"
                      inputMode="tel"
                      dir="ltr"
                      maxLength={16}
                      value={query}
                      disabled={searching}
                      onChange={(e) => setQuery(toAsciiDigits(e.target.value).replace(/[^0-9+]/g, '').slice(0, 16))}
                      placeholder="01xxxxxxxxx"
                      className="text-lg md:text-xl font-black font-mono tracking-[0.15em] text-right placeholder:text-base placeholder:tracking-normal placeholder:font-bold"
                    />
                  )}
                </FormField>
              )}
            </div>

            <div className="md:col-span-3 md:mt-6">
              <Button
                type="submit"
                size="lg"
                fullWidth
                icon={Search}
                isLoading={searching}
                disabled={!isValid || searching}
                className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
              >
                بحث
              </Button>
            </div>
          </form>
        </Card>
      </motion.div>

      {/* NO STUDENT */}
      {results !== null && results.length === 0 && (
        <Card className="border-amber-200 dark:border-amber-900/50">
          <EmptyState
            icon={XCircle}
            title={mode === 'code' ? 'لا يوجد طالب بهذا الكود' : 'لا يوجد طالب بهذا الرقم'}
            description={mode === 'phone'
              ? 'تأكد أن الرقم هو رقم موبايل الطالب نفسه وليس رقم ولي الأمر. لن يظهر هنا طالب ليس لديه أي اشتراك مسجّل.'
              : 'تأكد من كتابة الكود بشكل صحيح. لن يظهر هنا طالب ليس لديه أي اشتراك مسجّل.'}
          />
        </Card>
      )}

      {/* RESULTS */}
      {results?.map((student) => (
        <motion.div key={student.id} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} className="space-y-6">
          <Card className="flex flex-col md:flex-row md:items-center justify-between gap-5">
            <div className="flex items-center gap-4">
              <div className="w-16 h-16 rounded-2xl bg-blue-600 text-white flex items-center justify-center font-black text-2xl shrink-0">
                {(student.name ?? '؟').trim().charAt(0) || <User className="w-7 h-7" />}
              </div>
              <div className="space-y-1 text-right min-w-0">
                <strong className="text-lg font-black text-slate-900 dark:text-white block truncate">{student.name ?? '—'}</strong>
                <span className="text-sm font-black font-mono tracking-widest text-blue-600 dark:text-blue-400 block">
                  {student.studentCode ?? '—'}
                </span>
                <span className="text-sm font-bold font-mono text-slate-500 dark:text-slate-400 inline-flex items-center gap-1.5" dir="ltr">
                  <Phone className="w-3 h-3" /> {student.phone ?? '—'}
                </span>
              </div>
            </div>
            <div className="bg-slate-50 dark:bg-slate-950/60 border border-slate-200/70 dark:border-slate-800 px-4 py-2 rounded-xl text-center self-start md:self-auto">
              <span className="block text-[11px] font-bold text-slate-500 dark:text-slate-400">الاشتراكات النشطة</span>
              <strong className="text-lg font-black text-blue-600 dark:text-blue-400 font-mono">{student.active.length}</strong>
            </div>
          </Card>

          <Card padding="none" className="overflow-hidden">
            <CardHeader
              title={`الكورسات المشترك فيها حاليًا (${student.active.length})`}
              icon={Layers}
              className="p-5 pb-4 mb-0"
            />
            <Table>
              <TableHeader>
                <TableRow hoverable={false}>
                  <TableHead>الكورس</TableHead>
                  <TableHead>المدرس</TableHead>
                  <TableHead>الحالة</TableHead>
                  <TableHead>تاريخ الانتهاء</TableHead>
                  <TableHead>إجراء</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {student.active.length === 0 ? (
                  <TableEmptyState
                    colSpan={5}
                    icon={Layers}
                    title="لا يوجد اشتراكات نشطة لهذا الطالب."
                    description="الاشتراكات الملغاة أو المنتهية لا تظهر في هذه الصفحة."
                  />
                ) : student.active.map((sub) => {
                  const courseId = String(sub.courseId);
                  const courseName = sub.courseName ?? 'بدون اسم';
                  const isThisPending = cancelling && pending?.studentId === student.id && pending?.courseId === courseId;
                  return (
                    <TableRow key={courseId}>
                      <TableCell emphasis>{courseName}</TableCell>
                      <TableCell className="whitespace-nowrap">{sub.teacherName ?? '—'}</TableCell>
                      <TableCell className="whitespace-nowrap">
                        <Badge tone="success" icon={CheckCircle}>
                          {SUB_STATUS_LABEL[sub.status] ?? sub.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="whitespace-nowrap text-slate-500 dark:text-slate-400">
                        <span className="inline-flex items-center gap-1.5">
                          <CalendarClock className="w-3 h-3" />
                          {sub.expires_at ? formatDate(sub.expires_at) : 'بدون تاريخ انتهاء'}
                        </span>
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        <Button
                          variant="danger"
                          size="sm"
                          icon={Ban}
                          isLoading={isThisPending}
                          disabled={cancelling}
                          onClick={() => setPending({
                            studentId: student.id,
                            studentName: student.name,
                            courseId,
                            courseName,
                          })}
                        >
                          إلغاء الاشتراك
                        </Button>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </Card>
        </motion.div>
      ))}

      <ConfirmDialog
        open={pending !== null}
        onClose={() => { if (!cancelling) setPending(null); }}
        onConfirm={() => void confirmCancel()}
        title="تأكيد إلغاء الاشتراك"
        message={pending && (
          <>
            هل أنت متأكد من إلغاء اشتراك الطالب{' '}
            <strong className="font-black text-slate-900 dark:text-white">{pending.studentName ?? ''}</strong>{' '}
            في كورس{' '}
            <strong className="font-black text-rose-600 dark:text-rose-400">«{pending.courseName}»</strong>؟
            <br />
            سيفقد الطالب الوصول لهذا الكورس فقط، وتبقى اشتراكاته الأخرى كما هي.
          </>
        )}
        confirmLabel="نعم، إلغاء الاشتراك"
        cancelLabel="تراجع"
        tone="danger"
        isLoading={cancelling}
      />
    </div>
  );
}
