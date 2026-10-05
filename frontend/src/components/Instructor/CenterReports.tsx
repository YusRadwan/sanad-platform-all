import { useEffect, useMemo, useState } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { useNavigate } from 'react-router';
import type { LucideIcon } from 'lucide-react';
import { ClipboardList, Search, Users, UserCheck, UserX, Info, RotateCcw, ArrowRight, Download, Unlink, AlertTriangle } from 'lucide-react';
import { centerService } from '../../services/centerService';
import { catalogService } from '../../services/catalogService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { downloadXlsx, XlsxColumn } from '../../utils/xlsxExport';
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
  ConfirmDialog,
  cn,
} from '../UI';

interface CenterGroup {
  id: string;
  center_name: string;
  grade_label: string;
  group_name: string;
  schedule_text: string;
}

interface StudentReportRow {
  student_id: string;
  membership_id: string;
  center_code: string | null;
  student_code: string;
  name: string;
  phone: string;
  parent_phone: string;
  grade_level: string;
  groups: Array<{ id: string; center_name: string; grade_label: string; group_name: string }>;
  present_days: number;
  absent_days: number;
  note: string | null;
}

interface SessionOption {
  id: string;
  session_date: string;
  status: 'open' | 'closed';
}

interface SessionAttendee {
  attendance_id: string;
  name: string;
  student_code: string;
  marked_at: string;
  note: string | null;
}

interface CenterSessionDetail {
  id: string;
  status: 'open' | 'closed';
  session_date: string;
  total: number;
  present: number;
  absent: number;
  attendances: SessionAttendee[];
}

type ReportTab = 'students' | 'session';

interface ActionDef {
  id: ReportTab;
  icon: LucideIcon;
  title: string;
  description: string;
  tone: 'blue' | 'violet';
}

const TONE_STYLES: Record<ActionDef['tone'], { chip: string; hoverBorder: string }> = {
  blue: {
    chip: 'bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400',
    hoverBorder: 'hover:border-blue-300 dark:hover:border-blue-500/40',
  },
  violet: {
    chip: 'bg-violet-50 dark:bg-violet-500/10 text-violet-600 dark:text-violet-400',
    hoverBorder: 'hover:border-violet-300 dark:hover:border-violet-500/40',
  },
};

const ACTIONS: ActionDef[] = [
  { id: 'students', icon: Users, title: 'تقرير الطلاب', description: 'قائمة كل طلاب السنتر وحضورهم وغيابهم', tone: 'blue' },
  { id: 'session', icon: ClipboardList, title: 'تقرير حصة', description: 'تفاصيل حضور حصة واحدة محددة', tone: 'violet' },
];

/**
 * The landing picker: two clearly-labelled tiles instead of a tab strip that
 * renders content immediately, so the page opens on a single decision.
 * Ported from CourseManagement.tsx's ActionCard/PanelBackBar pattern.
 */
function ActionCard({ action, onSelect, index }: { action: ActionDef; onSelect: (id: ReportTab) => void; index: number }) {
  const tone = TONE_STYLES[action.tone];
  const Icon = action.icon;
  return (
    <motion.button
      type="button"
      onClick={() => onSelect(action.id)}
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, delay: index * 0.06, ease: 'easeOut' }}
      whileHover={{ y: -4 }}
      className={cn(
        'group flex flex-col items-center text-center gap-3 p-7 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800 cursor-pointer',
        'shadow-sm hover:shadow-lg transition-shadow duration-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
        tone.hoverBorder,
      )}
    >
      <div className={cn('w-16 h-16 rounded-2xl flex items-center justify-center transition-transform duration-300 group-hover:scale-105', tone.chip)}>
        <Icon className="w-7 h-7" aria-hidden="true" />
      </div>
      <div className="space-y-1">
        <h3 className="text-lg font-black text-slate-900 dark:text-white">{action.title}</h3>
        <p className="text-sm font-medium text-slate-500 dark:text-slate-400">{action.description}</p>
      </div>
    </motion.button>
  );
}

