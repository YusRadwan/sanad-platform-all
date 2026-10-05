import { useEffect, useMemo, useState } from 'react';
import { motion } from 'motion/react';
import {
  Wallet, Users, CheckCircle2, XCircle, Search, FileText, Calendar,
} from 'lucide-react';
import { centerService } from '../../services/centerService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import StatCard from '../Layout/StatCard';
import {
  PageHeader,
  Card,
  SectionHeader,
  Button,
  Input,
  Select,
  FormField,
  Skeleton,
  EmptyState,
  Dialog,
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
  TableEmptyState,
  Badge,
} from '../UI';

/**
 * "متابعة مدفوعات السنتر" — explicit teacher request, section 8 of which is
 * CRITICAL: this is a PURE paid/unpaid tracking log for the teacher's own
 * notes, never a financial screen. No amount/price/balance/total appears
 * anywhere on this page, and nothing here is read by any revenue/
 * subscription/financial-dashboard code — see backend
 * App\Models\CenterPaymentRecord's docblock for the enforced boundary.
 */

interface CenterGroup {
  id: string;
  center_name: string;
  grade_label: string;
  group_name: string;
  schedule_text: string;
  // 'شهر' | 'حصة' — set once at group creation (CreateCenterGroup.tsx),
  // never chosen again here. See `paymentType` below.
  billing_type: string;
}

interface PaymentRow {
  student_id: string;
  name: string | null;
  student_code: string | null;
  center_code: string | null;
  phone: string | null;
  status: 'paid' | 'unpaid';
}

type PaymentType = 'session' | 'month';

const todayIso = () => new Date().toISOString().slice(0, 10);
const currentMonthIso = () => new Date().toISOString().slice(0, 7);

