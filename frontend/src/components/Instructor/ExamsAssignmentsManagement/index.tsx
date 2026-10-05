import React, { useState, useEffect, useMemo } from 'react';
import { motion, AnimatePresence } from 'motion/react';
// `ChevronDown` is gone: the shared `Select` draws its own chevron, so the
// hand-positioned absolute one is no longer needed.
import { Plus, Edit, Trash2, Calendar, Clock, AlertTriangle, Sparkles, Info, HelpCircle, FileText, Lock, Unlock, Eye, EyeOff, ShieldCheck, Trophy, Layers, Shuffle, RotateCcw, ClipboardList, ArrowRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { ExamAssignment, ExamsAssignmentsManagementProps, Group, QuestionGroup } from '../../../types/index';
import { groupApiService } from '../../../services/groupApiService';
import { examAssignmentApiService, toExamAssignment } from '../../../services/examAssignmentApiService';
import { logger } from '../../../services/logger';
import { useCenteredNotification } from '../../../context/CenteredNotificationContext';
import {
  SectionHeader,
  Card,
  PageHeader,
  Button,
  Input,
  Select,
  Textarea,
  FormField,
  Skeleton,
  EmptyState,
  cn,
} from '../../UI';

type ExamAction = 'add' | 'edit' | 'delete';

interface ActionDef {
  id: ExamAction;
  icon: LucideIcon;
  title: string;
  description: string;
  tone: 'rose' | 'amber' | 'red';
}

const TONE_STYLES: Record<ActionDef['tone'], { chip: string; hoverBorder: string }> = {
  rose: {
    chip: 'bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400',
    hoverBorder: 'hover:border-rose-300 dark:hover:border-rose-500/40',
  },
  amber: {
    chip: 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
    hoverBorder: 'hover:border-amber-300 dark:hover:border-amber-500/40',
  },
  red: {
    chip: 'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400',
    hoverBorder: 'hover:border-red-300 dark:hover:border-red-500/40',
  },
};

const ACTIONS: ActionDef[] = [
  { id: 'add', icon: Plus, title: 'إضافة امتحان', description: 'إنشاء امتحان جديد وربطه بمجموعة الأسئلة', tone: 'rose' },
  { id: 'edit', icon: Edit, title: 'تعديل امتحان', description: 'تعديل بيانات امتحان أو تعيين قائم', tone: 'amber' },
  { id: 'delete', icon: Trash2, title: 'حذف امتحان', description: 'حذف امتحان نهائياً من المنصة', tone: 'red' },
];

/**
 * The landing picker: one clearly-labelled tile per operation instead of a
 * tab bar, so the page opens on a single, uncluttered decision rather than
 * three simultaneous forms/lists. Centred icon-over-label layout (no footer
 * meta/arrow row) — a plain, instantly-scannable menu of the 3 operations.
 */
function ActionCard({ action, onSelect, index }: { action: ActionDef; onSelect: (id: ExamAction) => void; index: number }) {
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
        <p className="text-base font-medium text-slate-500 dark:text-slate-400">{action.description}</p>
      </div>
    </motion.button>
  );
}

/** Back-to-picker bar shown at the top of every action panel. */
function PanelBackBar({ onBack, icon: Icon, title, description, tone = 'rose' }: {
  onBack: () => void;
  icon: LucideIcon;
  title: React.ReactNode;
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
          <h2 className="text-lg sm:text-xl font-black text-slate-900 dark:text-white leading-tight">{title}</h2>
          <p className="mt-0.5 text-sm sm:text-base font-medium text-slate-500 dark:text-slate-400">{description}</p>
        </div>
      </div>
      <Button type="button" variant="ghost" size="sm" icon={ArrowRight} onClick={onBack}>
        العودة لقائمة العمليات
      </Button>
    </div>
  );
}

/**
 * One of the three on/off feature cards (lock content / show answers /
 * randomize). Kept as a bespoke switch rather than the shared `Checkbox`:
 * these are settings toggles with an explanatory body, not form checkboxes.
 * The block appeared six times verbatim (three in Add, three in Edit) — this
 * only removes that duplication; each caller still passes its own state setter.
 */
function FeatureToggleCard({
  icon: Icon,
  title,
  description,
  checked,
  onToggle,
}: {
  icon: LucideIcon;
  title: string;
  description: string;
  checked: boolean;
  onToggle: () => void;
}) {
  return (
    <Card flat className="bg-slate-50 dark:bg-slate-950/40 flex items-start gap-4">
      <div className="p-3 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 shrink-0">
        <Icon className="w-5 h-5" />
      </div>
      <div className="space-y-2 flex-1 min-w-0">
        <div className="flex items-center justify-between gap-3">
          <span className="text-sm sm:text-base font-black text-slate-800 dark:text-slate-200">{title}</span>
          <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={title}
            onClick={onToggle}
            className={cn(
              'w-11 h-6 rounded-full transition-colors cursor-pointer relative shrink-0',
              'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
              checked ? 'bg-blue-600' : 'bg-slate-300 dark:bg-slate-700',
            )}
          >
            <span
              className={cn(
                'w-4 h-4 rounded-full bg-white absolute top-1 transition-all',
                checked ? 'right-6' : 'right-1',
              )}
            />
          </button>
        </div>
        <p className="text-[11px] sm:text-sm text-slate-500 dark:text-slate-400 leading-relaxed font-medium">
          {description}
        </p>
      </div>
    </Card>
  );
}

