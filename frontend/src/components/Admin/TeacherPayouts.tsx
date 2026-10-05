import React, { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { revenueService } from '../../services/revenueService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { User, Wallet, RefreshCw, Send, CheckCircle2, AlertCircle, CreditCard, FileText, Banknote, History } from 'lucide-react';
import { formatDateTime as formatDate } from '../../utils/dateUtils';
import { formatMoney as formatCurrency, formatNumber } from '../../utils/formatMoney';
import {
  RevenueTeacherSummary as TeacherSummary,
  RevenuePayout as Payout,
} from '../../types/index';
import {
  PageHeader,
  Card,
  CardHeader,
  Button,
  Input,
  Select,
  Textarea,
  FormField,
  Badge,
  EmptyState,
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
 * Design-system pass — presentation only. `fetchTeachers`, `fetchPayouts`,
 * `handleSubmit`, both `useEffect` bodies and dependency arrays (`[]` and
 * `[selectedTeacherId]`), the `selectedTeacher` / `availableBalance`
 * derivations and every conditional (`!selectedTeacherId`,
 * `availableBalance > 0`, `isLoadingPayouts`, `payouts.length === 0`) are
 * unchanged — only what each branch renders was restyled. The two stat cards
 * above the two-column layout are derived from the already-loaded
 * `availableBalance` and `payouts` array — nothing new fetched or invented.
 */

export default function TeacherPayouts() {
  const [teachers, setTeachers] = useState<TeacherSummary[]>([]);
  const [selectedTeacherId, setSelectedTeacherId] = useState<string>('');
  const [payouts, setPayouts] = useState<Payout[]>([]);
  
  const [isLoadingTeachers, setIsLoadingTeachers] = useState(true);
  const [isLoadingPayouts, setIsLoadingPayouts] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  
  const [amount, setAmount] = useState<string>('');
  const [paymentMethod, setPaymentMethod] = useState<string>('فودافون كاش');
  const [notes, setNotes] = useState<string>('');
  
  const { notify } = useCenteredNotification();
  const [page, setPage] = useState(1);

  const selectedTeacher = teachers.find(t => t.teacher_id === selectedTeacherId);
  const availableBalance = selectedTeacher ? parseFloat((selectedTeacher.available_balance ?? 0).toString()) : 0;

  useEffect(() => {
    fetchTeachers();
  }, []);

  useEffect(() => {
    if (selectedTeacherId) {
      fetchPayouts();
      setAmount('');
      setNotes('');
    } else {
      setPayouts([]);
    }
  }, [selectedTeacherId]);

  // Switching the scoped teacher swaps the payouts row source entirely —
  // reset the page counter so the table never renders stranded on a page
  // number that no longer exists for the newly selected teacher.
  useEffect(() => {
    setPage(1);
  }, [selectedTeacherId]);

  const fetchTeachers = async () => {
    try {
      setIsLoadingTeachers(true);
      const data = await revenueService.getRevenueTeachers();
      if (data.success) {
        setTeachers(data.data);
      }
    } catch (err) {
      logger.error('Failed to fetch teachers for payouts', err as Error);
    } finally {
      setIsLoadingTeachers(false);
    }
  };

  const fetchPayouts = async () => {
    try {
      setIsLoadingPayouts(true);
      const data = await revenueService.getTeacherPayouts(selectedTeacherId);
      if (data.success) {
        setPayouts(data.data);
      }
    } catch (err) {
      logger.error('Failed to fetch teacher payouts', err as Error);
    } finally {
      setIsLoadingPayouts(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!selectedTeacherId) {
      notify('يرجى اختيار مدرس', 'error');
      return;
    }

    const payoutAmount = parseFloat(amount);

    if (isNaN(payoutAmount) || payoutAmount <= 0) {
      notify('يرجى إدخال مبلغ صحيح أكبر من الصفر', 'error');
      return;
    }

    if (payoutAmount > availableBalance) {
      notify(`المبلغ يتجاوز الرصيد المتاح (${formatCurrency(availableBalance)})`, 'error');
      return;
    }

    try {
      setIsSubmitting(true);

      const data = await revenueService.createPayout(selectedTeacherId, payoutAmount, paymentMethod, notes || undefined);

      if (data.success) {
        notify('تم تسجيل الدفعة بنجاح', 'success');
        setAmount('');
        setNotes('');
        setPaymentMethod('فودافون كاش');
        // Refresh data
        await fetchTeachers();
        await fetchPayouts();
      } else {
        notify(data.message || 'حدث خطأ أثناء تسجيل الدفعة', 'error');
      }
    } catch (err: any) {
      notify(err.message || 'حدث خطأ في الاتصال بالخادم', 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const totalPages = Math.max(1, Math.ceil(payouts.length / PAGE_SIZE));
  const pagedPayouts = payouts.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  // Derived purely from the already-loaded `payouts` array — nothing fetched or invented.
  const totalPaidOut = payouts.reduce((sum, p) => sum + parseFloat((p.amount ?? 0).toString()), 0);

  return (
    <div className="relative p-4 md:p-8 space-y-6 md:space-y-8" dir="rtl">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-amber-500/10 dark:bg-amber-500/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-emerald-500/8 dark:bg-emerald-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-sky-500/6 dark:bg-sky-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Header */}
      <PageHeader
        icon={Wallet}
        title="دفعات المدرسين"
        description="متابعة دفعات المدرسين المالية"
        className="mb-0"
        actions={
          <div className="w-full md:w-96">
            <Select
              aria-label="اختر المدرس"
              value={selectedTeacherId}
              onChange={(e) => setSelectedTeacherId(e.target.value)}
              disabled={isLoadingTeachers}
            >
              <option value="">اختر المدرس...</option>
              {teachers.map(teacher => (
                <option key={teacher.teacher_id} value={teacher.teacher_id}>
                  {teacher.name} (الرصيد المتاح: {formatCurrency(teacher.available_balance ?? 0)})
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
              title="اختر مدرساً لتسجيل دفعة وعرض سجل الدفعات"
              description="استخدم القائمة أعلى الصفحة لاختيار المدرس المطلوب."
            />
          </Card>
        </motion.div>
      ) : (
        <div className="space-y-6 md:space-y-8">

          {/* Stat row — available balance, total paid out, payouts count */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
            <StatCard
              index={0}
              icon={Banknote}
              label="الرصيد المتاح للمدرس"
              value={`${formatNumber(availableBalance, { maximumFractionDigits: 2 })} ج.م`}
              color={availableBalance > 0 ? 'emerald' : 'rose'}
            />
            <StatCard
              index={1}
              icon={History}
              label="إجمالي المدفوع له سابقاً"
              value={`${formatNumber(totalPaidOut, { maximumFractionDigits: 2 })} ج.م`}
              color="indigo"
            />
            <StatCard
              index={2}
              icon={Wallet}
              label="عدد الدفعات المسجلة"
              value={formatNumber(payouts.length)}
              color="emerald"
            />
          </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">

          {/* Form Section */}
          <motion.div
            className="lg:col-span-1 space-y-6"
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 3 * 0.08, duration: 0.35 }}
          >
            <Card>
              {availableBalance > 0 ? (
                <div className="bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200/70 dark:border-emerald-500/20 rounded-2xl p-4 mb-6 flex items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-xs font-bold text-emerald-600 dark:text-emerald-400 mb-1">الرصيد المتاح للمدرس</p>
                    <p className="text-2xl font-black text-emerald-700 dark:text-emerald-300 font-mono tracking-tight">{formatCurrency(availableBalance)}</p>
                  </div>
                  <div className="w-11 h-11 shrink-0 bg-emerald-100 dark:bg-emerald-500/20 rounded-xl flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                    <CheckCircle2 className="w-5 h-5" />
                  </div>
                </div>
              ) : (
                <div className="bg-rose-50 dark:bg-rose-500/10 border border-rose-200/70 dark:border-rose-500/20 rounded-2xl p-4 mb-6 flex items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-xs font-bold text-rose-600 dark:text-rose-400 mb-1">الرصيد المتاح للمدرس</p>
                    <p className="text-xl font-black text-rose-700 dark:text-rose-300 font-mono tracking-tight">{formatCurrency(availableBalance)}</p>
                  </div>
                  <div className="w-11 h-11 shrink-0 bg-rose-100 dark:bg-rose-500/20 rounded-xl flex items-center justify-center text-rose-600 dark:text-rose-400">
                    <AlertCircle className="w-5 h-5" />
                  </div>
                </div>
              )}

              <CardHeader title="تسجيل دفعة جديدة" icon={Send} />

              <form onSubmit={handleSubmit} className="space-y-5">
                <fieldset disabled={availableBalance <= 0 || isSubmitting} className="space-y-4">
                  <FormField label="المبلغ (بالجنيه)" required>
                    {({ id }) => (
                      <Input
                        id={id}
                        type="number"
                        min="1"
                        step="0.01"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        className="font-mono"
                        placeholder="مثال: 1500"
                        suffix="ج.م"
                        required
                      />
                    )}
                  </FormField>

                  <FormField label="طريقة الدفع" required>
                    {({ id }) => (
                      <Select
                        id={id}
                        value={paymentMethod}
                        onChange={(e) => setPaymentMethod(e.target.value)}
                        required
                      >
                        <option value="فودافون كاش">فودافون كاش</option>
                        <option value="انستاباي">انستاباي</option>
                        <option value="تحويل بنكي">تحويل بنكي</option>
                        <option value="كاش">كاش</option>
                      </Select>
                    )}
                  </FormField>

                  <FormField label="ملاحظات (اختياري)">
                    {({ id }) => (
                      <Textarea
                        id={id}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        rows={3}
                        placeholder="أي ملاحظات إضافية حول الدفعة..."
                      />
                    )}
                  </FormField>

                  <Button
                    type="submit"
                    fullWidth
                    size="lg"
                    icon={Send}
                    isLoading={isSubmitting}
                    className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
                  >
                    تسجيل الدفعة
                  </Button>

                  {availableBalance <= 0 && (
                    <p className="text-center text-xs font-bold text-slate-500 dark:text-slate-400 mt-3">
                      لا يوجد رصيد متاح للصرف حالياً
                    </p>
                  )}
                </fieldset>
              </form>
            </Card>
          </motion.div>

          {/* Payouts Table Section */}
          <motion.div
            className="lg:col-span-2"
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 4 * 0.08, duration: 0.35 }}
          >
            <Card padding="none" className="overflow-hidden flex flex-col h-full">
              <CardHeader
                title="سجل الدفعات السابقة"
                icon={FileText}
                className="p-5 pb-4 mb-0"
                action={
                  <Button
                    variant="outline"
                    iconOnly
                    onClick={fetchPayouts}
                    title="تحديث"
                    aria-label="تحديث"
                  >
                    <RefreshCw className={`w-4 h-4 ${isLoadingPayouts ? 'animate-spin' : ''}`} />
                  </Button>
                }
              />

              <Table containerClassName="flex-1">
                <TableHeader>
                  <TableRow hoverable={false}>
                    <TableHead>التاريخ</TableHead>
                    <TableHead align="end">المبلغ</TableHead>
                    <TableHead>طريقة الدفع</TableHead>
                    <TableHead>ملاحظات</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {isLoadingPayouts ? (
                    <TableSkeleton columns={4} rows={5} />
                  ) : payouts.length === 0 ? (
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
                        <TableCell className="whitespace-nowrap">
                          <Badge tone="neutral" icon={CreditCard}>{p.payment_method}</Badge>
                        </TableCell>
                        <TableCell>{p.notes || '-'}</TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>

              {payouts.length > 0 && (
                <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
                  <TablePagination
                    page={page}
                    totalPages={totalPages}
                    onPageChange={setPage}
                    totalItems={payouts.length}
                    pageSize={PAGE_SIZE}
                  />
                </div>
              )}
            </Card>
          </motion.div>

        </div>
        </div>
      )}
    </div>
  );
}