export default function CenterPaymentTracking() {
  const { notify } = useCenteredNotification();

  // ── Group selection — center -> grade -> group cascade, same pattern as
  // the attendance and reports pages. ─────────────────────────────────────
  const [groups, setGroups] = useState<CenterGroup[]>([]);
  const [loadingGroups, setLoadingGroups] = useState(true);
  const [selectedCenter, setSelectedCenter] = useState('');
  const [selectedGrade, setSelectedGrade] = useState('');
  const [selectedGroupId, setSelectedGroupId] = useState('');

  useEffect(() => {
    const load = async () => {
      try {
        setLoadingGroups(true);
        const data = await centerService.getMyGroups();
        setGroups(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('CenterPaymentTracking: failed to load groups', err as Error);
        notify('تعذر تحميل مجموعاتك', 'error');
      } finally {
        setLoadingGroups(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const centers = useMemo(() => Array.from(new Set(groups.map((g) => g.center_name))).filter(Boolean), [groups]);
  const grades = useMemo(
    () => Array.from(new Set(groups.filter((g) => g.center_name === selectedCenter).map((g) => g.grade_label))).filter(Boolean),
    [groups, selectedCenter],
  );
  const groupsForSelection = useMemo(
    () => groups.filter((g) => g.center_name === selectedCenter && g.grade_label === selectedGrade),
    [groups, selectedCenter, selectedGrade],
  );

  // ── Payment type — DERIVED, not chosen here. ─────────────────────────
  //
  // "طريقة الدفع" (شهر/حصة) is a property of the GROUP, fixed once at
  // creation time (CreateCenterGroup.tsx's `billing_type`). This page used
  // to let the teacher pick it manually per search, which let them view
  // (and record) "حصة" payments for a group billed "شهر" and vice versa —
  // a mismatch nothing else in the schema guards against. Selecting a
  // group now derives its payment type automatically; there is no toggle
  // to override it.
  const selectedGroup = useMemo(
    () => groups.find((g) => g.id === selectedGroupId) ?? null,
    [groups, selectedGroupId],
  );
  const paymentType: PaymentType = selectedGroup?.billing_type === 'حصة' ? 'session' : 'month';

  const [period, setPeriod] = useState(currentMonthIso());

  // Reset the period to a sensible default of the NEW type whenever the
  // selected group (and therefore its derived payment type) changes.
  useEffect(() => {
    if (!selectedGroupId) return;
    setPeriod(paymentType === 'month' ? currentMonthIso() : todayIso());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedGroupId]);

  // ── Search + roster ──────────────────────────────────────────────────
  const [q, setQ] = useState('');
  const [rows, setRows] = useState<PaymentRow[]>([]);
  const [loading, setLoading] = useState(false);
  const [searched, setSearched] = useState(false);
  const [savingId, setSavingId] = useState<string | null>(null);

  const canSearch = Boolean(selectedGroupId) && period.trim() !== '';

  const runSearch = async () => {
    if (!canSearch) return;
    setLoading(true);
    try {
      const data = await centerService.getPaymentStudents({
        group_id: selectedGroupId,
        payment_type: paymentType,
        period,
        q: q.trim() || undefined,
      });
      setRows(Array.isArray(data) ? data : []);
      setSearched(true);
    } catch (err) {
      logger.error('CenterPaymentTracking: failed to load students', err as Error);
      notify('تعذر تحميل قائمة الطلاب، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setLoading(false);
    }
  };

  // Re-fetch automatically whenever the group/type/period changes (not on
  // every keystroke of q — that stays an explicit "بحث" action).
  useEffect(() => {
    if (selectedGroupId) runSearch();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedGroupId, paymentType, period]);

  const stats = useMemo(() => {
    const paid = rows.filter((r) => r.status === 'paid').length;
    return { total: rows.length, paid, unpaid: rows.length - paid };
  }, [rows]);

  const toggleStatus = async (row: PaymentRow) => {
    const nextStatus = row.status === 'paid' ? 'unpaid' : 'paid';
    setSavingId(row.student_id);
    try {
      await centerService.setPaymentStatus({
        group_id: selectedGroupId,
        student_id: row.student_id,
        payment_type: paymentType,
        period,
        status: nextStatus,
      });
      setRows((prev) => prev.map((r) => (r.student_id === row.student_id ? { ...r, status: nextStatus } : r)));
      notify(nextStatus === 'paid' ? 'تم تسجيل الحالة: دفع' : 'تم تسجيل الحالة: لم يدفع', 'success');
    } catch (err) {
      logger.error('CenterPaymentTracking: failed to set status', err as Error);
      notify('تعذر تسجيل الحالة، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setSavingId(null);
    }
  };

  // ── Student report ───────────────────────────────────────────────────
  const [reportStudentId, setReportStudentId] = useState<string | null>(null);
  const [reportData, setReportData] = useState<Awaited<ReturnType<typeof centerService.getPaymentReport>> | null>(null);
  const [loadingReport, setLoadingReport] = useState(false);

  const openReport = async (studentId: string) => {
    setReportStudentId(studentId);
    setLoadingReport(true);
    setReportData(null);
    try {
      const data = await centerService.getPaymentReport(studentId);
      setReportData(data);
    } catch (err) {
      logger.error('CenterPaymentTracking: failed to load report', err as Error);
      notify('تعذر تحميل تقرير الطالب', 'error');
      setReportStudentId(null);
    } finally {
      setLoadingReport(false);
    }
  };

  return (
    <div dir="rtl" className="relative space-y-6 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-emerald-500/8 dark:bg-emerald-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />

      <PageHeader
        icon={Wallet}
        title="متابعة مدفوعات السنتر"
        description="سجلّ متابعة لحالة دفع طلاب السنتر — دفع أو لم يدفع فقط، بلا أي أثر مالي أو محاسبي على المنصة"
      />

      {/* Group selection */}
      <Card radius="panel" className="space-y-4">
        <SectionHeader icon={Users} title="اختر المجموعة" description="حدد السنتر، ثم الصف، ثم المجموعة" />

        {loadingGroups ? (
          <Skeleton variant="block" className="h-12" />
        ) : groups.length === 0 ? (
          <EmptyState icon={Users} title="لا توجد مجموعات بعد" description="أضف مجموعة من صفحة (إنشاء مجموعة) أولاً" />
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <FormField label="السنتر">
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedCenter}
                  onChange={(e) => { setSelectedCenter(e.target.value); setSelectedGrade(''); setSelectedGroupId(''); }}
                >
                  <option value="">-- اختر السنتر --</option>
                  {centers.map((c) => <option key={c} value={c}>{c}</option>)}
                </Select>
              )}
            </FormField>

            <FormField label="الصف الدراسي">
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedGrade}
                  onChange={(e) => { setSelectedGrade(e.target.value); setSelectedGroupId(''); }}
                  disabled={!selectedCenter}
                >
                  {!selectedCenter ? <option value="">يرجى اختيار السنتر أولاً</option> : (
                    <>
                      <option value="">-- اختر الصف --</option>
                      {grades.map((g) => <option key={g} value={g}>{g}</option>)}
                    </>
                  )}
                </Select>
              )}
            </FormField>

            <FormField label="المجموعة">
              {({ id }) => (
                <Select id={id} value={selectedGroupId} onChange={(e) => setSelectedGroupId(e.target.value)} disabled={!selectedGrade}>
                  {!selectedGrade ? <option value="">يرجى اختيار الصف أولاً</option> : (
                    <>
                      <option value="">-- اختر المجموعة --</option>
                      {groupsForSelection.map((g) => <option key={g.id} value={g.id}>{g.group_name} — {g.schedule_text}</option>)}
                    </>
                  )}
                </Select>
              )}
            </FormField>
          </div>
        )}
      </Card>

      {selectedGroupId && (
        <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-6">
          {/* Payment type (derived from the group's own billing_type) + period */}
          <Card radius="panel" className="space-y-4">
            <SectionHeader
              icon={Calendar}
              title="طريقة الدفع"
              description="محدَّدة تلقائياً حسب إعداد هذه المجموعة عند إنشائها"
            />
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="flex items-center">
                <Badge tone="info" className="text-sm font-black px-4 py-3">
                  {paymentType === 'month' ? 'شهرياً' : 'بالحصة'}
                </Badge>
              </div>

              <FormField label={paymentType === 'month' ? 'الشهر' : 'تاريخ الحصة'}>
                {({ id }) => (
                  <Input
                    id={id}
                    size="lg"
                    type={paymentType === 'month' ? 'month' : 'date'}
                    value={period}
                    onChange={(e) => setPeriod(e.target.value)}
                    className="font-mono"
                  />
                )}
              </FormField>
            </div>
          </Card>

          {/* Search */}
          <Card radius="panel">
            <SectionHeader icon={Search} title="البحث" description="برقم الطالب أو كود السنتر — داخل هذه المجموعة فقط" />
            <div className="flex flex-col sm:flex-row items-start sm:items-end gap-3">
              <div className="w-full sm:max-w-xs">
                <Input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder="رقم الطالب أو كود السنتر"
                  onKeyDown={(e) => { if (e.key === 'Enter') runSearch(); }}
                />
              </div>
              <Button icon={Search} isLoading={loading} loadingText="جاري البحث..." onClick={runSearch}>
                بحث
              </Button>
            </div>
          </Card>

          {/* Stats — numeric counts only, no financial figure anywhere */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <StatCard index={0} icon={Users} label="إجمالي الطلاب" value={stats.total} color="blue" />
            <StatCard index={1} icon={CheckCircle2} label="دفع" value={stats.paid} color="emerald" />
            <StatCard index={2} icon={XCircle} label="لم يدفع" value={stats.unpaid} color="rose" />
          </div>

          {/* Students list */}
          <Card radius="panel" padding="none" className="overflow-hidden">
            <div className="p-5 sm:p-6 bg-slate-50/60 dark:bg-slate-800/20">
              <SectionHeader className="mb-0" icon={Users} title="قائمة الطلاب" description={`${rows.length} طالب`} />
            </div>

            <Table zebra>
              <TableHeader>
                <tr>
                  <TableHead>الطالب</TableHead>
                  <TableHead>رقم الطالب</TableHead>
                  <TableHead>كود السنتر</TableHead>
                  <TableHead align="center">الحالة</TableHead>
                  <TableHead align="center">إجراء</TableHead>
                </tr>
              </TableHeader>
              <TableBody>
                {loading ? (
                  Array.from({ length: 4 }).map((_, i) => (
                    <TableRow key={i}>
                      <TableCell colSpan={5}><Skeleton variant="text" width="100%" /></TableCell>
                    </TableRow>
                  ))
                ) : !searched || rows.length === 0 ? (
                  <TableEmptyState colSpan={5} icon={Users} title="لا يوجد طلاب" description="لا يوجد طلاب مسجَّلون فعلياً في هذه المجموعة بعد" />
                ) : (
                  rows.map((r) => (
                    <TableRow key={r.student_id}>
                      <TableCell emphasis>{r.name}</TableCell>
                      <TableCell className="font-mono" dir="ltr">{r.student_code || '—'}</TableCell>
                      <TableCell className="font-mono" dir="ltr">{r.center_code || '—'}</TableCell>
                      <TableCell align="center">
                        <button
                          type="button"
                          onClick={() => toggleStatus(r)}
                          disabled={savingId === r.student_id}
                          className="cursor-pointer disabled:opacity-50"
                          title="اضغط لتغيير الحالة"
                        >
                          {r.status === 'paid' ? (
                            <Badge tone="success" icon={CheckCircle2}>دفع</Badge>
                          ) : (
                            <Badge tone="danger" icon={XCircle}>لم يدفع</Badge>
                          )}
                        </button>
                      </TableCell>
                      <TableCell align="center">
                        <Button variant="ghost" size="sm" icon={FileText} onClick={() => openReport(r.student_id)}>
                          عرض التقرير
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </Card>
        </motion.div>
      )}

      {/* Student report */}
      <Dialog
        open={!!reportStudentId}
        onClose={() => setReportStudentId(null)}
        icon={FileText}
        title="تقرير مدفوعات الطالب"
        description="للمتابعة فقط — ليس تقريراً مالياً"
        size="lg"
      >
        {loadingReport ? (
          <div className="space-y-3">
            <Skeleton variant="block" className="h-16" />
            <Skeleton variant="block" className="h-40" />
          </div>
        ) : reportData ? (
          <div className="space-y-5">
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
              <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-950/40">
                <div className="text-slate-400 dark:text-slate-500 font-bold mb-0.5">اسم الطالب</div>
                <div className="font-black text-slate-800 dark:text-slate-100">{reportData.student.name}</div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-950/40">
                <div className="text-slate-400 dark:text-slate-500 font-bold mb-0.5">رقم الطالب</div>
                <div className="font-black font-mono text-slate-800 dark:text-slate-100">{reportData.student.student_code || '—'}</div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-950/40">
                <div className="text-slate-400 dark:text-slate-500 font-bold mb-0.5">كود السنتر</div>
                <div className="font-black font-mono text-slate-800 dark:text-slate-100">{reportData.student.center_code || '—'}</div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-950/40">
                <div className="text-slate-400 dark:text-slate-500 font-bold mb-0.5">السنتر</div>
                <div className="font-black text-slate-800 dark:text-slate-100">{reportData.student.center_name || '—'}</div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-950/40">
                <div className="text-slate-400 dark:text-slate-500 font-bold mb-0.5">المجموعة</div>
                <div className="font-black text-slate-800 dark:text-slate-100">{reportData.student.group_name || '—'}</div>
              </div>
            </div>

            <Table>
              <TableHeader>
                <tr>
                  <TableHead>الفترة/الحصة</TableHead>
                  <TableHead>نوع الدفع</TableHead>
                  <TableHead align="center">الحالة</TableHead>
                  <TableHead>تاريخ التسجيل</TableHead>
                </tr>
              </TableHeader>
              <TableBody>
                {reportData.records.length === 0 ? (
                  <TableEmptyState colSpan={4} icon={FileText} title="لا يوجد سجلّ دفع لهذا الطالب بعد" />
                ) : (
                  reportData.records.map((rec) => (
                    <TableRow key={rec.id}>
                      <TableCell emphasis className="font-mono">{rec.period}</TableCell>
                      <TableCell>{rec.payment_type === 'month' ? 'شهر' : 'حصة'}</TableCell>
                      <TableCell align="center">
                        {rec.status === 'paid' ? (
                          <Badge tone="success" icon={CheckCircle2}>دفع</Badge>
                        ) : (
                          <Badge tone="danger" icon={XCircle}>لم يدفع</Badge>
                        )}
                      </TableCell>
                      <TableCell className="font-mono text-[11px] text-slate-500 dark:text-slate-400">{rec.recorded_at}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>
        ) : null}
      </Dialog>
    </div>
  );
}
