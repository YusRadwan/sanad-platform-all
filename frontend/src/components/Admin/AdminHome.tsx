import { useEffect, useState } from 'react';
import { motion } from 'motion/react';
import {
  GraduationCap, Users, BookOpen, UserCog,
  KeyRound, ScanSearch, TableProperties, BarChart3, MapPin, Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useNavigate } from 'react-router';
import { adminService } from '../../services/adminService';
import { formatNumber } from '../../utils/formatMoney';
import { Card, Skeleton } from '../UI';
import QuickLinks from '../Layout/QuickLinks';
import StatCard, { StatCardColor } from '../Layout/StatCard';

/**
 * الصفحة الرئيسية للوحة الإدارة.
 *
 * Two blocks were removed from this page and are NOT coming back as empty
 * shells:
 *   1. The "الامتحانات" stat card. `total_exams` came from the same
 *      GET /admin/dashboard call that still feeds the three remaining cards,
 *      so only the card was dropped — the fetch is untouched and no other
 *      tile lost its data.
 *   2. The blue "تطوير المنصة" gradient card, together with the paragraph it
 *      contained ("بصفتك المدير العام، يمكنك مراقبة كافة النشاطات...") and its
 *      "إعدادات النظام" button. The whole card is gone, not just the text; the
 *      settings route it linked to is still reachable from the sidebar.
 *   3. The greeting hero panel ("صباح الخير، مدير النظام" + date) — removed
 *      on explicit request, not replaced by anything.
 *
 * Everything rendered below is real data from /admin/dashboard or a plain
 * navigation shortcut. No figure on this page is invented or computed from
 * anything the endpoint does not actually return.
 */

type StatCardDef = {
  title: string;
  value: number;
  icon: LucideIcon;
  color: StatCardColor;
  /** Formats `value` as "X.XX ج.م" instead of a plain integer count. */
  isCurrency?: boolean;
};

