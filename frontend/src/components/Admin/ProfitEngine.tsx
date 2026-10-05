import { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { Check, X, Edit2, Coins, TrendingUp, Users, Percent, ArrowUpCircle, Wallet } from 'lucide-react';
import { revenueService } from '../../services/revenueService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { RevenueTeacherSummary as TeacherSummary } from '../../types/index';
import { formatNumber, formatMoney } from '../../utils/formatMoney';
import { AVATAR_PLACEHOLDER } from '../../utils/imagePlaceholders';
import {
  PageHeader,
  Card,
  Badge,
  Button,
  Input,
  EmptyState,
  TablePagination,
} from '../UI';
import StatCard from '../Layout/StatCard';

/** Cards per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 9;

/**
 * Full redesign pass: presentation only. `fetchTeachers`, `handleSave`,
 * `showToast`, every `useState` and the `useEffect` dependency array `[]`
 * are unchanged — the plain data table is replaced by a grid of teacher
 * profile cards (photo + name + inline-editable percentage + balance), but
 * the inline edit-in-place mechanics (`editingId` / `editValue`, Edit2 to
 * enter edit mode, Check/X to save/cancel) still drive the exact same
 * state transitions as before.
 *
 * The stat row above the grid is derived entirely client-side from the same
 * `teachers` array the grid renders — no new fields, no new endpoint. The
 * per-card avatar uses `teacher.avatar_url` (now returned by
 * GET /revenue/teachers) with the same `AVATAR_PLACEHOLDER` fallback used
 * everywhere else in the app (see Admin/ManageTeachers.tsx).
 */

export default function ProfitEngine() {
  const [teachers, setTeachers] = useState<TeacherSummary[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editValue, setEditValue] = useState<string>('');
  const [page, setPage] = useState(1);

  const { notify } = useCenteredNotification();
  const showToast = (text: string, type: 'success' | 'error' = 'success') => {
    notify(text, type);
  };

  const fetchTeachers = async () => {
    try {
      setIsLoading(true);
      const data = await revenueService.getRevenueTeachers();

      if (data.success) {
        setTeachers(data.data);
        setPage(1);
      } else {
        throw new Error(data.message || 'Failed to fetch teachers');
      }
    } catch (err: any) {
      showToast(err.response?.data?.message || err.message || 'حدث خطأ أثناء تحميل البيانات', 'error');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchTeachers();
  }, []);

  const handleSave = async (teacherId: string) => {
    const numValue = parseFloat(editValue);
    if (isNaN(numValue) || numValue < 0 || numValue > 100) {
      showToast('يجب أن تكون النسبة بين 0 و 100', 'error');
      return;
    }

    try {
      const data = await revenueService.updateTeacherPercentage(teacherId, numValue);
      if (data.success) {
        showToast('تم تحديث النسبة بنجاح', 'success');
        setTeachers(teachers.map(t => t.teacher_id === teacherId ? { ...t, profit_percentage: numValue } : t));
        setEditingId(null);
      } else {
        throw new Error(data.message || 'فشل التحديث');
      }
    } catch (err: any) {
      showToast(err.response?.data?.message || err.message || 'حدث خطأ أثناء الحفظ', 'error');
    }
  };

  const totalPages = Math.max(1, Math.ceil(teachers.length / PAGE_SIZE));
  const pagedTeachers = teachers.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  // Derived purely from the already-loaded `teachers` array — nothing fetched or invented.
  const avgPercentage = teachers.length > 0
    ? teachers.reduce((sum, t) => sum + Number(t.profit_percentage || 0), 0) / teachers.length
    : 0;
  const maxPercentage = teachers.length > 0
    ? Math.max(...teachers.map(t => Number(t.profit_percentage || 0)))
    : 0;

  return (
    <div className="relative p-4 md:p-8 space-y-6 md:space-y-8" dir="rtl">
      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-amber-500/10 dark:bg-amber-500/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-emerald-500/8 dark:bg-emerald-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-blue-500/6 dark:bg-blue-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Header */}
      <PageHeader
        icon={Coins}
        title="محرك الأرباح"
        description="إدارة نسب أرباح المدرسين ومتابعة أرصدتهم المتاحة"
        className="mb-0"
      />

      {/* Stat row — total teachers, average share %, highest share % */}
      {!isLoading && teachers.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
          <StatCard
            index={0}
            icon={Users}
            label="إجمالي المدرسين"
            value={formatNumber(teachers.length)}
            color="blue"
          />
          <StatCard
            index={1}
            icon={Percent}
            label="متوسط نسبة الربح"
            value={`${formatNumber(avgPercentage, { maximumFractionDigits: 2 })}%`}
            color="emerald"
          />
          <StatCard
            index={2}
            icon={ArrowUpCircle}
            label="أعلى نسبة ربح مسجلة"
            value={`${formatNumber(maxPercentage, { maximumFractionDigits: 2 })}%`}
            color="amber"
          />
        </div>
      )}

      {/* Teachers grid */}
      {isLoading ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
          {Array.from({ length: 6 }).map((_, i) => (
            <Card key={i} padding="none" className="overflow-hidden animate-pulse">
              <div className="h-16 bg-slate-100 dark:bg-slate-800" />
              <div className="px-5 pb-5 -mt-8">
                <div className="w-16 h-16 rounded-2xl bg-slate-200 dark:bg-slate-700 border-4 border-white dark:border-slate-900" />
                <div className="h-4 w-2/3 bg-slate-200 dark:bg-slate-700 rounded mt-4" />
                <div className="h-3 w-1/3 bg-slate-200 dark:bg-slate-700 rounded mt-2" />
                <div className="h-9 w-full bg-slate-100 dark:bg-slate-800 rounded-xl mt-4" />
              </div>
            </Card>
          ))}
        </div>
      ) : teachers.length === 0 ? (
        <Card padding="none" className="overflow-hidden">
          <EmptyState
            icon={TrendingUp}
            title="لا يوجد مدرسون مسجلون بعد"
            description="ستظهر نسب الأرباح هنا بمجرد إضافة أول مدرس إلى المنصة."
          />
        </Card>
      ) : (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            {pagedTeachers.map((teacher, idx) => {
              const isEditing = editingId === teacher.teacher_id;
              const percentage = Number(teacher.profit_percentage || 0);
              const hasBalance = teacher.available_balance !== undefined && teacher.available_balance !== null;

              return (
                <motion.div
                  key={teacher.teacher_id}
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: (idx % PAGE_SIZE) * 0.05, duration: 0.3 }}
                >
                  <Card padding="none" className="overflow-hidden h-full flex flex-col group">
                    {/* Header strip with soft gradient */}
                    <div className="h-16 bg-gradient-to-l from-amber-400/20 via-emerald-400/10 to-blue-400/10 dark:from-amber-500/15 dark:via-emerald-500/10 dark:to-blue-500/10 relative">
                      <div className="absolute top-3 left-3">
                        <Badge tone="brand" size="sm" className="font-mono">
                          {percentage.toFixed(2)}%
                        </Badge>
                      </div>
                    </div>

                    <div className="px-5 pb-5 flex-1 flex flex-col">
                      {/* Avatar */}
                      <div className="-mt-8 mb-3">
                        <div className="w-16 h-16 rounded-2xl overflow-hidden border-4 border-white dark:border-slate-900 shadow-md bg-slate-100 dark:bg-slate-800">
                          <img
                            src={teacher.avatar_url || AVATAR_PLACEHOLDER}
                            alt={teacher.name}
                            className="w-full h-full object-cover"
                            referrerPolicy="no-referrer"
                            onError={(e) => {
                              e.currentTarget.onerror = null;
                              e.currentTarget.src = AVATAR_PLACEHOLDER;
                            }}
                          />
                        </div>
                      </div>

                      {/* Name */}
                      <h3 className="text-base font-bold text-slate-900 dark:text-white truncate" title={teacher.name}>
                        {teacher.name}
                      </h3>

                      {/* Available balance */}
                      <div className="flex items-center gap-1.5 mt-1 text-xs font-semibold text-slate-500 dark:text-slate-400">
                        <Wallet className="w-3.5 h-3.5 shrink-0" />
                        <span>الرصيد المتاح: {hasBalance ? formatMoney(teacher.available_balance) : '—'}</span>
                      </div>

                      <div className="flex-1" />

                      {/* Percentage editor */}
                      <div className="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800">
                        <AnimatePresence mode="wait" initial={false}>
                          {isEditing ? (
                            <motion.div
                              key="editing"
                              initial={{ opacity: 0 }}
                              animate={{ opacity: 1 }}
                              exit={{ opacity: 0 }}
                              transition={{ duration: 0.15 }}
                              className="flex items-center gap-2"
                            >
                              <div className="flex items-center gap-1.5 flex-1">
                                <Input
                                  size="sm"
                                  type="number"
                                  min="0"
                                  max="100"
                                  step="0.01"
                                  aria-label="نسبة الربح"
                                  value={editValue}
                                  onChange={(e) => setEditValue(e.target.value)}
                                  className="text-left font-mono"
                                  dir="ltr"
                                  autoFocus
                                />
                                <span className="font-bold text-slate-500 dark:text-slate-400">%</span>
                              </div>
                              <Button
                                size="sm"
                                iconOnly
                                variant="success"
                                onClick={() => handleSave(teacher.teacher_id)}
                                title="حفظ"
                                aria-label="حفظ"
                              >
                                <Check className="w-3.5 h-3.5" />
                              </Button>
                              <Button
                                size="sm"
                                iconOnly
                                variant="secondary"
                                onClick={() => setEditingId(null)}
                                title="إلغاء"
                                aria-label="إلغاء"
                              >
                                <X className="w-3.5 h-3.5" />
                              </Button>
                            </motion.div>
                          ) : (
                            <motion.div
                              key="display"
                              initial={{ opacity: 0 }}
                              animate={{ opacity: 1 }}
                              exit={{ opacity: 0 }}
                              transition={{ duration: 0.15 }}
                              className="flex items-center justify-between gap-2"
                            >
                              <div className="flex items-center gap-1.5">
                                <Percent className="w-3.5 h-3.5 text-slate-400 dark:text-slate-500" />
                                <span className="text-sm font-bold text-slate-700 dark:text-slate-200">نسبة الربح</span>
                              </div>
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                  setEditingId(teacher.teacher_id);
                                  setEditValue(String(teacher.profit_percentage));
                                }}
                                title="تعديل النسبة"
                                aria-label="تعديل النسبة"
                                className="gap-1.5"
                              >
                                <Edit2 className="w-3.5 h-3.5" />
                                تعديل
                              </Button>
                            </motion.div>
                          )}
                        </AnimatePresence>
                      </div>
                    </div>
                  </Card>
                </motion.div>
              );
            })}
          </div>

          <Card padding="none" className="overflow-hidden">
            <div className="px-5 py-4">
              <TablePagination
                page={page}
                totalPages={totalPages}
                onPageChange={setPage}
                totalItems={teachers.length}
                pageSize={PAGE_SIZE}
              />
            </div>
          </Card>
        </>
      )}

    </div>
  );
}