/** Back-to-picker bar shown at the top of whichever report is open. */
function PanelBackBar({ onBack, icon: Icon, title, description, tone = 'blue' }: {
  onBack: () => void;
  icon: LucideIcon;
  title: string;
  description: string;
  tone?: ActionDef['tone'];
}) {
  const toneClasses = TONE_STYLES[tone];
  return (
    <div className="flex items-start justify-between gap-4 flex-wrap pb-5 mb-6 border-b border-slate-100 dark:border-slate-800">
      <div className="flex items-center gap-3 min-w-0">
        <div className={cn('shrink-0 w-10 h-10 rounded-xl flex items-center justify-center', toneClasses.chip)}>
          <Icon className="w-4.5 h-4.5" aria-hidden="true" />
        </div>
        <div className="min-w-0">
          <h2 className="text-base sm:text-lg font-black text-slate-900 dark:text-white leading-tight">{title}</h2>
          <p className="mt-0.5 text-xs sm:text-sm font-medium text-slate-500 dark:text-slate-400">{description}</p>
        </div>
      </div>
      <Button type="button" variant="ghost" size="sm" icon={ArrowRight} onClick={onBack}>
        العودة لاختيار التقرير
      </Button>
    </div>
  );
}

/**
 * "تقارير طلاب السنتر" — two report views: a filterable list of every
 * center student (`getStudentsReport`), and a per-session snapshot picked
 * through the same center → grade → group cascade used on the attendance
 * page, then a session date picker (`getGroupSessions` → `getSession`).
 * The page opens on a landing picker; picking one shows only that report.
 */
