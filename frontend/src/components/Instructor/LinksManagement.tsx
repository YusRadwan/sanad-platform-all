import { authService } from '../../services/authService';
import React, { useState, useEffect, useMemo } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import type { LucideIcon } from 'lucide-react';
import {
  Plus, Edit, Trash2, Calendar, Clock,
  AlertTriangle, GraduationCap, BookOpen,
  Info, HelpCircle, FileText, Users, Link as LinkIcon, ArrowRight
} from 'lucide-react';
import { Group, LinkItem, LinksManagementProps } from '../../types/index';
import { groupApiService } from '../../services/groupApiService';
import { linkApiService } from '../../services/linkApiService';
import { logger } from '../../services/logger';
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

type LinkAction = 'add' | 'edit' | 'delete';

interface ActionDef {
  id: LinkAction;
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
  { id: 'add', icon: Plus, title: 'إضافة', description: 'إضافة لينك جديد', tone: 'rose' },
  { id: 'edit', icon: Edit, title: 'تعديل', description: 'تعديل لينك موجود', tone: 'amber' },
  { id: 'delete', icon: Trash2, title: 'حذف', description: 'حذف لينك نهائياً', tone: 'red' },
];

/**
 * The landing picker: one clearly-labelled tile per operation instead of a
 * tab bar, so the page opens on a single, uncluttered decision rather than
 * three simultaneous forms/lists. Centred icon-over-label layout (no footer
 * meta/arrow row) — a plain, instantly-scannable menu of the 3 operations.
 */
