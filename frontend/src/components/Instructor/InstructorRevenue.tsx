import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { revenueService } from '../../services/revenueService';
import { CreditCard, FileText, TrendingUp, ArrowUpRight, Activity, Wallet, Percent } from 'lucide-react';
import StatCard from '../Layout/StatCard';
import { formatDateTime as formatDate } from '../../utils/dateUtils';
import { paymentMethodLabel } from '../../utils/paymentMethod';
import { formatMoney as formatCurrency } from '../../utils/formatMoney';
import { logger } from '../../services/logger';
import {
  InstructorStatementSummary as StatementSummary,
  RevenueTransaction as Transaction,
  RevenuePayout as Payout,
} from '../../types/index';
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

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;

export default function InstructorRevenue() {
  const [summary, setSummary] = useState<StatementSummary | null>(null);
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [payouts, setPayouts] = useState<Payout[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [transactionsPage, setTransactionsPage] = useState(1);
  const [payoutsPage, setPayoutsPage] = useState(1);

  useEffect(() => {
    fetchData();
  }, []);

  const fetchData = async () => {
    try {
      setIsLoading(true);
      setLoadError(null);

      const [summaryData, transactionsData, payoutsData] = await Promise.all([
        revenueService.getMyStatement(),
        revenueService.getMyTransactions(),
        revenueService.getMyPayouts()
      ]);

      if (summaryData.success) setSummary(summaryData.data);
      if (transactionsData.success) setTransactions(transactionsData.data);
      if (payoutsData.success) setPayouts(payoutsData.data);
      // A refetch can shrink either list below the current page number, so
      // reset both back to page 1 whenever data is (re)loaded.
      setTransactionsPage(1);
      setPayoutsPage(1);
    } catch (err) {
      logger.error('Failed to fetch instructor revenue data', err as Error);
      setLoadError('حدث خطأ أثناء تحميل بيانات الإيرادات. حاول تحديث الصفحة مرة أخرى.');
    } finally {
      setIsLoading(false);
    }
  };

  const transactionsTotalPages = Math.max(1, Math.ceil(transactions.length / PAGE_SIZE));
  const pagedTransactions = transactions.slice((transactionsPage - 1) * PAGE_SIZE, transactionsPage * PAGE_SIZE);

  const payoutsTotalPages = Math.max(1, Math.ceil(payouts.length / PAGE_SIZE));
  const pagedPayouts = payouts.slice((payoutsPage - 1) * PAGE_SIZE, payoutsPage * PAGE_SIZE);

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Header */}
      <PageHeader
        icon={FileText}
        title="إيراداتي"
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إيراداتي' }]}
        description="متابعة أرباحك ودفعاتك المالية"
        className="mb-0"
      />

      {loadError && (
        <div className="p-4 rounded-xl border border-rose-200 dark:border-rose-500/20 bg-rose-50 dark:bg-rose-500/10 text-sm font-bold text-rose-600 dark:text-rose-400">
          {loadError}
        </div>
      )}

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
        {isLoading ? (
          Array.from({ length: 5 }).map((_, i) => (
            <Card key={i} className="h-36">
              <Skeleton variant="block" className="w-10 h-10 rounded-xl" />
              <Skeleton variant="text" width="45%" className="mt-4" />
              <Skeleton variant="block" width="35%" className="h-6 mt-2.5" />
            </Card>
          ))
        ) : summary && (
          <>
            <StatCard
              index={0}
              label="إجمالي أرباحك"
              value={formatCurrency(summary.total_earnings)}
              icon={TrendingUp}
              color="blue"
            />
            <StatCard
              index={1}
              label="إجمالي المدفوع لك"
              value={formatCurrency(summary.total_paid_out)}
              icon={ArrowUpRight}
              color="emerald"
            />
            <StatCard
              index={2}
              label="نسبتك الحالية"
              value={`${summary.profit_percentage}%`}
              icon={Percent}
              color="purple"
            />
            <StatCard
              index={3}
              label="عدد العمليات"
              value={Number(summary.transactions_count).toLocaleString('ar-EG')}
              icon={Activity}
              color="teal"
            />

            {/*
              Bespoke highlight variant for the page's primary figure (available
              balance) — deliberately NOT the flat shared StatCard, this one is
              meant to stand out with a gradient fill.
            */}
            <motion.div
              initial={{ opacity: 0, y: 15 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 4 * 0.08, duration: 0.35 }}
              className="h-full"
            >
              <Card
                radius="panel"
                className="group relative h-full overflow-hidden flex flex-col justify-between transition-shadow hover:shadow-md bg-gradient-to-br from-indigo-600 to-purple-600 border-transparent text-white shadow-lg shadow-indigo-600/20"
              >
                <div className="absolute inset-x-0 top-0 h-1 bg-white/30" />
                <div className="flex items-center justify-between gap-3 mb-4">
                  <h3 className="text-xs sm:text-sm font-bold text-indigo-100">
                    الرصيد المتاح لك
                  </h3>
                  <div className="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 transition-transform group-hover:scale-110 bg-white/20 text-white">
                    <Wallet className="w-5 h-5" />
                  </div>
                </div>
                <div className="text-xl sm:text-2xl font-black font-mono">
                  {formatCurrency(summary.balance)}
                </div>
              </Card>
            </motion.div>
          </>
        )}
      </div>

      {/* Transactions Table */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 5 * 0.08, duration: 0.35 }}
      >
        <Card radius="panel" padding="none" className="overflow-hidden">
          <CardHeader
            className="p-5 sm:p-6 mb-0 bg-slate-50/60 dark:bg-slate-800/20"
            icon={Activity}
            title="المعاملات المالية"
          />

          <Table>
            <TableHeader>
              <tr>
                <TableHead>التاريخ</TableHead>
                <TableHead>اسم الطالب</TableHead>
                <TableHead className="min-w-[200px] whitespace-normal">الكورس</TableHead>
                <TableHead align="end">المبلغ</TableHead>
                <TableHead align="center">نسبتك</TableHead>
                <TableHead align="end">نصيبك من العملية</TableHead>
                <TableHead>طريقة الدفع</TableHead>
                <TableHead align="center">الحالة</TableHead>
              </tr>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <TableRow key={i}>
                    <TableCell colSpan={8}>
                      <Skeleton variant="text" width="100%" />
                    </TableCell>
                  </TableRow>
                ))
              ) : transactions.length === 0 ? (
                <TableEmptyState
                  colSpan={8}
                  icon={CreditCard}
                  title="لا توجد معاملات بعد"
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
                    <TableCell>
                      <span className="line-clamp-1">{tx.course_name}</span>
                    </TableCell>
                    <TableCell align="end" className="whitespace-nowrap font-mono font-bold">
                      {formatCurrency(tx.gross_amount)}
                    </TableCell>
                    <TableCell align="center" className="whitespace-nowrap font-bold text-indigo-600 dark:text-indigo-400">
                      {tx.teacher_profit_percentage}%
                    </TableCell>
                    <TableCell align="end" className="whitespace-nowrap font-mono font-black text-emerald-600 dark:text-emerald-400">
                      {formatCurrency(tx.teacher_earnings ?? 0)}
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <Badge size="sm">{paymentMethodLabel(tx.payment_method)}</Badge>
                    </TableCell>
                    <TableCell align="center" className="whitespace-nowrap">
                      {/*
                        Backend RevenueTransaction only ever writes `status`
                        as 'paid' or 'refunded' (RevenueTransaction::
                        STATUS_PAID / STATUS_REFUNDED) — never 'completed'.
                        Checking for 'completed' here always failed, so every
                        transaction, paid or refunded alike, fell into the
                        else branch and showed as "مسترجع" (refunded).
                      */}
                      <Badge size="sm" tone={tx.status === 'refunded' ? 'danger' : 'success'}>
                        {tx.status === 'refunded' ? 'مسترجع' : 'مكتمل'}
                      </Badge>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>

          {!isLoading && transactions.length > 0 && (
            <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
              <TablePagination
                page={transactionsPage}
                totalPages={transactionsTotalPages}
                onPageChange={setTransactionsPage}
                totalItems={transactions.length}
                pageSize={PAGE_SIZE}
              />
            </div>
          )}
        </Card>
      </motion.div>

      {/* Payouts Table */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 6 * 0.08, duration: 0.35 }}
      >
        <Card radius="panel" padding="none" className="overflow-hidden">
          <CardHeader
            className="p-5 sm:p-6 mb-0 bg-slate-50/60 dark:bg-slate-800/20"
            icon={Wallet}
            title="سجل الدفعات المستلمة"
          />

          <Table>
            <TableHeader>
              <tr>
                <TableHead>التاريخ</TableHead>
                <TableHead align="end">المبلغ المستلم</TableHead>
                <TableHead>طريقة الدفع</TableHead>
                <TableHead>ملاحظات</TableHead>
              </tr>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                Array.from({ length: 3 }).map((_, i) => (
                  <TableRow key={i}>
                    <TableCell colSpan={4}>
                      <Skeleton variant="text" width="100%" />
                    </TableCell>
                  </TableRow>
                ))
              ) : payouts.length === 0 ? (
                <TableEmptyState
                  colSpan={4}
                  icon={Wallet}
                  title="لا توجد دفعات مسجلة بعد"
                />
              ) : (
                pagedPayouts.map(p => (
                  <TableRow key={p.id}>
                    <TableCell className="whitespace-nowrap text-slate-500 dark:text-slate-400">
                      {formatDate(p.created_at)}
                    </TableCell>
                    <TableCell align="end" className="whitespace-nowrap font-mono font-black text-indigo-600 dark:text-indigo-400">
                      {formatCurrency(p.amount)}
                    </TableCell>
                    <TableCell className="whitespace-nowrap font-bold text-slate-700 dark:text-slate-300">
                      {p.payment_method}
                    </TableCell>
                    <TableCell>
                      {p.notes || '-'}
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>

          {!isLoading && payouts.length > 0 && (
            <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
              <TablePagination
                page={payoutsPage}
                totalPages={payoutsTotalPages}
                onPageChange={setPayoutsPage}
                totalItems={payouts.length}
                pageSize={PAGE_SIZE}
              />
            </div>
          )}
        </Card>
      </motion.div>
    </div>
  );
}
