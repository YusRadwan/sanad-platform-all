import { useEffect, useMemo, useState } from 'react';
import { motion } from 'motion/react';
import {
  BarChart3, Search, Users, UserCheck, UserX, Award, Target, Percent,
  FileDown, FileSpreadsheet, GraduationCap, BookOpen, Video,
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
 * "إحصائيات المشاهدات" — التوأم الخاص بالمدرّس لـ"إحصائيات الامتحانات":
 * الصف الدراسي ثم الكورس ثم الفيديو، ثم تقرير كامل لكل من شاهد ومن لم
 * يشاهد. يقرأ GET /content-items/{id}/watch-statistics
 * (App\Http\Controllers\VideoStatisticsController، decision 10.36) —
 * مالك الفيديو فقط.
 */

interface CourseOption {
  id: string;
  title: string;
  grade?: string;
  grade_name?: string;
}

interface VideoOption {
  id: string;
  title: string;
}

interface WatchedRow {
  student_id: string;
  name: string | null;
  phone: string | null;
  parent_phone: string | null;
  grade_level: string | null;
  watched_seconds: number;
  watch_percent: number;
  completed: boolean;
  last_watched_at: string | null;
}

interface NotWatchedRow {
  student_id: string;
  name: string | null;
  phone: string | null;
  parent_phone: string | null;
  grade_level: string | null;
}

interface WatchStatisticsReport {
  video: { id: string; title: string | null; duration_seconds: number };
  stats: {
    average_watch_percent: number;
    not_watched_count: number;
    watched_count: number;
    total_students: number;
    completed_count: number;
    completion_rate_percent: number | null;
  };
  watched: WatchedRow[];
  not_watched: NotWatchedRow[];
}

type Tab = 'watched' | 'not_watched';

export default function WatchStatistics() {
  const { notify } = useCenteredNotification();

  // ── Grade -> Course -> Video cascade ────────────────────────────────────
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
        logger.error('WatchStatistics: failed to load courses', err as Error);
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

  const [videos, setVideos] = useState<VideoOption[]>([]);
  const [loadingVideos, setLoadingVideos] = useState(false);
  const [selectedVideoId, setSelectedVideoId] = useState('');

  useEffect(() => {
    if (!selectedCourseId) {
      setVideos([]);
      return;
    }
    const load = async () => {
      try {
        setLoadingVideos(true);
        const data = await courseService.getCourseVideos(selectedCourseId);
        setVideos(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('WatchStatistics: failed to load videos', err as Error);
        notify('تعذر تحميل فيديوهات الكورس', 'error');
      } finally {
        setLoadingVideos(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedCourseId]);

  // ── Report ────────────────────────────────────────────────────────────
  const [report, setReport] = useState<WatchStatisticsReport | null>(null);
  const [loadingReport, setLoadingReport] = useState(false);

  useEffect(() => {
    if (!selectedVideoId) {
      setReport(null);
      return;
    }
    const load = async () => {
      try {
        setLoadingReport(true);
        const data = await statisticsService.getVideoWatchStatistics(selectedVideoId);
        setReport(data);
      } catch (err) {
        logger.error('WatchStatistics: failed to load report', err as Error);
        notify('تعذر تحميل إحصائيات المشاهدة', 'error');
        setReport(null);
      } finally {
        setLoadingReport(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedVideoId]);

  const [tab, setTab] = useState<Tab>('watched');
  const [phone, setPhone] = useState('');

  const watchedRows = useMemo(() => {
    if (!report) return [];
    if (!phone.trim()) return report.watched;
    return report.watched.filter((r) => (r.phone || '').includes(phone.trim()));
  }, [report, phone]);

  const notWatchedRows = useMemo(() => {
    if (!report) return [];
    if (!phone.trim()) return report.not_watched;
    return report.not_watched.filter((r) => (r.phone || '').includes(phone.trim()));
  }, [report, phone]);

  const exportWatchedXlsx = () => {
    if (!report) return;
    const columns: XlsxColumn[] = [
      { header: '#', width: 6 }, { header: 'الطالب', width: 26 }, { header: 'رقم الهاتف', width: 16 },
      { header: 'هاتف ولي الأمر', width: 16 }, { header: 'نسبة المشاهدة', width: 12 },
      { header: 'آخر مشاهدة', width: 20 }, { header: 'الحالة', width: 12 },
    ];
    const rows = watchedRows.map((r, i) => [
      String(i + 1), r.name || '—', r.phone || '—', r.parent_phone || '—',
      `${r.watch_percent}%`, r.last_watched_at || '—', r.completed ? 'مكتمل' : 'غير مكتمل',
    ]);
    downloadXlsx(`المشاهدون - ${report.video.title || 'فيديو'}`, columns, rows, 'المشاهدون');
  };

  const exportWatchedPdf = () => {
    if (!report) return;
    printTableAsPdf(
      `المشاهدون — ${report.video.title || 'فيديو'}`,
      ['#', 'الطالب', 'رقم الهاتف', 'هاتف ولي الأمر', 'نسبة المشاهدة', 'آخر مشاهدة', 'الحالة'],
      watchedRows.map((r, i) => [
        String(i + 1), r.name || '—', r.phone || '—', r.parent_phone || '—',
        `${r.watch_percent}%`, r.last_watched_at || '—', r.completed ? 'مكتمل' : 'غير مكتمل',
      ])
    );
  };

  const exportNotWatchedXlsx = () => {
    if (!report) return;
    const columns: XlsxColumn[] = [
      { header: '#', width: 6 }, { header: 'الطالب', width: 26 }, { header: 'رقم الهاتف', width: 16 },
      { header: 'هاتف ولي الأمر', width: 16 }, { header: 'الصف الدراسي', width: 20 },
    ];
    const rows = notWatchedRows.map((r, i) => [String(i + 1), r.name || '—', r.phone || '—', r.parent_phone || '—', r.grade_level || '—']);
    downloadXlsx(`لم يشاهدوا - ${report.video.title || 'فيديو'}`, columns, rows, 'لم يشاهدوا');
  };

  const exportNotWatchedPdf = () => {
    if (!report) return;
    printTableAsPdf(
      `لم يشاهدوا — ${report.video.title || 'فيديو'}`,
      ['#', 'الطالب', 'رقم الهاتف', 'هاتف ولي الأمر', 'الصف الدراسي'],
      notWatchedRows.map((r, i) => [String(i + 1), r.name || '—', r.phone || '—', r.parent_phone || '—', r.grade_level || '—'])
    );
  };

  return (
    <div dir="rtl" className="relative space-y-6 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-violet-600/10 dark:bg-violet-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-blue-500/8 dark:bg-blue-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />

      <PageHeader
        icon={BarChart3}
        title="إحصائيات المشاهدات"
        description="تحليل مشاهدة الطلاب لفيديوهات الكورسات"
      />

      <Card radius="panel" className="space-y-4">
        <SectionHeader icon={Video} title="اختر الفيديو" description="حدد الصف، ثم الكورس، ثم الفيديو" />

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
                  onChange={(e) => { setSelectedGrade(e.target.value); setSelectedCourseId(''); setSelectedVideoId(''); }}
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
                  onChange={(e) => { setSelectedCourseId(e.target.value); setSelectedVideoId(''); }}
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

            <FormField label="الفيديو">
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedVideoId}
                  onChange={(e) => setSelectedVideoId(e.target.value)}
                  disabled={!selectedCourseId || loadingVideos}
                >
                  {!selectedCourseId ? <option value="">يرجى اختيار الكورس أولاً</option> : loadingVideos ? (
                    <option value="">جاري التحميل...</option>
                  ) : videos.length === 0 ? (
                    <option value="">لا توجد فيديوهات في هذا الكورس</option>
                  ) : (
                    <>
                      <option value="">-- اختر الفيديو --</option>
                      {videos.map((v) => <option key={v.id} value={v.id}>{v.title}</option>)}
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
            <StatCard index={0} icon={Percent} label="متوسط نسبة المشاهدة" value={`${report.stats.average_watch_percent}%`} color="violet" />
            <StatCard index={1} icon={UserX} label="لم يشاهدوا" value={report.stats.not_watched_count} color="amber" />
            <StatCard index={2} icon={UserCheck} label="عدد المشاهدين" value={report.stats.watched_count} color="emerald" />
            <StatCard index={3} icon={Users} label="إجمالي الطلاب" value={report.stats.total_students} color="blue" />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <StatCard index={4} icon={Award} label="عدد من أكملوا المشاهدة" value={report.stats.completed_count} color="rose" />
            <StatCard index={5} icon={Target} label="نسبة الإكمال" value={report.stats.completion_rate_percent !== null ? `${report.stats.completion_rate_percent}%` : '—'} color="emerald" />
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
              onClick={() => setTab('watched')}
              className={`px-4 py-2.5 rounded-2xl border-2 text-sm font-black transition-all cursor-pointer ${
                tab === 'watched'
                  ? 'border-emerald-500 bg-emerald-50/70 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 shadow-sm'
                  : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-700'
              }`}
            >
              المشاهدون ({report.stats.watched_count})
            </button>
            <button
              type="button"
              onClick={() => setTab('not_watched')}
              className={`px-4 py-2.5 rounded-2xl border-2 text-sm font-black transition-all cursor-pointer ${
                tab === 'not_watched'
                  ? 'border-amber-500 bg-amber-50/70 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 shadow-sm'
                  : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-700'
              }`}
            >
              لم يشاهدوا ({report.stats.not_watched_count})
            </button>

            <div className="flex-1" />

            {tab === 'watched' ? (
              <div className="flex gap-2">
                <Button variant="outline" size="sm" icon={FileDown} onClick={exportWatchedPdf}>PDF</Button>
                <Button variant="outline" size="sm" icon={FileSpreadsheet} onClick={exportWatchedXlsx}>XLSX</Button>
              </div>
            ) : (
              <div className="flex gap-2">
                <Button variant="outline" size="sm" icon={FileDown} onClick={exportNotWatchedPdf}>PDF</Button>
                <Button variant="outline" size="sm" icon={FileSpreadsheet} onClick={exportNotWatchedXlsx}>XLSX</Button>
              </div>
            )}
          </div>

          <Card radius="panel" padding="none" className="overflow-hidden">
            {tab === 'watched' ? (
              <Table zebra>
                <TableHeader>
                  <tr>
                    <TableHead>#</TableHead>
                    <TableHead>الطالب</TableHead>
                    <TableHead>رقم الهاتف</TableHead>
                    <TableHead>هاتف ولي الأمر</TableHead>
                    <TableHead align="center">نسبة المشاهدة</TableHead>
                    <TableHead align="center">آخر مشاهدة</TableHead>
                    <TableHead align="center">الحالة</TableHead>
                  </tr>
                </TableHeader>
                <TableBody>
                  {watchedRows.length === 0 ? (
                    <TableEmptyState colSpan={7} icon={UserCheck} title="لا يوجد مشاهدون" />
                  ) : (
                    watchedRows.map((r, i) => (
                      <TableRow key={r.student_id}>
                        <TableCell className="font-mono text-slate-400">{i + 1}</TableCell>
                        <TableCell emphasis>{r.name || '—'}</TableCell>
                        <TableCell className="font-mono" dir="ltr">{r.phone || '—'}</TableCell>
                        <TableCell className="font-mono" dir="ltr">{r.parent_phone || '—'}</TableCell>
                        <TableCell align="center" className="font-black">{r.watch_percent}%</TableCell>
                        <TableCell align="center" className="font-mono text-[11px] text-slate-500 dark:text-slate-400">{r.last_watched_at || '—'}</TableCell>
                        <TableCell align="center">
                          {r.completed ? <Badge tone="success">مكتمل</Badge> : <Badge tone="warning">غير مكتمل</Badge>}
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
                  {notWatchedRows.length === 0 ? (
                    <TableEmptyState colSpan={5} icon={GraduationCap} title="لا يوجد طلاب لم يشاهدوا" />
                  ) : (
                    notWatchedRows.map((r, i) => (
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
