import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { Users, BookOpen, BarChart3, TrendingUp, AlertTriangle, Wallet, CalendarClock } from 'lucide-react';
import { InstructorStatisticsProps } from '../../types/index';
import { teacherService } from '../../services/teacherService';
import { revenueService } from '../../services/revenueService';
import { formatMoney as formatCurrency } from '../../utils/formatMoney';
import { logger } from '../../services/logger';
import StatCard from '../Layout/StatCard';



import {
  PieChart,
  Pie,
  Tooltip,
  ResponsiveContainer,
  Cell,
  Legend
} from 'recharts';
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
  EmptyState,
} from '../UI';

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;

// Colors for the pie chart slices
const COLORS = ['#3b82f6', '#8b5cf6', '#ec4899', '#f43f5e', '#f59e0b', '#10b981', '#0ea5e9'];

export default function InstructorStatistics({ username }: InstructorStatisticsProps) {
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  // Revenue — deliberately a SEPARATE fetch from `teacherService.getStatistics()`:
  // `total_earnings` (already net of the platform's cut — it's the sum of
  // each transaction's `teacher_earnings`, computed server-side from
  // `teacher_profit_percentage`, the same figure InstructorRevenue.tsx and
  // Admin/InstructorStatement.tsx show) comes from GET /revenue/my-statement.
  // The monthly figure has no dedicated backend endpoint, so it is derived
  // here from the real transaction list — summing `teacher_earnings` for
  // transactions dated in the current calendar month, excluding refunded
  // ones — rather than fabricated.
  const [totalRevenue, setTotalRevenue] = useState<number | null>(null);
  const [monthlyRevenue, setMonthlyRevenue] = useState<number | null>(null);
  const [revenueLoading, setRevenueLoading] = useState(true);

  useEffect(() => {
    const fetchStats = async () => {
      try {
        setLoading(true);
        setLoadError(null);
        const data = await teacherService.getStatistics();
        setStats(data);
        setPage(1);
      } catch (err) {
        console.error('Failed to fetch instructor statistics:', err);
        setLoadError('تعذر تحميل الإحصائيات، يرجى إعادة تحميل الصفحة أو المحاولة لاحقاً');
      } finally {
        setLoading(false);
      }
    };
    fetchStats();
  }, [username]);

  useEffect(() => {
    const fetchRevenue = async () => {
      try {
        setRevenueLoading(true);
        const [statementRes, transactionsRes] = await Promise.all([
          revenueService.getMyStatement(),
          revenueService.getMyTransactions(),
        ]);

        if (statementRes.success) {
          setTotalRevenue(Number(statementRes.data?.total_earnings ?? 0));
        }

        if (transactionsRes.success && Array.isArray(transactionsRes.data)) {
          const now = new Date();
          const monthSum = transactionsRes.data.reduce((sum: number, tx: any) => {
            const created = new Date(tx.created_at);
            const inCurrentMonth = created.getFullYear() === now.getFullYear() && created.getMonth() === now.getMonth();
            const isRefunded = tx.status === 'refunded';
            if (inCurrentMonth && !isRefunded) {
              return sum + Number(tx.teacher_earnings ?? 0);
            }
            return sum;
          }, 0);
          setMonthlyRevenue(monthSum);
        }
      } catch (err) {
        logger.error('Failed to fetch instructor revenue for statistics page', err as Error);
      } finally {
        setRevenueLoading(false);
      }
    };
    fetchRevenue();
  }, [username]);

  // Summary stats from API
  const totalStudents = stats?.summary?.total_students || 0;
  const totalCourses = stats?.summary?.total_courses || 0;
  const governorateData = stats?.governorateStats || [];
  const courseStats = stats?.courses || [];

  const totalPages = Math.max(1, Math.ceil(courseStats.length / PAGE_SIZE));
  const pagedCourseStats = courseStats.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  // Chart Customization
  const CustomTooltip = ({ active, payload, label }: any) => {
    if (active && payload && payload.length) {
      return (
        <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-3 rounded-xl shadow-lg">
          <p className="font-bold text-slate-900 dark:text-white text-base mb-1">{label}</p>
          <p className="text-blue-600 dark:text-blue-400 font-black text-base">
            {payload[0].value} طالب
          </p>
        </div>
      );
    }
    return null;
  };

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Header */}
      <PageHeader
        icon={BarChart3}
        title="الإحصائيات"
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'الإحصائيات' }]}
        description="أداء كورساتك وتوزيع طلابك"
        className="mb-0"
      />

      {/* Load error banner — surfaces a failed statistics fetch instead of
          silently rendering the page as if the teacher has zero data. */}
      {loadError && (
        <div className="p-4 rounded-2xl flex items-center gap-3.5 font-bold text-sm sm:text-base border bg-rose-50 text-rose-800 border-rose-200/60 dark:bg-rose-500/10 dark:text-rose-400 dark:border-rose-500/20">
          <AlertTriangle className="w-4 h-4 shrink-0" />
          <span>{loadError}</span>
        </div>
      )}

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {loading ? (
          Array.from({ length: 2 }).map((_, i) => (
            <Card key={i} className="h-36">
              <Skeleton variant="block" className="w-12 h-12 rounded-2xl" />
              <Skeleton variant="text" width="45%" className="mt-4" />
              <Skeleton variant="block" width="35%" className="h-6 mt-2.5" />
            </Card>
          ))
        ) : (
          <>
            <StatCard
              index={0}
              label="إجمالي الطلاب"
              value={totalStudents}
              icon={Users}
              color="blue"
            />
            <StatCard
              index={1}
              label="إجمالي الكورسات"
              value={totalCourses}
              icon={BookOpen}
              color="indigo"
            />
          </>
        )}

        {/* Revenue cards — `total_earnings`/`teacher_earnings` are already
            net of the platform's cut (see the fetch effect above), not the
            gross sale price. */}
        {revenueLoading ? (
          Array.from({ length: 2 }).map((_, i) => (
            <Card key={`rev-${i}`} className="h-36">
              <Skeleton variant="block" className="w-12 h-12 rounded-2xl" />
              <Skeleton variant="text" width="45%" className="mt-4" />
              <Skeleton variant="block" width="35%" className="h-6 mt-2.5" />
            </Card>
          ))
        ) : (
          <>
            <StatCard
              index={2}
              label="إجمالي الإيرادات المستحقة"
              value={formatCurrency(totalRevenue ?? 0)}
              icon={Wallet}
              color="emerald"
              caption="بعد خصم نسبة المنصة"
            />
            <StatCard
              index={3}
              label="إيرادات هذا الشهر"
              value={formatCurrency(monthlyRevenue ?? 0)}
              icon={CalendarClock}
              color="amber"
              caption="بعد خصم نسبة المنصة"
            />
          </>
        )}
      </div>

      {/* Chart */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 2 * 0.08, duration: 0.35 }}
      >
        <Card radius="panel">
          <CardHeader
            className="mb-8"
            icon={TrendingUp}
            title="توزيع الطلاب حسب المحافظة"
          />

          {loading ? (
            <Skeleton variant="block" className="h-[350px] w-full rounded-2xl" />
          ) : governorateData.length > 0 ? (
          <div className="h-[350px] w-full" dir="ltr">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie
                  data={governorateData}
                  cx="50%"
                  cy="50%"
                  labelLine={false}
                  outerRadius={120}
                  fill="#8884d8"
                  dataKey="count"
                  label={({ name, percent }) => `${name} ${((percent || 0) * 100).toFixed(0)}%`}
                >
                  {governorateData.map((_entry: any, index: number) => (
                    <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
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
          </div>
          ) : (
            <EmptyState
              icon={BarChart3}
              title="لا توجد بيانات متاحة لعرض التوزيع الجغرافي"
              className="h-[250px] justify-center bg-slate-50/50 dark:bg-slate-900/30 rounded-2xl border border-dashed border-slate-200 dark:border-slate-800"
            />
          )}
        </Card>
      </motion.div>

      {/* Courses Table */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 3 * 0.08, duration: 0.35 }}
      >
        <Card radius="panel" padding="none" className="overflow-hidden">
          <CardHeader
            className="p-5 sm:p-6 mb-0 bg-slate-50/60 dark:bg-slate-800/20"
            icon={BookOpen}
            title="إحصائيات الكورسات"
          />

          <Table>
            <TableHeader>
              <tr>
                <TableHead>اسم الكورس</TableHead>
                <TableHead>الصف الدراسي</TableHead>
                <TableHead>إجمالي الطلاب</TableHead>
              </tr>
            </TableHeader>
            <TableBody>
              {loading ? (
                Array.from({ length: 4 }).map((_, i) => (
                  <TableRow key={i}>
                    <TableCell colSpan={3}>
                      <Skeleton variant="text" width="100%" />
                    </TableCell>
                  </TableRow>
                ))
              ) : courseStats.length > 0 ? (
                pagedCourseStats.map((stat: any) => (
                  <TableRow key={stat.id}>
                    <TableCell emphasis>{stat.title}</TableCell>
                    <TableCell>
                      {/*
                        Deliberately NOT `statusTone(...)`: `grade_name` is a
                        grade label ("الصف الثالث الثانوي"), not a status, and
                        this chip was plain grey before. Pinned to `neutral`.
                      */}
                      <Badge tone="neutral">{stat.grade_name || 'غير محدد'}</Badge>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        <Users className="w-4 h-4 text-slate-400 shrink-0" />
                        <span className="font-black text-slate-900 dark:text-white">{stat.enrollment_count || 0}</span>
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              ) : (
                <TableEmptyState
                  colSpan={3}
                  icon={BookOpen}
                  title="لا توجد كورسات مضافة حالياً"
                />
              )}
            </TableBody>
          </Table>

          {!loading && courseStats.length > 0 && (
            <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
              <TablePagination
                page={page}
                totalPages={totalPages}
                onPageChange={setPage}
                totalItems={courseStats.length}
                pageSize={PAGE_SIZE}
              />
            </div>
          )}
        </Card>
      </motion.div>
    </div>
  );
}