export default function CenterReports() {
  const { notify } = useCenteredNotification();
  const [tab, setTab] = useState<ReportTab | null>(null);

  const [groups, setGroups] = useState<CenterGroup[]>([]);
  const [loadingGroups, setLoadingGroups] = useState(true);

  useEffect(() => {
    const load = async () => {
      try {
        setLoadingGroups(true);
        const data = await centerService.getMyGroups();
        setGroups(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('CenterReports: failed to load groups', err as Error);
        notify('تعذر تحميل مجموعاتك، يرجى إعادة تحميل الصفحة', 'error');
      } finally {
        setLoadingGroups(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />

      <PageHeader icon={ClipboardList} title="تقارير طلاب السنتر" description="تابع حضور وغياب طلاب السنتر وأداءهم في الحصص" />

      <AnimatePresence mode="wait">
        {tab === null ? (
          <motion.div
            key="report_picker"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="grid grid-cols-1 sm:grid-cols-2 gap-4"
          >
            {ACTIONS.map((action, i) => (
              <ActionCard key={action.id} action={action} index={i} onSelect={setTab} />
            ))}
          </motion.div>
        ) : (
          <div key="report_panel">
            {tab === 'students' ? (
              <PanelBackBar
                onBack={() => setTab(null)}
                icon={Users}
                title="تقرير الطلاب"
                description="قائمة كل طلاب السنتر وحضورهم وغيابهم"
                tone="blue"
              />
            ) : (
              <PanelBackBar
                onBack={() => setTab(null)}
                icon={ClipboardList}
                title="تقرير حصة"
                description="تفاصيل حضور حصة واحدة محددة"
                tone="violet"
              />
            )}

            {tab === 'students' ? (
              <StudentsReportTab groups={groups} loadingGroups={loadingGroups} />
            ) : (
              <SessionReportTab groups={groups} loadingGroups={loadingGroups} />
            )}
          </div>
        )}
      </AnimatePresence>
    </div>
  );
}

// ── Tab 1: students report ───────────────────────────────────────────────

const STUDENTS_EXPORT_COLUMNS: XlsxColumn[] = [
  { header: 'كود السنتر', width: 16 },
  { header: 'الاسم', width: 26 },
  { header: 'الهاتف', width: 16 },
  { header: 'هاتف ولي الأمر', width: 16 },
  { header: 'الصف', width: 18 },
  { header: 'المجموعة', width: 20 },
  { header: 'أيام الحضور', width: 12 },
  { header: 'أيام الغياب', width: 12 },
  { header: 'الملاحظة', width: 30 },
];

function StudentsReportTab({ groups, loadingGroups }: { groups: CenterGroup[]; loadingGroups: boolean }) {
  const { notify } = useCenteredNotification();
  const [phone, setPhone] = useState('');
  const [studentCode, setStudentCode] = useState('');
  const [centerName, setCenterName] = useState('');
  const [groupId, setGroupId] = useState('');
  const [gradeLevel, setGradeLevel] = useState('');

  const [rows, setRows] = useState<StudentReportRow[]>([]);
  const [loading, setLoading] = useState(false);
  const [searched, setSearched] = useState(false);

  // "فلتر الصف الدراسي" — the same fixed, platform-wide grades catalog
  // CreateCenterGroup.tsx reads, not free text.
  const [gradeOptions, setGradeOptions] = useState<string[]>([]);
  useEffect(() => {
    (async () => {
      try {
        const items = await catalogService.list('grades');
        setGradeOptions(items.map((g) => g.name));
      } catch (err) {
        logger.error('CenterReports: failed to load grades catalog', err as Error);
      }
    })();
  }, []);

  const centers = useMemo(() => Array.from(new Set(groups.map((g) => g.center_name))).filter(Boolean), [groups]);

  const runSearch = async () => {
    setLoading(true);
    try {
      const data = await centerService.getStudentsReport({
        phone: phone.trim() || undefined,
        student_code: studentCode.trim() || undefined,
        center_name: centerName || undefined,
        group_id: groupId || undefined,
        grade_level: gradeLevel || undefined,
      });
      setRows(Array.isArray(data) ? data : []);
      setSearched(true);
    } catch (err) {
      logger.error('CenterReports: students search failed', err as Error);
      notify('تعذر تحميل التقرير، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!loadingGroups) runSearch();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loadingGroups]);

  // ── "فك الربط" from the report's own action column ──────────────────
  const [unlinkTarget, setUnlinkTarget] = useState<StudentReportRow | null>(null);
  const [unlinking, setUnlinking] = useState(false);

  const confirmUnlink = async () => {
    if (!unlinkTarget) return;
    setUnlinking(true);
    try {
      await centerService.unlinkCenterMembership(unlinkTarget.membership_id);
      notify(`تم فك ارتباط "${unlinkTarget.name}" بنجاح`, 'success');
      setUnlinkTarget(null);
      await runSearch();
    } catch (err) {
      logger.error('CenterReports: unlink failed', err as Error);
      notify('تعذر فك الربط، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setUnlinking(false);
    }
  };

  const handleExport = () => {
    if (rows.length === 0) return;
    const exportRows = rows.map((r) => [
      r.center_code || '—',
      r.name,
      r.phone,
      r.parent_phone,
      r.grade_level,
      r.groups.map((g) => g.group_name).join('، ') || '—',
      String(r.present_days),
      String(r.absent_days),
      r.note || '—',
    ]);
    const stamp = new Date().toISOString().slice(0, 10);
    downloadXlsx(`تقرير-طلاب-السنتر-${stamp}.xlsx`, STUDENTS_EXPORT_COLUMNS, exportRows, 'الطلاب');
  };

  return (
    <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.3 }} className="space-y-6">
      <Card radius="panel">
        <SectionHeader icon={Search} title="فلاتر البحث" />

        <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
          <FormField label="رقم الهاتف">
            {({ id }) => <Input id={id} value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="ابحث بالهاتف" />}
          </FormField>
          <FormField label="كود الطالب">
            {({ id }) => <Input id={id} value={studentCode} onChange={(e) => setStudentCode(e.target.value)} placeholder="ابحث بكود السنتر، مثال: A58214" />}
          </FormField>
          <FormField label="الصف الدراسي">
            {({ id }) => (
              <Select id={id} value={gradeLevel} onChange={(e) => setGradeLevel(e.target.value)}>
                <option value="">-- كل الصفوف --</option>
                {gradeOptions.map((g) => <option key={g} value={g}>{g}</option>)}
              </Select>
            )}
          </FormField>
          <FormField label="السنتر">
            {({ id }) => (
              <Select
                id={id}
                value={centerName}
                onChange={(e) => {
                  setCenterName(e.target.value);
                  setGroupId('');
                }}
                disabled={loadingGroups}
              >
                <option value="">-- كل السناتر --</option>
                {centers.map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </Select>
            )}
          </FormField>
          <FormField label="المجموعة">
            {({ id }) => (
              <Select id={id} value={groupId} onChange={(e) => setGroupId(e.target.value)} disabled={loadingGroups}>
                <option value="">-- كل المجموعات --</option>
                {groups
                  .filter((g) => !centerName || g.center_name === centerName)
                  .map((g) => (
                    <option key={g.id} value={g.id}>
                      {g.center_name} — {g.group_name}
                    </option>
                  ))}
              </Select>
            )}
          </FormField>
        </div>

        <div className="flex justify-end gap-3 mt-4">
          <Button icon={Download} variant="outline" disabled={rows.length === 0} onClick={handleExport}>
            تصدير البيانات (اكسيل)
          </Button>
          <Button icon={Search} isLoading={loading} loadingText="جاري البحث..." onClick={runSearch}>
            بحث
          </Button>
        </div>
      </Card>

      <Card radius="panel" padding="none" className="overflow-hidden">
        <Table>
          <TableHeader>
            <tr>
              <TableHead>كود الطالب</TableHead>
              <TableHead>الاسم</TableHead>
              <TableHead>الهاتف</TableHead>
              <TableHead>هاتف ولي الأمر</TableHead>
              <TableHead>الصف</TableHead>
              <TableHead>المجموعة</TableHead>
              <TableHead align="center">أيام الحضور</TableHead>
              <TableHead align="center">أيام الغياب</TableHead>
              <TableHead>الملاحظة</TableHead>
              <TableHead align="center">إجراء</TableHead>
            </tr>
          </TableHeader>
          <TableBody>
            {loading ? (
              Array.from({ length: 4 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell colSpan={10}>
                    <Skeleton variant="text" width="100%" />
                  </TableCell>
                </TableRow>
              ))
            ) : !searched || rows.length === 0 ? (
              <TableEmptyState colSpan={10} icon={Users} title="لا توجد نتائج" description="جرّب تعديل الفلاتر أعلاه" />
            ) : (
              rows.map((r) => (
                <TableRow key={r.student_id}>
                  <TableCell emphasis>{r.center_code || '—'}</TableCell>
                  <TableCell>{r.name}</TableCell>
                  <TableCell>{r.phone}</TableCell>
                  <TableCell>{r.parent_phone}</TableCell>
                  <TableCell>{r.grade_level}</TableCell>
                  <TableCell>{r.groups.map((g) => g.group_name).join('، ') || '—'}</TableCell>
                  <TableCell align="center">{r.present_days}</TableCell>
                  <TableCell align="center">{r.absent_days}</TableCell>
                  <TableCell>
                    <span className="flex items-center gap-1.5 text-slate-400" title="لتعديل الملاحظة انتقل لصفحة الحضور">
                      <Info className="w-3.5 h-3.5 shrink-0" />
                      {r.note || 'لا توجد ملاحظة'}
                    </span>
                  </TableCell>
                  <TableCell align="center">
                    <Button
                      variant="ghost"
                      size="sm"
                      icon={Unlink}
                      className="text-rose-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 whitespace-nowrap"
                      onClick={() => setUnlinkTarget(r)}
                    >
                      فك الربط
                    </Button>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
        <p className="px-5 py-3 text-[11px] font-bold text-slate-400 dark:text-slate-500 border-t border-slate-100 dark:border-slate-800">
          لتعديل الملاحظة انتقل لصفحة الحضور.
        </p>
      </Card>

      <ConfirmDialog
        open={!!unlinkTarget}
        onClose={() => setUnlinkTarget(null)}
        onConfirm={confirmUnlink}
        title="فك ربط الطالب"
        message={unlinkTarget ? `هل أنت متأكد من فك ربط "${unlinkTarget.name}" بمركزك؟ سيتمكن الطالب (أو أي طالب آخر) من استخدام نفس الكود مرة أخرى.` : ''}
        confirmLabel="فك الربط"
        tone="danger"
        icon={AlertTriangle}
        isLoading={unlinking}
      />
    </motion.div>
  );
}

// ── Tab 2: single-session report ─────────────────────────────────────────

const SESSION_EXPORT_COLUMNS: XlsxColumn[] = [
  { header: 'الاسم', width: 26 },
  { header: 'كود السنتر', width: 16 },
  { header: 'وقت التسجيل', width: 20 },
  { header: 'ملاحظة', width: 30 },
];

function SessionReportTab({ groups, loadingGroups }: { groups: CenterGroup[]; loadingGroups: boolean }) {
  const { notify } = useCenteredNotification();
  const navigate = useNavigate();

  const [selectedCenter, setSelectedCenter] = useState('');
  const [selectedGrade, setSelectedGrade] = useState('');
  const [selectedGroupId, setSelectedGroupId] = useState('');
  const [selectedSessionId, setSelectedSessionId] = useState('');

  const [sessions, setSessions] = useState<SessionOption[]>([]);
  const [loadingSessions, setLoadingSessions] = useState(false);

  const [detail, setDetail] = useState<CenterSessionDetail | null>(null);
  const [loadingDetail, setLoadingDetail] = useState(false);

  const centers = useMemo(() => Array.from(new Set(groups.map((g) => g.center_name))).filter(Boolean), [groups]);
  const grades = useMemo(
    () =>
      Array.from(new Set(groups.filter((g) => g.center_name === selectedCenter).map((g) => g.grade_label))).filter(
        Boolean,
      ),
    [groups, selectedCenter],
  );
  const groupsForSelection = useMemo(
    () => groups.filter((g) => g.center_name === selectedCenter && g.grade_label === selectedGrade),
    [groups, selectedCenter, selectedGrade],
  );

  useEffect(() => {
    if (!selectedGroupId) {
      setSessions([]);
      setSelectedSessionId('');
      setDetail(null);
      return;
    }
    const load = async () => {
      try {
        setLoadingSessions(true);
        setSelectedSessionId('');
        setDetail(null);
        const data = await centerService.getGroupSessions(selectedGroupId);
        setSessions(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('CenterReports: failed to load sessions', err as Error);
        notify('تعذر تحميل حصص المجموعة', 'error');
      } finally {
        setLoadingSessions(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedGroupId]);

  const runSearch = async () => {
    if (!selectedSessionId) return;
    setLoadingDetail(true);
    try {
      const data = await centerService.getSession(selectedSessionId);
      setDetail(data);
    } catch (err) {
      logger.error('CenterReports: failed to load session detail', err as Error);
      notify('تعذر تحميل تقرير الحصة', 'error');
    } finally {
      setLoadingDetail(false);
    }
  };

  const handleExport = () => {
    if (!detail) return;
    const group = groupsForSelection.find((g) => g.id === selectedGroupId);
    const rows = detail.attendances.map((a) => [a.name, a.student_code, a.marked_at, a.note || '—']);
    const summary = [
      [`السنتر: ${group?.center_name ?? '—'}`, '', '', ''],
      [`الصف: ${group?.grade_label ?? '—'} — المجموعة: ${group?.group_name ?? '—'}`, '', '', ''],
      [`تاريخ الحصة: ${detail.session_date}`, '', '', ''],
      [`إجمالي: ${detail.total} — حاضر: ${detail.present} — غائب: ${detail.absent}`, '', '', ''],
      ['', '', '', ''],
    ];
    const groupLabel = group ? `${group.center_name}-${group.group_name}` : 'حصة';
    downloadXlsx(
      `تقرير-حصة-${groupLabel}-${detail.session_date}.xlsx`,
      SESSION_EXPORT_COLUMNS,
      [...summary, ...rows],
      'الحضور',
    );
  };

  return (
    <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.3 }} className="space-y-6">
      <Card radius="panel" className="space-y-4">
        <SectionHeader icon={ClipboardList} title="اختر الحصة" description="حدد السنتر، ثم الصف، ثم المجموعة، ثم تاريخ الحصة" />

        {loadingGroups ? (
          <Skeleton variant="block" className="h-12" />
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <FormField label="1. السنتر">
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedCenter}
                  onChange={(e) => {
                    setSelectedCenter(e.target.value);
                    setSelectedGrade('');
                    setSelectedGroupId('');
                  }}
                >
                  <option value="">-- اختر السنتر --</option>
                  {centers.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </Select>
              )}
            </FormField>

            <FormField label="2. الصف">
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedGrade}
                  onChange={(e) => {
                    setSelectedGrade(e.target.value);
                    setSelectedGroupId('');
                  }}
                  disabled={!selectedCenter}
                >
                  {!selectedCenter ? (
                    <option value="">يرجى اختيار السنتر أولاً</option>
                  ) : (
                    <>
                      <option value="">-- اختر الصف --</option>
                      {grades.map((g) => (
                        <option key={g} value={g}>
                          {g}
                        </option>
                      ))}
                    </>
                  )}
                </Select>
              )}
            </FormField>

            <FormField label="3. المجموعة">
              {({ id }) => (
                <Select id={id} value={selectedGroupId} onChange={(e) => setSelectedGroupId(e.target.value)} disabled={!selectedGrade}>
                  {!selectedGrade ? (
                    <option value="">يرجى اختيار الصف أولاً</option>
                  ) : (
                    <>
                      <option value="">-- اختر المجموعة --</option>
                      {groupsForSelection.map((g) => (
                        <option key={g.id} value={g.id}>
                          {g.group_name} — {g.schedule_text}
                        </option>
                      ))}
                    </>
                  )}
                </Select>
              )}
            </FormField>

            <FormField label="4. الحصة">
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedSessionId}
                  onChange={(e) => setSelectedSessionId(e.target.value)}
                  disabled={!selectedGroupId || loadingSessions}
                >
                  {!selectedGroupId ? (
                    <option value="">يرجى اختيار المجموعة أولاً</option>
                  ) : loadingSessions ? (
                    <option value="">جاري التحميل...</option>
                  ) : sessions.length === 0 ? (
                    <option value="">لا توجد حصص لهذه المجموعة</option>
                  ) : (
                    <>
                      <option value="">-- اختر الحصة --</option>
                      {sessions.map((s) => (
                        <option key={s.id} value={s.id}>
                          {s.session_date} {s.status === 'open' ? '(مفتوحة)' : '(مغلقة)'}
                        </option>
                      ))}
                    </>
                  )}
                </Select>
              )}
            </FormField>
          </div>
        )}

        <div className="flex justify-end">
          <Button icon={Search} isLoading={loadingDetail} loadingText="جاري البحث..." onClick={runSearch} disabled={!selectedSessionId}>
            بحث
          </Button>
        </div>
      </Card>

      {detail && (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <StatCard index={0} icon={Users} label="إجمالي طلاب المجموعة" value={detail.total} color="blue" />
            <StatCard index={1} icon={UserCheck} label="الحاضرون" value={detail.present} color="emerald" />
            <StatCard index={2} icon={UserX} label="الغائبون" value={detail.absent} color="rose" />
          </div>

          <div className="flex justify-end gap-3">
            <Button icon={Download} variant="outline" onClick={handleExport}>
              تصدير بيانات الحصة (اكسيل)
            </Button>
            <Button
              icon={RotateCcw}
              variant="secondary"
              onClick={() => navigate(`/instructor/center-attendance?sessionId=${detail.id}`)}
            >
              استعادة حضور الحصة
            </Button>
          </div>

          <Card radius="panel" padding="none" className="overflow-hidden">
            <Table>
              <TableHeader>
                <tr>
                  <TableHead>الاسم</TableHead>
                  <TableHead>الكود</TableHead>
                  <TableHead>وقت التسجيل</TableHead>
                  <TableHead>ملاحظة</TableHead>
                </tr>
              </TableHeader>
              <TableBody>
                {detail.attendances.length === 0 ? (
                  <TableEmptyState colSpan={4} icon={Users} title="لم يتم تسجيل أي حضور في هذه الحصة" />
                ) : (
                  detail.attendances.map((a) => (
                    <TableRow key={a.attendance_id}>
                      <TableCell emphasis>{a.name}</TableCell>
                      <TableCell>{a.student_code}</TableCell>
                      <TableCell>{a.marked_at}</TableCell>
                      <TableCell>{a.note || '—'}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </Card>
        </>
      )}

      {!detail && !loadingDetail && (
        <EmptyState icon={ClipboardList} title="اختر حصة واضغط بحث لعرض تقريرها" />
      )}
    </motion.div>
  );
}
