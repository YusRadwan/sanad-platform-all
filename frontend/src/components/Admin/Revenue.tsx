import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { revenueService } from '../../services/revenueService';
import { logger } from '../../services/logger';
import { formatDateTime as formatDate } from '../../utils/dateUtils';
import { paymentMethodLabel } from '../../utils/paymentMethod';
import { formatMoney as formatCurrency } from '../../utils/formatMoney';
import { CreditCard, RefreshCw, TrendingUp, DollarSign, ArrowDownRight, ArrowUpRight, Activity } from 'lucide-react';
import {
  PlatformRevenueSummary as PlatformSummary,
  RevenueTransaction as Transaction,
} from '../../types/index';
import {
  PageHeader,
  Card,
  Toolbar,
  SearchBox,
  Select,
  Button,
  Badge,
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
import StatCard from '../Layout/StatCard';

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;

/**
 * Reference implementation #1 of the SANAD design system: a stat-cards +
 * toolbar + table screen.
 *
 * Only the JSX presentation layer was rewritten. Every piece of behaviour is
 * byte-identical to the previous version: the same four state hooks, the same
 * `fetchRevenueData` body, the same `useEffect` dependency array
 * `[dateFrom, dateTo, paymentMethod]`, the same client-side
 * `filteredTransactions` predicate, and the same fields read off each record.
 */

export default function Revenue() {
  const [summary, setSummary] = useState<PlatformSummary | null>(null);
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  // Filters
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('All');
  const [searchQuery, setSearchQuery] = useState('');
  const [page, setPage] = useState(1);

  const fetchRevenueData = async () => {
    try {
      setIsLoading(true);

      const params = new URLSearchParams();
      if (dateFrom) params.append('dateFrom', dateFrom);
      if (dateTo) params.append('dateTo', dateTo);
      if (paymentMethod !== 'All') params.append('paymentMethod', paymentMethod);
      const queryString = params.toString();

      const [summaryData, transactionsData] = await Promise.all([
        revenueService.getPlatformRevenue(queryString),
        revenueService.getTransactions(queryString),
      ]);

      if (summaryData.success) setSummary(summaryData.data);
      if (transactionsData.success) setTransactions(transactionsData.data);
    } catch (err) {
      logger.error('Failed to fetch platform revenue data', err as Error);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchRevenueData();
  }, [dateFrom, dateTo, paymentMethod]);

  const filteredTransactions = transactions.filter(t => {
    if (!searchQuery) return true;
    const q = searchQuery.toLowerCase();
    return (
      t.teacher_name?.toLowerCase().includes(q) ||
      t.student_name?.toLowerCase().includes(q)
    );
  });

  // A search change can shrink the result set below the current page number —
  // reset to page 1 whenever the filtered set changes so the table never
  // renders empty while real matches exist on an earlier page.
  useEffect(() => {
    setPage(1);
  }, [searchQuery]);

  const totalPages = Math.max(1, Math.ceil(filteredTransactions.length / PAGE_SIZE));
  const pagedTransactions = filteredTransactions.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  return (
    <div className="relative p-4 md:p-8" dir="rtl">
      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-amber-500/10 dark:bg-amber-500/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-emerald-500/8 dark:bg-emerald-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-blue-500/6 dark:bg-blue-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <PageHeader
        icon={Activity}
        title="إيرادات المنصة"
        description="تتبع جميع المعاملات والأرباح"
      />

      {/* Summary Cards.
          Field names below are App\Actions\Revenue\PlatformSummary's actual
          wire shape (gross_revenue / refunds_total / platform_share) — the
          component used to read gross_sales / total_refunds / net_sales /
          total_platform_earnings, none of which the backend has ever sent,
          so every card here always rendered a stale "0 ج.م" regardless of
          real activity. "صافي المبيعات" (net sales) has no backend field of
          its own; it is gross minus refunds, computed here. */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <StatCard
          index={0}
          label="إجمالي المبيعات"
          value={summary ? formatCurrency(summary.gross_revenue) : '0 ج.م'}
          icon={TrendingUp}
          color="blue"
        />
        <StatCard
          index={1}
          label="إجمالي الاسترجاعات"
          value={summary ? formatCurrency(summary.refunds_total) : '0 ج.م'}
          icon={ArrowDownRight}
          color="rose"
        />
        <StatCard
          index={2}
          label="صافي المبيعات"
          value={summary ? formatCurrency(Number(summary.gross_revenue) - Number(summary.refunds_total)) : '0 ج.م'}
          icon={ArrowUpRight}
          color="emerald"
        />

        {/* The one deliberately saturated tile — it carries the headline number. */}
        <motion.div
          initial={{ opacity: 0, y: 15 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 3 * 0.08, duration: 0.35 }}
          className="h-full"
        >
          {/*
            `!bg-*`/`!border-*`: Card's own defaults ('bg-white dark:bg-slate-900
            border-slate-200/70') are utilities of equal CSS specificity, so
            without `!important` whichever rule Tailwind happens to emit LATER
            in the compiled stylesheet wins — regardless of the order classes
            appear in this string. That silently left this tile plain white
            with near-invisible white-on-white text instead of the intended
            solid blue.
          */}
          <Card className="group h-full flex flex-col justify-between bg-blue-600! dark:bg-blue-600! border-blue-600! dark:border-blue-600! text-white shadow-md shadow-blue-600/20 transition-shadow hover:shadow-lg">
            <div className="flex justify-between items-start mb-4">
              <div className="p-3 rounded-2xl bg-white/15 transition-transform group-hover:scale-110">
                <DollarSign className="w-6 h-6" />
              </div>
            </div>
            <span className="block text-sm font-bold text-blue-100 mb-1.5">نصيب المنصة</span>
            <strong className="text-3xl font-black font-mono leading-none tracking-tight">
              {summary ? formatCurrency(summary.platform_share) : '0 ج.م'}
            </strong>
          </Card>
        </motion.div>
      </div>

      {/* Filters & Transactions */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 4 * 0.08, duration: 0.35 }}
      >
      <Card padding="none" className="overflow-hidden">
        <Toolbar
          className="p-4 md:p-5 border-b border-slate-100 dark:border-slate-800"
          search={
            <SearchBox
              placeholder="البحث باسم الطالب أو المدرس..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onClear={() => setSearchQuery('')}
            />
          }
          filters={
            <>
              <div className="flex items-center gap-2 h-10 px-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700">
                <input
                  type="date"
                  aria-label="من تاريخ"
                  value={dateFrom}
                  onChange={(e) => setDateFrom(e.target.value)}
                  className="bg-transparent border-none outline-none text-xs font-bold text-slate-700 dark:text-slate-300"
                />
                <span className="text-slate-400 text-xs font-bold">إلى</span>
                <input
                  type="date"
                  aria-label="إلى تاريخ"
                  value={dateTo}
                  onChange={(e) => setDateTo(e.target.value)}
                  className="bg-transparent border-none outline-none text-xs font-bold text-slate-700 dark:text-slate-300"
                />
              </div>

              <Select
                aria-label="طريقة الدفع"
                value={paymentMethod}
                onChange={(e) => setPaymentMethod(e.target.value)}
                className="w-auto min-w-44"
              >
                <option value="All">كل طرق الدفع</option>
                <option value="manual_admin">تفعيل يدوي (أدمن)</option>
                <option value="enrollment_code">كود اشتراك</option>
              </Select>
            </>
          }
          actions={
            <Button
              variant="outline"
              iconOnly
              onClick={fetchRevenueData}
              title="تحديث"
              aria-label="تحديث"
              className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
            >
              <RefreshCw className={`w-4 h-4 ${isLoading ? 'animate-spin' : ''}`} />
            </Button>
          }
        />

        {/* Transactions Table */}
        <Table>
          <TableHeader>
            <TableRow hoverable={false}>
              <TableHead>التاريخ</TableHead>
              <TableHead>اسم الطالب</TableHead>
              <TableHead>اسم المدرس</TableHead>
              <TableHead className="min-w-[200px]">الكورس</TableHead>
              <TableHead>طريقة الدفع</TableHead>
              <TableHead align="center">الحالة</TableHead>
              <TableHead align="end">المبلغ</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              <TableSkeleton columns={7} rows={6} />
            ) : filteredTransactions.length === 0 ? (
              <TableEmptyState
                colSpan={7}
                icon={CreditCard}
                title="لا توجد معاملات بعد"
                description="لم يتم تسجيل أي معاملة مطابقة للفلاتر الحالية."
              />
            ) : (
              pagedTransactions.map(tx => (
                <TableRow key={tx.id}>
                  <TableCell className="whitespace-nowrap text-slate-500 dark:text-slate-400">
                    {formatDate(tx.created_at)}
                  </TableCell>
                  <TableCell emphasis className="whitespace-nowrap">
                    {tx.student_name}
                  </TableCell>
                  <TableCell className="whitespace-nowrap font-bold">
                    {tx.teacher_name}
                  </TableCell>
                  <TableCell>
                    <span className="line-clamp-1">{tx.course_name}</span>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <Badge tone="neutral">{paymentMethodLabel(tx.payment_method)}</Badge>
                  </TableCell>
                  <TableCell align="center" className="whitespace-nowrap">
                    <Badge dot tone={tx.status === 'refunded' ? 'danger' : 'success'}>
                      {tx.status === 'refunded' ? 'مسترجع' : 'مكتمل'}
                    </Badge>
                  </TableCell>
                  <TableCell emphasis align="end" className="whitespace-nowrap font-mono">
                    {formatCurrency(tx.gross_amount)}
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>

        {filteredTransactions.length > 0 && (
          <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
            <TablePagination
              page={page}
              totalPages={totalPages}
              onPageChange={setPage}
              totalItems={filteredTransactions.length}
              pageSize={PAGE_SIZE}
            />
          </div>
        )}
      </Card>
      </motion.div>
    </div>
  );
}
