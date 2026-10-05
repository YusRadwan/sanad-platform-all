import { useEffect, useMemo, useState } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { Unlock, BookOpen, Users, UserCheck, CheckCircle2, Sparkles } from 'lucide-react';
import { centerService } from '../../services/centerService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
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
} from '../UI';

interface CenterGroup {
  id: string;
  center_name: string;
  grade_label: string;
  group_name: string;
  schedule_text: string;
}

interface CenterCourse {
  id: string;
  title: string;
}

interface SessionOption {
  id: string;
  session_date: string;
  status: 'open' | 'closed';
}

type ActivationMode = 'session' | 'student';

/**
 * "تفعيل كورس سنتر" — grants a course for FREE, no Subscription row, either
 * to every student who has a `center_attendances` row for one chosen
 * session, or to exactly one student by center code/platform code/phone.
 * See App\Actions\Center\ActivateCenterCourse's docblock for why this is a
 * fourth, purely-additive way into a course rather than a visibility toggle.
 */
export default function ActivateCenterCourse() {
  const { notify } = useCenteredNotification();

  // ── Course picker — الكورسات المخصَّصة للسنتر فقط (سنتر أو سنتر ومنصة) —
  // decision 10.39 (تصحيح صريح على القرار 10.35): طلب المستخدم لاحقاً أن
  // "قسم السنتر بالكامل" يعرض فقط كورسات مخصَّصة للسنتر، بلا استثناء لهذه
  // الصفحة تحديداً — حتى لو كان ذلك يُلغي إمكانية منح كورس مدفوع عادي
  // مجاناً (كانت نقطة القرار 10.35 نفسها). راجَع المستخدم صراحةً هذا
  // التعارض وأكَّد التقييد. ──────────────────────────────────────────────
  const [courses, setCourses] = useState<CenterCourse[]>([]);
  const [loadingCourses, setLoadingCourses] = useState(true);
  const [selectedCourseId, setSelectedCourseId] = useState('');

  useEffect(() => {
    const load = async () => {
      try {
        setLoadingCourses(true);
        const data = await centerService.getCenterCourses();
        setCourses(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('ActivateCenterCourse: failed to load courses', err as Error);
        notify('تعذر تحميل كورسات السنتر', 'error');
      } finally {
        setLoadingCourses(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const [mode, setMode] = useState<ActivationMode>('session');

  // ── Session mode — center → grade → group → session cascade, same
  // pattern as CenterReports.tsx's session-report tab. ───────────────────
  const [groups, setGroups] = useState<CenterGroup[]>([]);
  const [loadingGroups, setLoadingGroups] = useState(true);
  const [selectedCenter, setSelectedCenter] = useState('');
  const [selectedGrade, setSelectedGrade] = useState('');
  const [selectedGroupId, setSelectedGroupId] = useState('');
  const [selectedSessionId, setSelectedSessionId] = useState('');
  const [sessions, setSessions] = useState<SessionOption[]>([]);
  const [loadingSessions, setLoadingSessions] = useState(false);

  useEffect(() => {
    const load = async () => {
      try {
        setLoadingGroups(true);
        const data = await centerService.getMyGroups();
        setGroups(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('ActivateCenterCourse: failed to load groups', err as Error);
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

  useEffect(() => {
    if (!selectedGroupId) {
      setSessions([]);
      setSelectedSessionId('');
      return;
    }
    const load = async () => {
      try {
        setLoadingSessions(true);
        setSelectedSessionId('');
        const data = await centerService.getGroupSessions(selectedGroupId);
        setSessions(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('ActivateCenterCourse: failed to load sessions', err as Error);
        notify('تعذر تحميل حصص المجموعة', 'error');
      } finally {
        setLoadingSessions(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedGroupId]);

  // ── Student mode — one identifier, same shape as attendance marking. ───
  const [identifier, setIdentifier] = useState('');

  // ── Activation ───────────────────────────────────────────────────────
  const [activating, setActivating] = useState(false);
  const [result, setResult] = useState<
    | { mode: 'session'; granted: number; total_attendees: number }
    | { mode: 'student'; student_name: string | null }
    | null
  >(null);

  const canActivate = Boolean(selectedCourseId) && (mode === 'session' ? Boolean(selectedSessionId) : identifier.trim() !== '');

  const handleActivate = async () => {
    if (!canActivate) return;
    setActivating(true);
    setResult(null);
    try {
      const data = await centerService.activateCourse(selectedCourseId, {
        centerSessionId: mode === 'session' ? selectedSessionId : undefined,
        identifier: mode === 'student' ? identifier.trim() : undefined,
      });
      setResult(data);
      notify(
        data.mode === 'session'
          ? `تم تفعيل الكورس لـ ${data.granted} طالب من أصل ${data.total_attendees} ممن حضروا هذه الحصة`
          : `تم تفعيل الكورس للطالب "${data.student_name ?? ''}" بنجاح`,
        'success',
      );
      if (mode === 'student') setIdentifier('');
    } catch (err: any) {
      logger.error('ActivateCenterCourse: activation failed', err as Error);
      const message = err?.response?.data?.message || 'تعذر تفعيل الكورس، يرجى المحاولة مرة أخرى';
      notify(message, 'error');
    } finally {
      setActivating(false);
    }
  };

  return (
    <div dir="rtl" className="relative space-y-6 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-emerald-600/10 dark:bg-emerald-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-blue-500/8 dark:bg-blue-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />

      <PageHeader
        icon={Unlock}
        title="تفعيل كورس سنتر"
        description="امنح كورساً مجاناً بلا أي عملية مالية — لكل من حضر حصة معينة، أو لطالب واحد بكوده أو هاتفه"
      />

      <Card radius="panel" className="space-y-4">
        <SectionHeader icon={BookOpen} title="1. اختر الكورس" description="كورسات السنتر الخاصة بك فقط" />
        {loadingCourses ? (
          <Skeleton variant="block" className="h-12" />
        ) : courses.length === 0 ? (
          <EmptyState icon={BookOpen} title="لا توجد كورسات سنتر بعد" description="أضف كورساً بنطاق (السنتر) أولاً من صفحة إضافة الكورسات" />
        ) : (
          <Select value={selectedCourseId} onChange={(e) => { setSelectedCourseId(e.target.value); setResult(null); }}>
            <option value="">-- اختر الكورس --</option>
            {courses.map((c) => (
              <option key={c.id} value={c.id}>{c.title}</option>
            ))}
          </Select>
        )}
      </Card>

      {selectedCourseId && (
        <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-6">
          {/* Mode picker */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <button
              type="button"
              onClick={() => { setMode('session'); setResult(null); }}
              className={`flex items-center gap-3 p-4 rounded-2xl border-2 text-right transition-all cursor-pointer ${
                mode === 'session'
                  ? 'border-blue-500 bg-blue-50/70 dark:bg-blue-500/10 shadow-sm'
                  : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:border-slate-300 dark:hover:border-slate-700'
              }`}
            >
              <div className={`w-11 h-11 shrink-0 rounded-xl flex items-center justify-center ${mode === 'session' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'}`}>
                <Users className="w-5 h-5" />
              </div>
              <div>
                <div className="text-sm font-black text-slate-900 dark:text-white">تفعيل حصة كاملة</div>
                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400">لكل من حضر حصة معينة</div>
              </div>
            </button>

            <button
              type="button"
              onClick={() => { setMode('student'); setResult(null); }}
              className={`flex items-center gap-3 p-4 rounded-2xl border-2 text-right transition-all cursor-pointer ${
                mode === 'student'
                  ? 'border-emerald-500 bg-emerald-50/70 dark:bg-emerald-500/10 shadow-sm'
                  : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:border-slate-300 dark:hover:border-slate-700'
              }`}
            >
              <div className={`w-11 h-11 shrink-0 rounded-xl flex items-center justify-center ${mode === 'student' ? 'bg-emerald-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'}`}>
                <UserCheck className="w-5 h-5" />
              </div>
              <div>
                <div className="text-sm font-black text-slate-900 dark:text-white">تفعيل طالب واحد</div>
                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400">بكود السنتر أو رقم الهاتف</div>
              </div>
            </button>
          </div>

          <Card radius="panel" className="space-y-5">
            <AnimatePresence mode="wait">
              {mode === 'session' ? (
                <motion.div key="session" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.15 }} className="space-y-4">
                  <SectionHeader icon={Users} title="2. اختر الحصة" description="حدد السنتر، ثم الصف، ثم المجموعة، ثم الحصة" />

                  {loadingGroups ? (
                    <Skeleton variant="block" className="h-12" />
                  ) : groups.length === 0 ? (
                    <EmptyState icon={Users} title="لا توجد مجموعات بعد" description="أضف مجموعة من صفحة (إنشاء مجموعة) أولاً" />
                  ) : (
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                      <FormField label="السنتر">
                        {({ id }) => (
                          <Select id={id} value={selectedCenter} onChange={(e) => { setSelectedCenter(e.target.value); setSelectedGrade(''); setSelectedGroupId(''); }}>
                            <option value="">-- اختر السنتر --</option>
                            {centers.map((c) => <option key={c} value={c}>{c}</option>)}
                          </Select>
                        )}
                      </FormField>

                      <FormField label="الصف">
                        {({ id }) => (
                          <Select id={id} value={selectedGrade} onChange={(e) => { setSelectedGrade(e.target.value); setSelectedGroupId(''); }} disabled={!selectedCenter}>
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

                      <FormField label="الحصة">
                        {({ id }) => (
                          <Select id={id} value={selectedSessionId} onChange={(e) => setSelectedSessionId(e.target.value)} disabled={!selectedGroupId || loadingSessions}>
                            {!selectedGroupId ? <option value="">يرجى اختيار المجموعة أولاً</option>
                              : loadingSessions ? <option value="">جاري التحميل...</option>
                              : sessions.length === 0 ? <option value="">لا توجد حصص لهذه المجموعة</option>
                              : (
                                <>
                                  <option value="">-- اختر الحصة --</option>
                                  {sessions.map((s) => <option key={s.id} value={s.id}>{s.session_date} {s.status === 'open' ? '(مفتوحة)' : '(مغلقة)'}</option>)}
                                </>
                              )}
                          </Select>
                        )}
                      </FormField>
                    </div>
                  )}
                </motion.div>
              ) : (
                <motion.div key="student" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.15 }} className="space-y-4">
                  <SectionHeader icon={UserCheck} title="2. كود السنتر الخاص بالطالب أو رقم الهاتف" />
                  <div className="max-w-sm">
                    <Input
                      size="lg"
                      value={identifier}
                      onChange={(e) => setIdentifier(e.target.value)}
                      placeholder="A58214"
                      className="text-center font-mono font-black tracking-widest"
                    />
                  </div>
                </motion.div>
              )}
            </AnimatePresence>

            <div className="flex justify-end pt-2 border-t border-slate-100 dark:border-slate-800">
              <Button icon={Unlock} isLoading={activating} loadingText="جاري التفعيل..." disabled={!canActivate} onClick={handleActivate}>
                تفعيل
              </Button>
            </div>
          </Card>

          <AnimatePresence>
            {result && (
              <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }}>
                <Card radius="panel" className="flex items-center gap-3 border-emerald-200/60 dark:border-emerald-500/20">
                  <div className="w-11 h-11 shrink-0 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                    <CheckCircle2 className="w-5 h-5" />
                  </div>
                  {result.mode === 'session' ? (
                    <p className="text-sm font-bold text-slate-700 dark:text-slate-200">
                      تم تفعيل الكورس لـ <span className="font-black text-emerald-600 dark:text-emerald-400">{result.granted}</span> طالب من أصل {result.total_attendees} ممن حضروا هذه الحصة.
                    </p>
                  ) : (
                    <p className="text-sm font-bold text-slate-700 dark:text-slate-200">
                      تم تفعيل الكورس للطالب <span className="font-black text-emerald-600 dark:text-emerald-400">{result.student_name}</span> بنجاح.
                    </p>
                  )}
                </Card>
              </motion.div>
            )}
          </AnimatePresence>

          <div className="flex items-center gap-2 text-[11px] font-bold text-slate-400 dark:text-slate-500 px-1">
            <Sparkles className="w-3.5 h-3.5 shrink-0" />
            <span>التفعيل مجاني بالكامل ولا يُسجَّل كأي عملية مالية أو اشتراك.</span>
          </div>
        </motion.div>
      )}
    </div>
  );
}
