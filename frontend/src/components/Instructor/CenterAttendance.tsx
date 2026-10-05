import { useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { useSearchParams } from 'react-router';
import { motion, AnimatePresence } from 'motion/react';
import {
  ClipboardCheck,
  PlayCircle,
  ScanLine,
  Save,
  Trash2,
  XCircle,
  CheckCircle2,
  Users,
  ClipboardList,
  Building2 as Building2Fallback,
  AlertTriangle,
} from 'lucide-react';
import { centerService } from '../../services/centerService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  PageHeader,
  Card,
  CardHeader,
  SectionHeader,
  Button,
  Input,
  Select,
  FormField,
  Skeleton,
  EmptyState,
  ConfirmDialog,
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
  TableEmptyState,
  Badge,
} from '../UI';

interface CenterGroup {
  id: string;
  center_name: string;
  grade_label: string;
  group_name: string;
  schedule_text: string;
}

interface MaterialOption {
  id: string;
  title: string;
}

interface CenterCourse {
  id: string;
  title: string;
}

interface ExamScore {
  taken: boolean;
  score: number | null;
}

interface VideoProgress {
  watched: boolean;
  percent: number | null;
}

interface Attendance {
  attendance_id: string;
  student_id: string;
  name: string;
  student_code: string;
  phone: string | null;
  /** The center code (letter+5digits) this student joined with. */
  center_code: string | null;
  /** Their home group's name — the group this attendance counts toward. */
  group_name: string | null;
  marked_at: string;
  note: string | null;
  /** "حصة تعويضية" — checked in through a group other than their own. */
  is_makeup: boolean;
  exam_scores: Record<string, ExamScore>;
  video_progress: Record<string, VideoProgress>;
}

interface CenterSession {
  id: string;
  status: 'open' | 'closed';
  session_date: string;
  opened_at: string;
  closed_at: string | null;
  group: CenterGroup;
  exams: MaterialOption[];
  videos: MaterialOption[];
  total: number;
  present: number;
  absent: number;
  /** Attendees checked in as a compensatory session — excluded from `present`/`absent`. */
  makeup_present: number;
  attendances: Attendance[];
}

/** The "press Enter to submit" hint, styled as a keycap instead of plain helper text. */
function EnterHint() {
  return (
    <span className="inline-flex items-center gap-1.5 text-[11px] font-bold text-slate-400 dark:text-slate-500">
      اضغط
      <kbd className="px-1.5 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-slate-500 dark:text-slate-400">
        Enter
      </kbd>
      لتسجيل الحضور
    </span>
  );
}

/**
 * "حضور الطلاب" — the Center System's live attendance page. Two steps:
 * 1) pick a center group via three client-side cascading selects built from
 *    one `getMyGroups()` call, then open a new session.
 * 2) the active session: attach exams, mark attendance through one unified
 *    input (typed student code, typed phone, or a hardware barcode/QR
 *    scanner acting as a keyboard — it types the value then Enter), edit
 *    notes inline, and close the session.
 */
