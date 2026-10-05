import { authService } from '../../services/authService';
import React, { useState, useEffect, useMemo } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import type { LucideIcon } from 'lucide-react';
import {
  Plus, Edit, Trash2, Calendar, Clock,
  AlertTriangle, GraduationCap, BookOpen,
  Info, HelpCircle, FileText, Users, UploadCloud,
  StickyNote, ArrowRight,
} from 'lucide-react';
import { Group, Note, NotesManagementProps } from '../../types/index';
import { groupApiService } from '../../services/groupApiService';
import { groupNoteApiService } from '../../services/groupNoteApiService';
import { uploadApiService } from '../../services/uploadApiService';
import { validateDocumentFile } from '../../utils/fileValidation';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  Card,
  CardHeader,
  PageHeader,
  Button,
  Input,
  Select,
  Textarea,
  FormField,
  Skeleton,
  EmptyState,
  cn,
} from '../UI';

type NotesAction = 'add' | 'edit' | 'delete';

interface ActionDef {
  id: NotesAction;
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
  { id: 'add', icon: Plus, title: 'إضافة', description: 'إضافة مذكرة جديدة', tone: 'rose' },
  { id: 'edit', icon: Edit, title: 'تعديل', description: 'تعديل مذكرة موجودة', tone: 'amber' },
  { id: 'delete', icon: Trash2, title: 'حذف', description: 'حذف مذكرة نهائياً', tone: 'red' },
];

/**
 * The landing picker: one clearly-labelled tile per operation instead of a
 * tab bar, so the page opens on a single, uncluttered decision rather than
 * three simultaneous forms/lists. Centred icon-over-label layout (no footer
 * meta/arrow row) — a plain, instantly-scannable menu of the 3 operations.
 */
