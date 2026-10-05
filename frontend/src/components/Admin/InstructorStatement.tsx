import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { CreditCard, FileText, User, RefreshCw, TrendingUp, ArrowUpRight, Activity, Wallet, Percent } from 'lucide-react';
import { revenueService } from '../../services/revenueService';
import { logger } from '../../services/logger';
import { formatDateTime as formatDate } from '../../utils/dateUtils';
import { formatMoney as formatCurrency } from '../../utils/formatMoney';
import { paymentMethodLabel } from '../../utils/paymentMethod';
import {
  RevenueTeacherSummary as TeacherSummary,
  InstructorStatementSummary as StatementSummary,
  RevenueTransaction as Transaction,
  RevenuePayout as Payout,
} from '../../types/index';
import {
  PageHeader,
  Card,
  CardHeader,
  Button,
  Select,
  Badge,
  EmptyState,
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
import StatCard from '../Layout/StatCard';

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;

/**
 * Design-system pass — presentation only. `fetchTeachers`, `fetchStatement`,
 * both `useEffect` bodies and their dependency arrays (`[]`,
 * `[selectedTeacherId]`) and every conditional branch
 * (`!selectedTeacherId` / `isLoadingStatement` / `summary` /
 * `transactions.length === 0` / `payouts.length === 0`) are unchanged.
 */

export default function InstructorStatement() {
  const [teachers, setTeachers] = useState<TeacherSummary[]>([]);
  const [selectedTeacherId, setSelectedTeacherId] = useState<string>('');
  const [summary, setSummary] = useState<StatementSummary | null>(null);
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [payouts, setPayouts] = useState<Payout[]>([]);
  const [isLoadingTeachers, setIsLoadingTeachers] = useState(true);
  const [isLoadingStatement, setIsLoadingStatement] = useState(false);
  const [transactionsPage, setTransactionsPage] = useState(1);
  const [payoutsPage, setPayoutsPage] = useState(1);

  useEffect(() => {
    fetchTeachers();
  }, []);

  useEffect(() => {
    if (selectedTeacherId) {
      fetchStatement();
    } else {
      setSummary(null);
      setTransactions([]);
      setPayouts([]);
    }
  }, [selectedTeacherId]);

  // Switching teachers swaps both row sources entirely — reset both page
  // counters so the new teacher's tables never render stranded on a page
  // number that no longer exists.
  useEffect(() => {
    setTransactionsPage(1);
    setPayoutsPage(1);
  }, [selectedTeacherId]);

  const fetchTeachers = async () => {
    try {
      setIsLoadingTeachers(true);
      const data = await revenueService.getRevenueTeachers();
      if (data.success) {
        setTeachers(data.data);
      }
    } catch (err) {
      logger.error('Failed to fetch teachers for statement', err as Error);
    } finally {
      setIsLoadingTeachers(false);
    }
  };

  const fetchStatement = async () => {
    try {
      setIsLoadingStatement(true);

      const [summaryData, transactionsData, payoutsData] = await Promise.all([
        revenueService.getTeacherStatement(selectedTeacherId),
        revenueService.getTeacherTransactions(selectedTeacherId),
        revenueService.getTeacherPayouts(selectedTeacherId),
      ]);

      if (summaryData.success) setSummary(summaryData.data);
      if (transactionsData.success) setTransactions(transactionsData.data);
      if (payoutsData.success) setPayouts(payoutsData.data);
    } catch (err) {
      logger.error('Failed to fetch instructor statement', err as Error);
    } finally {
      setIsLoadingStatement(false);
    }
  };

  const transactionsTotalPages = Math.max(1, Math.ceil(transactions.length / PAGE_SIZE));
  const pagedTransactions = transactions.slice((transactionsPage - 1) * PAGE_SIZE, transactionsPage * PAGE_SIZE);

  const payoutsTotalPages = Math.max(1, Math.ceil(payouts.length / PAGE_SIZE));
  const pagedPayouts = payouts.slice((payoutsPage - 1) * PAGE_SIZE, payoutsPage * PAGE_SIZE);

  return (
    <div className="relative p-4 md:p-8 space-y-6 md:space-y-8" dir="rtl">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-emerald-500/10 dark:bg-emerald-500/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-amber-500/8 dark:bg-amber-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-sky-500/6 dark:bg-sky-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Header */}
      <PageHeader
        icon={FileText}
        title="كشف حساب مدرس"
        description="تتبع المبيعات والاسترجاعات والدفعات"
        className="mb-0"
        actions={
          <div className="w-full md:w-80">
            <Select
              aria-label="اختر المدرس"
              value={selectedTeacherId}
              onChange={(e) => setSelectedTeacherId(e.target.value)}
              disabled={isLoadingTeachers}
            >
              <option value="">اختر المدرس...</option>
              {teachers.map(teacher => (
                <option key={teacher.teacher_id} value={teacher.teacher_id}>
                  {teacher.name}
                </option>
              ))}
            </Select>
          </div>
        }
      />

      {!selectedTeacherId ? (
        <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0, duration: 0.35 }}>
          <Card>
            <EmptyState
              icon={User}
              title="اختر مدرساً لعرض كشف حسابه"
              description="استخدم القائمة أعلى الصفحة لاختيار المدرس المطلوب."
            />
          </Card>
        </motion.div>
      ) : isLoadingStatement ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
          {Array.from({ length: 5 }).map((_, i) => (
            <Card key={i} className="h-36">
              <Skeleton variant="block" className="w-11 h-11 rounded-2xl" />
              <Skeleton variant="text" width="45%" className="mt-4" />
              <Skeleton variant="block" width="60%" className="h-7 mt-2.5" />
            </Card>
          ))}
        </div>
      ) : (
        <>
          {/* Summary Cards */}
          {summary && (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
              <StatCard index={0} label="إجمالي أرباح المدرس" value={formatCurrency(summary.total_earnings)} icon={TrendingUp} color="blue" />
              <StatCard index={1} label="إجمالي المدفوع له" value={formatCurrency(summary.total_paid_out)} icon={ArrowUpRight} color="emerald" />
              <StatCard index={2} label="نسبته الحالية" value={`${summary.profit_percentage}%`} icon={Percent} color="purple" />
              <StatCard index={3} label="عدد العمليات" value={Number(summary.transactions_count).toLocaleString('ar-EG')} icon={Activity} color="teal" />
              <StatCard index={4} label="الرصيد المتبقي للمدرس" value={formatCurrency(summary.balance)} icon={Wallet} color="indigo" />
            </div>
          )}

          {/* Transactions Table */}
          <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 5 * 0.08, duration: 0.35 }}>
          <Card padding="none" className="overflow-hidden flex flex-col">
            <CardHeader
              title="المعاملات المالية"
              icon={Activity}
              className="p-5 pb-4 mb-0"
              action={
                <Button variant="outline" iconOnly onClick={fetchStatement} title="تحديث" aria-label="تحديث">
                  <RefreshCw className={`w-4 h-4 ${isLoadingStatement ? 'animate-spin' : ''}`} />
                </Button>
              }
            />

            <Table>
              <TableHeader>
                <TableRow hoverable={false}>
                  <TableHead>التاريخ</TableHead>
                  <TableHead>اسم الطالب</TableHead>
                  <TableHead className="min-w-[200px]">الكورس</TableHead>
                  <TableHead align="end">المبلغ</TableHead>
                  <TableHead align="center">نسبة المدرس</TableHead>
                  <TableHead align="end">نصيب المدرس</TableHead>
                  <TableHead>طريقة الدفع</TableHead>
                  <TableHead align="center">الحالة</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {transactions.length === 0 ? (
                  <TableEmptyState
                    colSpan={8}
                    icon={CreditCard}
                    title="لا توجد معاملات بعد"
                    description="لم تُسجَّل أي عملية بيع لهذا المدرس حتى الآن."
                  />
                ) : (
                  pagedTransactions.map(tx => (
                    <TableRow key={tx.id}>
                      <TableCell className="whitespace-nowrap text-slate-500 dark:text-slate-400">
                        {formatDate(tx.created_at)}
                      </TableCell>
                      <TableCell emphasis className="whitespace-nowrap">{tx.student_name}</TableCell>
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
                        <Badge tone="neutral">{paymentMethodLabel(tx.payment_method)}</Badge>
                      </TableCell>
                      <TableCell align="center" className="whitespace-nowrap">
                        <Badge dot tone={tx.status === 'refunded' ? 'danger' : 'success'}>
                          {tx.status === 'refunded' ? 'مسترجع' : 'مكتمل'}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>

            {transactions.length > 0 && (
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
          <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 6 * 0.08, duration: 0.35 }}>
          <Card padding="none" className="overflow-hidden flex flex-col">
            <CardHeader title="سجل الدفعات" icon={Wallet} className="p-5 pb-4 mb-0" />

            <Table>
              <TableHeader>
                <TableRow hoverable={false}>
                  <TableHead>التاريخ</TableHead>
                  <TableHead align="end">المبلغ المدفوع</TableHead>
                  <TableHead>طريقة الدفع</TableHead>
                  <TableHead>ملاحظات</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {payouts.length === 0 ? (
                  <TableEmptyState
                    colSpan={4}
                    icon={Wallet}
                    title="لا توجد دفعات مسجلة بعد"
                    description="سيظهر هنا كل مبلغ يتم صرفه لهذا المدرس."
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
                      <TableCell className="whitespace-nowrap font-bold">{p.payment_method}</TableCell>
                      <TableCell>{p.notes || '-'}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>

            {payouts.length > 0 && (
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
        </>
      )}
    </div>
  );
}