export default function CenterAttendance() {
  const { notify } = useCenteredNotification();

  // ── Step 1: group selection ──────────────────────────────────────────
  const [groups, setGroups] = useState<CenterGroup[]>([]);
  const [loadingGroups, setLoadingGroups] = useState(true);
  const [selectedCenter, setSelectedCenter] = useState('');
  const [selectedGrade, setSelectedGrade] = useState('');
  const [selectedGroupId, setSelectedGroupId] = useState('');
  const [openingSession, setOpeningSession] = useState(false);

  useEffect(() => {
    const load = async () => {
      try {
        setLoadingGroups(true);
        const data = await centerService.getMyGroups();
        setGroups(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('CenterAttendance: failed to load groups', err as Error);
        notify('تعذر تحميل مجموعاتك، يرجى إعادة تحميل الصفحة', 'error');
      } finally {
        setLoadingGroups(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const centers = useMemo(
    () => Array.from(new Set(groups.map((g) => g.center_name))).filter(Boolean),
    [groups],
  );

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

  // ── Course & materials picker — "اختر الكورس" then multiple videos/exams,
  // scoped to the teacher's own CENTER-visible courses only (GET
  // /center/courses already filters to visibility center/both; there is no
  // client-side re-filtering to get wrong). ──────────────────────────────
  const [centerCourses, setCenterCourses] = useState<CenterCourse[]>([]);
  const [loadingCourses, setLoadingCourses] = useState(true);
  const [selectedCourseId, setSelectedCourseId] = useState('');
  const [courseVideos, setCourseVideos] = useState<MaterialOption[]>([]);
  const [courseExams, setCourseExams] = useState<MaterialOption[]>([]);
  const [loadingMaterials, setLoadingMaterials] = useState(false);

  useEffect(() => {
    const load = async () => {
      try {
        setLoadingCourses(true);
        const data = await centerService.getCenterCourses();
        setCenterCourses(Array.isArray(data) ? data : []);
      } catch (err) {
        logger.error('CenterAttendance: failed to load center courses', err as Error);
      } finally {
        setLoadingCourses(false);
      }
    };
    load();
  }, []);

  useEffect(() => {
    if (!selectedCourseId) {
      setCourseVideos([]);
      setCourseExams([]);
      return;
    }
    const load = async () => {
      try {
        setLoadingMaterials(true);
        const data = await centerService.getCourseMaterials(selectedCourseId);
        setCourseVideos(data.videos ?? []);
        setCourseExams(data.exams ?? []);
      } catch (err) {
        logger.error('CenterAttendance: failed to load course materials', err as Error);
        notify('تعذر تحميل فيديوهات وامتحانات هذا الكورس', 'error');
      } finally {
        setLoadingMaterials(false);
      }
    };
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedCourseId]);

  // ── Step 2: active session ───────────────────────────────────────────
  const [session, setSession] = useState<CenterSession | null>(null);
  const [selectedExamIds, setSelectedExamIds] = useState<string[]>([]);
  const [selectedVideoIds, setSelectedVideoIds] = useState<string[]>([]);
  const [attachingMaterials, setAttachingMaterials] = useState(false);

  useEffect(() => {
    setSelectedExamIds(session?.exams?.map((e) => e.id) ?? []);
    setSelectedVideoIds(session?.videos?.map((v) => v.id) ?? []);
  }, [session?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  // ── "استعادة حضور الحصة" — resuming an existing (possibly closed) session
  // via ?sessionId=… from the session report, skipping group selection
  // entirely. A closed session is reopened automatically on arrival so
  // every edit action below (add/edit/delete attendance) works right away. ─
  const [searchParams] = useSearchParams();
  const resumeSessionId = searchParams.get('sessionId');
  const [resuming, setResuming] = useState(Boolean(resumeSessionId));

  useEffect(() => {
    if (!resumeSessionId) return;
    (async () => {
      try {
        setResuming(true);
        let loaded = await centerService.getSession(resumeSessionId);
        if (loaded?.status === 'closed') {
          loaded = await centerService.reopenSession(resumeSessionId);
          notify('تم استعادة الحصة، يمكنك الآن تعديل الحضور', 'success');
        }
        setSession(loaded);
      } catch (err) {
        logger.error('CenterAttendance: failed to resume session', err as Error);
        notify('تعذر استعادة الحصة المطلوبة', 'error');
      } finally {
        setResuming(false);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resumeSessionId]);

  // ── Delete the whole session ──────────────────────────────────────────
  const [showDeleteSessionConfirm, setShowDeleteSessionConfirm] = useState(false);
  const [deletingSession, setDeletingSession] = useState(false);

  const handleDeleteSession = async () => {
    if (!session) return;
    setDeletingSession(true);
    try {
      await centerService.deleteSession(session.id);
      notify('تم حذف الحصة بالكامل', 'success');
      setShowDeleteSessionConfirm(false);
      backToSelection();
    } catch (err) {
      logger.error('CenterAttendance: failed to delete session', err as Error);
      notify('تعذر حذف الحصة، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setDeletingSession(false);
    }
  };

  const handleOpenSession = async () => {
    if (!selectedGroupId) return;
    setOpeningSession(true);
    try {
      const newSession = await centerService.openSession(selectedGroupId);
      setSession(newSession);
      notify('تم فتح الحصة بنجاح', 'success');
    } catch (err: any) {
      logger.error('CenterAttendance: failed to open session', err as Error);
      const message = err?.response?.data?.message || 'تعذر فتح الحصة، يرجى المحاولة مرة أخرى';
      notify(message, 'error');
    } finally {
      setOpeningSession(false);
    }
  };

  const toggleExam = (id: string) => {
    setSelectedExamIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  const toggleVideo = (id: string) => {
    setSelectedVideoIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  const handleAttachMaterials = async () => {
    if (!session) return;
    setAttachingMaterials(true);
    try {
      let updated = session;
      if (selectedExamIds.length > 0) {
        updated = await centerService.attachExams(session.id, selectedExamIds);
      }
      if (selectedVideoIds.length > 0) {
        updated = await centerService.attachVideos(session.id, selectedVideoIds);
      }
      setSession(updated);
      notify('تم ربط المواد المختارة بالحصة', 'success');
    } catch (err) {
      logger.error('CenterAttendance: failed to attach materials', err as Error);
      notify('تعذر ربط المواد المختارة، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setAttachingMaterials(false);
    }
  };

  // ── Attendance marking — one unified input handles a typed CENTER code
  // (the letter+5-digit code printed/scanned off the student's card — what
  // the backend now tries FIRST, see MarkAttendance::resolveByCenterCode),
  // a typed phone number, and a hardware barcode/QR scanner (which behaves
  // as a keyboard: it types the scanned value into whatever is focused,
  // then sends Enter) — all of it just ends up as a string submitted on Enter.
  const [identifierInput, setIdentifierInput] = useState('');
  const [marking, setMarking] = useState(false);
  const [lastMarkedName, setLastMarkedName] = useState<string | null>(null);
  // `disabled` during the request drops focus from the input (browsers blur
  // a disabled element outright), and re-enabling it afterward does NOT
  // bring focus back on its own — so without this, the scanner's next scan
  // (which just "types" into whatever is focused) has nowhere to land.
  const identifierInputRef = useRef<HTMLInputElement>(null);

  // Refocuses once `marking` flips back to false — i.e. AFTER React commits
  // the re-enabled (non-disabled) input to the DOM. Calling .focus()
  // synchronously inside markAttendance's `finally` is too early: the state
  // update that clears `disabled` hasn't been applied to the DOM yet at that
  // point, and focusing a still-disabled element is a no-op.
  useEffect(() => {
    if (!marking) {
      identifierInputRef.current?.focus();
    }
  }, [marking]);

  // A student whose home group differs from this session's group needs an
  // explicit "مقبول تعويضياً" confirmation before the check-in goes through
  // (backend answers 409, message names their real group — see
  // MarkAttendance.php). Holds the identifier so the SAME value can be
  // resubmitted with `confirm: true` once the teacher accepts.
  const [pendingMakeup, setPendingMakeup] = useState<{ identifier: string; message: string } | null>(null);
  const [confirmingMakeup, setConfirmingMakeup] = useState(false);

  const markAttendance = async (identifier: string, confirm = false) => {
    if (!session || !identifier.trim()) return;
    setMarking(true);
    try {
      const updated = await centerService.markAttendance(session.id, identifier.trim(), confirm);
      setSession(updated);
      setLastMarkedName(updated.attendances?.[0]?.name ?? null);
      notify('تم تسجيل الحضور بنجاح', 'success');
    } catch (err: any) {
      if (err?.response?.status === 409) {
        setPendingMakeup({ identifier: identifier.trim(), message: err.response.data?.message || '' });
        return;
      }
      logger.error('CenterAttendance: failed to mark attendance', err as Error);
      const message = err?.response?.data?.message || 'تعذر تسجيل الحضور، تأكد من صحة الكود أو رقم الهاتف';
      notify(message, 'error');
    } finally {
      setMarking(false);
    }
  };

  const handleIdentifierKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      markAttendance(identifierInput);
      setIdentifierInput('');
    }
  };

  const confirmMakeupAttendance = async () => {
    if (!pendingMakeup) return;
    setConfirmingMakeup(true);
    try {
      await markAttendance(pendingMakeup.identifier, true);
    } finally {
      setConfirmingMakeup(false);
      setPendingMakeup(null);
    }
  };

  // ── Note editing ──────────────────────────────────────────────────────
  const [editingNoteId, setEditingNoteId] = useState<string | null>(null);
  const [noteDraft, setNoteDraft] = useState('');
  const [savingNote, setSavingNote] = useState(false);

  const startEditNote = (attendanceId: string, current: string | null) => {
    setEditingNoteId(attendanceId);
    setNoteDraft(current || '');
  };

  const saveNote = async (attendanceId: string) => {
    setSavingNote(true);
    try {
      await centerService.updateAttendanceNote(attendanceId, noteDraft.trim() || null);
      setSession((prev) =>
        prev
          ? {
              ...prev,
              attendances: prev.attendances.map((a) =>
                a.attendance_id === attendanceId ? { ...a, note: noteDraft.trim() || null } : a,
              ),
            }
          : prev,
      );
      setEditingNoteId(null);
      notify('تم حفظ الملاحظة', 'success');
    } catch (err) {
      logger.error('CenterAttendance: failed to save note', err as Error);
      notify('تعذر حفظ الملاحظة، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setSavingNote(false);
    }
  };

  // ── Delete attendance ─────────────────────────────────────────────────
  const [deletingId, setDeletingId] = useState<string | null>(null);

  const handleDeleteAttendance = async (attendanceId: string) => {
    if (!session) return;
    setDeletingId(attendanceId);
    try {
      await centerService.deleteAttendance(session.id, attendanceId);
      const refreshed = await centerService.getSession(session.id);
      setSession(refreshed);
    } catch (err) {
      logger.error('CenterAttendance: failed to delete attendance', err as Error);
      notify('تعذر حذف سجل الحضور، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setDeletingId(null);
    }
  };

  // ── Close session ─────────────────────────────────────────────────────
  const [showCloseConfirm, setShowCloseConfirm] = useState(false);
  const [closing, setClosing] = useState(false);
  const [closedSummary, setClosedSummary] = useState<{ total: number; present: number; absent: number } | null>(
    null,
  );

  const handleCloseSession = async () => {
    if (!session) return;
    setClosing(true);
    try {
      const summary = await centerService.closeSession(session.id);
      setClosedSummary(summary);
      setShowCloseConfirm(false);
      notify('تم إغلاق الحصة بنجاح', 'success');
    } catch (err) {
      logger.error('CenterAttendance: failed to close session', err as Error);
      notify('تعذر إغلاق الحصة، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setClosing(false);
    }
  };

  const backToSelection = () => {
    setSession(null);
    setClosedSummary(null);
    setIdentifierInput('');
    setLastMarkedName(null);
  };

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />

      <PageHeader icon={ClipboardCheck} title="حضور الطلاب" description="افتح حصة جديدة وسجّل حضور طلاب السنتر" />

      {resuming ? (
        <Card radius="panel" className="space-y-4">
          <Skeleton variant="block" className="h-10 w-1/2" />
          <Skeleton variant="block" className="h-24" />
        </Card>
      ) : !session ? (
        // ── STEP 1: pick a group, then open a session ──────────────────
        <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.35 }}>
          <Card radius="panel" className="space-y-6">
            <SectionHeader icon={Users} title="اختر المجموعة" description="حدد السنتر، ثم الصف، ثم المجموعة والميعاد" />

            {loadingGroups ? (
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Skeleton variant="block" className="h-12" />
                <Skeleton variant="block" className="h-12" />
                <Skeleton variant="block" className="h-12" />
              </div>
            ) : groups.length === 0 ? (
              <EmptyState
                icon={Building2Fallback}
                title="لا توجد مجموعات بعد"
                description="أضف مجموعة من صفحة (إنشاء مجموعة) أولاً"
              />
            ) : (
              <>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
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

                  <FormField label="3. المجموعة والميعاد">
                    {({ id }) => (
                      <Select
                        id={id}
                        value={selectedGroupId}
                        onChange={(e) => setSelectedGroupId(e.target.value)}
                        disabled={!selectedGrade}
                      >
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
                </div>

                {selectedGroupId && (
                  <div className="flex justify-end pt-2 border-t border-slate-100 dark:border-slate-800">
                    <Button icon={PlayCircle} isLoading={openingSession} loadingText="جاري فتح الحصة..." onClick={handleOpenSession}>
                      فتح حصة جديدة
                    </Button>
                  </div>
                )}
              </>
            )}
          </Card>
        </motion.div>
      ) : (
        // ── STEP 2: active session ───────────────────────────────────────
        <div className="space-y-6">
          <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.3 }}>
            <Card radius="panel">
              <CardHeader
                icon={ClipboardCheck}
                title={`${session.group.center_name} — ${session.group.group_name}`}
                description={`${session.group.grade_label} • ${session.group.schedule_text} • ${session.session_date}`}
                action={
                  <div className="flex items-center gap-2">
                    <Button variant="ghost" size="sm" icon={Trash2} className="text-rose-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10" onClick={() => setShowDeleteSessionConfirm(true)}>
                      حذف الحصة بالكامل
                    </Button>
                    {session.status === 'open' && (
                      <Button variant="danger" size="sm" icon={XCircle} onClick={() => setShowCloseConfirm(true)}>
                        إغلاق الحصة
                      </Button>
                    )}
                  </div>
                }
              />
            </Card>
          </motion.div>

          {/* Materials (left) + attendance marking (right) side by side on
              wide screens — both used to be full-width single-column cards
              with mostly short content, wasting the whole other half of the
              row. Same grid the rest of this codebase reaches for whenever
              two independent, similarly-sized panels sit at one step. */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
            {/* Course & materials — "اختر الكورس" (center-visible only), then
                multiple videos (watch %) and/or multiple exams (score), as
                compact toggle pills instead of full-width checkbox rows. */}
            <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.05, duration: 0.3 }}>
              <Card radius="panel" className="space-y-4">
                <SectionHeader
                  icon={ClipboardList}
                  title="ربط فيديوهات وامتحانات بالحصة"
                  description="اختر الكورس، ثم المواد المطلوب متابعتها"
                  actions={
                    selectedCourseId && !loadingMaterials && (courseVideos.length > 0 || courseExams.length > 0) ? (
                      <Button size="sm" icon={Save} isLoading={attachingMaterials} loadingText="جاري الحفظ..." onClick={handleAttachMaterials}>
                        حفظ
                      </Button>
                    ) : undefined
                  }
                />

                <Select
                  value={selectedCourseId}
                  onChange={(e) => setSelectedCourseId(e.target.value)}
                  disabled={loadingCourses}
                >
                  <option value="">
                    {loadingCourses ? '-- جاري التحميل... --' : centerCourses.length === 0 ? '-- لا توجد كورسات سنتر --' : '-- اختر الكورس --'}
                  </option>
                  {centerCourses.map((c) => (
                    <option key={c.id} value={c.id}>{c.title}</option>
                  ))}
                </Select>

                {selectedCourseId && (
                  loadingMaterials ? (
                    <Skeleton variant="block" className="h-16" />
                  ) : courseVideos.length === 0 && courseExams.length === 0 ? (
                    <p className="text-xs font-bold text-slate-500 dark:text-slate-400">لا توجد فيديوهات أو امتحانات في هذا الكورس.</p>
                  ) : (
                    <div className="space-y-4">
                      {courseVideos.length > 0 && (
                        <div className="space-y-1.5">
                          <span className="text-[11px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-wide">
                            الفيديوهات (نسبة المشاهدة)
                          </span>
                          <div className="flex flex-wrap gap-1.5">
                            {courseVideos.map((video) => {
                              const active = selectedVideoIds.includes(video.id);
                              return (
                                <button
                                  key={video.id}
                                  type="button"
                                  onClick={() => toggleVideo(video.id)}
                                  className={`px-2.5 py-1.5 rounded-lg text-[11px] font-bold border transition-colors cursor-pointer ${
                                    active
                                      ? 'bg-blue-600 border-blue-600 text-white'
                                      : 'bg-slate-50 dark:bg-slate-950/40 border-slate-200/70 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-700'
                                  }`}
                                >
                                  {video.title}
                                </button>
                              );
                            })}
                          </div>
                        </div>
                      )}

                      {courseExams.length > 0 && (
                        <div className="space-y-1.5">
                          <span className="text-[11px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-wide">
                            الامتحانات (الدرجة)
                          </span>
                          <div className="flex flex-wrap gap-1.5">
                            {courseExams.map((exam) => {
                              const active = selectedExamIds.includes(exam.id);
                              return (
                                <button
                                  key={exam.id}
                                  type="button"
                                  onClick={() => toggleExam(exam.id)}
                                  className={`px-2.5 py-1.5 rounded-lg text-[11px] font-bold border transition-colors cursor-pointer ${
                                    active
                                      ? 'bg-violet-600 border-violet-600 text-white'
                                      : 'bg-slate-50 dark:bg-slate-950/40 border-slate-200/70 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-700'
                                  }`}
                                >
                                  {exam.title}
                                </button>
                              );
                            })}
                          </div>
                        </div>
                      )}
                    </div>
                  )
                )}
              </Card>
            </motion.div>

            {/* Attendance marking */}
            <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1, duration: 0.3 }}>
              <Card radius="panel" className="space-y-3">
                <SectionHeader
                  icon={ClipboardCheck}
                  title="تسجيل الحضور"
                  description="كود السنتر أو الهاتف، أو مرّر الكارت أمام الماسح"
                />

                {/* One unified cell — types a student code, a phone number, or
                    whatever a hardware barcode/QR scanner "types" when the
                    card is passed over it (it emulates a keyboard and sends
                    Enter automatically, so scans submit with no extra step). */}
                <div className="flex items-center gap-3">
                  <div className="w-11 h-11 shrink-0 rounded-xl bg-blue-50 dark:bg-blue-500/10 flex items-center justify-center text-blue-600 dark:text-blue-400">
                    <ScanLine className="w-5 h-5" />
                  </div>
                  <Input
                    ref={identifierInputRef}
                    size="lg"
                    value={identifierInput}
                    onChange={(e) => setIdentifierInput(e.target.value)}
                    onKeyDown={handleIdentifierKeyDown}
                    placeholder="A58214"
                    disabled={marking}
                    className="text-center font-mono font-black tracking-widest"
                    autoFocus
                  />
                </div>
                <div className="flex justify-center">
                  <EnterHint />
                </div>

                <AnimatePresence>
                  {lastMarkedName && (
                    <motion.div
                      initial={{ opacity: 0, y: 6, height: 0 }}
                      animate={{ opacity: 1, y: 0, height: 'auto' }}
                      exit={{ opacity: 0, height: 0 }}
                      className="overflow-hidden"
                    >
                      <div className="flex items-center justify-center gap-2 p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200/60 dark:border-emerald-500/20 text-emerald-700 dark:text-emerald-400">
                        <CheckCircle2 className="w-4 h-4 shrink-0" />
                        <span className="text-sm font-black">تم تسجيل حضور: {lastMarkedName}</span>
                      </div>
                    </motion.div>
                  )}
                </AnimatePresence>
              </Card>
            </motion.div>
          </div>

          {/* Attendee list */}
          <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15, duration: 0.3 }}>
            <Card radius="panel" padding="none" className="overflow-hidden">
              <div className="p-5 sm:p-6 bg-slate-50/60 dark:bg-slate-800/20">
                <SectionHeader className="mb-0" icon={Users} title="الطلاب الحاضرون" description={`${session.attendances.length} طالب`} />
              </div>

              <Table zebra className="min-w-[1200px] table-fixed">
                <TableHeader>
                  <tr className="divide-x divide-slate-200 dark:divide-slate-800 rtl:divide-x-reverse">
                    <TableHead className="w-[130px]">كود الطالب</TableHead>
                    <TableHead className="w-[110px]">كود السنتر</TableHead>
                    <TableHead className="w-[180px]">اسم الطالب</TableHead>
                    <TableHead className="w-[130px]">رقم هاتف الطالب</TableHead>
                    <TableHead className="w-[150px]">المجموعة</TableHead>
                    <TableHead className="w-[150px] text-center">وقت الحضور</TableHead>
                    {session.videos.map((video) => (
                      <TableHead key={video.id} align="center" className="w-[120px]">
                        {video.title}
                      </TableHead>
                    ))}
                    {session.exams.map((exam) => (
                      <TableHead key={exam.id} align="center" className="w-[120px]">
                        {exam.title}
                      </TableHead>
                    ))}
                    <TableHead className="w-[220px]">ملاحظة</TableHead>
                    <TableHead align="center" className="w-[90px]">إجراء</TableHead>
                  </tr>
                </TableHeader>
                <TableBody>
                  {session.attendances.length === 0 ? (
                    <TableEmptyState colSpan={8 + session.exams.length + session.videos.length} icon={Users} title="لم يتم تسجيل أي حضور بعد" />
                  ) : (
                    session.attendances.map((a) => (
                      <TableRow key={a.attendance_id} className="divide-x divide-slate-100 dark:divide-slate-800 rtl:divide-x-reverse">
                        <TableCell emphasis className="font-mono truncate" dir="ltr">{a.student_code}</TableCell>
                        <TableCell className="font-mono truncate" dir="ltr">{a.center_code || '—'}</TableCell>
                        <TableCell className="truncate" title={a.name}>
                          <span className="inline-flex items-center gap-1.5">
                            {a.name}
                            {a.is_makeup && <Badge tone="warning" size="sm">تعويضية</Badge>}
                          </span>
                        </TableCell>
                        <TableCell className="font-mono truncate" dir="ltr">{a.phone || '—'}</TableCell>
                        <TableCell className="truncate">{a.group_name || '—'}</TableCell>
                        <TableCell className="font-mono text-[11px] text-center text-slate-500 dark:text-slate-400 truncate">
                          {a.marked_at}
                        </TableCell>
                        {session.videos.map((video) => {
                          const p = a.video_progress?.[video.id];
                          return (
                            <TableCell key={video.id} align="center">
                              {p?.percent !== null && p?.percent !== undefined ? (
                                <Badge tone={p.percent >= 90 ? 'success' : 'warning'} size="sm">{p.percent}%</Badge>
                              ) : (
                                <span className="text-slate-300 dark:text-slate-700">—</span>
                              )}
                            </TableCell>
                          );
                        })}
                        {session.exams.map((exam) => {
                          const s = a.exam_scores?.[exam.id];
                          return (
                            <TableCell key={exam.id} align="center">
                              {s?.taken ? (
                                <Badge tone="success" size="sm">{s.score ?? '—'}</Badge>
                              ) : (
                                <span className="text-slate-300 dark:text-slate-700">—</span>
                              )}
                            </TableCell>
                          );
                        })}
                        <TableCell>
                          {editingNoteId === a.attendance_id ? (
                            <div className="flex items-center gap-1.5">
                              <Input
                                size="sm"
                                value={noteDraft}
                                onChange={(e) => setNoteDraft(e.target.value)}
                                onKeyDown={(e) => {
                                  if (e.key === 'Enter') saveNote(a.attendance_id);
                                }}
                                autoFocus
                              />
                              <Button size="sm" iconOnly aria-label="حفظ" isLoading={savingNote} onClick={() => saveNote(a.attendance_id)}>
                                <Save className="w-3.5 h-3.5" />
                              </Button>
                            </div>
                          ) : (
                            <button
                              type="button"
                              onClick={() => startEditNote(a.attendance_id, a.note)}
                              className="w-full text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 text-start cursor-pointer truncate"
                            >
                              {a.note || 'إضافة ملاحظة...'}
                            </button>
                          )}
                        </TableCell>
                        <TableCell align="center">
                          <Button
                            variant="ghost"
                            size="sm"
                            iconOnly
                            aria-label="حذف"
                            isLoading={deletingId === a.attendance_id}
                            className="text-rose-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10"
                            onClick={() => handleDeleteAttendance(a.attendance_id)}
                          >
                            <Trash2 className="w-4 h-4" />
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </Card>
          </motion.div>
        </div>
      )}

      <ConfirmDialog
        open={showCloseConfirm}
        onClose={() => setShowCloseConfirm(false)}
        onConfirm={handleCloseSession}
        title="إغلاق الحصة"
        message="سيتم إغلاق الحصة نهائياً ولن يمكن تسجيل المزيد من الحضور بعد ذلك."
        confirmLabel="إغلاق الحصة"
        tone="danger"
        isLoading={closing}
      >
        {session && (
          <div className="space-y-2 text-xs font-bold text-slate-600 dark:text-slate-300">
            <div className="flex justify-between"><span>السنتر</span><span>{session.group.center_name}</span></div>
            <div className="flex justify-between"><span>الصف</span><span>{session.group.grade_label}</span></div>
            <div className="flex justify-between"><span>المجموعة</span><span>{session.group.group_name}</span></div>
            <div className="flex justify-between"><span>الميعاد</span><span>{session.group.schedule_text}</span></div>
            <div className="flex justify-between"><span>إجمالي الطلاب</span><span>{session.total}</span></div>
            <div className="flex justify-between"><span>الحاضرون</span><span>{session.present}</span></div>
            <div className="flex justify-between"><span>الغائبون</span><span>{session.absent}</span></div>
          </div>
        )}
      </ConfirmDialog>

      <ConfirmDialog
        open={!!pendingMakeup}
        onClose={() => setPendingMakeup(null)}
        onConfirm={confirmMakeupAttendance}
        title="تسجيل حضور في مجموعة أخرى"
        message={pendingMakeup?.message || ''}
        confirmLabel="تسجيل كحصة تعويضية"
        cancelLabel="إلغاء"
        tone="brand"
        icon={AlertTriangle}
        isLoading={confirmingMakeup}
      />

      <ConfirmDialog
        open={!!closedSummary}
        onClose={backToSelection}
        onConfirm={backToSelection}
        title="تم إغلاق الحصة"
        message=""
        confirmLabel="العودة لاختيار مجموعة"
        cancelLabel="إغلاق"
        tone="brand"
        icon={CheckCircle2}
      >
        {closedSummary && (
          <div className="grid grid-cols-3 gap-3 text-center">
            <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-950/40">
              <div className="text-lg font-black text-slate-900 dark:text-white">{closedSummary.total}</div>
              <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400">إجمالي</div>
            </div>
            <div className="p-3 rounded-xl bg-emerald-50 dark:bg-emerald-500/10">
              <div className="text-lg font-black text-emerald-700 dark:text-emerald-400">{closedSummary.present}</div>
              <div className="text-[11px] font-bold text-emerald-600 dark:text-emerald-400">حاضر</div>
            </div>
            <div className="p-3 rounded-xl bg-rose-50 dark:bg-rose-500/10">
              <div className="text-lg font-black text-rose-700 dark:text-rose-400">{closedSummary.absent}</div>
              <div className="text-[11px] font-bold text-rose-600 dark:text-rose-400">غائب</div>
            </div>
          </div>
        )}
      </ConfirmDialog>

      <ConfirmDialog
        open={showDeleteSessionConfirm}
        onClose={() => setShowDeleteSessionConfirm(false)}
        onConfirm={handleDeleteSession}
        title="حذف الحصة بالكامل"
        message="سيتم حذف هذه الحصة وكل سجلات الحضور والدرجات المرتبطة بها نهائياً. هذا الإجراء لا يمكن التراجع عنه."
        confirmLabel="حذف الحصة نهائياً"
        tone="danger"
        icon={AlertTriangle}
        isLoading={deletingSession}
      />
    </div>
  );
}
