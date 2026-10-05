import { useEffect, useMemo, useState } from 'react';
import { motion } from 'motion/react';
import {
  BarChart3, Search, Users, UserCheck, UserX, Award, Target, Percent,
  FileDown, FileSpreadsheet, GraduationCap, BookOpen, ClipboardList,
} from 'lucide-react';
import { courseService } from '../../services/courseService';
import { statisticsService } from '../../services/statisticsService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { downloadXlsx, XlsxColumn } from '../../utils/xlsxExport';
import { printTableAsPdf } from '../../utils/printTable';
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
 * "إحصائيات الامتحانات" — الصف الدراسي ثم الكورس ثم الامتحان، ثم تقرير كامل
 * لكل من امتحن ومن لم يمتحن. يقرأ GET /exams/{id}/statistics
 * (App\Http\Controllers\ExamStatisticsController، decision 10.36) —
 * مالك الامتحان فقط، بلا وصول للطالب حتى لو كان مشتركاً.
 *
 * "الوقت المستغرق" غائب عمداً عن هذا التقرير: `exam_attempts.started_at`
 * و`.submitted_at` يُكتَبان بنفس اللحظة عند التسليم (لا حدث "بدء الامتحان"
 * منفصل في هذا المخطط) — فلا توجد بيانات حقيقية لحساب مدة، وعرض رقم مختلَق
 * أسوأ من عدم عرضه.
 */

interface CourseOption {
  id: string;
  title: string;
  grade?: string;
  grade_name?: string;
}

interface ExamOption {
  id: string;
  title: string;
}

interface AttemptedRow {
  student_id: string;
  name: string | null;
  phone: string | null;
  parent_phone: string | null;
  grade_level: string | null;
  score: number;
  total_score: number;
  attempts_count: number;
  submitted_at: string | null;
  status: 'passed' | 'failed' | null;
}

interface NotAttendedRow {
  student_id: string;
  name: string | null;
  phone: string | null;
  parent_phone: string | null;
  grade_level: string | null;
}

interface ExamStatisticsReport {
  exam: { id: string; title: string | null; total_score: number; pass_score: number | null };
  stats: {
    average_score_percent: number;
    not_attempted_count: number;
    attempted_count: number;
    total_students: number;
    highest_score: number;
    success_rate_percent: number | null;
  };
  attempted: AttemptedRow[];
  not_attempted: NotAttendedRow[];
}

type Tab = 'attempted' | 'not_attempted';