export default function ExamsAssignmentsManagement({ courses = [] }: ExamsAssignmentsManagementProps) {
  const [exams, setExams] = useState<ExamAssignment[]>([]);
  const [groups, setGroups] = useState<Group[]>([]);
  const [questionGroups, setQuestionGroups] = useState<QuestionGroup[]>([]);
  const [addQuestionsCount, setAddQuestionsCount] = useState(0);
  const [editQuestionsCount, setEditQuestionsCount] = useState(0);
  const [loading, setLoading] = useState(true);
  const [isAdding, setIsAdding] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  useEffect(() => {
    let isMounted = true;
    const loadData = async () => {
      try {
        setLoading(true);

        // 1. Fetch groups from the API (student groups per course)
        let fetchedGroups: Group[] = [];
        try {
          const apiGroups = await groupApiService.getInstructorGroups();
          if (apiGroups && apiGroups.length > 0) {
            fetchedGroups = apiGroups.map((g: any) => ({
              id: String(g.id),
              courseId: String(g.courseId || g.course_id || ''),
              grade: g.grade || '',
              // The raw API object's field is `title` (see GroupController),
              // not `name` — `g.name` is always undefined here, which made
              // every group in this page's dropdown render as a blank label.
              name: g.name || g.title || '',
              description: g.description || ''
            }));
          }
        } catch (err) {
          logger.error('Failed to fetch instructor groups from API', err as Error);
          if (isMounted) {
            triggerFeedback('حدث خطأ أثناء تحميل مجموعات الطلاب، يرجى إعادة تحميل الصفحة', 'error');
          }
        }

        // 2. Fetch exams (content-items of type exam) for every group
        let mergedExams: ExamAssignment[] = [];
        try {
          const itemsByGroup = await Promise.all(
            fetchedGroups.map(g => examAssignmentApiService.listByGroup(g.id).catch(() => []))
          );
          mergedExams = itemsByGroup
            .flat()
            .filter((item: any) => item && item.type === 'exam')
            .map(toExamAssignment);
        } catch (err) {
          logger.error('Failed to fetch exams from API', err as Error);
          if (isMounted) {
            triggerFeedback('حدث خطأ أثناء تحميل الامتحانات، يرجى إعادة تحميل الصفحة', 'error');
          }
        }

        // 3. Fetch question groups
        let fetchedQuestionGroups: QuestionGroup[] = [];
        try {
          fetchedQuestionGroups = await examAssignmentApiService.getQuestionGroups();
        } catch (err) {
          logger.error('Failed to fetch question groups from API', err as Error);
          if (isMounted) {
            triggerFeedback('حدث خطأ أثناء تحميل مجموعات الأسئلة، يرجى إعادة تحميل الصفحة', 'error');
          }
        }

        if (isMounted) {
          setExams(mergedExams);
          setGroups(fetchedGroups);
          setQuestionGroups(fetchedQuestionGroups);
        }
      } catch (err) {
        logger.error('Failed to load exams data', err as Error);
        if (isMounted) {
          triggerFeedback('حدث خطأ أثناء تحميل بيانات الامتحانات، يرجى إعادة تحميل الصفحة', 'error');
        }
      } finally {
        if (isMounted) {
          setLoading(false);
        }
      }
    };

    loadData();

    return () => {
      isMounted = false;
    };
  }, [courses]);

  // NOTE: this was `useState<'add' | 'edit' | 'delete'>('add')` driving a
  // `Tabs` pill bar. It is now the landing-picker's selected-operation state
  // (`null` = no operation chosen, i.e. the picker grid is shown) — the same
  // role `courseActionTab` plays in CourseManagement.tsx. No business logic
  // reads or depends on the specific default value 'add' vs null; every
  // handler below keys off `selectedExamId` / the individual add-/edit-/
  // delete-form state, not off this navigation flag.
  const [activeTab, setActiveTab] = useState<ExamAction | null>(null);
  const { notify } = useCenteredNotification();
  const triggerFeedback = (message: string, type: 'success' | 'error') => {
    notify(message, type);
  };

  const coursesForGrade = courses;

  // Add Form states
  const [addCourseId, setAddCourseId] = useState('');
  const [addGroupId, setAddGroupId] = useState('');
  const [addQuestionGroupId, setAddQuestionGroupId] = useState('');
  const [addTitle, setAddTitle] = useState('');
  const [addDescription, setAddDescription] = useState('');
  const [addDuration, setAddDuration] = useState<number>(45);
  const [addTotalScore, setAddTotalScore] = useState<number>(100);
  const [addPassingScore, setAddPassingScore] = useState<string>('');
  const [addStartDate, setAddStartDate] = useState('');
  const [addStartTime, setAddStartTime] = useState('');
  const [addEndDate, setAddEndDate] = useState('');
  const [addEndTime, setAddEndTime] = useState('');
  const [addAllowedAttempts, setAddAllowedAttempts] = useState<string>('');
  const [addLockContent, setAddLockContent] = useState(false);
  const [addShowAnswers, setAddShowAnswers] = useState(true);
  const [addRandomizeQuestions, setAddRandomizeQuestions] = useState(false);

  // Groups cascading filter for Add Form
  const groupsForAddCourse = useMemo(() => {
    if (!addCourseId) return [];
    return groups.filter(g => String(g.courseId) === String(addCourseId));
  }, [groups, addCourseId]);

  // Question groups cascading filter for Add Form.
  // NOTE: the backend question-bank does not tie question groups to a grade
  // (question_groups only has id/title/teacher_id), so all of the
  // instructor's question groups are shown regardless of the selected course.
  const questionGroupsForAddGrade = questionGroups;

  // Fetch real question count whenever the selected question group changes.
  useEffect(() => {
    let cancelled = false;
    if (!addQuestionGroupId) {
      setAddQuestionsCount(0);
      return;
    }
    examAssignmentApiService.getQuestionsForGroup(addQuestionGroupId).then(list => {
      if (!cancelled) setAddQuestionsCount(list.length);
    }).catch(() => {
      if (!cancelled) setAddQuestionsCount(0);
    });
    return () => { cancelled = true; };
  }, [addQuestionGroupId]);

  const activeQuestionsInSelectedGroup = addQuestionsCount;

  const handleAddSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!addCourseId) {
      triggerFeedback('يرجى تحديد الكورس أولاً', 'error');
      return;
    }
    if (!addGroupId) {
      triggerFeedback('يرجى تحديد المجموعة أولاً', 'error');
      return;
    }
    if (!addQuestionGroupId) {
      triggerFeedback('يرجى تحديد مجموعة أسئلة أولاً', 'error');
      return;
    }
    if (!addTitle.trim()) {
      triggerFeedback('يرجى إدخال عنوان الامتحان', 'error');
      return;
    }

    const parsedPassing = addPassingScore ? parseFloat(addPassingScore) : undefined;
    if (parsedPassing !== undefined && parsedPassing > addTotalScore) {
      triggerFeedback('درجة النجاح لا يمكن أن تكون أكبر من الدرجة الكلية', 'error');
      return;
    }

    setIsAdding(true);

    const run = async () => {
      try {
        const created = await examAssignmentApiService.createExam(addGroupId, {
          title: addTitle.trim(),
          description: addDescription.trim(),
          questionGroupId: addQuestionGroupId,
          duration: addDuration,
          totalScore: addTotalScore,
          passingScore: parsedPassing,
          allowedAttempts: addAllowedAttempts ? parseInt(addAllowedAttempts) : undefined,
          lockContent: addLockContent,
          showAnswers: addShowAnswers,
          randomizeQuestions: addRandomizeQuestions,
          startDate: addStartDate || undefined,
          startTime: addStartTime || undefined,
          endDate: addEndDate || undefined,
          endTime: addEndTime || undefined,
        });

        setExams(prev => [created, ...prev]);

        triggerFeedback(`تم إضافة الامتحان "${addTitle.trim()}" بنجاح!`, 'success');

        // Reset Form
        setAddTitle('');
        setAddDescription('');
        setAddDuration(45);
        setAddTotalScore(100);
        setAddPassingScore('');
        setAddStartDate('');
        setAddStartTime('');
        setAddEndDate('');
        setAddEndTime('');
        setAddAllowedAttempts('');
        setAddLockContent(false);
        setAddShowAnswers(true);
        setAddRandomizeQuestions(false);
      } catch (err: any) {
        const status = err?.response?.status;
        const message = err?.response?.data?.message
          || (status === 403 ? 'ليس لديك صلاحية لإضافة محتوى لهذه المجموعة' : 'حدث خطأ أثناء الحفظ، يرجى المحاولة مرة أخرى');
        triggerFeedback(message, 'error');
      } finally {
        setIsAdding(false);
      }
    };

    run();
  };

  // Edit / Delete states
  const [selectedExamId, setSelectedExamId] = useState('');
  // The Edit panel's own selection cascade — course, then group, then the
  // exam living inside that group — kept SEPARATE from `editCourseId`/
  // `editGroupId` below, which are the read-only fields the edit FORM shows
  // once an exam is picked (derived from the matched exam, not user input).
  const [editPickerCourseId, setEditPickerCourseId] = useState('');
  const [editPickerGroupId, setEditPickerGroupId] = useState('');
  const [editCourseId, setEditCourseId] = useState('');
  const [editGroupId, setEditGroupId] = useState('');
  const [editQuestionGroupId, setEditQuestionGroupId] = useState('');
  const [editTitle, setEditTitle] = useState('');
  const [editDescription, setEditDescription] = useState('');
  const [editDuration, setEditDuration] = useState<number>(45);
  const [editTotalScore, setEditTotalScore] = useState<number>(100);
  const [editPassingScore, setEditPassingScore] = useState<string>('');
  const [editStartDate, setEditStartDate] = useState('');
  const [editStartTime, setEditStartTime] = useState('');
  const [editEndDate, setEditEndDate] = useState('');
  const [editEndTime, setEditEndTime] = useState('');
  const [editAllowedAttempts, setEditAllowedAttempts] = useState<string>('');
  const [editLockContent, setEditLockContent] = useState(false);
  const [editShowAnswers, setEditShowAnswers] = useState(true);
  const [editRandomizeQuestions, setEditRandomizeQuestions] = useState(false);

  const [showConfirmDeleteAlert, setShowConfirmDeleteAlert] = useState(false);

  const examsForGrade = exams;

  // Edit panel's own cascade: groups that belong to the picked course, then
  // exams that belong to the picked group. Mirrors the Add form's
  // `groupsForAddCourse` pattern exactly, just scoped to the Edit picker's
  // own state instead of the Add form's.
  const groupsForEditPicker = useMemo(() => {
    if (!editPickerCourseId) return [];
    return groups.filter(g => String(g.courseId) === String(editPickerCourseId));
  }, [groups, editPickerCourseId]);

  const examsForEditPickerGroup = useMemo(() => {
    if (!editPickerGroupId) return [];
    return exams.filter(ex => String(ex.groupId) === String(editPickerGroupId));
  }, [exams, editPickerGroupId]);

  // Question groups for Edit Form.
  // NOTE: same as Add Form — the backend question-bank has no grade linkage,
  // so all instructor question groups are shown.
  const questionGroupsForEditGrade = questionGroups;

  // Synchronize edit target
  useEffect(() => {
    if (!examsForGrade.find(ex => ex.id === selectedExamId)) {
      setSelectedExamId('');
    }
  }, [examsForGrade, selectedExamId]);

  useEffect(() => {
    if (selectedExamId) {
      const matched = exams.find(ex => ex.id === selectedExamId);
      if (matched) {
        setEditCourseId(matched.courseId || matched.course_id || '');
        setEditGroupId(matched.groupId || '');
        setEditQuestionGroupId(matched.questionGroupId || '');
        setEditTitle(matched.title);
        setEditDescription(matched.description || '');
        setEditDuration(matched.duration || matched.time_limit || 0);
        setEditTotalScore(matched.totalScore || 0);
        setEditPassingScore(matched.passingScore ? matched.passingScore.toString() : '');
        setEditStartDate(matched.startDate || '');
        setEditStartTime(matched.startTime || '');
        setEditEndDate(matched.endDate || '');
        setEditEndTime(matched.endTime || '');
        setEditAllowedAttempts(matched.allowedAttempts ? matched.allowedAttempts.toString() : '');
        setEditLockContent(matched.lockContent || false);
        setEditShowAnswers(matched.showAnswers || false);
        setEditRandomizeQuestions(matched.randomizeQuestions ?? false);
      }
    } else {
      setEditCourseId('');
      setEditGroupId('');
      setEditQuestionGroupId('');
      setEditTitle('');
      setEditDescription('');
      setEditDuration(45);
      setEditTotalScore(100);
      setEditPassingScore('');
      setEditStartDate('');
      setEditStartTime('');
      setEditEndDate('');
      setEditEndTime('');
      setEditAllowedAttempts('');
      setEditLockContent(false);
      setEditShowAnswers(true);
      setEditRandomizeQuestions(false);
    }
  }, [selectedExamId, exams]);

  // Fetch real question count whenever the selected question group changes.
  useEffect(() => {
    let cancelled = false;
    if (!editQuestionGroupId) {
      setEditQuestionsCount(0);
      return;
    }
    examAssignmentApiService.getQuestionsForGroup(editQuestionGroupId).then(list => {
      if (!cancelled) setEditQuestionsCount(list.length);
    }).catch(() => {
      if (!cancelled) setEditQuestionsCount(0);
    });
    return () => { cancelled = true; };
  }, [editQuestionGroupId]);

  const handleEditSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedExamId) return;

    if (!editCourseId) {
      triggerFeedback('يرجى تحديد الكورس أولاً', 'error');
      return;
    }
    if (!editGroupId) {
      triggerFeedback('يرجى تحديد المجموعة أولاً', 'error');
      return;
    }
    if (!editQuestionGroupId) {
      triggerFeedback('يرجى تحديد مجموعة أسئلة أولاً', 'error');
      return;
    }
    if (!editTitle.trim()) {
      triggerFeedback('يرجى إدخال عنوان الامتحان', 'error');
      return;
    }

    const parsedPassing = editPassingScore ? parseFloat(editPassingScore) : undefined;
    if (parsedPassing !== undefined && parsedPassing > editTotalScore) {
      triggerFeedback('درجة النجاح لا يمكن أن تكون أكبر من الدرجة الكلية', 'error');
      return;
    }

    setIsEditing(true);

    const run = async () => {
      try {
        const updated = await examAssignmentApiService.updateExam(selectedExamId, {
          title: editTitle.trim(),
          description: editDescription.trim(),
          questionGroupId: editQuestionGroupId,
          duration: editDuration,
          totalScore: editTotalScore,
          passingScore: parsedPassing,
          allowedAttempts: editAllowedAttempts ? parseInt(editAllowedAttempts) : undefined,
          lockContent: editLockContent,
          showAnswers: editShowAnswers,
          randomizeQuestions: editRandomizeQuestions,
          startDate: editStartDate || undefined,
          startTime: editStartTime || undefined,
          endDate: editEndDate || undefined,
          endTime: editEndTime || undefined,
        });

        setExams(prev => prev.map(ex => ex.id === selectedExamId ? updated : ex));

        triggerFeedback('تم تحديث الامتحان بنجاح!', 'success');
      } catch (err: any) {
        const status = err?.response?.status;
        const message = err?.response?.data?.message
          || (status === 403 ? 'ليس لديك صلاحية لتعديل هذا العنصر' : 'حدث خطأ أثناء حفظ التعديلات، يرجى المحاولة مرة أخرى');
        triggerFeedback(message, 'error');
      } finally {
        setIsEditing(false);
      }
    };

    run();
  };

  const executeExamDeletion = () => {
    if (!selectedExamId) return;
    setIsDeleting(true);
    const target = exams.find(ex => ex.id === selectedExamId);
    examAssignmentApiService.deleteItem(selectedExamId).then(() => {
      setExams(prev => prev.filter(ex => ex.id !== selectedExamId));
      setSelectedExamId('');
      setShowConfirmDeleteAlert(false);
      triggerFeedback(`تم حذف الامتحان "${target?.title || ''}" بنجاح!`, 'success');
    }).catch((err: any) => {
      const status = err?.response?.status;
      const message = err?.response?.data?.message
        || (status === 403 ? 'ليس لديك صلاحية لحذف هذا العنصر' : 'حدث خطأ أثناء الحذف، يرجى المحاولة مرة أخرى');
      triggerFeedback(message, 'error');
    }).finally(() => {
      setIsDeleting(false);
    });
  };

  if (loading) {
    /*
      Same `loading` gate and same early return — only the placeholder changed
      from a bare spinner to the design system's skeleton, so the page keeps its
      shape while data lands.
    */
    return (
      <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
        <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
        <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
        <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

        <Skeleton variant="block" className="h-24" />

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          {Array.from({ length: 3 }).map((_, i) => (
            <Card key={i} className="space-y-4">
              <Skeleton variant="block" className="w-16 h-16 rounded-2xl" />
              <Skeleton variant="text" width="70%" />
              <Skeleton variant="text" width="90%" />
            </Card>
          ))}
        </div>

        <Card radius="panel" className="space-y-5">
          <Skeleton variant="text" width="40%" />
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Skeleton variant="block" className="h-24" />
            <Skeleton variant="block" className="h-24" />
            <Skeleton variant="block" className="h-24" />
          </div>
          <Skeleton variant="block" className="h-32" />
        </Card>
      </div>
    );
  }

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />


      <PageHeader
        icon={ClipboardList}
        title="إضافة وتعديل الامتحانات"
        description="إدارة امتحاناتك وتكليفاتك الدراسية"
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إضافة وتعديل الامتحانات' }]}
      />

      <AnimatePresence mode="wait">
        {activeTab === null ? (
          /* LANDING PICKER — one decision at a time, nothing crowded */
          <motion.div
            key="action_picker"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="grid grid-cols-1 sm:grid-cols-3 gap-4"
          >
            {ACTIONS.map((action, i) => (
              <ActionCard
                key={action.id}
                action={action}
                index={i}
                onSelect={(id) => { setActiveTab(id); setShowConfirmDeleteAlert(false); }}
              />
            ))}
          </motion.div>
        ) : (
          <div key="action_panel">
            <AnimatePresence mode="wait">

              {/* PANEL 1: ADD EXAM */}
              {activeTab === 'add' && (
                <motion.div
                  key="add_section"
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -10 }}
                  transition={{ duration: 0.2 }}
                  className="space-y-6"
                >
                  <PanelBackBar
                    onBack={() => { setActiveTab(null); setShowConfirmDeleteAlert(false); }}
                    icon={Sparkles}
                    title="إنشاء امتحان جديد"
                    description="قم بتعبئة حقول الامتحان، وربطه بمجموعة الأسئلة لتحقيق التوزيع التلقائي للدرجات."
                    tone="rose"
                  />

                  <form onSubmit={handleAddSubmit} className="space-y-6">
                    <div className="space-y-6">
                        {/* CASCADING FIELDS CARD */}
                        {/*
                          Every `value`/`onChange`/`disabled` expression below is
                          carried over verbatim — including the cascade resets
                          (course change clears group + question group). Only the
                          bespoke `<select>` skin and the hand-placed absolute
                          `ChevronDown` are replaced by the shared `Select`, which
                          draws its own chevron.
                        */}
                        <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0 * 0.08, duration: 0.35 }}>
                        <Card flat className="bg-slate-50/50 dark:bg-slate-950/40 space-y-4">
                          <h4 className="text-sm font-black text-blue-600 dark:text-blue-400 flex items-center gap-1.5">
                            <Layers className="w-4 h-4" />
                            الربط الهيكلي والتعليمي
                          </h4>

                          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            {/* Course */}
                            <FormField label="1. اختر الكورس">
                              {({ id }) => (
                                <Select
                                  id={id}
                                  value={addCourseId}
                                  onChange={(e) => {
                                    setAddCourseId(e.target.value);
                                    setAddGroupId('');
                                    setAddQuestionGroupId('');
                                  }}
                                  disabled={coursesForGrade.length === 0}
                                >
                                  <option value="">-- اختر الكورس --</option>
                                  {coursesForGrade.map((c: any) => <option key={c.id} value={c.id}>{c.title}</option>)}
                                </Select>
                              )}
                            </FormField>

                            {/* Group */}
                            <FormField label="2. اختر المجموعة">
                              {({ id }) => (
                                <Select
                                  id={id}
                                  value={addGroupId}
                                  onChange={(e) => {
                                    setAddGroupId(e.target.value);
                                    setAddQuestionGroupId('');
                                  }}
                                  disabled={!addCourseId || groupsForAddCourse.length === 0}
                                >
                                  {!addCourseId ? (
                                    <option value="">يرجى تحديد الكورس أولاً</option>
                                  ) : groupsForAddCourse.length === 0 ? (
                                    <option value="">لا توجد مجموعات طلابية لهذا الكورس</option>
                                  ) : (
                                    <>
                                      <option value="">-- اختر المجموعة --</option>
                                      {groupsForAddCourse.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                                    </>
                                  )}
                                </Select>
                              )}
                            </FormField>

                            {/* Question Group */}
                            <div className="space-y-1.5">
                              <FormField label="3. مجموعة الأسئلة المرتبطة">
                                {({ id }) => (
                                  <Select
                                    id={id}
                                    value={addQuestionGroupId}
                                    onChange={(e) => setAddQuestionGroupId(e.target.value)}
                                    disabled={!addGroupId || questionGroupsForAddGrade.length === 0}
                                  >
                                    {!addGroupId ? (
                                      <option value="">يرجى تحديد مجموعة طلاب أولاً</option>
                                    ) : questionGroupsForAddGrade.length === 0 ? (
                                      <option value="">لا توجد مجموعات أسئلة متاحة</option>
                                    ) : (
                                      <>
                                        <option value="">-- اختر مجموعة الأسئلة --</option>
                                        {questionGroupsForAddGrade.map(qg => <option key={qg.id} value={qg.id}>{qg.name}</option>)}
                                      </>
                                    )}
                                  </Select>
                                )}
                              </FormField>
                              {addQuestionGroupId && (
                                <motion.div
                                  initial={{ opacity: 0, y: -5 }}
                                  animate={{ opacity: 1, y: 0 }}
                                  className="mt-2 p-2 rounded-xl bg-blue-50/60 dark:bg-blue-950/20 border border-blue-100/50 dark:border-blue-900/30 flex items-center gap-2 text-xs font-bold text-blue-700 dark:text-blue-400"
                                >
                                  <FileText className="w-3.5 h-3.5 text-blue-500" />
                                  <span>عدد الأسئلة في المجموعة المحددة: {activeQuestionsInSelectedGroup} سؤال</span>
                                </motion.div>
                              )}
                            </div>
                          </div>
                        </Card>
                        </motion.div>

                        {/* The rest of the form only appears once all three
                            cascading steps (course, group, question group)
                            are chosen. */}
                        {!addQuestionGroupId ? (
                          <EmptyState
                            icon={ClipboardList}
                            title="أكمل الربط الهيكلي أولاً"
                            description="اختر الكورس ثم المجموعة ثم مجموعة الأسئلة أعلاه لإظهار بيانات الامتحان."
                          />
                        ) : (
                        <>
                        {/* INFO FIELDS */}
                        <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 1 * 0.08, duration: 0.35 }} className="space-y-6">
                        <FormField
                          required
                          label={
                            <span className="flex items-center gap-1.5">
                              <FileText className="w-4 h-4 text-slate-400 shrink-0" />
                              عنوان الامتحان الرسمي
                            </span>
                          }
                        >
                          {({ id }) => (
                            <Input
                              id={id}
                              size="lg"
                              type="text"
                              value={addTitle}
                              onChange={(e) => setAddTitle(e.target.value)}
                              placeholder="مثال: الاختبار الدوري الأول للفيزياء"
                              className="font-bold"
                              required
                            />
                          )}
                        </FormField>

                        {/* DESCRIPTION */}
                        <FormField label="الوصف أو التعليمات للطلاب">
                          {({ id }) => (
                            <Textarea
                              id={id}
                              value={addDescription}
                              onChange={(e) => setAddDescription(e.target.value)}
                              placeholder="اكتب تعليمات الاختبار، شروط القبول، أو تمنياتك بالتوفيق..."
                              rows={2}
                              className="resize-none"
                            />
                          )}
                        </FormField>
                        </motion.div>

                        {/* GRADING & TIMERS CARD */}
                        <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 2 * 0.08, duration: 0.35 }} className="space-y-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                          <FormField
                            required
                            label={
                              <span className="flex items-center gap-1.5">
                                <Clock className="w-4 h-4 text-slate-400 shrink-0" />
                                المدة الزمنية (بالدقائق)
                              </span>
                            }
                          >
                            {({ id }) => (
                              <Input
                                id={id}
                                size="lg"
                                type="number"
                                value={addDuration}
                                onChange={(e) => setAddDuration(Math.max(1, parseInt(e.target.value) || 0))}
                                required
                              />
                            )}
                          </FormField>

                          {/* Total Score */}
                          <FormField
                            required
                            label={
                              <span className="flex items-center gap-1.5">
                                <Trophy className="w-4 h-4 text-amber-500 shrink-0" />
                                الدرجة الكلية الكبرى
                              </span>
                            }
                          >
                            {({ id }) => (
                              <Input
                                id={id}
                                size="lg"
                                type="number"
                                value={addTotalScore}
                                onChange={(e) => setAddTotalScore(Math.max(1, parseInt(e.target.value) || 0))}
                                required
                              />
                            )}
                          </FormField>

                          <FormField
                            label={
                              <span className="flex items-center gap-1.5">
                                <ShieldCheck className="w-4 h-4 text-emerald-500 shrink-0" />
                                حد النجاح الأدنى (اختياري)
                              </span>
                            }
                          >
                            {({ id }) => (
                              <Input
                                id={id}
                                size="lg"
                                type="number"
                                value={addPassingScore}
                                onChange={(e) => setAddPassingScore(e.target.value)}
                                placeholder="مثال: 50"
                              />
                            )}
                          </FormField>

                          <FormField
                            label={
                              <span className="flex items-center gap-1.5">
                                <RotateCcw className="w-4 h-4 text-blue-500 shrink-0" />
                                عدد مرات فتح الاختبار (اختياري)
                              </span>
                            }
                            helperText="اذا لم يتم التحديد يمكن للطالب فتحه دائماً."
                          >
                            {({ id }) => (
                              <Input
                                id={id}
                                size="lg"
                                type="number"
                                value={addAllowedAttempts}
                                onChange={(e) => setAddAllowedAttempts(e.target.value)}
                                placeholder="مثال: 2"
                                min="1"
                              />
                            )}
                          </FormField>

                        </div>

                        {/* SCORE AUTOMATIC DISTRIBUTION ALERTER */}
                        {/* Same ternary, same computed per-question score. */}
                        {activeQuestionsInSelectedGroup > 0 ? (
                          <div className="p-3.5 rounded-card bg-blue-500/10 text-blue-800 dark:text-blue-300 border border-blue-200/60 dark:border-blue-500/20 text-xs font-bold leading-relaxed flex items-center gap-2">
                            <Info className="w-4 h-4 shrink-0" />
                            <span>
                              مجموعة الأسئلة المحددة تحتوي على ({activeQuestionsInSelectedGroup}) سؤال. سيتم تلقائياً توزيع الدرجة الكلية لتصبح ({Math.round((addTotalScore / activeQuestionsInSelectedGroup) * 100) / 100}) لكل سؤال.
                            </span>
                          </div>
                        ) : (
                          <div className="p-3.5 rounded-card bg-amber-500/10 text-amber-800 dark:text-amber-400 border border-amber-200/60 dark:border-amber-500/20 text-xs font-bold leading-relaxed flex items-center gap-2">
                            <AlertTriangle className="w-4 h-4 shrink-0" />
                            <span>
                              مجموعة الأسئلة المحددة لا تحتوي على أي أسئلة حالياً. يرجى التوجه لبنك الأسئلة وإضافة أسئلة لضمان تفعيل الاختبار للطلاب.
                            </span>
                          </div>
                        )}
                        </motion.div>

                        {/* SCHEDULING CONTROLS */}
                        <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 3 * 0.08, duration: 0.35 }}>
                        <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-6">
                          <div className="flex flex-col md:flex-row gap-6">
                            <div className="flex-1 space-y-3">
                              <h4 className="flex items-center gap-1.5 text-sm sm:text-base font-black text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-3.5 py-1.5 rounded-xl w-fit">
                                <Calendar className="w-4 h-4" />
                                تاريخ ووقت بدء الإتاحة (اختياري)
                              </h4>
                              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <FormField label="تاريخ الإتاحة">
                                  {({ id }) => (
                                    <Input id={id} type="date" value={addStartDate} onChange={(e) => setAddStartDate(e.target.value)} />
                                  )}
                                </FormField>
                                <FormField label="وقت الإتاحة">
                                  {({ id }) => (
                                    <Input id={id} type="time" value={addStartTime} onChange={(e) => setAddStartTime(e.target.value)} />
                                  )}
                                </FormField>
                              </div>
                            </div>

                            <div className="flex-1 space-y-3">
                              <h4 className="flex items-center gap-1.5 text-sm sm:text-base font-black text-rose-600 dark:text-rose-400 bg-rose-500/10 px-3.5 py-1.5 rounded-xl w-fit">
                                <Clock className="w-4 h-4" />
                                تاريخ ووقت نهاية الإتاحة (اختياري)
                              </h4>
                              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <FormField label="تاريخ الإغلاق والانتهاء">
                                  {({ id }) => (
                                    <Input id={id} type="date" value={addEndDate} onChange={(e) => setAddEndDate(e.target.value)} />
                                  )}
                                </FormField>
                                <FormField label="وقت الإغلاق">
                                  {({ id }) => (
                                    <Input id={id} type="time" value={addEndTime} onChange={(e) => setAddEndTime(e.target.value)} />
                                  )}
                                </FormField>
                              </div>
                            </div>
                          </div>
                        </Card>
                        </motion.div>

                        {/* FEATURES / SETTINGS SWICHES */}
                        {/*
                          The three identical hand-rolled switch cards collapse into
                          the local `FeatureToggleCard`. Each still owns exactly its
                          original state setter, title and body copy; the lock and
                          answers cards keep their state-dependent icon swap.
                        */}
                        <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 4 * 0.08, duration: 0.35 }} className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                          <FeatureToggleCard
                            icon={addLockContent ? Lock : Unlock}
                            title="ميزة قفل المحتوى التعليمي اللاحق"
                            description="في حال التفعيل، لن يستطيع الطلاب تصفح المحاضرات أو الملفات أو الروابط التالية حتى يجتازوا هذا الامتحان بنجاح."
                            checked={addLockContent}
                            onToggle={() => setAddLockContent(!addLockContent)}
                          />
                          <FeatureToggleCard
                            icon={addShowAnswers ? Eye : EyeOff}
                            title="عرض الإجابات بعد تسليم المحاولة"
                            description="في حال التفعيل، سيتمكن الطالب بعد إنهاء الامتحان من مراجعة إجاباته الخاطئة ومطابقتها مع الأجوبة الصحيحة كاملةً."
                            checked={addShowAnswers}
                            onToggle={() => setAddShowAnswers(!addShowAnswers)}
                          />
                          <FeatureToggleCard
                            icon={Shuffle}
                            title="ترتيب عشوائي للأسئلة"
                            description="عند تفعيل هذا الزر يتم تغيير ترتيب الأسئلة لكل طالب ويتم ترتيبها بطريقة عشوائية لكل مرة يفتح فيها الطلاب الاختبار."
                            checked={addRandomizeQuestions}
                            onToggle={() => setAddRandomizeQuestions(!addRandomizeQuestions)}
                          />
                        </motion.div>

                        {/* SUBMIT BUTTON */}
                        <div className="pt-4 flex justify-end">
                          <Button
                            type="submit"
                            size="lg"
                            icon={Plus}
                            disabled={isAdding}
                            isLoading={isAdding}
                            loadingText="جاري الحفظ والتعيين..."
                            className="w-full md:w-auto shadow-lg shadow-rose-500/20 hover:shadow-xl hover:shadow-rose-500/30 transition-shadow"
                          >
                            إرسال وحفظ التعيين الجديد
                          </Button>
                        </div>
                        </>
                        )}
                    </div>
                  </form>
                </motion.div>
              )}

              {/* PANEL 2: EDIT EXAM */}
              {activeTab === 'edit' && (
                <motion.div
                  key="edit_section"
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -10 }}
                  transition={{ duration: 0.2 }}
                  className="space-y-8"
                >
                  <PanelBackBar
                    onBack={() => { setActiveTab(null); setShowConfirmDeleteAlert(false); }}
                    icon={Edit}
                    title="تعديل امتحان"
                    description="اختر الكورس، ثم المجموعة، ثم الامتحان الموجود داخل هذه المجموعة، وبعدها يبدأ التعديل"
                    tone="amber"
                  />

                  {/* TARGET EXAM SELECTION ROW — a real cascade: course, then
                      the groups that belong to it, then the exams that live
                      inside the chosen group. Each step is disabled until the
                      one before it is picked, and picking a new value at any
                      step clears everything that depended on the old one. */}
                  <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0 * 0.08, duration: 0.35 }}>
                  <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-4">
                    <SectionHeader
                      className="mb-0 pb-1 border-b border-slate-200 dark:border-slate-800"
                      icon={HelpCircle}
                      title="مسار تحديد وتعديل الامتحان"
                    />

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                      <FormField label="1. اختر الكورس">
                        {({ id }) => (
                          <Select
                            id={id}
                            value={editPickerCourseId}
                            onChange={(e) => {
                              setEditPickerCourseId(e.target.value);
                              setEditPickerGroupId('');
                              setSelectedExamId('');
                            }}
                            disabled={coursesForGrade.length === 0}
                          >
                            <option value="">-- اختر الكورس --</option>
                            {coursesForGrade.map((c: any) => <option key={c.id} value={c.id}>{c.title}</option>)}
                          </Select>
                        )}
                      </FormField>

                      <FormField label="2. اختر المجموعة">
                        {({ id }) => (
                          <Select
                            id={id}
                            value={editPickerGroupId}
                            onChange={(e) => {
                              setEditPickerGroupId(e.target.value);
                              setSelectedExamId('');
                            }}
                            disabled={!editPickerCourseId || groupsForEditPicker.length === 0}
                          >
                            {!editPickerCourseId ? (
                              <option value="">يرجى تحديد الكورس أولاً</option>
                            ) : groupsForEditPicker.length === 0 ? (
                              <option value="">لا توجد مجموعات طلابية لهذا الكورس</option>
                            ) : (
                              <>
                                <option value="">-- اختر المجموعة --</option>
                                {groupsForEditPicker.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                              </>
                            )}
                          </Select>
                        )}
                      </FormField>

                      <FormField label="3. اختر الامتحان">
                        {({ id }) => (
                          <Select
                            id={id}
                            value={selectedExamId}
                            onChange={(e) => setSelectedExamId(e.target.value)}
                            disabled={!editPickerGroupId || examsForEditPickerGroup.length === 0}
                          >
                            {!editPickerGroupId ? (
                              <option value="">يرجى تحديد المجموعة أولاً</option>
                            ) : examsForEditPickerGroup.length === 0 ? (
                              <option value="">لا توجد امتحانات في هذه المجموعة</option>
                            ) : (
                              <>
                                <option value="">-- اختر الامتحان --</option>
                                {examsForEditPickerGroup.map(ex => <option key={ex.id} value={ex.id}>{ex.title}</option>)}
                              </>
                            )}
                          </Select>
                        )}
                      </FormField>
                    </div>
                  </Card>
                  </motion.div>

                  {selectedExamId && (
                    <form onSubmit={handleEditSubmit} className="space-y-6 pt-4 border-t border-slate-200 dark:border-slate-800">

                      {/* QUESTION GROUP BINDING — the course/group are no
                          longer repeated here read-only: the cascade above
                          already confirmed and displayed both before this
                          form ever appears, so showing them a second time
                          (disabled) would just be duplicate UI. Only the
                          question group stays here, since it is real,
                          editable exam data, not a picker step. */}
                      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 1 * 0.08, duration: 0.35 }}>
                      <Card flat className="bg-slate-50/50 dark:bg-slate-950/40 space-y-4">
                        <h4 className="text-sm font-black text-blue-600 dark:text-blue-400 flex items-center gap-1.5">
                          <Layers className="w-4 h-4" />
                          مجموعة الأسئلة
                        </h4>

                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                          <div className="space-y-1.5">
                            <FormField label="مجموعة الأسئلة المرتبطة">
                              {({ id }) => (
                                <Select
                                  id={id}
                                  value={editQuestionGroupId}
                                  onChange={(e) => setEditQuestionGroupId(e.target.value)}
                                  disabled={questionGroupsForEditGrade.length === 0}
                                >
                                  {questionGroupsForEditGrade.length === 0 ? (
                                    <option value="">لا توجد مجموعات أسئلة متاحة</option>
                                  ) : (
                                    <>
                                      <option value="">-- اختر مجموعة الأسئلة --</option>
                                      {questionGroupsForEditGrade.map(qg => <option key={qg.id} value={qg.id}>{qg.name}</option>)}
                                    </>
                                  )}
                                </Select>
                              )}
                            </FormField>
                            {editQuestionGroupId && (
                              <motion.div
                                initial={{ opacity: 0, y: -5 }}
                                animate={{ opacity: 1, y: 0 }}
                                className="mt-2 p-2 rounded-xl bg-blue-50/60 dark:bg-blue-950/20 border border-blue-100/50 dark:border-blue-900/30 flex items-center gap-2 text-xs font-bold text-blue-700 dark:text-blue-400"
                              >
                                <FileText className="w-3.5 h-3.5 text-blue-500" />
                                <span>عدد الأسئلة في المجموعة المحددة: {editQuestionsCount} سؤال</span>
                              </motion.div>
                            )}
                          </div>
                        </div>
                      </Card>
                      </motion.div>

                      {/* EDIT CORE FORM */}
                      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 2 * 0.08, duration: 0.35 }} className="space-y-6">
                      <FormField required label="عنوان الامتحان الرسمي">
                        {({ id }) => (
                          <Input
                            id={id}
                            size="lg"
                            type="text"
                            value={editTitle}
                            onChange={(e) => setEditTitle(e.target.value)}
                            className="font-bold"
                            required
                          />
                        )}
                      </FormField>

                      <FormField label="الوصف أو التعليمات للطلاب">
                        {({ id }) => (
                          <Textarea
                            id={id}
                            value={editDescription}
                            onChange={(e) => setEditDescription(e.target.value)}
                            rows={2}
                            className="resize-none"
                          />
                        )}
                      </FormField>
                      </motion.div>

                      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 3 * 0.08, duration: 0.35 }} className="space-y-6">
                      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <FormField required label="المدة الزمنية (بالدقائق)">
                          {({ id }) => (
                            <Input
                              id={id}
                              size="lg"
                              type="number"
                              value={editDuration}
                              onChange={(e) => setEditDuration(Math.max(1, parseInt(e.target.value) || 0))}
                              required
                            />
                          )}
                        </FormField>

                        <FormField required label="الدرجة الكلية الكبرى">
                          {({ id }) => (
                            <Input
                              id={id}
                              size="lg"
                              type="number"
                              value={editTotalScore}
                              onChange={(e) => setEditTotalScore(Math.max(1, parseInt(e.target.value) || 0))}
                              required
                            />
                          )}
                        </FormField>

                        <FormField label="حد النجاح الأدنى (اختياري)">
                          {({ id }) => (
                            <Input
                              id={id}
                              size="lg"
                              type="number"
                              value={editPassingScore}
                              onChange={(e) => setEditPassingScore(e.target.value)}
                            />
                          )}
                        </FormField>

                        <FormField
                          label="عدد مرات فتح الاختبار (اختياري)"
                          helperText="اذا لم يتم التحديد يمكن للطالب فتحه دائماً."
                        >
                          {({ id }) => (
                            <Input
                              id={id}
                              size="lg"
                              type="number"
                              value={editAllowedAttempts}
                              onChange={(e) => setEditAllowedAttempts(e.target.value)}
                              placeholder="مثال: 2"
                              min="1"
                            />
                          )}
                        </FormField>

                      </div>

                      {editQuestionsCount > 0 && (
                        <div className="p-3.5 rounded-card bg-blue-500/10 text-blue-800 dark:text-blue-300 border border-blue-200/60 dark:border-blue-500/20 text-xs font-bold leading-relaxed flex items-center gap-2">
                          <Info className="w-4 h-4 shrink-0" />
                          <span>
                            مجموعة الأسئلة المحددة تحتوي على ({editQuestionsCount}) سؤال. سيتم تلقائياً توزيع الدرجة الكلية لتصبح ({Math.round((editTotalScore / editQuestionsCount) * 100) / 100}) لكل سؤال.
                          </span>
                        </div>
                      )}
                      </motion.div>

                      {/* EDIT SCHEDULING CONTROLS */}
                      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 4 * 0.08, duration: 0.35 }}>
                      <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-6">
                        <div className="flex flex-col md:flex-row gap-6">
                          <div className="flex-1 space-y-3">
                            <h4 className="flex items-center gap-1.5 text-sm sm:text-base font-black text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-3.5 py-1.5 rounded-xl w-fit">
                              <Calendar className="w-4 h-4" />
                              تاريخ ووقت بدء الإتاحة (اختياري)
                            </h4>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                              <FormField label="تاريخ الإتاحة">
                                {({ id }) => (
                                  <Input id={id} type="date" value={editStartDate} onChange={(e) => setEditStartDate(e.target.value)} />
                                )}
                              </FormField>
                              <FormField label="وقت الإتاحة">
                                {({ id }) => (
                                  <Input id={id} type="time" value={editStartTime} onChange={(e) => setEditStartTime(e.target.value)} />
                                )}
                              </FormField>
                            </div>
                          </div>

                          <div className="flex-1 space-y-3">
                            <h4 className="flex items-center gap-1.5 text-sm sm:text-base font-black text-rose-600 dark:text-rose-400 bg-rose-500/10 px-3.5 py-1.5 rounded-xl w-fit">
                              <Clock className="w-4 h-4" />
                              تاريخ ووقت نهاية الإتاحة (اختياري)
                            </h4>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                              <FormField label="تاريخ الإغلاق والانتهاء">
                                {({ id }) => (
                                  <Input id={id} type="date" value={editEndDate} onChange={(e) => setEditEndDate(e.target.value)} />
                                )}
                              </FormField>
                              <FormField label="وقت الإغلاق">
                                {({ id }) => (
                                  <Input id={id} type="time" value={editEndTime} onChange={(e) => setEditEndTime(e.target.value)} />
                                )}
                              </FormField>
                            </div>
                          </div>
                        </div>
                      </Card>
                      </motion.div>

                      {/* EDIT SETTINGS SWITCHES — same three toggles as the Add tab, bound to the edit state. */}
                      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 5 * 0.08, duration: 0.35 }} className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <FeatureToggleCard
                          icon={editLockContent ? Lock : Unlock}
                          title="ميزة قفل المحتوى التعليمي اللاحق"
                          description="في حال التفعيل، لن يستطيع الطلاب تصفح المحاضرات أو الملفات أو الروابط التالية حتى يجتازوا هذا الامتحان بنجاح."
                          checked={editLockContent}
                          onToggle={() => setEditLockContent(!editLockContent)}
                        />
                        <FeatureToggleCard
                          icon={editShowAnswers ? Eye : EyeOff}
                          title="عرض الإجابات بعد تسليم المحاولة"
                          description="في حال التفعيل، سيتمكن الطالب بعد إنهاء الامتحان من مراجعة إجاباته الخاطئة ومطابقتها مع الأجوبة الصحيحة كاملةً."
                          checked={editShowAnswers}
                          onToggle={() => setEditShowAnswers(!editShowAnswers)}
                        />
                        <FeatureToggleCard
                          icon={Shuffle}
                          title="ترتيب عشوائي للأسئلة"
                          description="عند تفعيل هذا الزر يتم تغيير ترتيب الأسئلة لكل طالب ويتم ترتيبها بطريقة عشوائية لكل مرة يفتح فيها الطلاب الاختبار."
                          checked={editRandomizeQuestions}
                          onToggle={() => setEditRandomizeQuestions(!editRandomizeQuestions)}
                        />
                      </motion.div>

                      {/*
                        `variant="warning"` replaces the bespoke amber->orange
                        gradient, keeping this project's "edit = amber" convention.
                      */}
                      <div className="pt-4 flex justify-end">
                        <Button
                          type="submit"
                          size="lg"
                          variant="warning"
                          icon={Edit}
                          disabled={isEditing}
                          isLoading={isEditing}
                          loadingText="جاري حفظ التعديلات..."
                          className="w-full md:w-auto shadow-lg shadow-amber-500/20 hover:shadow-xl hover:shadow-amber-500/30 transition-shadow"
                        >
                          حفظ التعديلات المدخلة
                        </Button>
                      </div>
                    </form>
                  )}
                </motion.div>
              )}

              {/* PANEL 3: DELETE EXAM */}
              {activeTab === 'delete' && (
                <motion.div
                  key="delete_section"
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -10 }}
                  transition={{ duration: 0.2 }}
                  className="space-y-8"
                >
                  <PanelBackBar
                    onBack={() => { setActiveTab(null); setShowConfirmDeleteAlert(false); }}
                    icon={Trash2}
                    title={<span className="text-red-600 dark:text-red-400">حذف الامتحانات القائمة</span>}
                    description="اضغط على زر الحذف لإزالة الامتحان نهائياً من على المنصة — هذا الإجراء لا يمكن التراجع عنه"
                    tone="red"
                  />

                  {/* TARGET EXAM SELECTION ROW */}
                  <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0 * 0.08, duration: 0.35 }}>
                  <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-4">
                    <SectionHeader
                      className="mb-0 pb-1 border-b border-slate-200 dark:border-slate-800"
                      icon={HelpCircle}
                      title="مسار تحديد وتعديل التعيين"
                    />

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <FormField label="الاختيار والفلترة">
                        {({ id }) => (
                          <Select
                            id={id}
                            value={selectedExamId}
                            onChange={(e) => setSelectedExamId(e.target.value)}
                            disabled={examsForGrade.length === 0}
                          >
                            {examsForGrade.length === 0 ? <option value="">لا توجد امتحانات في هذا الصف</option> : (
                              <>
                                <option value="">-- اختر الامتحان --</option>
                                {examsForGrade.map(ex => <option key={ex.id} value={ex.id}>{ex.title}</option>)}
                              </>
                            )}
                          </Select>
                        )}
                      </FormField>
                    </div>
                  </Card>
                  </motion.div>

                  {/*
                    Delete stays an INLINE two-step confirm (not `ConfirmDialog`):
                    it is driven by the `delete` panel plus `showConfirmDeleteAlert`,
                    and the back button above resets that flag. Turning it into a
                    modal would change that flow. Only the surfaces and the two
                    buttons are restyled; both handlers are untouched.
                  */}
                  {selectedExamId && (
                    <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 1 * 0.08, duration: 0.35 }} className="space-y-6 pt-6 border-t border-slate-200 dark:border-slate-800">
                      {!showConfirmDeleteAlert ? (
                        <Card className="flex flex-col md:flex-row gap-6 items-center justify-between">
                          <div className="space-y-1.5 flex-1 min-w-0">
                            <h4 className="text-lg font-black text-slate-800 dark:text-slate-100">
                              {exams.find(ex => ex.id === selectedExamId)?.title}
                            </h4>
                            <p className="text-sm text-slate-500 dark:text-slate-400 font-medium">
                              سيتم إزالة الامتحان بشكل كلي، وحذف محاولات وإجابات الطلاب المسجلة عليه ولن يتكمنوا من تقديمه بعد الآن.
                            </p>
                          </div>
                          {/*
                            This is the ARM step, not the destructive act, so it
                            keeps the original's soft rose treatment (`outline` +
                            a rose tint) rather than the solid `danger` fill —
                            that is reserved for the confirm button below.
                          */}
                          <Button
                            variant="outline"
                            icon={Trash2}
                            onClick={() => setShowConfirmDeleteAlert(true)}
                            className="shrink-0 bg-rose-50 text-rose-600 border-rose-200 hover:bg-rose-100 hover:border-rose-300 dark:bg-rose-950/30 dark:text-rose-400 dark:border-rose-900/50 dark:hover:bg-rose-900/40 shadow-lg shadow-rose-500/10 hover:shadow-xl hover:shadow-rose-500/20 transition-shadow"
                          >
                            حذف الامتحان
                          </Button>
                        </Card>
                      ) : (
                        <motion.div
                          initial={{ opacity: 0, y: 8 }}
                          animate={{ opacity: 1, y: 0 }}
                          className="p-6 rounded-panel bg-rose-50 dark:bg-rose-950/20 border-2 border-rose-200 dark:border-rose-900 shadow-e1"
                        >
                          <div className="flex gap-4">
                            <div className="p-3 bg-white dark:bg-rose-900/40 rounded-full shadow-e1 h-fit shrink-0">
                              <AlertTriangle className="w-6 h-6 text-rose-600 dark:text-rose-400" />
                            </div>
                            <div className="space-y-4 min-w-0">
                              <div className="space-y-1">
                                <h4 className="text-lg font-black text-rose-800 dark:text-rose-300">هل أنت متأكد من حذف التعيين نهائياً؟</h4>
                                <p className="text-sm sm:text-base font-bold text-rose-600/80 dark:text-rose-400/80 leading-relaxed max-w-2xl">
                                  حذف الامتحان سيؤدي لإزالته نهائياً وتفقد بيانات مشاركات الطلاب ومحاولاتهم السابقة ولا يمكن استرجاعها.
                                </p>
                              </div>
                              <div className="flex flex-wrap gap-3 pt-2">
                                <Button
                                  variant="danger"
                                  icon={Trash2}
                                  onClick={executeExamDeletion}
                                  disabled={isDeleting}
                                  isLoading={isDeleting}
                                  loadingText="جاري الحذف..."
                                  className="shadow-lg shadow-red-500/20 hover:shadow-xl hover:shadow-red-500/30 transition-shadow"
                                >
                                  نعم، احذف نهائياً
                                </Button>
                                <Button variant="secondary" onClick={() => setShowConfirmDeleteAlert(false)}>
                                  إلغاء وتراجع
                                </Button>
                              </div>
                            </div>
                          </div>
                        </motion.div>
                      )}
                    </motion.div>
                  )}
                </motion.div>
              )}

            </AnimatePresence>
          </div>
        )}
      </AnimatePresence>
    </div>
  );
}
