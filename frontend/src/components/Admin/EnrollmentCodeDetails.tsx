import { useState } from 'react';
import { motion } from 'motion/react';
import {
  Search, ScanSearch, CheckCircle, Clock,
  BookOpen, User, ShieldCheck, Phone, CalendarClock, Banknote, Hash, XCircle,
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import { PageHeader, Card, CardHeader, Button, Input, FormField, Badge, EmptyState } from '../UI';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';

/**
 * صفحة تفاصيل كود الاشتراك (admin only).
 *
 * Design-system pass — presentation only. `handleSearch`, the
 * `CODE_PATTERN` gate, the `isValid` derivation and every conditional
 * (`notFound`, `details`, `details.is_used`, `details.student`) are unchanged.
 * The non-existent palette shades used here (`slate-450`, `slate-505`,
 * `emerald-550`, `indigo-550`, `blue-550`, `slate-350`, `emerald-850`,
 * `emerald-350`, `amber-850`) emitted no CSS and were replaced with real ones.
 *
 * Read-only companion to EnrollmentCodes.tsx: that page MINTS codes, this one
 * INSPECTS one. The admin types the 8-digit code and gets the whole story —
 * which course it unlocks and for which teacher, which admin generated it and
 * when, and (if redeemed) exactly which student burned it and at what moment.
 *
 * Everything rendered here comes from GET /admin/enrollment-codes/{code}.
 * Nothing is derived client-side: `is_used` is the server's own column, and
 * the redeemer block is driven by the presence of the `student` object rather
 * than by re-checking the flag, matching the backend's own rule (a redeemed
 * code whose student was later deleted has is_used = 1 but no student data).
 */

interface CodeDetails {
  code: string;
  is_used: boolean;
  created_at: string | null;
  used_at: string | null;
  course: {
    id: string;
    title: string | null;
    price: number;
    discount_price: number | null;
    is_published: boolean;
    is_deleted: boolean;
    teacher_id: string | null;
    teacher_name: string | null;
  };
  created_by: { id: string | null; name: string | null; username: string | null };
  student: {
    id: string;
    name: string | null;
    username: string | null;
    student_code: string | null;
    phone: string | null;
    parent_phone: string | null;
  } | null;
}

const CODE_PATTERN = /^\d{8}$/;

export default function EnrollmentCodeDetails() {
  const [query, setQuery] = useState('');
  const [searching, setSearching] = useState(false);
  const [details, setDetails] = useState<CodeDetails | null>(null);
  // `notFound` is deliberately separate from `toast`: a 404 is a normal,
  // expected outcome of a lookup and gets a calm in-panel message, not the
  // transient warning banner used for genuine failures.
  const [notFound, setNotFound] = useState<string | null>(null);

  const { notify } = useCenteredNotification();

  // Client-side gate mirrors the server's own rule so an obviously invalid
  // entry never costs a round trip. The server still validates independently.
  const isValid = CODE_PATTERN.test(query.trim());

  const handleSearch = async () => {
    const code = query.trim();
    if (!CODE_PATTERN.test(code)) {
      notify('الكود يجب أن يتكون من 8 أرقام', 'error');
      return;
    }

    setSearching(true);
    setDetails(null);
    setNotFound(null);

    try {
      const data = await adminService.getEnrollmentCodeDetails(code);
      setDetails(data);
    } catch (err: any) {
      const status = err?.response?.status;
      if (status === 404) {
        setNotFound(err?.response?.data?.message || 'لا يوجد كود اشتراك بهذا الرقم');
      } else if (status === 422) {
        notify(err?.response?.data?.data?.errors?.code?.[0] || 'صيغة الكود غير صحيحة', 'error');
      } else {
        notify('تعذر الاتصال بالخادم، برجاء المحاولة مرة أخرى', 'error');
      }
    } finally {
      setSearching(false);
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
        title="تفاصيل كود الاشتراك"
        breadcrumbs={[{ label: 'منظومة تنظّيم وضبط المنصة العُليا' }, { label: 'تفاصيل كود الاشتراك' }]}
        description="عرض تفاصيل كود الاشتراك"
        className="mb-0"
      />

      {/* SEARCH */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0, duration: 0.35 }}
      >
      <Card className="space-y-5">
        <CardHeader title="البحث عن كود" icon={Search} />

        <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
          <div className="md:col-span-9">
            <FormField
              label={<span className="inline-flex items-center gap-1.5"><Hash className="w-3 h-3" /> كود الاشتراك (8 أرقام):</span>}
              error={query.length > 0 && !isValid
                ? `الكود يجب أن يتكون من 8 أرقام بالضبط (تم إدخال ${query.length})`
                : null}
            >
              {({ id, hasError }) => (
                <Input
                  id={id}
                  size="lg"
                  hasError={hasError}
                  type="text"
                  inputMode="numeric"
                  maxLength={8}
                  value={query}
                  // Non-digits are stripped on entry rather than rejected on submit
                  // so the field can never hold a value the endpoint would refuse.
                  onChange={(e) => setQuery(e.target.value.replace(/\D/g, '').slice(0, 8))}
                  onKeyDown={(e) => { if (e.key === 'Enter' && isValid) void handleSearch(); }}
                  placeholder="مثال: 95715987"
                  className="text-lg md:text-xl font-black font-mono tracking-[0.35em] placeholder:text-base placeholder:tracking-normal placeholder:font-bold"
                />
              )}
            </FormField>
          </div>

          <div className="md:col-span-3 md:mt-6">
            <Button
              variant="success"
              size="lg"
              fullWidth
              icon={Search}
              isLoading={searching}
              onClick={() => void handleSearch()}
              disabled={!isValid || searching}
              className="shadow-lg shadow-emerald-500/20 hover:shadow-xl hover:shadow-emerald-500/30 transition-shadow"
            >
              بحث عن الكود
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
            description="تأكد من كتابة الكود بشكل صحيح. الأكواد المولّدة يمكن مراجعتها كاملةً من صفحة «إنشاء أكواد الاشتراك»."
          />
        </Card>
      )}

      {/* RESULTS */}
      {details && (
        <motion.div
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          className="space-y-6"
        >
          {/* Code + status banner */}
          <div className={`rounded-3xl border-2 border-dashed p-6 flex flex-col md:flex-row items-center justify-between gap-5 ${
            details.is_used
              ? 'border-slate-300 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-900/40'
              : 'border-emerald-300 dark:border-emerald-800 bg-emerald-50/60 dark:bg-emerald-950/20'
          }`}>
            <div className="space-y-2 text-center md:text-right">
              <span className="text-[11px] font-black text-slate-500 dark:text-slate-400 block">كود الاشتراك</span>
              <span className="text-3xl md:text-4xl font-black font-mono tracking-[0.3em] text-slate-900 dark:text-white select-all block">
                {details.code}
              </span>
            </div>
            <div className="flex flex-col items-center md:items-end gap-2">
              <Badge
                tone={details.is_used ? 'neutral' : 'success'}
                icon={details.is_used ? Clock : CheckCircle}
              >
                {details.is_used ? 'مستخدم — لا يمكن استخدامه مرة أخرى' : 'متاح — لم يُستخدم بعد'}
              </Badge>
              <span className="text-[11px] font-medium text-slate-400 dark:text-slate-500 font-mono">
                تاريخ الإنشاء: {details.created_at ?? '—'}
              </span>
            </div>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Course */}
            <Panel title="الكورس المرتبط بالكود" icon={<BookOpen className="w-4 h-4 text-blue-600 dark:text-blue-400" />}>
              <Row label="اسم الكورس" value={details.course.title} strong />
              <Row label="المدرس" value={details.course.teacher_name} />
              <Row
                label="سعر الكورس"
                value={
                  details.course.discount_price !== null
                    ? `${details.course.discount_price} ج.م (بدلاً من ${details.course.price} ج.م)`
                    : `${details.course.price} ج.م`
                }
                icon={<Banknote className="w-3 h-3" />}
              />
              <Row
                label="حالة الكورس"
                value={
                  details.course.is_deleted
                    ? 'محذوف من المنصة'
                    : details.course.is_published ? 'منشور' : 'غير منشور'
                }
              />
            </Panel>

            {/* Generated by */}
            <Panel title="بيانات التوليد" icon={<ShieldCheck className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />}>
              <Row label="أنشأه" value={details.created_by.name ?? details.created_by.username} strong />
              <Row label="اسم المستخدم" value={details.created_by.username} />
              <Row label="تاريخ الإنشاء" value={details.created_at} icon={<CalendarClock className="w-3 h-3" />} mono />
            </Panel>
          </div>

          {/* Redeemer */}
          <Panel
            title={details.student ? 'الطالب الذي استخدم الكود' : 'حالة الاستخدام'}
            icon={<User className="w-4 h-4 text-emerald-600 dark:text-emerald-400" />}
          >
            {details.student ? (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                <Row label="اسم الطالب" value={details.student.name} strong />
                <Row label="كود الطالب" value={details.student.student_code} mono />
                <Row label="هاتف الطالب" value={details.student.phone} icon={<Phone className="w-3 h-3" />} mono />
                <Row label="هاتف ولي الأمر" value={details.student.parent_phone} icon={<Phone className="w-3 h-3" />} mono />
                <Row label="تاريخ التفعيل" value={details.used_at} icon={<CalendarClock className="w-3 h-3" />} mono />
              </div>
            ) : details.is_used ? (
              <p className="text-sm font-bold text-slate-500 dark:text-slate-400 py-3 leading-relaxed">
                هذا الكود مُستخدَم، لكن حساب الطالب الذي استخدمه لم يعد موجوداً على المنصة.
              </p>
            ) : (
              <p className="text-sm font-bold text-slate-500 dark:text-slate-400 py-3 leading-relaxed">
                لم يستخدم أي طالب هذا الكود حتى الآن — ما زال صالحاً للتفعيل مرة واحدة.
              </p>
            )}
          </Panel>
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