export default function ExamStatistics() {
  const { notify } = useCenteredNotification();

  // ── Grade -> Course -> Exam cascade ─────────────────────────────────────
  const [courses, setCourses] = useState<CourseOption[]>([]);
  const [loadingCourses, setLoadingCourses] = useState(true);
  const [selectedGrade, setSelectedGrade] = useState('');
  const [selectedCourseId, setSelectedCourseId] = useState('');

  useEffect(() => {
    const load = async () => {
      try {
        setLoadingCourses(true);
        const data = await courseService.getMyCourses();
        setCourses(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('ExamStatistics: failed to load courses', err as Error);
        notify('تعذر تحميل الكورسات', 'error');
      } finally {
        setLoadingCourses(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const grades = useMemo(
    () => Array.from(new Set(courses.map((c) => c.grade || c.grade_name).filter(Boolean) as string[])),
    [courses]
  );
  const coursesForGrade = useMemo(
    () => courses.filter((c) => (c.grade || c.grade_name) === selectedGrade),
    [courses, selectedGrade]
  );

  const [exams, setExams] = useState<ExamOption[]>([]);
  const [loadingExams, setLoadingExams] = useState(false);
  const [selectedExamId, setSelectedExamId] = useState('');

  useEffect(() => {
    if (!selectedCourseId) {
      setExams([]);
      return;
    }
    const load = async () => {
      try {
        setLoadingExams(true);
        const data = await courseService.getExams(selectedCourseId);
        setExams(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('ExamStatistics: failed to load exams', err as Error);
        notify('تعذر تحميل امتحانات الكورس', 'error');
      } finally {
        setLoadingExams(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedCourseId]);

  // ── Report ────────────────────────────────────────────────────────────
  const [report, setReport] = useState<ExamStatisticsReport | null>(null);
  const [loadingReport, setLoadingReport] = useState(false);

  useEffect(() => {
    if (!selectedExamId) {
      setReport(null);
      return;
    }
    const load = async () => {
      try {
        setLoadingReport(true);
        const data = await statisticsService.getExamStatistics(selectedExamId);
        setReport(data);
      } catch (err) {
        logger.error('ExamStatistics: failed to load report', err as Error);
        notify('تعذر تحميل إحصائيات الامتحان', 'error');
        setReport(null);
      } finally {
        setLoadingReport(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedExamId]);

  const [tab, setTab] = useState<Tab>('attempted');
  const [phone, setPhone] = useState('');

  const attemptedRows = useMemo(() => {
    if (!report) return [];
    if (!phone.trim()) return report.attempted;
    return report.attempted.filter((r) => (r.phone || '').includes(phone.trim()));
  }, [report, phone]);

  const notAttendedRows = useMemo(() => {
    if (!report) return [];
    if (!phone.trim()) return report.not_attempted;
    return report.not_attempted.filter((r) => (r.phone || '').includes(phone.trim()));
  }, [report, phone]);

  const exportAttemptedXlsx = () => {
    if (!report) return;
    const columns: XlsxColumn[] = [
      { header: '#', width: 6 }, { header: 'الطالب', width: 26 }, { header: 'رقم الهاتف', width: 16 },
      { header: 'هاتف ولي الأمر', width: 16 }, { header: 'الدرجة', width: 12 },
      { header: 'عدد المحاولات', width: 12 }, { header: 'تاريخ التسليم', width: 20 }, { header: 'الحالة', width: 12 },
    ];
    const rows = attemptedRows.map((r, i) => [
      String(i + 1), r.name || '—', r.phone || '—', r.parent_phone || '—',
      `${r.score}/${r.total_score}`, String(r.attempts_count), r.submitted_at || '—',
      r.status === 'passed' ? 'ناجح' : r.status === 'failed' ? 'راسب' : '—',
    ]);
    downloadXlsx(`الممتحنون - ${report.exam.title || 'امتحان'}`, columns, rows, 'الممتحنون');
  };

  const exportAttemptedPdf = () => {
    if (!report) return;
    printTableAsPdf(
      `الممتحنون — ${report.exam.title || 'امتحان'}`,
      ['#', 'الطالب', 'رقم الهاتف', 'هاتف ولي الأمر', 'الدرجة', 'عدد المحاولات', 'تاريخ التسليم', 'الحالة'],
      attemptedRows.map((r, i) => [
        String(i + 1), r.name || '—', r.phone || '—', r.parent_phone || '—',
        `${r.score}/${r.total_score}`, String(r.attempts_count), r.submitted_at || '—',
        r.status === 'passed' ? 'ناجح' : r.status === 'failed' ? 'راسب' : '—',
      ])
    );
  };

  const exportNotAttendedXlsx = () => {
    if (!report) return;
    const columns: XlsxColumn[] = [
      { header: '#', width: 6 }, { header: 'الطالب', width: 26 }, { header: 'رقم الهاتف', width: 16 },
      { header: 'هاتف ولي الأمر', width: 16 }, { header: 'الصف الدراسي', width: 20 },
    ];
    const rows = notAttendedRows.map((r, i) => [String(i + 1), r.name || '—', r.phone || '—', r.parent_phone || '—', r.grade_level || '—']);
    downloadXlsx(`لم يمتحنوا - ${report.exam.title || 'امتحان'}`, columns, rows, 'لم يمتحنوا');
  };

  const exportNotAttendedPdf = () => {
    if (!report) return;
    printTableAsPdf(
      `لم يمتحنوا — ${report.exam.title || 'امتحان'}`,
      ['#', 'الطالب', 'رقم الهاتف', 'هاتف ولي الأمر', 'الصف الدراسي'],
      notAttendedRows.map((r, i) => [String(i + 1), r.name || '—', r.phone || '—', r.parent_phone || '—', r.grade_level || '—'])
    );
  };

  return (
    <div dir="rtl" className="relative space-y-6 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-rose-600/10 dark:bg-rose-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-blue-500/8 dark:bg-blue-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />

      <PageHeader
        icon={BarChart3}
        title="إحصائيات الامتحانات"
        description="تحليل أداء الطلاب في الامتحانات"
      />

      <Card radius="panel" className="space-y-4">
        <SectionHeader icon={ClipboardList} title="اختر الامتحان" description="حدد الصف، ثم الكورس، ثم الامتحان" />

        {loadingCourses ? (
          <Skeleton variant="block" className="h-12" />
        ) : courses.length === 0 ? (
          <EmptyState icon={BookOpen} title="لا توجد كورسات بعد" description="أضف كورساً أولاً من صفحة إدارة الكورسات" />
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <FormField label="الصف الدراسي">
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedGrade}
                  onChange={(e) => { setSelectedGrade(e.target.value); setSelectedCourseId(''); setSelectedExamId(''); }}
                >
                  <option value="">-- اختر الصف --</option>
                  {grades.map((g) => <option key={g} value={g}>{g}</option>)}
                </Select>
              )}
            </FormField>

            <FormField label="الكورس">
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedCourseId}
                  onChange={(e) => { setSelectedCourseId(e.target.value); setSelectedExamId(''); }}
                  disabled={!selectedGrade}
                >
                  {!selectedGrade ? <option value="">يرجى اختيار الصف أولاً</option> : (
                    <>
                      <option value="">-- اختر الكورس --</option>
                      {coursesForGrade.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
                    </>
                  )}
                </Select>
              )}
            </FormField>

            <FormField label="الامتحان">
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedExamId}
                  onChange={(e) => setSelectedExamId(e.target.value)}
                  disabled={!selectedCourseId || loadingExams}
                >
                  {!selectedCourseId ? <option value="">يرجى اختيار الكورس أولاً</option> : loadingExams ? (
                    <option value="">جاري التحميل...</option>
                  ) : exams.length === 0 ? (
                    <option value="">لا توجد امتحانات في هذا الكورس</option>
                  ) : (
                    <>
                      <option value="">-- اختر الامتحان --</option>
                      {exams.map((ex) => <option key={ex.id} value={ex.id}>{ex.title}</option>)}
                    </>
                  )}
                </Select>
              )}
            </FormField>
          </div>
        )}
      </Card>

      {loadingReport && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} variant="block" className="h-28" />)}
        </div>
      )}

      {!loadingReport && report && (
        <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <StatCard index={0} icon={Percent} label="متوسط الدرجة" value={`${report.stats.average_score_percent}%`} color="rose" />
            <StatCard index={1} icon={UserX} label="لم يمتحنوا" value={report.stats.not_attempted_count} color="amber" />
            <StatCard index={2} icon={UserCheck} label="عدد الممتحنين" value={report.stats.attempted_count} color="emerald" />
            <StatCard index={3} icon={Users} label="إجمالي الطلاب" value={report.stats.total_students} color="blue" />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <StatCard index={4} icon={Award} label="أعلى درجة" value={`${report.stats.highest_score}/${report.exam.total_score}`} color="rose" />
            <StatCard index={5} icon={Target} label="نسبة النجاح" value={report.stats.success_rate_percent !== null ? `${report.stats.success_rate_percent}%` : '—'} color="emerald" />
          </div>

          <Card radius="panel">
            <div className="flex flex-col sm:flex-row items-start sm:items-end gap-3">
              <div className="w-full sm:max-w-xs">
                <FormField label="بحث برقم الهاتف">
                  {({ id }) => (
                    <Input id={id} value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="ابحث برقم الهاتف..." icon={Search} />
                  )}
                </FormField>
              </div>
            </div>
          </Card>

          <div className="flex flex-wrap items-center gap-3">
            <button
              type="button"
              onClick={() => setTab('attempted')}
              className={`px-4 py-2.5 rounded-2xl border-2 text-sm font-black transition-all cursor-pointer ${
                tab === 'attempted'
                  ? 'border-emerald-500 bg-emerald-50/70 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 shadow-sm'
                  : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-700'
              }`}
            >
              الممتحنون ({report.stats.attempted_count})
            </button>
            <button
              type="button"
              onClick={() => setTab('not_attempted')}
              className={`px-4 py-2.5 rounded-2xl border-2 text-sm font-black transition-all cursor-pointer ${
                tab === 'not_attempted'
                  ? 'border-amber-500 bg-amber-50/70 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 shadow-sm'
                  : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-700'
              }`}
            >
              لم يمتحنوا ({report.stats.not_attempted_count})
            </button>

            <div className="flex-1" />

            {tab === 'attempted' ? (
              <div className="flex gap-2">
                <Button variant="outline" size="sm" icon={FileDown} onClick={exportAttemptedPdf}>PDF</Button>
                <Button variant="outline" size="sm" icon={FileSpreadsheet} onClick={exportAttemptedXlsx}>XLSX</Button>
              </div>
            ) : (
              <div className="flex gap-2">
                <Button variant="outline" size="sm" icon={FileDown} onClick={exportNotAttendedPdf}>PDF</Button>
                <Button variant="outline" size="sm" icon={FileSpreadsheet} onClick={exportNotAttendedXlsx}>XLSX</Button>
              </div>
            )}
          </div>

          <Card radius="panel" padding="none" className="overflow-hidden">
            {tab === 'attempted' ? (
              <Table zebra>
                <TableHeader>
                  <tr>
                    <TableHead>#</TableHead>
                    <TableHead>الطالب</TableHead>
                    <TableHead>رقم الهاتف</TableHead>
                    <TableHead>هاتف ولي الأمر</TableHead>
                    <TableHead align="center">الدرجة</TableHead>
                    <TableHead align="center">تاريخ التسليم</TableHead>
                    <TableHead align="center">الحالة</TableHead>
                  </tr>
                </TableHeader>
                <TableBody>
                  {attemptedRows.length === 0 ? (
                    <TableEmptyState colSpan={7} icon={UserCheck} title="لا يوجد ممتحنون" />
                  ) : (
                    attemptedRows.map((r, i) => (
                      <TableRow key={r.student_id}>
                        <TableCell className="font-mono text-slate-400">{i + 1}</TableCell>
                        <TableCell emphasis>
                          {r.name || '—'}
                          {r.attempts_count > 1 && (
                            <Badge tone="warning" className="mr-2">{r.attempts_count} محاولات</Badge>
                          )}
                        </TableCell>
                        <TableCell className="font-mono" dir="ltr">{r.phone || '—'}</TableCell>
                        <TableCell className="font-mono" dir="ltr">{r.parent_phone || '—'}</TableCell>
                        <TableCell align="center" className="font-black">{r.score}/{r.total_score}</TableCell>
                        <TableCell align="center" className="font-mono text-[11px] text-slate-500 dark:text-slate-400">{r.submitted_at || '—'}</TableCell>
                        <TableCell align="center">
                          {r.status === 'passed' ? <Badge tone="success">ناجح</Badge> : r.status === 'failed' ? <Badge tone="danger">راسب</Badge> : <Badge tone="neutral">—</Badge>}
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            ) : (
              <Table zebra>
                <TableHeader>
                  <tr>
                    <TableHead>#</TableHead>
                    <TableHead>الطالب</TableHead>
                    <TableHead>رقم الهاتف</TableHead>
                    <TableHead>هاتف ولي الأمر</TableHead>
                    <TableHead>الصف الدراسي</TableHead>
                  </tr>
                </TableHeader>
                <TableBody>
                  {notAttendedRows.length === 0 ? (
                    <TableEmptyState colSpan={5} icon={GraduationCap} title="لا يوجد طلاب لم يمتحنوا" />
                  ) : (
                    notAttendedRows.map((r, i) => (
                      <TableRow key={r.student_id}>
                        <TableCell className="font-mono text-slate-400">{i + 1}</TableCell>
                        <TableCell emphasis>{r.name || '—'}</TableCell>
                        <TableCell className="font-mono" dir="ltr">{r.phone || '—'}</TableCell>
                        <TableCell className="font-mono" dir="ltr">{r.parent_phone || '—'}</TableCell>
                        <TableCell>{r.grade_level || '—'}</TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            )}
          </Card>
        </motion.div>
      )}
    </div>
  );
}