export default function AdminHome() {
  const navigate = useNavigate();
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchStats = async () => {
      try {
        const data = await adminService.getDashboardStats();
        setStats(data);
      } catch (err) {
        console.error('Failed to fetch admin stats:', err);
      } finally {
        setLoading(false);
      }
    };
    fetchStats();
  }, []);

  // Ordered most-important-first: people on the platform, then the people
  // teaching them, then the catalogue those two meet in, then this month's
  // money.
  const statCards: StatCardDef[] = [
    { title: 'إجمالي الطلاب', value: stats?.total_students || 0, icon: GraduationCap, color: 'blue' },
    { title: 'إجمالي المدرسين', value: stats?.total_teachers || 0, icon: Users, color: 'purple' },
    { title: 'الكورسات التعليمية', value: stats?.total_courses || 0, icon: BookOpen, color: 'emerald' },
    { title: 'الإيرادات الشهرية', value: stats?.monthly_revenue || 0, icon: Wallet, color: 'amber', isCurrency: true },
  ];

  // Real current month name, computed client-side — not a stored/cached
  // label, so it always matches whatever month `monthly_revenue` above was
  // just scoped to on the server.
  const currentMonthLabel = new Date().toLocaleDateString('ar-EG', { month: 'long' });

  // Plain navigation, no data. Same destinations as before — only the card
  // shape changed (flat icon-over-label, no chip/description).
  const shortcuts = [
    { label: 'إدارة المدرسين', icon: UserCog, path: '/admin/manage-teachers', color: 'text-purple-500' },
    { label: 'جداول الاشتراكات', icon: TableProperties, path: '/admin/manage-subscriptions', color: 'text-emerald-500' },
    { label: 'إنشاء أكواد الاشتراك', icon: KeyRound, path: '/admin/enrollment-codes', color: 'text-amber-500' },
    { label: 'تفاصيل كود الاشتراك', icon: ScanSearch, path: '/admin/enrollment-code-details', color: 'text-teal-500' },
    { label: 'إحصائيات المنصة', icon: BarChart3, path: '/admin/statistics', color: 'text-blue-500' },
    { label: 'جدول المستخدمين', icon: Users, path: '/admin/users', color: 'text-indigo-500' },
  ];

  const governorates: Array<{ governorate: string; count: number }> = Array.isArray(stats?.students_by_governorate)
    ? stats.students_by_governorate
    : [];
  const maxGovernorateCount = Math.max(1, ...governorates.map((g) => g.count));
  const totalGovernorateStudents = governorates.reduce((sum, g) => sum + g.count, 0);

  return (
    <motion.div
      key="admin_dashboard_home"
      initial={{ opacity: 0, y: 15 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -15 }}
      dir="rtl"
      className="relative space-y-8 text-right font-sans"
    >
      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* 1. PLATFORM NUMBERS — four real stats, four columns. The monthly
             revenue tile is scoped to the current calendar month server-side
             (DashboardStats::monthly_revenue) — it naturally recalculates
             once a new month starts, no cron or cache involved. */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        {loading
          ? Array.from({ length: 4 }).map((_, i) => (
              <Card key={i} className="h-28">
                <Skeleton variant="block" className="w-9 h-9 rounded-xl" />
                <Skeleton variant="text" width="45%" className="mt-3" />
                <Skeleton variant="block" width="35%" className="h-5 mt-2" />
              </Card>
            ))
          : statCards.map((card, i) => (
              <StatCard
                key={card.title}
                index={i}
                icon={card.icon}
                label={card.title}
                value={
                  card.isCurrency
                    ? `${formatNumber(card.value, { maximumFractionDigits: 2 })} ج.م`
                    : formatNumber(card.value)
                }
                color={card.color}
                caption={card.isCurrency ? `إيراد شهر ${currentMonthLabel}` : undefined}
              />
            ))}
      </div>

      {/* 2. SHORTCUTS — flat icon-over-label cards, same shape shared with
             the instructor home page (Layout/QuickLinks.tsx). */}
      <QuickLinks links={shortcuts} onNavigate={navigate} />

      {/* 3. STUDENT DISTRIBUTION BY GOVERNORATE — a ranked horizontal bar-list,
             not a pie/donut: with ~27 governorates a wheel of that many slices
             is unreadable, while a sorted list with a proportional bar reads
             instantly. Counts EVERY student who created an account, regardless
             of subscription status (open decision 10.16). */}
      {!loading && governorates.length > 0 && (
        <motion.div initial={{ opacity: 0, y: 15 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15 }}>
          <Card padding="none">
            <div className="flex items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-800 p-5">
              <div className="flex items-center gap-2">
                <MapPin className="w-4 h-4 text-blue-600 dark:text-blue-400" />
                <h3 className="text-sm font-black text-slate-800 dark:text-slate-200">توزيع الطلاب على المحافظات</h3>
              </div>
              <span className="text-xs font-bold text-slate-400 dark:text-slate-500">
                من إجمالي {formatNumber(totalGovernorateStudents)} طالب
              </span>
            </div>

            <div className="p-5 space-y-3">
              {governorates.map((g, i) => {
                const pct = totalGovernorateStudents > 0 ? Math.round((g.count / totalGovernorateStudents) * 100) : 0;
                const barWidth = Math.max(2, Math.round((g.count / maxGovernorateCount) * 100));
                return (
                  <motion.div
                    key={g.governorate}
                    initial={{ opacity: 0, x: 10 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ delay: 0.15 + i * 0.02 }}
                    className="flex items-center gap-4"
                  >
                    <span className="w-28 shrink-0 text-xs font-bold text-slate-700 dark:text-slate-300 truncate">
                      {g.governorate || '—'}
                    </span>
                    <div className="flex-1 h-2.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                      <motion.div
                        initial={{ width: 0 }}
                        animate={{ width: `${barWidth}%` }}
                        transition={{ delay: 0.2 + i * 0.02, duration: 0.5 }}
                        className="h-full rounded-full bg-gradient-to-l from-blue-500 to-violet-500"
                      />
                    </div>
                    <span className="w-20 shrink-0 text-left text-xs font-mono font-black text-slate-800 dark:text-slate-100">
                      {formatNumber(g.count)}
                      <span className="text-slate-400 dark:text-slate-500 font-medium"> ({pct}%)</span>
                    </span>
                  </motion.div>
                );
              })}
            </div>
          </Card>
        </motion.div>
      )}
    </motion.div>
  );
}