function ActionCard({ action, onSelect, index }: { action: ActionDef; onSelect: (id: LinkAction) => void; index: number }) {
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
          <h2 className="text-base sm:text-lg font-black text-slate-900 dark:text-white leading-tight">{title}</h2>
          <p className="mt-0.5 text-xs sm:text-sm font-medium text-slate-500 dark:text-slate-400">{description}</p>
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







export default function LinksManagement({ courses = [] }: LinksManagementProps) {
  const [groups, setGroups] = useState<Group[]>([]);
  const [links, setLinks] = useState<LinkItem[]>([]);
  const [loading, setLoading] = useState(true);

  const [activeTab, setActiveTab] = useState<LinkAction | null>(null);
  const [isAdding, setIsAdding] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  useEffect(() => {
    let isMounted = true;
    (async () => {
      try {
        setLoading(true);
        const apiGroups = await groupApiService.getInstructorGroups();
        const fetchedGroups: Group[] = (apiGroups || []).map((g: any) => ({
          id: String(g.id),
          courseId: String(g.courseId || g.course_id || ''),
          name: g.name || g.title || '',
        }));
        let hadLinkFetchFailure = false;
        const itemsByGroup = await Promise.all(
          fetchedGroups.map(g => linkApiService.listByGroup(g.id).catch((linkErr) => {
            logger.error(`Failed to load links for group ${g.id}`, linkErr as Error);
            hadLinkFetchFailure = true;
            return [];
          }))
        );
        if (isMounted) {
          setGroups(fetchedGroups);
          setLinks(itemsByGroup.flat());
          if (hadLinkFetchFailure) {
            triggerFeedback('تعذر تحميل لينكات بعض المجموعات، قد تكون بعض البيانات غير مكتملة', 'error');
          }
        }
      } catch (err) {
        logger.error('Failed to load groups/links', err as Error);
        if (isMounted) triggerFeedback('حدث خطأ أثناء تحميل اللينكات', 'error');
      } finally {
        if (isMounted) setLoading(false);
      }
    })();
    return () => { isMounted = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

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
  const [addUrl, setAddUrl] = useState('');
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
  
  // No auto-pick of the first course/group: the rest of the "add link" form
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

  const handleAddSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!addGroupId) {
      triggerFeedback('يرجى اختيار المجموعة أولاً لإلحاق اللينك بها', 'error');
      return;
    }
    if (!addTitle.trim() || !addDescription.trim() || !addUrl.trim()) {
      triggerFeedback('يرجى ملء جميع الحقول المطلوبة (عنوان، وصف، الرابط)', 'error');
      return;
    }

    setIsAdding(true);
    try {
      const newLink = await linkApiService.create(addGroupId, {
        title: addTitle.trim(),
        description: addDescription.trim(),
        url: addUrl.trim(),
        startDate: addStartDate,
        startTime: addStartTime,
        endDate: addEndDate || undefined,
        endTime: addEndTime || undefined,
      });

      setLinks([newLink, ...links]);
      triggerFeedback(`تم إضافة اللينك "${addTitle.trim()}" بنجاح!`, 'success');

      setAddTitle('');
      setAddDescription('');
      setAddUrl('');
      setAddStartDate('');
      setAddStartTime('');
      setAddEndDate('');
      setAddEndTime('');
    } catch (err) {
      logger.error('Failed to create link', err as Error);
      triggerFeedback('حدث خطأ أثناء إضافة اللينك', 'error');
    } finally {
      setIsAdding(false);
    }
  };

  // Edit / Delete Cascading
  const [selectedCourseId, setSelectedCourseId] = useState('');
  const [selectedGroupId, setSelectedGroupId] = useState('');
  const [selectedLinkId, setSelectedLinkId] = useState('');
  const [showConfirmDeleteAlert, setShowConfirmDeleteAlert] = useState(false);

  const [editTitle, setEditTitle] = useState('');
  const [editDescription, setEditDescription] = useState('');
  const [editUrl, setEditUrl] = useState('');
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

  const linksForGroup = useMemo(() => {
    return links.filter(l => l.groupId === selectedGroupId);
  }, [links, selectedGroupId]);

  useEffect(() => {
    if (!linksForGroup.find(l => l.id === selectedLinkId)) {
      setSelectedLinkId('');
    }
  }, [linksForGroup, selectedLinkId]);

  useEffect(() => {
    setShowConfirmDeleteAlert(false);
  }, [selectedCourseId, selectedGroupId, selectedLinkId]);

  useEffect(() => {
    if (activeTab === 'edit' && selectedLinkId) {
       const link = links.find(l => l.id === selectedLinkId);
       if (link) {
          setEditTitle(link.title);
          setEditDescription(link.description || '');
          setEditUrl(link.url);
          setEditStartDate(link.startDate || '');
          setEditStartTime(link.startTime || '');
          setEditEndDate(link.endDate || '');
          setEditEndTime(link.endTime || '');
       }
    }
  }, [selectedLinkId, activeTab]);

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedLinkId) {
      triggerFeedback('يرجى تحديد اللينك المُراد تعديله', 'error');
      return;
    }
    if (!editTitle.trim() || !editDescription.trim() || !editUrl.trim()) {
      triggerFeedback('يرجى التحقق من إدخال الحقول الإلزامية لتعديل اللينك', 'error');
      return;
    }

    setIsEditing(true);
    try {
      const updated = await linkApiService.update(selectedLinkId, {
        title: editTitle.trim(),
        description: editDescription.trim(),
        url: editUrl.trim(),
        startDate: editStartDate,
        startTime: editStartTime,
        endDate: editEndDate || undefined,
        endTime: editEndTime || undefined,
      });
      setLinks(links.map(l => (l.id === selectedLinkId ? updated : l)));
      triggerFeedback(`تم تحديث بيانات اللينك "${editTitle.trim()}" بنجاح!`, 'success');
    } catch (err) {
      logger.error('Failed to update link', err as Error);
      triggerFeedback('حدث خطأ أثناء تحديث اللينك', 'error');
    } finally {
      setIsEditing(false);
    }
  };

  const executeLinkDeletion = async () => {
    if (!selectedLinkId) return;
    setIsDeleting(true);
    const targetLink = links.find(l => l.id === selectedLinkId);
    try {
      await linkApiService.delete(selectedLinkId);
      setLinks(links.filter(l => l.id !== selectedLinkId));
      setSelectedLinkId('');
      setShowConfirmDeleteAlert(false);
      triggerFeedback(`تم إزالة اللينك "${targetLink?.title || ''}" بنجاح!`, 'success');
    } catch (err) {
      logger.error('Failed to delete link', err as Error);
      triggerFeedback('حدث خطأ أثناء حذف اللينك', 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  if (loading) {
    return (
      <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
        <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
        <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
        <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

        <div className="space-y-2">
          <Skeleton variant="text" width="18%" className="h-6" />
          <Skeleton variant="text" width="38%" />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          {Array.from({ length: 3 }).map((_, i) => (
            <Card key={i} className="flex flex-col items-center gap-3 p-7">
              <Skeleton variant="circle" className="w-16 h-16" />
              <Skeleton variant="text" width="50%" />
              <Skeleton variant="text" width="70%" />
            </Card>
          ))}
        </div>
      </div>
    );
  }

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <PageHeader
        icon={LinkIcon}
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إضافة وتعديل اللينكات' }]}
        title="إضافة وتعديل اللينكات"
        description="إدارة روابط مجموعاتك التعليمية"
      />

      <AnimatePresence mode="wait">
        {activeTab === null ? (
          /* LANDING PICKER — one decision at a time, nothing crowded */
          <motion.div
            key="action_picker"
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.35 }}
            className="grid grid-cols-1 sm:grid-cols-3 gap-4"
          >
            {ACTIONS.map((action, i) => (
              <ActionCard key={action.id} action={action} index={i} onSelect={(id) => { setActiveTab(id); setShowConfirmDeleteAlert(false); }} />
            ))}
          </motion.div>
        ) : (
      <motion.div
        key="action_panel"
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0, duration: 0.35 }}
      >
        <div>
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
                  onBack={() => setActiveTab(null)}
                  icon={Plus}
                  title="إضافة لينك جديد"
                  description="أضف روابط خارجية للمجموعة (مثل روابط زووم، أو مراجع إضافية)"
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
                      icon={LinkIcon}
                      title="اختر الكورس والمجموعة أولاً"
                      description="حدد الكورس والمجموعة أعلاه لإظهار بيانات اللينك الجديد."
                    />
                  ) : (
                  <>
                  {/* LINK TITLE */}
                  <FormField
                    required
                    label={
                      <span className="flex items-center gap-1.5">
                        <FileText className="w-4 h-4 text-slate-400 shrink-0" />
                        عنوان اللينك
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
                        placeholder="مثال: رابط حصة الزووم"
                        className="font-bold"
                        required
                      />
                    )}
                  </FormField>

                  {/* LINK DESCRIPTION */}
                  <FormField
                    label={
                      <span className="flex items-center gap-1.5">
                        <FileText className="w-4 h-4 text-slate-400 shrink-0" />
                        وصف اللينك
                      </span>
                    }
                  >
                    {({ id }) => (
                      <Textarea
                        id={id}
                        value={addDescription}
                        onChange={(e) => setAddDescription(e.target.value)}
                        placeholder="وصف محتويات اللينك..."
                        rows={3}
                        className="resize-none"
                        required
                      />
                    )}
                  </FormField>

                  {/* LINK URL */}
                  {/*
                    `type="url"` and `dir="ltr"` are carried over untouched — the
                    browser-level URL validation and the LTR rendering of the
                    address are behaviour, not styling.
                  */}
                  <FormField
                    required
                    label={
                      <span className="flex items-center gap-1.5">
                        <LinkIcon className="w-4 h-4 text-slate-400 shrink-0" />
                        الرابط (URL)
                      </span>
                    }
                  >
                    {({ id }) => (
                      <Input
                        id={id}
                        size="lg"
                        type="url"
                        value={addUrl}
                        onChange={(e) => setAddUrl(e.target.value)}
                        placeholder="https://..."
                        className="text-left font-bold"
                        dir="ltr"
                        required
                      />
                    )}
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
                          <p className="text-xs sm:text-sm font-bold text-blue-800 dark:text-blue-300">قواعد ظهور اللينك</p>
                          <p className="text-[11px] sm:text-xs font-normal leading-relaxed text-slate-600 dark:text-slate-400">
                            في حال تعيين تاريخ/وقت بديل، سيظهر اللينك عند حلوله تلقائياً. إذا لم تقم بتدوين وقت للإخفاء؛ فسيظل متاحاً على الدوام.
                          </p>
                       </div>
                    </div>
                  </Card>

                  <div className="pt-4 flex justify-end">
                    <Button
                      type="submit"
                      size="lg"
                      icon={Plus}
                      disabled={groupsForAddCourse.length === 0 || isAdding}
                      isLoading={isAdding}
                      loadingText="جاري إضافة اللينك..."
                      className="w-full md:w-auto shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
                    >
                      إضافة اللينك وحفظ التغييرات
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
                {activeTab === 'edit' ? (
                  <PanelBackBar
                    onBack={() => setActiveTab(null)}
                    icon={Edit}
                    title="تعديل لينك موجود"
                    description="اختر اللينك المطلوب تعديله، ثم عدّل بياناته واحفظ التغييرات"
                    tone="amber"
                  />
                ) : (
                  <PanelBackBar
                    onBack={() => setActiveTab(null)}
                    icon={Trash2}
                    title={<span className="text-red-600 dark:text-red-400">حذف لينك موجود</span>}
                    description="اختر اللينك المطلوب حذفه — هذا الإجراء لا يمكن التراجع عنه"
                    tone="red"
                  />
                )}

                <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-4">
                  <CardHeader
                    className="mb-0 pb-1"
                    divider
                    icon={HelpCircle}
                    title="مسار تحديد اللينك المستهدف"
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
                    {/* Link */}
                    <FormField label={<span className="text-blue-700 dark:text-blue-400">4. اللينك</span>}>
                      {({ id }) => (
                        <Select
                          id={id}
                          value={selectedLinkId}
                          onChange={(e) => setSelectedLinkId(e.target.value)}
                          disabled={linksForGroup.length === 0}
                        >
                          {linksForGroup.length === 0 ? <option value="">لا توجد لينكات</option> : (
                            <>
                              <option value="">-- اختر اللينك --</option>
                              {linksForGroup.map(l => <option key={l.id} value={l.id}>{l.title}</option>)}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>
                  </div>
                </Card>

                {activeTab === 'edit' && selectedLinkId && (
                  <form onSubmit={handleEditSubmit} className="space-y-6 pt-4 border-t border-slate-200 dark:border-slate-800">
                    <FormField label="عنوان اللينك" required>
                      {({ id }) => (
                        <Input id={id} size="lg" type="text" value={editTitle} onChange={(e) => setEditTitle(e.target.value)} className="font-bold" required />
                      )}
                    </FormField>
                    <FormField label="وصف اللينك" required>
                      {({ id }) => (
                        <Textarea id={id} value={editDescription} onChange={(e) => setEditDescription(e.target.value)} rows={3} className="resize-none" required />
                      )}
                    </FormField>
                    <FormField label="الرابط (URL)" required>
                      {({ id }) => (
                        <Input id={id} size="lg" type="url" value={editUrl} onChange={(e) => setEditUrl(e.target.value)} className="text-left font-bold" dir="ltr" required />
                      )}
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
                        loadingText="جاري حفظ اللينك..."
                        className="w-full md:w-auto shadow-lg shadow-amber-500/20 hover:shadow-xl hover:shadow-amber-500/30 transition-shadow"
                      >
                        حفظ بيانات اللينك
                      </Button>
                    </div>
                  </form>
                )}

                {activeTab === 'delete' && selectedLinkId && (
                  <div className="space-y-6 pt-6 border-t border-slate-200 dark:border-slate-800">
                    {!showConfirmDeleteAlert ? (
                      <Card className="flex flex-col md:flex-row gap-6 items-center justify-between">
                        <div className="space-y-1.5 flex-1 min-w-0">
                          <h4 className="text-lg font-black text-slate-800 dark:text-slate-100">{links.find(l => l.id === selectedLinkId)?.title}</h4>
                          <p className="text-sm text-slate-500 dark:text-slate-400 font-normal leading-relaxed">سيتم حذف اللينك نهائياً ولن يتمكن الطلاب من الوصول إليه.</p>
                        </div>
                        <Button
                          variant="danger"
                          icon={Trash2}
                          onClick={() => setShowConfirmDeleteAlert(true)}
                          className="shrink-0 w-full md:w-auto"
                        >
                          حذف هذا اللينك
                        </Button>
                      </Card>
                    ) : (
                      /*
                        Kept as an INLINE confirmation panel rather than swapped
                        for `ConfirmDialog`: `showConfirmDeleteAlert` is not a
                        modal-open flag — an effect resets it whenever the
                        course/group/link selection changes, and it renders in
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
                              <h4 className="text-lg font-black text-rose-800 dark:text-rose-300">هل أنت متأكد من حذف هذا اللينك نهائياً؟</h4>
                              <p className="text-sm sm:text-base font-medium text-rose-600/80 dark:text-rose-400/80 leading-relaxed max-w-2xl">
                                لن يتمكن الطلاب من استعراض اللينك مرة أخرى إذا قمت بتأكيد الحذف.
                              </p>
                            </div>
                            <div className="flex flex-wrap gap-3 pt-2">
                              <Button
                                variant="danger"
                                onClick={executeLinkDeletion}
                                disabled={isDeleting}
                                isLoading={isDeleting}
                                loadingText="جاري الحذف..."
                              >
                                نعم، احذف اللينك
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
      </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