function ActionCard({ action, onSelect, index }: { action: ActionDef; onSelect: (id: NotesAction) => void; index: number }) {
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
 * Read-only stand-in shown instead of the grade `<select>` when the instructor
 * is assigned exactly one grade. Deliberately NOT a disabled Select: the
 * original markup rendered a plain div here (no form control, nothing
 * submitted), so turning it into a control would add a field to the form.
 */
function StaticGradeValue({ children, className }: { children: React.ReactNode; className?: string }) {
  return (
    <div
      className={cn(
        'w-full flex items-center rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700',
        'text-slate-500 dark:text-slate-400 font-medium select-none',
        className,
      )}
    >
      {children}
    </div>
  );
}

/** "2026-07-25 14:30:00" -> { date: "2026-07-25", time: "14:30" } */
function splitDateTime(value?: string | null): { date: string; time: string } {
  if (!value) return { date: '', time: '' };
  const [date, timePart] = value.split(' ');
  return { date: date || '', time: timePart ? timePart.slice(0, 5) : '' };
}

/** { date: "2026-07-25", time: "14:30" } -> "2026-07-25 14:30:00" (or null if no date) */
function combineDateTime(date?: string, time?: string): string | null {
  if (!date) return null;
  return `${date} ${time || '00:00'}:00`;
}

/** Maps a backend content_items row (type=note, group-scoped) to the frontend Note shape. */
function toNote(row: any): Note {
  const start = splitDateTime(row.start_at);
  const end = splitDateTime(row.end_at);
  return {
    id: row.id,
    groupId: row.group_id,
    courseId: row.course_id,
    title: row.title,
    description: row.description || '',
    fileName: row.detail?.file_name || '',
    filePath: row.detail?.file_path || '',
    startDate: start.date,
    startTime: start.time,
    endDate: end.date || undefined,
    endTime: end.time || undefined,
  };
}

export default function NotesManagement({ courses = [] }: NotesManagementProps) {
  const [groups, setGroups] = useState<Group[]>([]);
  const [groupsLoading, setGroupsLoading] = useState(true);
  const [notes, setNotes] = useState<Note[]>([]);
  const [notesLoading, setNotesLoading] = useState(false);

  const [activeTab, setActiveTab] = useState<'add' | 'edit' | 'delete'>('add');
  // Picker/panel display state only — decides whether the landing picker or
  // the action panel is shown. Purely presentational: it mirrors `activeTab`
  // once an operation is chosen, but starts at `null` so the page opens on
  // the picker instead of straight into the "add" form.
  const [notesActionTab, setNotesActionTab] = useState<NotesAction | null>(null);
  const [isAdding, setIsAdding] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [isUploading, setIsUploading] = useState(false);

  // Load the instructor's real groups from the backend (course_groups table)
  // once on mount.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      setGroupsLoading(true);
      try {
        const rows = await groupApiService.getInstructorGroups();
        if (cancelled) return;
        setGroups((rows || []).map((g: any) => ({ id: g.id, name: g.title, courseId: g.course_id })));
      } catch {
        if (!cancelled) notify('تعذر تحميل المجموعات', 'error');
      } finally {
        if (!cancelled) setGroupsLoading(false);
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Fetch real notes (content_items type=note) for a given group and merge
  // them into local state, replacing any previously-cached rows for that
  // same group.
  const loadNotesForGroup = async (groupId: string) => {
    if (!groupId) return;
    setNotesLoading(true);
    try {
      const rows = await groupNoteApiService.listByGroup(groupId);
      const mapped = (rows || []).filter((row: any) => row.type === 'note').map(toNote);
      setNotes(prev => [...prev.filter((n: Note) => n.groupId !== groupId), ...mapped]);
    } catch {
      notify('تعذر تحميل المذكرات الخاصة بالمجموعة', 'error');
    } finally {
      setNotesLoading(false);
    }
  };

  const { notify } = useCenteredNotification();
  const triggerFeedback = (message: string, type: 'success' | 'error') => {
    notify(message, type);
  };

    const getInstructorInfo = () => {
    try {
      const currentUser = authService.getUser();
      if (currentUser && currentUser.role === 'instructor') {
        return {
          name: currentUser.fullName || currentUser.username || '',
          grades: Array.isArray(currentUser.grades) ? currentUser.grades : [],
          subjects: Array.isArray(currentUser.subjects) ? currentUser.subjects : [],
          id: currentUser.id
        };
      }
      return null;
    } catch {
      return null;
    }
  };

  const currentTeacher = getInstructorInfo();
  const availableGrades = currentTeacher?.grades || [];

  const [selectedGrade, setSelectedGrade] = useState(() => availableGrades[0]);

  // ADD state
  const [addCourseId, setAddCourseId] = useState('');
  const [addGroupId, setAddGroupId] = useState('');
  const [addTitle, setAddTitle] = useState('');
  const [addDescription, setAddDescription] = useState('');
  const [addFileName, setAddFileName] = useState('');
  const [addFilePath, setAddFilePath] = useState('');
  const [addStartDate, setAddStartDate] = useState('');
  const [addStartTime, setAddStartTime] = useState('');
  const [addEndDate, setAddEndDate] = useState('');
  const [addEndTime, setAddEndTime] = useState('');

  // Synchronize grade
  useEffect(() => {
    if (availableGrades.length > 0 && !availableGrades.includes(selectedGrade)) {
      setSelectedGrade(availableGrades[0]);
    }
  }, [availableGrades, selectedGrade]);

  const coursesForGrade = useMemo(() => {
    return (courses || []).filter((c: any) => c.grade === selectedGrade);
  }, [courses, selectedGrade]);

  // No auto-pick of the first course/group: the rest of the "add note" form
  // only reveals itself once both are explicitly chosen.
  useEffect(() => {
    if (coursesForGrade.length > 0 && !coursesForGrade.find((c: any) => c.id === addCourseId)) {
      setAddCourseId('');
    } else if (coursesForGrade.length === 0) {
      setAddCourseId('');
    }
  }, [coursesForGrade, addCourseId]);

  const groupsForAddCourse = useMemo(() => {
    return groups.filter(g => g.courseId === addCourseId);
  }, [groups, addCourseId]);

  useEffect(() => {
    if (groupsForAddCourse.length > 0 && !groupsForAddCourse.find(g => g.id === addGroupId)) {
      setAddGroupId('');
    } else if (groupsForAddCourse.length === 0) {
      setAddGroupId('');
    }
  }, [groupsForAddCourse, addGroupId]);

  const handleFileChange = async (
    e: React.ChangeEvent<HTMLInputElement>,
    setName: React.Dispatch<React.SetStateAction<string>>,
    setPath: React.Dispatch<React.SetStateAction<string>>
  ) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const validation = validateDocumentFile(file);
    if (!validation.valid) {
      triggerFeedback(validation.error!, 'error');
      e.target.value = '';
      return;
    }
    setIsUploading(true);
    try {
      const uploaded = await uploadApiService.upload(file, 'document');
      setName(file.name);
      setPath(uploaded.path);
    } catch {
      triggerFeedback('فشل رفع الملف، يرجى المحاولة مرة أخرى', 'error');
      e.target.value = '';
    } finally {
      setIsUploading(false);
    }
  };

  const handleAddSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!addGroupId) {
      triggerFeedback('يرجى اختيار المجموعة أولاً لإلحاق المذكرة بها', 'error');
      return;
    }
    if (!addTitle.trim() || !addDescription.trim() || !addFileName) {
      triggerFeedback('يرجى ملء جميع الحقول المطلوبة (عنوان، وصف، ملف)', 'error');
      return;
    }

    setIsAdding(true);
    try {
      const created = await groupNoteApiService.createNote(addGroupId, {
        title: addTitle.trim(),
        description: addDescription.trim(),
        file_name: addFileName,
        file_path: addFilePath,
        start_at: combineDateTime(addStartDate, addStartTime),
        end_at: combineDateTime(addEndDate, addEndTime),
      });

      setNotes(prev => [toNote(created), ...prev]);
      triggerFeedback(`تم رفع المذكرة "${addTitle.trim()}" بنجاح!`, 'success');

      setAddTitle('');
      setAddDescription('');
      setAddFileName('');
      setAddFilePath('');
      setAddStartDate('');
      setAddStartTime('');
      setAddEndDate('');
      setAddEndTime('');
    } catch {
      triggerFeedback('تعذر رفع المذكرة، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setIsAdding(false);
    }
  };

  // Edit / Delete Cascading
  const [selectedCourseId, setSelectedCourseId] = useState('');
  const [selectedGroupId, setSelectedGroupId] = useState('');
  const [selectedNoteId, setSelectedNoteId] = useState('');
  const [showConfirmDeleteAlert, setShowConfirmDeleteAlert] = useState(false);

  const [editTitle, setEditTitle] = useState('');
  const [editDescription, setEditDescription] = useState('');
  const [editFileName, setEditFileName] = useState('');
  const [editFilePath, setEditFilePath] = useState('');
  const [editStartDate, setEditStartDate] = useState('');
  const [editStartTime, setEditStartTime] = useState('');
  const [editEndDate, setEditEndDate] = useState('');
  const [editEndTime, setEditEndTime] = useState('');

  useEffect(() => {
    if (!coursesForGrade.find((c: any) => c.id === selectedCourseId)) {
      setSelectedCourseId('');
    }
  }, [coursesForGrade, selectedCourseId]);

  const groupsForCourse = useMemo(() => {
    return groups.filter(g => g.courseId === selectedCourseId);
  }, [groups, selectedCourseId]);

  useEffect(() => {
    if (!groupsForCourse.find(g => g.id === selectedGroupId)) {
      setSelectedGroupId('');
    }
  }, [groupsForCourse, selectedGroupId]);

  // Load real notes whenever the group selected in the Add tab or the
  // Edit/Delete tab changes.
  useEffect(() => {
    if (addGroupId) loadNotesForGroup(addGroupId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [addGroupId]);

  useEffect(() => {
    if (selectedGroupId) loadNotesForGroup(selectedGroupId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedGroupId]);

  const notesForGroup = useMemo(() => {
    return notes.filter(n => n.groupId === selectedGroupId);
  }, [notes, selectedGroupId]);

  useEffect(() => {
    if (!notesForGroup.find(n => n.id === selectedNoteId)) {
      setSelectedNoteId('');
    }
  }, [notesForGroup, selectedNoteId]);

  useEffect(() => {
    setShowConfirmDeleteAlert(false);
  }, [selectedCourseId, selectedGroupId, selectedNoteId]);

  useEffect(() => {
    if (activeTab === 'edit' && selectedNoteId) {
       const note = notes.find(n => n.id === selectedNoteId);
       if (note) {
          setEditTitle(note.title);
          setEditDescription(note.description || '');
          setEditFileName(note.fileName || '');
          setEditFilePath(note.filePath || '');
          setEditStartDate(note.startDate || '');
          setEditStartTime(note.startTime || '');
          setEditEndDate(note.endDate || '');
          setEditEndTime(note.endTime || '');
       }
    }
  }, [selectedNoteId, activeTab]);

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedNoteId) {
      triggerFeedback('يرجى تحديد المذكرة المُراد تعديلها', 'error');
      return;
    }
    if (!editTitle.trim() || !editDescription.trim() || !editFileName) {
      triggerFeedback('يرجى التحقق من إدخال الحقول الإلزامية لتعديل المذكرة', 'error');
      return;
    }

    setIsEditing(true);
    try {
      const updated = await groupNoteApiService.updateNote(selectedNoteId, {
        title: editTitle.trim(),
        description: editDescription.trim(),
        file_name: editFileName,
        file_path: editFilePath,
        start_at: combineDateTime(editStartDate, editStartTime),
        end_at: combineDateTime(editEndDate, editEndTime),
      });

      setNotes(prev => prev.map(n => (n.id === selectedNoteId ? toNote(updated) : n)));
      triggerFeedback(`تم تحديث بيانات المذكرة "${editTitle.trim()}" بنجاح!`, 'success');
    } catch {
      triggerFeedback('تعذر تحديث المذكرة، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setIsEditing(false);
    }
  };

  const executeNoteDeletion = async () => {
    if (!selectedNoteId) return;
    setIsDeleting(true);
    const targetNote = notes.find(n => n.id === selectedNoteId);
    try {
      await groupNoteApiService.deleteNote(selectedNoteId);
      setNotes(prev => prev.filter(n => n.id !== selectedNoteId));
      setSelectedNoteId('');
      setShowConfirmDeleteAlert(false);
      triggerFeedback(`تم إزالة المذكرة "${targetNote?.title || ''}" بنجاح!`, 'success');
    } catch {
      triggerFeedback('تعذر حذف المذكرة، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  // Chosen once from an ActionCard: sets both the picker/panel display state
  // and `activeTab` together, and clears a half-armed delete confirmation —
  // exactly what each of the three hand-rolled tab buttons did before.
  const handleSelectAction = (id: NotesAction) => {
    setNotesActionTab(id);
    setActiveTab(id);
    setShowConfirmDeleteAlert(false);
  };

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <PageHeader
        icon={StickyNote}
        title="إضافة وتعديل المذكرات"
        description="إدارة مذكرات مجموعاتك الدراسية"
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إضافة وتعديل المذكرات' }]}
      />

      <AnimatePresence mode="wait">
        {notesActionTab === null ? (
          /* LANDING PICKER — one decision at a time, nothing crowded */
          <motion.div
            key="action_picker"
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            transition={{ delay: 0.08, duration: 0.35 }}
            className="grid grid-cols-1 sm:grid-cols-3 gap-4"
          >
            {ACTIONS.map((action, i) => (
              <ActionCard key={action.id} action={action} index={i} onSelect={handleSelectAction} />
            ))}
          </motion.div>
        ) : (
        <div key="action_panel">

          {(groupsLoading || notesLoading) && (
            <div className="pb-4 space-y-2">
              <Skeleton variant="text" width="30%" />
              <Skeleton variant="block" height={44} />
            </div>
          )}

          <AnimatePresence mode="wait">

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
                  onBack={() => setNotesActionTab(null)}
                  icon={Plus}
                  title="إضافة مذكرة دراسية جديدة المرفقات"
                  description="قم برفع المذكرات والملفات التعليمية الخاصة بالدروس والحصص للمجموعة."
                  tone="rose"
                />

                <form onSubmit={handleAddSubmit} className="space-y-6">
                  {/* SELECT CONTROLS */}
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* Grade */}
                    <FormField
                      label={
                        <span className="flex items-center gap-1.5">
                          <GraduationCap className="w-4 h-4 text-slate-400 shrink-0" />
                          المركب التعليمي
                        </span>
                      }
                    >
                      {({ id }) =>
                        availableGrades.length > 1 ? (
                          <Select id={id} size="lg" value={selectedGrade} onChange={(e) => setSelectedGrade(e.target.value)}>
                            {availableGrades.map((grade: any) => <option key={grade} value={grade}>{grade}</option>)}
                          </Select>
                        ) : (
                          <StaticGradeValue className="h-12 px-4 text-base">
                            {availableGrades[0] || 'لم يتم تحديد صف'}
                          </StaticGradeValue>
                        )
                      }
                    </FormField>
                    {/* Course */}
                    <FormField
                      label={
                        <span className="flex items-center gap-1.5">
                          <BookOpen className="w-4 h-4 text-slate-400 shrink-0" />
                          الكورس المساق
                        </span>
                      }
                    >
                      {({ id }) => (
                        <Select
                          id={id}
                          size="lg"
                          value={addCourseId}
                          onChange={(e) => setAddCourseId(e.target.value)}
                          disabled={coursesForGrade.length === 0}
                        >
                          {coursesForGrade.length === 0 ? <option value="">لا توجد كورسات</option> : (
                            <>
                              <option value="">-- اختر الكورس --</option>
                              {coursesForGrade.map((c: any) => <option key={c.id} value={c.id}>{c.title}</option>)}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>
                    {/* Group */}
                    <FormField
                      label={
                        <span className="flex items-center gap-1.5">
                          <Users className="w-4 h-4 text-slate-400 shrink-0" />
                          المجموعة التعليمية
                        </span>
                      }
                    >
                      {({ id }) => (
                        <Select
                          id={id}
                          size="lg"
                          value={addGroupId}
                          onChange={(e) => setAddGroupId(e.target.value)}
                          disabled={groupsForAddCourse.length === 0}
                        >
                          {groupsForAddCourse.length === 0 ? <option value="">لا توجد مجموعات</option> : (
                            <>
                              <option value="">-- اختر المجموعة --</option>
                              {groupsForAddCourse.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>
                  </div>

                  {/* The rest of the form only appears once a course AND a
                      group are both chosen. */}
                  {!addGroupId ? (
                    <EmptyState
                      icon={StickyNote}
                      title="اختر الكورس والمجموعة أولاً"
                      description="حدد الكورس والمجموعة أعلاه لإظهار بيانات المذكرة الجديدة."
                    />
                  ) : (
                  <>
                  {/* NOTE TITLE */}
                  <FormField
                    required
                    label={
                      <span className="flex items-center gap-1.5">
                        <FileText className="w-4 h-4 text-slate-400 shrink-0" />
                        عنوان المذكرة
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
                        placeholder="مثال: مذكرة مراجعة الفصل الأول"
                        className="font-bold"
                        required
                      />
                    )}
                  </FormField>

                  {/* NOTE DESCRIPTION */}
                  <FormField
                    label={
                      <span className="flex items-center gap-1.5">
                        <FileText className="w-4 h-4 text-slate-400 shrink-0" />
                        وصف المذكرة
                      </span>
                    }
                  >
                    {({ id }) => (
                      <Textarea
                        id={id}
                        value={addDescription}
                        onChange={(e) => setAddDescription(e.target.value)}
                        placeholder="وصف محتويات الملف..."
                        rows={3}
                        className="resize-none"
                        required
                      />
                    )}
                  </FormField>

                  {/* FILE UPLOAD */}
                  {/*
                    Dropzone chrome only. The <input type="file"> keeps its exact
                    `accept` list and `onChange` — `handleFileChange` still runs
                    `validateDocumentFile()` and only then calls
                    `uploadApiService.upload(file, 'document')`.
                  */}
                  <FormField
                    label={
                      <span className="flex items-center gap-1.5">
                        <UploadCloud className="w-4 h-4 text-slate-400 shrink-0" />
                        رفع ملف المذكرة من الجهاز *
                      </span>
                    }
                    helperText="يدعم PDF, DOC, PPT"
                  >
                    <div className="relative border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-card p-6 text-center hover:border-blue-400 dark:hover:border-blue-500/60 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors cursor-pointer group">
                      <input type="file" onChange={(e) => handleFileChange(e, setAddFileName, setAddFilePath)} className="absolute inset-0 w-full h-full opacity-0 cursor-pointer" accept=".pdf,.doc,.docx,.ppt,.pptx" />
                      <UploadCloud className="w-8 h-8 text-slate-400 mx-auto mb-2 group-hover:text-blue-500 transition-colors" />
                      <div className="text-sm sm:text-base font-bold text-slate-700 dark:text-slate-300">
                        {addFileName ? (
                          <span className="text-blue-600 dark:text-blue-400">{addFileName}</span>
                        ) : (
                          <span>اسحب الملف هنا أو انقر للاختيار</span>
                        )}
                      </div>
                    </div>
                  </FormField>

                  {/* VISIBILITY SCHEDULE */}
                  <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-6">
                    <div className="flex flex-col md:flex-row gap-6">
                      <div className="flex-1 space-y-3">
                        <h4 className="flex items-center gap-1.5 text-sm sm:text-base font-black text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-3.5 py-1.5 rounded-xl w-fit">
                          <Calendar className="w-4 h-4" />
                          تاريخ/وقت الإتاحة (اختياري)
                        </h4>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <FormField label="تاريخ الظهور">
                            {({ id }) => <Input id={id} size="lg" type="date" value={addStartDate} onChange={(e) => setAddStartDate(e.target.value)} />}
                          </FormField>
                          <FormField label="وقت الإتاحة">
                            {({ id }) => <Input id={id} size="lg" type="time" value={addStartTime} onChange={(e) => setAddStartTime(e.target.value)} />}
                          </FormField>
                        </div>
                      </div>

                      <div className="flex-1 space-y-3">
                        <h4 className="flex items-center gap-1.5 text-sm sm:text-base font-black text-rose-600 dark:text-rose-400 bg-rose-500/10 px-3.5 py-1.5 rounded-xl w-fit">
                          <Clock className="w-4 h-4" />
                          تاريخ/وقت الإخفاء (اختياري)
                        </h4>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <FormField label="تاريخ الإخفاء">
                            {({ id }) => <Input id={id} size="lg" type="date" value={addEndDate} onChange={(e) => setAddEndDate(e.target.value)} />}
                          </FormField>
                          <FormField label="وقت الانتهاء">
                            {({ id }) => <Input id={id} size="lg" type="time" value={addEndTime} onChange={(e) => setAddEndTime(e.target.value)} />}
                          </FormField>
                        </div>
                      </div>
                    </div>
                    <div className="flex gap-2.5 p-3.5 bg-blue-50/60 dark:bg-blue-500/10 rounded-xl border border-blue-200/50 dark:border-blue-500/20">
                       <Info className="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                       <div className="space-y-1">
                          <p className="text-xs sm:text-sm font-bold text-blue-800 dark:text-blue-300">قواعد ظهور المذكرة</p>
                          <p className="text-[11px] sm:text-xs font-normal leading-relaxed text-slate-600 dark:text-slate-400">
                            في حال تعيين تاريخ/وقت بديل، ستظهر المذكرة عند حلوله تلقائياً. إذا لم تقم بتدوين وقت للإخفاء؛ فستظل متاحة على الدوام.
                          </p>
                       </div>
                    </div>
                  </Card>

                  <div className="pt-4 flex justify-end">
                    <Button
                      type="submit"
                      size="lg"
                      icon={Plus}
                      disabled={groupsForAddCourse.length === 0 || isAdding || isUploading}
                      isLoading={isAdding}
                      loadingText="جاري رفع المذكرة..."
                      className="w-full md:w-auto shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
                    >
                      رفع المذكرة وحفظ التغييرات
                    </Button>
                  </div>
                  </>
                  )}
                </form>
              </motion.div>
            )}

            {(activeTab === 'edit' || activeTab === 'delete') && (
              <motion.div
                key="edit_delete_section"
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -10 }}
                transition={{ duration: 0.2 }}
                className="space-y-8"
              >
                <PanelBackBar
                  onBack={() => setNotesActionTab(null)}
                  icon={activeTab === 'edit' ? Edit : Trash2}
                  title={activeTab === 'edit' ? 'تعديل مذكرة دراسية قائمة' : <span className="text-red-600 dark:text-red-400">حذف مذكرة دراسية قائمة</span>}
                  description={activeTab === 'edit' ? 'اختر المذكرة المطلوب تعديلها ثم احفظ التغييرات' : 'اختر المذكرة المطلوب حذفها — هذا الإجراء لا يمكن التراجع عنه'}
                  tone={activeTab === 'edit' ? 'amber' : 'red'}
                />

                <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-4">
                  <CardHeader
                    className="mb-0 pb-1"
                    divider
                    icon={HelpCircle}
                    title="مسار تحديد المذكرة المستهدفة"
                  />

                  <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    {/* Grade */}
                    <FormField label={<span className="text-blue-700 dark:text-blue-400">1. الصف</span>}>
                      {({ id }) =>
                        availableGrades.length > 1 ? (
                          <Select id={id} value={selectedGrade} onChange={(e) => setSelectedGrade(e.target.value)}>
                            {availableGrades.map((grade: any) => <option key={grade} value={grade}>{grade}</option>)}
                          </Select>
                        ) : (
                          <StaticGradeValue className="h-10 px-3.5 text-sm">
                            {availableGrades[0] || 'لم يتم تحديد صف'}
                          </StaticGradeValue>
                        )
                      }
                    </FormField>
                    {/* Course */}
                    <FormField label={<span className="text-blue-700 dark:text-blue-400">2. الكورس</span>}>
                      {({ id }) => (
                        <Select
                          id={id}
                          value={selectedCourseId}
                          onChange={(e) => setSelectedCourseId(e.target.value)}
                          disabled={coursesForGrade.length === 0}
                        >
                          {coursesForGrade.length === 0 ? <option value="">لا توجد كورسات</option> : (
                            <>
                              <option value="">-- اختر الكورس --</option>
                              {coursesForGrade.map((c: any) => <option key={c.id} value={c.id}>{c.title}</option>)}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>
                    {/* Group */}
                    <FormField label={<span className="text-blue-700 dark:text-blue-400">3. المجموعة</span>}>
                      {({ id }) => (
                        <Select
                          id={id}
                          value={selectedGroupId}
                          onChange={(e) => setSelectedGroupId(e.target.value)}
                          disabled={groupsForCourse.length === 0}
                        >
                          {groupsForCourse.length === 0 ? <option value="">لا توجد مجموعات</option> : (
                            <>
                              <option value="">-- اختر المجموعة --</option>
                              {groupsForCourse.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>
                    {/* Note */}
                    <FormField label={<span className="text-blue-700 dark:text-blue-400">4. المذكرة</span>}>
                      {({ id }) => (
                        <Select
                          id={id}
                          value={selectedNoteId}
                          onChange={(e) => setSelectedNoteId(e.target.value)}
                          disabled={notesForGroup.length === 0}
                        >
                          {notesForGroup.length === 0 ? <option value="">لا توجد مذكرات</option> : (
                            <>
                              <option value="">-- اختر المذكرة --</option>
                              {notesForGroup.map(n => <option key={n.id} value={n.id}>{n.title}</option>)}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>
                  </div>
                </Card>

                {activeTab === 'edit' && selectedNoteId && (
                  <form onSubmit={handleEditSubmit} className="space-y-6 pt-4 border-t border-slate-200 dark:border-slate-800">
                    <FormField label="عنوان المذكرة" required>
                      {({ id }) => (
                        <Input id={id} size="lg" type="text" value={editTitle} onChange={(e) => setEditTitle(e.target.value)} className="font-bold" required />
                      )}
                    </FormField>
                    <FormField label="وصف المذكرة" required>
                      {({ id }) => (
                        <Textarea id={id} value={editDescription} onChange={(e) => setEditDescription(e.target.value)} rows={3} className="resize-none" required />
                      )}
                    </FormField>
                    {/*
                      Same dropzone chrome as the Add tab; the file input keeps
                      its `accept` list and its `handleFileChange` handler, so
                      `validateDocumentFile()` still gates every upload here too.
                    */}
                    <FormField label="تحديث الملف (اختياري)">
                      <div className="relative border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-card p-4 text-center hover:border-blue-400 dark:hover:border-blue-500/60 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors cursor-pointer group">
                        <input type="file" onChange={(e) => handleFileChange(e, setEditFileName, setEditFilePath)} className="absolute inset-0 w-full h-full opacity-0 cursor-pointer" accept=".pdf,.doc,.docx,.ppt,.pptx" />
                        <div className="text-sm sm:text-base font-bold text-slate-700 dark:text-slate-300">
                          {editFileName ? <span className="text-blue-600 dark:text-blue-400">الملف الحالي: {editFileName}</span> : <span>اضغط لتحديث الملف</span>}
                        </div>
                      </div>
                    </FormField>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <FormField label="تاريخ الإتاحة">
                        {({ id }) => <Input id={id} size="lg" type="date" value={editStartDate} onChange={(e) => setEditStartDate(e.target.value)} />}
                      </FormField>
                      <FormField label="تاريخ الإخفاء">
                        {({ id }) => <Input id={id} size="lg" type="date" value={editEndDate} onChange={(e) => setEditEndDate(e.target.value)} />}
                      </FormField>
                    </div>
                    <div className="pt-4 flex justify-end">
                      {/*
                        `variant="warning"` replaces the bespoke amber→orange
                        gradient, keeping "edit = amber" intact.
                      */}
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
                        حفظ بيانات المذكرة
                      </Button>
                    </div>
                  </form>
                )}

                {activeTab === 'delete' && selectedNoteId && (
                  <div className="space-y-6 pt-6 border-t border-slate-200 dark:border-slate-800">
                    {!showConfirmDeleteAlert ? (
                      <Card className="flex flex-col md:flex-row gap-6 items-center justify-between">
                        <div className="space-y-1.5 flex-1 min-w-0">
                          <h4 className="text-lg font-black text-slate-800 dark:text-slate-100">{notes.find(n => n.id === selectedNoteId)?.title}</h4>
                          <p className="text-sm text-slate-500 dark:text-slate-400 font-normal leading-relaxed">سيتم حذف المذكرة والملفات المرفقة نهائياً.</p>
                        </div>
                        <Button
                          variant="danger"
                          icon={Trash2}
                          onClick={() => setShowConfirmDeleteAlert(true)}
                          className="shrink-0 w-full md:w-auto shadow-lg shadow-red-500/20 hover:shadow-xl hover:shadow-red-500/30 transition-shadow"
                        >
                          حذف هذه المذكرة
                        </Button>
                      </Card>
                    ) : (
                      /*
                        Kept as an INLINE confirmation panel rather than swapped
                        for `ConfirmDialog`: `showConfirmDeleteAlert` is not a
                        modal-open flag — an effect resets it whenever the
                        course/group/note selection changes, and it renders in
                        flow under the selector. Turning it into a dialog would
                        change when and where it appears.
                      */
                      <div className="p-6 rounded-2xl bg-rose-50 dark:bg-rose-500/10 border-2 border-rose-200 dark:border-rose-500/30 shadow-e1">
                        <div className="flex gap-4">
                          <div className="p-3 bg-white dark:bg-rose-500/20 rounded-full shadow-sm h-fit shrink-0">
                            <AlertTriangle className="w-6 h-6 text-rose-600 dark:text-rose-400" />
                          </div>
                          <div className="space-y-4 min-w-0">
                            <div className="space-y-1">
                              <h4 className="text-lg font-black text-rose-800 dark:text-rose-300">هل أنت متأكد من حذف هذه المذكرة نهائياً؟</h4>
                              <p className="text-sm sm:text-base font-medium text-rose-600/80 dark:text-rose-400/80 leading-relaxed max-w-2xl">
                                لن يتمكن الطلاب من استعراض المذكرة مرة أخرى إذا قمت بتأكيد الحذف.
                              </p>
                            </div>
                            <div className="flex flex-wrap gap-3 pt-2">
                              <Button
                                variant="danger"
                                onClick={executeNoteDeletion}
                                disabled={isDeleting}
                                isLoading={isDeleting}
                                loadingText="جاري الحذف..."
                              >
                                نعم، احذف المذكرة
                              </Button>
                              <Button variant="secondary" onClick={() => setShowConfirmDeleteAlert(false)}>
                                إلغاء وتراجع
                              </Button>
                            </div>
                          </div>
                        </div>
                      </div>
                    )}
                  </div>
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
