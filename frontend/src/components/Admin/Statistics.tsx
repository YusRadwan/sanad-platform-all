import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import {
  Users, UserCheck, BookOpen, TrendingUp, Layers,
  Banknote, CheckCircle2, EyeOff, PieChart as PieChartIcon,
} from 'lucide-react';
import { PieChart, Pie, Cell, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { adminService } from '../../services/adminService';
import { formatMoney, formatNumber } from '../../utils/formatMoney';
import StatCard from '../Layout/StatCard';
import {
  PageHeader,
  Card,
  CardHeader,
  Badge,
  Skeleton,
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
  TableEmptyState,
  TablePagination,
} from '../UI';

/** Emerald for subscribed, amber for unsubscribed — matches this platform's
 * existing status-tint conventions (success = emerald, pending/attention =
 * amber) and passes the categorical CVD/normal-vision separation checks. */
const SUBSCRIPTION_COLORS = { subscribed: '#10b981', unsubscribed: '#f59e0b' };

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;

/**
 * صفحة إحصائيات المنصة.
 *
 * Every number on this page comes from one of exactly two real endpoints:
 *   GET /admin/dashboard        -> stats.{students_count, teachers_count,
 *                                  courses_count, published_courses_count,
 *                                  active_subscriptions, total_revenue}
 *                                  (adminService also aliases the first three
 *                                  to total_*; both names resolve)
 *   GET /admin/courses/overview -> per-course {title, teacher_name,
 *                                  enrolled_students, revenue, is_published}
 *
 * IMPORTANT — do not regress: `teacher_name` and `enrolled_students` are the
 * two columns a previous session fixed in
 * SubscriptionRepository::coursesOverview after they rendered blank on every
 * row. They are read here under exactly those names.
 *
 * Nothing is derived, averaged or projected client-side; the layout below is a
 * reorganisation of real values only.
 */

export default function Statistics() {
  const [stats, setStats] = useState<any>(null);
  const [coursesOverview, setCoursesOverview] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);

  useEffect(() => {
    const fetchData = async () => {
      try {
        setLoading(true);
        const [dashStats, courses] = await Promise.all([
          adminService.getDashboardStats(),
          adminService.getCoursesOverview()
        ]);
        setStats(dashStats);
        setCoursesOverview(Array.isArray(courses) ? courses : []);
      } catch (err) {
        console.error('Failed to fetch statistics:', err);
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);

  const money = (v: any) => formatMoney(v, { maximumFractionDigits: 2 });

  const totalPages = Math.max(1, Math.ceil(coursesOverview.length / PAGE_SIZE));
  const pagedCourses = coursesOverview.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-sky-500/6 dark:bg-sky-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* 1. HEADER. `text-slate-400` here was a non-existent palette shade that
             emitted no CSS at all; the crumb now uses the shared PageHeader
             breadcrumb treatment instead. */}
      <PageHeader
        icon={TrendingUp}
        title="إحصائيات وتقارير المنصة العامة"
        breadcrumbs={[{ label: 'منظومة تنظّيم وضبط المنصة العُليا' }, { label: 'إحصائيات المنصة' }]}
        description="إحصائيات وأداء المنصة العام"
        className="mb-0"
      />

      {/* 2. PRIMARY NUMBERS — the four figures that describe the platform's
             scale. Filled 4-up grid (the previous layout declared four columns
             but rendered only three cards, leaving a permanent empty slot). */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        {loading ? (
          Array.from({ length: 4 }).map((_, i) => (
            <Card key={i} className="h-40">
              <Skeleton variant="block" className="w-12 h-12 rounded-2xl" />
              <Skeleton variant="text" width="50%" className="mt-4" />
              <Skeleton variant="block" width="40%" className="h-7 mt-2.5" />
            </Card>
          ))
        ) : (
          <>
            <StatCard
              index={0}
              icon={Users}
              label="إجمالي الطلاب"
              value={formatNumber(stats?.total_students ?? 0)}
              color="blue"
            />
            <StatCard
              index={1}
              icon={UserCheck}
              label="إجمالي المدرسين"
              value={formatNumber(stats?.total_teachers ?? 0)}
              color="emerald"
            />
            <StatCard
              index={2}
              icon={BookOpen}
              label="إجمالي الكورسات"
              value={formatNumber(stats?.total_courses ?? 0)}
              color="purple"
              caption={`منها ${formatNumber(stats?.published_courses_count)} كورس منشور`}
            />
            <StatCard
              index={3}
              icon={Banknote}
              label="إجمالي إيرادات المنصة"
              value={`${formatNumber(stats?.total_revenue ?? 0, { maximumFractionDigits: 2 })} ج.م`}
              color="amber"
              caption="الإجمالي المُحصَّل من المعاملات المدفوعة المسجّلة"
            />
          </>
        )}
      </div>

      {/* 3. STUDENT SUBSCRIPTION COMPARISON — students_subscribed /
             students_unsubscribed, freshly added to GET /admin/dashboard.
             Donut (innerRadius set, unlike InstructorStatistics.tsx's full
             pie) + legend on the right, total + two stat tiles on the left.
             Moved above the course-performance table (explicit request). */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 4 * 0.08, duration: 0.35 }}
      >
        <Card>
          <CardHeader
            icon={PieChartIcon}
            title="مقارنة اشتراكات الطلاب"
            description="عدد الطلاب المشتركين في كورس واحد على الأقل مقابل غير المشتركين"
          />
          <SubscriptionComparison
            subscribed={stats?.students_subscribed ?? 0}
            unsubscribed={stats?.students_unsubscribed ?? 0}
          />
        </Card>
      </motion.div>

      {/* 4. COURSE PERFORMANCE — the table whose المدرس / الطلاب columns were
             fixed in a previous session. teacher_name and enrolled_students are
             read here under those exact names; do not rename. */}
      {/* `text-blue-500` and `dark:text-slate-300` here were non-existent
          palette shades that emitted no CSS; corrected to real ones. */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 5 * 0.08, duration: 0.35 }}
      >
      <Card padding="none" className="overflow-hidden">
        <div className="flex items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-800 p-5">
          <div className="flex items-center gap-2">
            <Layers className="w-4 h-4 text-blue-600 dark:text-blue-400" />
            <h3 className="text-sm font-black text-slate-800 dark:text-slate-200">أداء الكورسات الدراسية</h3>
          </div>
          <span className="text-[11px] font-black text-slate-400 dark:text-slate-500">
            {coursesOverview.length} كورس
          </span>
        </div>

        <Table>
          <TableHeader>
            <TableRow hoverable={false}>
              <TableHead>الكورس</TableHead>
              <TableHead>المدرس</TableHead>
              <TableHead align="center">الطلاب</TableHead>
              <TableHead>الإيراد</TableHead>
              <TableHead>الحالة</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {coursesOverview.length === 0 ? (
              <TableEmptyState
                colSpan={5}
                icon={Layers}
                title="لا توجد كورسات على المنصة حتى الآن"
                description="ستظهر بيانات أداء كل كورس هنا بمجرد إنشاء أول كورس."
              />
            ) : pagedCourses.map((c) => (
              <TableRow key={c.id}>
                <TableCell emphasis>
                  {/* A `{c.grade}` sub-label used to sit under the title.
                      /admin/courses/overview has no `grade` field, and the
                      `grades` table it would come from has no write path
                      anywhere in the system (same gap noted in
                      ManageSubscriptions.tsx), so it rendered an empty
                      element on every row. Removed rather than left blank. */}
                  <span className="block">{c.title}</span>
                </TableCell>
                <TableCell className="whitespace-nowrap font-bold">
                  {c.teacher_name ?? '—'}
                </TableCell>
                <TableCell align="center" className="font-mono font-black text-blue-600 dark:text-blue-400">
                  {c.enrolled_students}
                </TableCell>
                <TableCell className="whitespace-nowrap font-mono font-black text-emerald-600 dark:text-emerald-400">
                  {money(c.revenue)}
                </TableCell>
                <TableCell className="whitespace-nowrap">
                  <Badge
                    tone={Number(c.is_published) === 1 ? 'success' : 'neutral'}
                    icon={Number(c.is_published) === 1 ? CheckCircle2 : EyeOff}
                  >
                    {Number(c.is_published) === 1 ? 'منشور' : 'غير منشور'}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>

        {coursesOverview.length > 0 && (
          <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
            <TablePagination
              page={page}
              totalPages={totalPages}
              onPageChange={setPage}
              totalItems={coursesOverview.length}
              pageSize={PAGE_SIZE}
            />
          </div>
        )}
      </Card>
      </motion.div>
    </div>
  );
}

function SubscriptionComparison({ subscribed, unsubscribed }: { subscribed: number; unsubscribed: number }) {
  const total = subscribed + unsubscribed;
  const pct = (v: number) => (total > 0 ? Math.round((v / total) * 100) : 0);

  const data = [
    { name: 'مشترك في كورس', value: subscribed, color: SUBSCRIPTION_COLORS.subscribed },
    { name: 'غير مشترك', value: unsubscribed, color: SUBSCRIPTION_COLORS.unsubscribed },
  ];

  const CustomTooltip = ({ active, payload }: any) => {
    if (active && payload && payload.length) {
      const entry = payload[0];
      return (
        <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-3 rounded-xl shadow-lg">
          <p className="font-bold text-slate-900 dark:text-white text-sm mb-1">{entry.name}</p>
          <p className="font-black text-base" style={{ color: entry.payload.color }}>
            {formatNumber(entry.value)} طالب
          </p>
        </div>
      );
    }
    return null;
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-6 items-center">
      {/* Left: total + stat tiles */}
      <div className="order-2 lg:order-1 flex flex-col gap-4">
        <div>
          <span className="block text-xs font-bold text-slate-400 dark:text-slate-500 mb-1">الإجمالي</span>
          <strong className="text-2xl font-black text-slate-900 dark:text-white font-mono">
            {formatNumber(total)} <span className="text-sm font-bold text-slate-400">طالب</span>
          </strong>
        </div>

        <SubscriptionTile
          dotColor={SUBSCRIPTION_COLORS.subscribed}
          label="مشترك في كورس"
          value={subscribed}
          percent={pct(subscribed)}
        />
        <SubscriptionTile
          dotColor={SUBSCRIPTION_COLORS.unsubscribed}
          label="غير مشترك"
          value={unsubscribed}
          percent={pct(unsubscribed)}
        />
      </div>

      {/* Right: donut chart, with the total centered in the ring */}
      <div className="order-1 lg:order-2 relative h-[220px] w-full" dir="ltr">
        {total > 0 ? (
          <>
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie
                  data={data}
                  cx="50%"
                  cy="50%"
                  innerRadius={50}
                  outerRadius={80}
                  paddingAngle={2}
                  dataKey="value"
                >
                  {data.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={entry.color} />
                  ))}
                </Pie>
                <Tooltip content={<CustomTooltip />} />
                <Legend
                  layout="horizontal"
                  verticalAlign="bottom"
                  align="center"
                  wrapperStyle={{ fontSize: '12px', fontWeight: 'bold' }}
                />
              </PieChart>
            </ResponsiveContainer>
            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center -mt-6" dir="rtl">
              <strong className="text-2xl font-black text-slate-900 dark:text-white font-mono leading-none">
                {formatNumber(total)}
              </strong>
              <span className="text-[11px] font-bold text-slate-400 dark:text-slate-500 mt-1">إجمالي الطلاب</span>
            </div>
          </>
        ) : (
          <div className="h-full flex items-center justify-center text-sm font-bold text-slate-400 dark:text-slate-500">
            لا توجد بيانات كافية لعرض المقارنة
          </div>
        )}
      </div>
    </div>
  );
}

function SubscriptionTile({ dotColor, label, value, percent }: {
  dotColor: string; label: string; value: number; percent: number;
}) {
  return (
    <div className="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-950/40 p-3.5">
      <div className="flex items-center gap-2 mb-1.5">
        <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: dotColor }} />
        <span className="text-xs font-bold text-slate-500 dark:text-slate-400 truncate">{label}</span>
      </div>
      <strong className="block text-xl font-black text-slate-900 dark:text-white font-mono leading-none">
        {formatNumber(value)}
      </strong>
      <span className="block text-[11px] font-medium text-slate-400 dark:text-slate-500 mt-1.5">
        {percent}% من الطلاب
      </span>
    </div>
  );
}
