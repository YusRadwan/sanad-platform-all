import React, { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { Search, AlertCircle, Calendar, CreditCard, Undo2, X, Trash2, ListChecks, Ban } from 'lucide-react';
import { revenueService } from '../../services/revenueService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { formatDateTime as formatDate } from '../../utils/dateUtils';
import { formatMoney as formatCurrency, formatNumber } from '../../utils/formatMoney';
import { paymentMethodLabel } from '../../utils/paymentMethod';
import { RevenueTransaction as Transaction } from '../../types/index';
import {
  PageHeader, Card, CardHeader, Button, Input, Textarea, FormField, Badge,
  Toolbar, SearchBox, Dialog,
  Table, TableHeader, TableBody, TableRow, TableHead, TableCell, TableEmptyState, TableSkeleton,
  TablePagination,
} from '../UI';
import StatCard from '../Layout/StatCard';

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;

/**
 * Design-system pass — presentation only. `fetchTransactions`,
 * `handleOpenRefundModal`, `handleProcessRefund`, `handleResetFilters`, the
 * `filteredTransactions` predicate, every `useState` and the `useEffect`
 * dependency array `[dateFrom, dateTo]` are unchanged. The refund modal still
 * submits through the same `<form onSubmit={handleProcessRefund}>`; only its
 * shell is now the shared Dialog primitive. The stat row above the filter
 * card is derived entirely from the already-loaded `filteredTransactions`
 * array — nothing fetched or invented.
 */

export default function RefundManagement() {
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);

  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  
  // Modal State
  const [selectedTx, setSelectedTx] = useState<Transaction | null>(null);
  const [refundReason, setRefundReason] = useState('');
  const [showModal, setShowModal] = useState(false);
  
  const { notify } = useCenteredNotification();

  useEffect(() => {
    fetchTransactions();
  }, [dateFrom, dateTo]);

  const fetchTransactions = async () => {
    try {
      setIsLoading(true);

      const params = new URLSearchParams();
      if (dateFrom) params.append('dateFrom', dateFrom);
      if (dateTo) params.append('dateTo', dateTo);
      const queryString = params.toString();

      const data = await revenueService.getTransactions(queryString || undefined);

      if (data.success) {
        setTransactions(data.data);
      } else {
        notify(data.message || 'حدث خطأ أثناء تحميل المعاملات', 'error');
      }
    } catch (err) {
      logger.error('Failed to fetch transactions for refund management', err as Error);
      notify('خطأ في الاتصال بالخادم أثناء تحميل المعاملات', 'error');
    } finally {
      setIsLoading(false);
    }
  };

  const handleOpenRefundModal = (tx: Transaction) => {
    setSelectedTx(tx);
    setRefundReason('');
    setShowModal(true);
  };

  const handleProcessRefund = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedTx) return;

    try {
      setIsSubmitting(true);

      const data = await revenueService.requestRefund(selectedTx.id, refundReason);

      if (data.success) {
        notify('تم تسجيل طلب الاسترجاع بحالة «قيد المراجعة»', 'success');
        setShowModal(false);
        setSelectedTx(null);
        setRefundReason('');

        // Refresh transactions list
        await fetchTransactions();
      } else {
        notify(data.message || 'حدث خطأ أثناء معالجة الاسترجاع', 'error');
      }
    } catch (err: any) {
      notify(err.message || 'حدث خطأ في الاتصال بالخادم', 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleResetFilters = () => {
    setDateFrom('');
    setDateTo('');
    setSearchQuery('');
  };

  // Filter transactions in frontend by student or course name
  const filteredTransactions = transactions.filter(tx => {
    const query = searchQuery.trim().toLowerCase();
    if (!query) return true;
    
    const studentMatch = tx.student_name?.toLowerCase().includes(query);
    const courseMatch = tx.course_name?.toLowerCase().includes(query);
    return studentMatch || courseMatch;
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
    <div className="relative p-4 md:p-8 space-y-6 md:space-y-8" dir="rtl">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-rose-500/10 dark:bg-rose-500/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-amber-500/8 dark:bg-amber-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Header */}
      <PageHeader
        icon={Undo2}
        title="إدارة عمليات الاسترجاع"
        description="إدارة المبالغ المستردة والإلغاءات"
        className="mb-0"
      />

      {/* Stat row — total transactions in view, refundable count, refunded count */}
      {!isLoading && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
          <StatCard
            index={0}
            icon={ListChecks}
            label="إجمالي المعاملات المعروضة"
            value={formatNumber(filteredTransactions.length)}
            color="blue"
          />
          <StatCard
            index={1}
            icon={CreditCard}
            label="معاملات قابلة للاسترجاع"
            value={formatNumber(filteredTransactions.filter(tx => tx.status !== 'refunded').length)}
            color="emerald"
          />
          <StatCard
            index={2}
            icon={Ban}
            label="معاملات مسترجعة بالفعل"
            value={formatNumber(filteredTransactions.filter(tx => tx.status === 'refunded').length)}
            color="rose"
          />
        </div>
      )}

      {/* Filters Card */}
      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 3 * 0.08, duration: 0.35 }}>
      <Card className="space-y-4">
        <CardHeader title="خيارات التصفية والبحث" icon={Search} />

        <Toolbar
          search={
            <FormField label="البحث باسم الطالب أو الكورس">
              {({ id }) => (
                <SearchBox
                  id={id}
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  onClear={() => setSearchQuery('')}
                  placeholder="اكتب اسم الطالب أو اسم الكورس للبحث..."
                />
              )}
            </FormField>
          }
          filters={
            <>
              <FormField label="من تاريخ" className="w-auto">
                {({ id }) => (
                  <Input
                    id={id}
                    type="date"
                    icon={Calendar}
                    value={dateFrom}
                    onChange={(e) => setDateFrom(e.target.value)}
                    className="min-w-44"
                  />
                )}
              </FormField>
              <FormField label="إلى تاريخ" className="w-auto">
                {({ id }) => (
                  <Input
                    id={id}
                    type="date"
                    icon={Calendar}
                    value={dateTo}
                    onChange={(e) => setDateTo(e.target.value)}
                    className="min-w-44"
                  />
                )}
              </FormField>
            </>
          }
          className="items-end"
        />

        {(searchQuery || dateFrom || dateTo) && (
          <div className="flex justify-end pt-2">
            <Button variant="ghost" size="sm" icon={X} onClick={handleResetFilters}>
              إعادة تعيين الفلاتر
            </Button>
          </div>
        )}
      </Card>
      </motion.div>

      {/* Transactions List */}
      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 4 * 0.08, duration: 0.35 }}>
      <Card padding="none" className="overflow-hidden flex flex-col">
        <CardHeader
          title="المعاملات المالية القابلة للاسترجاع"
          icon={CreditCard}
          className="p-5 pb-4 mb-0"
          action={
            <Badge tone="neutral">عدد المعاملات: {filteredTransactions.length}</Badge>
          }
        />

        <Table>
          <TableHeader>
            <TableRow hoverable={false}>
              <TableHead>التاريخ</TableHead>
              <TableHead>اسم الطالب</TableHead>
              <TableHead>اسم المدرس</TableHead>
              <TableHead className="min-w-[200px]">اسم الكورس</TableHead>
              <TableHead align="end">المبلغ</TableHead>
              <TableHead>طريقة الدفع</TableHead>
              <TableHead align="center">الحالة</TableHead>
              <TableHead align="center">الإجراء</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              <TableSkeleton columns={8} rows={6} />
            ) : filteredTransactions.length === 0 ? (
              <TableEmptyState
                colSpan={8}
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
                  <TableCell emphasis className="whitespace-nowrap">{tx.student_name}</TableCell>
                  <TableCell className="whitespace-nowrap">{tx.teacher_name}</TableCell>
                  <TableCell>
                    <span className="line-clamp-1">{tx.course_name}</span>
                  </TableCell>
                  <TableCell emphasis align="end" className="whitespace-nowrap font-mono">
                    {formatCurrency(tx.gross_amount)}
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <Badge tone="neutral">{paymentMethodLabel(tx.payment_method)}</Badge>
                  </TableCell>
                  <TableCell align="center" className="whitespace-nowrap">
                    <Badge dot tone={tx.status === 'refunded' ? 'danger' : 'success'}>
                      {tx.status === 'refunded' ? 'مسترجع' : 'مكتمل'}
                    </Badge>
                  </TableCell>
                  <TableCell align="center" className="whitespace-nowrap">
                    {tx.status !== 'refunded' ? (
                      <Button
                        size="sm"
                        variant="danger"
                        icon={Undo2}
                        onClick={() => handleOpenRefundModal(tx)}
                        className="mx-auto shadow-lg shadow-rose-500/20 hover:shadow-xl hover:shadow-rose-500/30 transition-shadow"
                      >
                        استرجاع
                      </Button>
                    ) : (
                      <Button size="sm" variant="secondary" icon={Undo2} disabled className="mx-auto">
                        مسترجع
                      </Button>
                    )}
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

      {/* Refund Modal Overlay */}
      {showModal && selectedTx && (
        <Dialog
          open={showModal}
          onClose={() => setShowModal(false)}
          title="تأكيد عملية الاسترجاع"
          icon={Undo2}
          tone="danger"
          size="md"
        >
          {/* Modal Body / Form */}
          <form onSubmit={handleProcessRefund} className="space-y-6">

            {/* Alert Content */}
            <div className="bg-rose-50 dark:bg-rose-950/30 border border-rose-200/70 dark:border-rose-900/50 rounded-2xl p-4 text-rose-800 dark:text-rose-300 space-y-2">
              <div className="flex items-center gap-2">
                <AlertCircle className="w-5 h-5 text-rose-600 dark:text-rose-400 shrink-0" />
                <p className="font-black text-sm">تنبيه هام!</p>
              </div>
              <p className="text-sm leading-relaxed font-medium">
                {/* The copy here used to promise "سيتم إلغاء اشتراكه في الكورس
                    تلقائياً". POST /revenue/refunds only ever inserts a refunds
                    row with status='pending' (RevenueService::requestRefund):
                    the enrolment stays active and the transaction stays 'paid'.
                    Even approval (RevenueService::processRefund) only flips the
                    transaction to 'refunded' — it never touches the enrolment.
                    Wording corrected to describe what actually happens. */}
                هل أنت متأكد من تسجيل طلب استرجاع بمبلغ <span className="font-mono font-black underline">{formatCurrency(selectedTx.gross_amount)}</span> للطالب <span className="font-black underline">{selectedTx.student_name}</span>؟ سيُسجَّل الطلب بحالة «قيد المراجعة» ولن يتم إلغاء اشتراك الطالب تلقائياً.
              </p>
            </div>

            {/* Details List */}
            <div className="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl space-y-3 text-sm border border-slate-200/70 dark:border-slate-800">
              <div className="flex justify-between gap-4">
                <span className="font-bold text-slate-500 dark:text-slate-400">اسم الكورس:</span>
                <span className="font-black text-slate-900 dark:text-white">{selectedTx.course_name}</span>
              </div>
              <div className="flex justify-between gap-4">
                <span className="font-bold text-slate-500 dark:text-slate-400">المدرس:</span>
                <span className="font-black text-slate-900 dark:text-white">{selectedTx.teacher_name}</span>
              </div>
              <div className="flex justify-between gap-4">
                <span className="font-bold text-slate-500 dark:text-slate-400">طريقة الدفع:</span>
                <Badge tone="brand">{paymentMethodLabel(selectedTx.payment_method)}</Badge>
              </div>
            </div>

            {/* Reason input */}
            <FormField label="سبب الاسترجاع (اختياري)">
              {({ id }) => (
                <Textarea
                  id={id}
                  value={refundReason}
                  onChange={(e) => setRefundReason(e.target.value)}
                  placeholder="اكتب سبب إلغاء الاشتراك والاسترجاع..."
                  rows={3}
                />
              )}
            </FormField>

            {/* Action Buttons */}
            <div className="flex gap-4">
              <Button
                type="button"
                variant="secondary"
                className="w-1/2"
                onClick={() => setShowModal(false)}
              >
                إلغاء
              </Button>
              <Button
                type="submit"
                variant="danger"
                className="w-1/2"
                icon={Trash2}
                isLoading={isSubmitting}
                disabled={isSubmitting}
              >
                تأكيد الاسترجاع
              </Button>
            </div>

          </form>
        </Dialog>
      )}
    </div>
  );
}
