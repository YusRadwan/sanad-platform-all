import { authService } from '../../services/authService';
import React, { useState, useEffect, useMemo } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import type { LucideIcon } from 'lucide-react';
import {
  Users, Plus, Edit, Trash2, Calendar, Clock,
  AlertTriangle, GraduationCap, BookOpen,
  Sparkles, Info, HelpCircle, FileText, ArrowRight,
} from 'lucide-react';
import { GroupManagementProps } from '../../types/index';
import { groupApiService } from '../../services/groupApiService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  Card,
  CardHeader,
  SectionHeader,
  PageHeader,
  Button,
  Input,
  Select,
  Textarea,
  FormField,
  EmptyState,
  cn,
} from '../UI';

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

type GroupAction = 'add' | 'edit' | 'delete';

interface ActionDef {
  id: GroupAction;
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
  { id: 'add', icon: Plus, title: 'إضافة', description: 'إضافة مجموعة جديدة', tone: 'rose' },
  { id: 'edit', icon: Edit, title: 'تعديل', description: 'تعديل مجموعة موجودة', tone: 'amber' },
  { id: 'delete', icon: Trash2, title: 'حذف', description: 'حذف مجموعة نهائياً', tone: 'red' },
];

/**
 * The landing picker: one clearly-labelled tile per operation instead of a
 * tab bar, so the page opens on a single, uncluttered decision rather than
 * three simultaneous forms/lists. Centred icon-over-label layout (no footer
 * meta/arrow row) — a plain, instantly-scannable menu of the 3 operations.
 */
function ActionCard({ action, onSelect, index }: { action: ActionDef; onSelect: (id: GroupAction) => void; index: number }) {
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










export default function GroupManagement({ courses = [] }: GroupManagementProps) {
  const [groups, setGroups] = useState<any[]>([]);
  const [isAdding, setIsAdding] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  const [activeTab, setActiveTab] = useState<'add' | 'edit' | 'delete'>('add');

  // UI-only: whether an operation has been picked from the landing picker
  // (replaces the old always-visible Tabs pill bar). Purely presentational —
  // `activeTab` above still tracks WHICH operation, unchanged.
  const [isActionChosen, setIsActionChosen] = useState(false);

  useEffect(() => {
    const fetchGroups = async () => {
      try {
        const data = await groupApiService.getInstructorGroups();
        
        // Map database fields to the component's expected structure.
        // CourseGroupResource (backend) answers snake_case with DIFFERENT
        // names than this form's own state — `course_id` not `courseId`,
        // `title` not `name`, `start_at`/`end_at` not `startDate`/`endDate`.
        // Reading the camelCase names here always produced `undefined`,
        // so every previously-created group came back with courseId
        // undefined and silently failed the edit/delete course filter
        // below — a course that genuinely had groups looked empty.
        // `grade` and `description` are not persisted on the backend at
        // all (not in CourseGroup::$fillable, not in the resource), so a
        // fetched group can never carry them back — defaulted here instead
        // of left `undefined` so the edit form doesn't call `.trim()` on it.
        const mappedGroups = data.map((g: any) => {
          let stDate = '', stTime = '', enDate = '', enTime = '';
          if (g.start_at) {
            const d = new Date(g.start_at);
            stDate = d.toISOString().split('T')[0];
            stTime = d.toISOString().split('T')[1].substring(0, 5);
          }
          if (g.end_at) {
            const d = new Date(g.end_at);
            enDate = d.toISOString().split('T')[0];
            enTime = d.toISOString().split('T')[1].substring(0, 5);
          }

          return {
            id: g.id,
            courseId: g.course_id,
            grade: g.grade,
            name: g.title,
            description: g.description || '',
            startDate: stDate,
            startTime: stTime,
            endDate: enDate || undefined,
            endTime: enTime || undefined
          };
        });

        setGroups(mappedGroups);
      } catch (err) {
        triggerFeedback('حدث خطأ أثناء تحميل البيانات', 'error');
      } finally {
      }
    };
    fetchGroups();
  }, []);

  const { notify } = useCenteredNotification();
  const triggerFeedback = (message: string, type: 'success' | 'error') => {
    notify(message, type);
  };

  // Instructor Info Helper
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

  // Selection states
  const [selectedGrade, setSelectedGrade] = useState(() => availableGrades[0]);
  const [addCourseId, setAddCourseId] = useState('');
  
  // Add Group Input States
  const [addName, setAddName] = useState('');
  const [addDescription, setAddDescription] = useState('');
  const [addStartDate, setAddStartDate] = useState('');
  const [addStartTime, setAddStartTime] = useState('');
  const [addEndDate, setAddEndDate] = useState('');
  const [addEndTime, setAddEndTime] = useState('');

  // Synchronize selected grade with available teacher grades
  useEffect(() => {
    if (availableGrades.length > 0 && !availableGrades.includes(selectedGrade)) {
      setSelectedGrade(availableGrades[0]);
    }
  }, [availableGrades, selectedGrade]);

  // Course Options filtered by level
  const coursesForGrade = useMemo(() => {
    return (courses || []).filter((c: any) => c.grade === selectedGrade);
  }, [courses, selectedGrade]);
  
  // Update Course state based on grade selections. No auto-pick of the
  // first course: the rest of the "add group" form only reveals itself once
  // the instructor has explicitly chosen a course, so silently pre-selecting
  // one here would skip that step instead of enforcing it.
  useEffect(() => {
    if (coursesForGrade.length > 0 && !coursesForGrade.find((c: any) => c.id === addCourseId)) {
      setAddCourseId('');
    } else if (coursesForGrade.length === 0) {
      setAddCourseId('');
    }
  }, [coursesForGrade, addCourseId]);

  const handleAddSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!addCourseId) {
      triggerFeedback('يرجى اختيار الكورس أولاً لإلحاق المجموعة به', 'error');
      return;
    }
    if (!addName.trim() || !addDescription.trim()) {
      triggerFeedback('يرجى ملء جميع الحقول المطلوبة بنجاح', 'error');
      return;
    }

    setIsAdding(true);
    try {
      // No start/end date set → `null`, a real permanent state on the
      // backend meaning "no gate" (immediately visible, forever), not
      // merely "not chosen yet". See App\Models\CourseGroup::
      // isCurrentlyVisible().
      const startStr = addStartDate
        ? (addStartTime ? `${addStartDate}T${addStartTime}:00Z` : `${addStartDate}T00:00:00Z`)
        : null;
      const endStr = addEndDate ? (addEndTime ? `${addEndDate}T${addEndTime}:00Z` : `${addEndDate}T23:59:59Z`) : null;

      const newGroupData = await groupApiService.createGroup({
        // The backend contract is `title`, not `name` — this form used to
        // send `name`, which the create route never reads, so every "add
        // group" submission silently 422'd before this fix.
        courseId: addCourseId,
        grade: selectedGrade,
        title: addName.trim(),
        description: addDescription.trim(),
        start_at: startStr,
        end_at: endStr
      });

      const newGroup = {
        id: newGroupData.id,
        courseId: addCourseId,
        grade: selectedGrade,
        name: addName.trim(),
        description: addDescription.trim(),
        startDate: addStartDate || undefined,
        startTime: addStartTime || undefined,
        endDate: addEndDate || undefined,
        endTime: addEndTime || undefined
      };

      setGroups([newGroup, ...groups]);
      triggerFeedback(
        startStr
          ? `تم إضافة المجموعة "${addName.trim()}" وجدولة ظهورها بنجاح!`
          : `تم إضافة المجموعة "${addName.trim()}" ونشرها فوراً بنجاح!`,
        'success'
      );

      // reset form
      setAddName('');
      setAddDescription('');
      setAddStartDate('');
      setAddStartTime('');
      setAddEndDate('');
      setAddEndTime('');
    } catch (err) {
      triggerFeedback('حدث خطأ أثناء إضافة المجموعة', 'error');
    }
    finally {
        setIsAdding(false);
    }
  };

  // Edit / Delete Cascading Select State
  const [selectedCourseId, setSelectedCourseId] = useState('');
  const [selectedGroupId, setSelectedGroupId] = useState('');
  
  // Show delete danger alert state
  const [showConfirmDeleteAlert, setShowConfirmDeleteAlert] = useState(false);

  // Form State for edit
  const [editName, setEditName] = useState('');
  const [editDescription, setEditDescription] = useState('');
  const [editStartDate, setEditStartDate] = useState('');
  const [editStartTime, setEditStartTime] = useState('');
  const [editEndDate, setEditEndDate] = useState('');
  const [editEndTime, setEditEndTime] = useState('');

  // Cascade selections helper
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

  // reset delete triggers when cascading targets change
  useEffect(() => {
    setShowConfirmDeleteAlert(false);
  }, [selectedCourseId, selectedGroupId]);

  // Load and pre-populate edit states safely
  useEffect(() => {
    if (activeTab === 'edit' && selectedGroupId) {
       const group = groups.find(g => g.id === selectedGroupId);
       if (group) {
          setEditName(group.name);
          setEditDescription(group.description);
          setEditStartDate(group.startDate || '');
          setEditStartTime(group.startTime || '');
          setEditEndDate(group.endDate || '');
          setEditEndTime(group.endTime || '');
       }
    }
  }, [selectedGroupId, activeTab]);

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedGroupId) {
      triggerFeedback('يرجى تحديد المجموعة المُراد تعديلها', 'error');
      return;
    }
    if (!editName.trim() || !editDescription.trim()) {
      triggerFeedback('يرجى التحقق من إدخال الحقول الإلزامية لتعديل المجموعة', 'error');
      return;
    }

    setIsEditing(true);
    try {
      // No start/end date set → `null`, a real permanent "no gate" state on
      // the backend (immediately visible, forever) — see CourseGroup::
      // isCurrentlyVisible().
      const startStr = editStartDate
        ? (editStartTime ? `${editStartDate}T${editStartTime}:00Z` : `${editStartDate}T00:00:00Z`)
        : null;
      const endStr = editEndDate ? (editEndTime ? `${editEndDate}T${editEndTime}:00Z` : `${editEndDate}T23:59:59Z`) : null;

      await groupApiService.updateGroup(selectedGroupId, {
        // The backend contract is `title`, not `name` — this form used to
        // send `name`, which PATCH /groups/{id} never reads, so a rename
        // through this form always silently no-opped before this fix.
        courseId: selectedCourseId,
        grade: selectedGrade,
        title: editName.trim(),
        description: editDescription.trim(),
        start_at: startStr,
        end_at: endStr
      });

      setGroups(groups.map(g => {
        if (g.id === selectedGroupId) {
          return {
            ...g,
            name: editName.trim(),
            description: editDescription.trim(),
            startDate: editStartDate || undefined,
            startTime: editStartTime || undefined,
            endDate: editEndDate || undefined,
            endTime: editEndTime || undefined
          };
        }
        return g;
      }));

      triggerFeedback(`تم تطبيق وحفظ كافة تحديثات المجموعة "${editName.trim()}" بنجاح!`, 'success');
    } catch (err) {
      triggerFeedback('حدث خطأ أثناء تعديل المجموعة', 'error');
    }
    finally {
        setIsEditing(false);
    }
  };

  const executeGroupDeletion = async () => {
    if (!selectedGroupId) return;
    const targetGroup = groups.find(g => g.id === selectedGroupId);
    
    setIsDeleting(true);
    try {
      await groupApiService.deleteGroup(selectedGroupId);
      setGroups(groups.filter(g => g.id !== selectedGroupId));
      setSelectedGroupId('');
      setShowConfirmDeleteAlert(false);
      triggerFeedback(`تم إزالة المجموعة التنشيطية "${targetGroup?.name || ''}" بنجاح!`, 'success');
    } catch (err) {
      triggerFeedback('حدث خطأ أثناء إزالة المجموعة', 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <PageHeader
        icon={Users}
        title="إضافة وتعديل المجموعات"
        description="إدارة مجموعات طلابك الدراسية"
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إضافة وتعديل المجموعات' }]}
      />

      <AnimatePresence mode="wait">
        {!isActionChosen ? (
          /* LANDING PICKER — one decision at a time, nothing crowded */
          <motion.div
            key="group_action_picker"
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
                onSelect={(id) => {
                  // Same body as the old Tabs `onChange`: pick the operation
                  // AND clear any pending delete confirmation.
                  setActiveTab(id);
                  setShowConfirmDeleteAlert(false);
                  setIsActionChosen(true);
                }}
              />
            ))}
          </motion.div>
        ) : (
        <div key="group_action_panel">

          {activeTab === 'add' && (
            <PanelBackBar
              onBack={() => setIsActionChosen(false)}
              icon={Plus}
              title="إضافة مجموعة جديدة"
              description="أنشئ مجموعة تفعيلية جديدة وأربطها بكورس ومستوى دراسي"
              tone="rose"
            />
          )}
          {activeTab === 'edit' && (
            <PanelBackBar
              onBack={() => setIsActionChosen(false)}
              icon={Edit}
              title="تعديل بيانات المجموعات"
              description="حدد الصف والكورس والمجموعة، ثم عدّل بياناتها"
              tone="amber"
            />
          )}
          {activeTab === 'delete' && (
            <PanelBackBar
              onBack={() => setIsActionChosen(false)}
              icon={Trash2}
              title={<span className="text-red-600 dark:text-red-400">حذف مجموعة</span>}
              description="حدد الصف والكورس والمجموعة، ثم أكّد الحذف — هذا الإجراء لا يمكن التراجع عنه"
              tone="red"
            />
          )}

          {/* COMPONENT SCENARIOS */}
          <AnimatePresence mode="wait">

            {/* ADD GROUP TAB SCENE */}
            {activeTab === 'add' && (
              <motion.div
                key="add_group_section"
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -10 }}
                transition={{ duration: 0.2 }}
                className="space-y-6"
              >
                <SectionHeader
                  className="pb-3 border-b border-slate-100 dark:border-slate-800"
                  icon={Sparkles}
                  title={`إطلاق وتفعيل مجموعة جديدة لمستويات ${selectedGrade}`}
                  description="أنشئ مجموعة تفعيلية أو حصص زمنية دورية لتنظيم تدريب الدارسين وربطهم بالكورس المخصص."
                />

                <form onSubmit={handleAddSubmit} className="space-y-6">
                  {/* SELECT CONTROLS */}
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {/* Select Grade level */}
                    <FormField
                      label={
                        <span className="flex items-center gap-1.5">
                          <GraduationCap className="w-4 h-4 text-slate-400 shrink-0" />
                          المركب التعليمي / الصف الدراسي
                        </span>
                      }
                    >
                      {({ id }) =>
                        availableGrades.length > 1 ? (
                          <Select id={id} size="lg" value={selectedGrade} onChange={(e) => setSelectedGrade(e.target.value)}>
                            {availableGrades.map((grade: any) => (
                              <option key={grade} value={grade}>{grade}</option>
                            ))}
                          </Select>
                        ) : (
                          <StaticGradeValue className="h-12 px-4 text-base">
                            {availableGrades[0] || 'لم يتم تحديد صف'}
                          </StaticGradeValue>
                        )
                      }
                    </FormField>

                    {/* Select course inside selected grade */}
                    <FormField
                      label={
                        <span className="flex items-center gap-1.5">
                          <BookOpen className="w-4 h-4 text-slate-400 shrink-0" />
                          الكورس المساق المستوعب للدراسة
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
                          {coursesForGrade.length === 0 ? (
                            <option value="">لا يوجد كورسات على ذمة هذه المرحلة</option>
                          ) : (
                            <>
                              <option value="">-- اختر الكورس --</option>
                              {coursesForGrade.map((c: any) => (
                                <option key={c.id} value={c.id}>{c.title}</option>
                              ))}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>
                  </div>

                  {/* The rest of the form (name, description, schedule, submit)
                      only appears once a grade AND a course are both chosen —
                      picking the group's parent before describing the group
                      itself, instead of showing every field at once. */}
                  {!addCourseId ? (
                    <EmptyState
                      icon={BookOpen}
                      title="اختر الصف والكورس أولاً"
                      description="حدد المركب التعليمي والكورس المستهدف أعلاه لإظهار بيانات المجموعة الجديدة."
                    />
                  ) : (
                  <>
                  {/* GROUP NAME INPUT */}
                  <FormField
                    required
                    label={
                      <span className="flex items-center gap-1.5">
                        <Users className="w-4 h-4 text-slate-400 shrink-0" />
                        اسم المجموعة
                      </span>
                    }
                  >
                    {({ id }) => (
                      <Input
                        id={id}
                        size="lg"
                        type="text"
                        value={addName}
                        onChange={(e) => setAddName(e.target.value)}
                        placeholder="مثال: مجموعة الفيزياء - يوم السبت والخميس 6 مساءً"
                        className="font-bold"
                        required
                      />
                    )}
                  </FormField>

                  {/* GROUP DESCRIPTION */}
                  <FormField
                    required
                    label={
                      <span className="flex items-center gap-1.5">
                        <FileText className="w-4 h-4 text-slate-400 shrink-0" />
                        وصف المجموعة وتفصيل الحصص والمواعيد
                      </span>
                    }
                  >
                    {({ id }) => (
                      <Textarea
                        id={id}
                        value={addDescription}
                        onChange={(e) => setAddDescription(e.target.value)}
                        placeholder="تفاصيل الحصص ومواعيد اللقاء وحساب المهام، مثال: مخصصة للمراجعات والاختبارات الدورية..."
                        rows={3}
                        className="resize-none"
                        required
                      />
                    )}
                  </FormField>

                  {/* DISPLAY VISIBILITY SCHEDULE WITH GUIDELINE RULE BANNER */}
                  <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-6">
                    <div className="flex flex-col md:flex-row gap-6">

                      {/* START DATE & TIME FIELD */}
                      <div className="flex-1 space-y-3">
                        <h4 className="flex items-center gap-1.5 text-sm sm:text-base font-black text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-3.5 py-1.5 rounded-xl w-fit">
                          <Calendar className="w-4 h-4" />
                          تاريخ/وقت إتاحة وظهور المجموعة (اختياري)
                        </h4>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <FormField label="تاريخ الظهور">
                            {({ id }) => (
                              <Input id={id} size="lg" type="date" value={addStartDate} onChange={(e) => setAddStartDate(e.target.value)} />
                            )}
                          </FormField>

                          <FormField label="ساعة وتوقيت البدء">
                            {({ id }) => (
                              <Input id={id} size="lg" type="time" value={addStartTime} onChange={(e) => setAddStartTime(e.target.value)} />
                            )}
                          </FormField>
                        </div>
                      </div>

                      {/* END DATE & TIME FIELD */}
                      <div className="flex-1 space-y-3">
                        <h4 className="flex items-center gap-1.5 text-sm sm:text-base font-black text-rose-600 dark:text-rose-400 bg-rose-500/10 px-3.5 py-1.5 rounded-xl w-fit">
                          <Clock className="w-4 h-4" />
                          تاريخ/وقت الإخفاء والانتهاء (اختياري)
                        </h4>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <FormField label="تاريخ الإخفاء">
                            {({ id }) => (
                              <Input id={id} size="lg" type="date" value={addEndDate} onChange={(e) => setAddEndDate(e.target.value)} />
                            )}
                          </FormField>

                          <FormField label="ساعة وتوقيت الانتهاء">
                            {({ id }) => (
                              <Input id={id} size="lg" type="time" value={addEndTime} onChange={(e) => setAddEndTime(e.target.value)} />
                            )}
                          </FormField>
                        </div>
                      </div>

                    </div>

                    {/* DYNAMIC SHIELD/VISIBILITY RULE CALLOUT */}
                    <div className="flex gap-2.5 p-3.5 bg-blue-50/60 dark:bg-blue-500/10 rounded-xl border border-blue-200/50 dark:border-blue-500/20">
                       <Info className="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                       <div className="space-y-1">
                          <p className="text-xs sm:text-sm font-bold text-blue-800 dark:text-blue-300">قواعد ظهور وجدولة المجموعة للدارسين</p>
                          <p className="text-[11px] sm:text-xs font-normal leading-relaxed text-slate-600 dark:text-slate-400">
                            في حال تعيين تاريخ البدء ووقت الظهور، سيتولى النظام تمكين المجموعة تلقائياً عند حلول الساعة المقررة. أما إذا تركت هذا الحقل فارغاً، فسيتم نشر المجموعة وإتاحتها للطلاب فوراً بمجرد الضغط على زر الإرسال. وإذا لم تقم بتدوين وقت للإخفاء؛ فستظل المجموعة متاحة على الدوام للطلاب دون تعطيل مفاجئ.
                          </p>
                       </div>
                    </div>
                  </Card>

                  {/* ACTION CONTROLS */}
                  <div className="pt-4 flex justify-end">
                    <Button
                      type="submit"
                      size="lg"
                      icon={Plus}
                      disabled={coursesForGrade.length === 0 || isAdding}
                      isLoading={isAdding}
                      loadingText="جاري إضافة المجموعة..."
                      className="w-full md:w-auto shadow-lg shadow-rose-500/20 hover:shadow-xl hover:shadow-rose-500/30 transition-shadow"
                    >
                      إرسال المجموعة وتفعيل الجدولة
                    </Button>
                  </div>
                  </>
                  )}
                </form>
              </motion.div>
            )}

            {/* EDIT GROUP TAB OR DELETE GROUP SCENE */}
            {(activeTab === 'edit' || activeTab === 'delete') && (
              <motion.div
                key="cascade_controls_and_forms_container"
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -10 }}
                transition={{ duration: 0.2 }}
                className="space-y-8"
              >
                
                {/* STEPPER STEPPING GRID - CASCADE DROPDOWNS */}
                <motion.div
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0 * 0.08, duration: 0.35 }}
                >
                <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-4">
                  <CardHeader
                    className="mb-0 pb-1"
                    divider
                    icon={HelpCircle}
                    title="مسار تحديد المجموعة المستهدفة"
                  />

                  <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* Step 1: Select Grade */}
                    <FormField label={<span className="text-blue-700 dark:text-blue-400">1. اختر الصف الدراسي</span>}>
                      {({ id }) =>
                        availableGrades.length > 1 ? (
                          <Select id={id} value={selectedGrade} onChange={(e) => setSelectedGrade(e.target.value)}>
                            {availableGrades.map((grade: any) => (
                              <option key={grade} value={grade}>{grade}</option>
                            ))}
                          </Select>
                        ) : (
                          <StaticGradeValue className="h-10 px-3.5 text-sm">
                            {availableGrades[0] || 'لم يتم تحديد صف'}
                          </StaticGradeValue>
                        )
                      }
                    </FormField>

                    {/* Step 2: Select Course */}
                    <FormField label={<span className="text-blue-700 dark:text-blue-400">2. حدد الكورس التعليمي</span>}>
                      {({ id }) => (
                        <Select
                          id={id}
                          value={selectedCourseId}
                          onChange={(e) => setSelectedCourseId(e.target.value)}
                          disabled={coursesForGrade.length === 0}
                        >
                          {coursesForGrade.length === 0 ? (
                            <option value="">لا يوجد كورسات حالية</option>
                          ) : (
                            <>
                              <option value="">-- اختر الكورس --</option>
                              {coursesForGrade.map((c: any) => (
                                <option key={c.id} value={c.id}>{c.title}</option>
                              ))}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>

                    {/* Step 3: Select Group */}
                    {/*
                      This last step keeps its blue-tinted surface: in the
                      original it was highlighted on purpose as the actual
                      target of the edit/delete action, so the emphasis is
                      preserved via a className rather than dropped.
                    */}
                    <FormField label={<span className="text-blue-700 dark:text-blue-400">3. حدد المجموعة المستهدفة</span>}>
                      {({ id }) => (
                        <Select
                          id={id}
                          value={selectedGroupId}
                          onChange={(e) => setSelectedGroupId(e.target.value)}
                          disabled={groupsForCourse.length === 0}
                          className="bg-blue-50/70 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/30 text-blue-900 dark:text-blue-300"
                        >
                          {groupsForCourse.length === 0 ? (
                            <option value="">لا يوجد مجموعات حالية</option>
                          ) : (
                            <>
                              <option value="">-- اختر المجموعة --</option>
                              {groupsForCourse.map(g => (
                                <option key={g.id} value={g.id}>{g.name}</option>
                              ))}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>
                  </div>
                </Card>
                </motion.div>

                {/* CASCADE CHECK CONTENT FLOW */}
                {groupsForCourse.length === 0 ? (
                  <motion.div
                    initial={{ opacity: 0, y: 16 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 1 * 0.08, duration: 0.35 }}
                  >
                    <EmptyState
                      icon={Users}
                      title="لا توجد أي مجموعات مدرجة"
                      description={'لم يتم ربط أي مجموعة بالكورس المختار حتى الآن. يرجى التوجه لخطوة "إضافة مجموعة جديدة" لإطلاق أولى مجموعات الكورس.'}
                    />
                  </motion.div>
                ) : activeTab === 'edit' && selectedGroupId ? (
                  /* EDIT FORMS */
                  <motion.form
                    onSubmit={handleEditSubmit}
                    className="space-y-6 pt-2"
                    initial={{ opacity: 0, y: 16 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 1 * 0.08, duration: 0.35 }}
                  >
                    <SectionHeader
                      className="pb-3 border-b border-slate-100 dark:border-slate-800"
                      icon={Sparkles}
                      title="تحرير وتعديل المجموعة النشطة"
                    />

                    {/* Group Name input */}
                    <FormField
                      required
                      label={
                        <span className="flex items-center gap-1.5">
                          <Users className="w-4 h-4 text-slate-400 shrink-0" />
                          اسم المجموعة المفعّلة
                        </span>
                      }
                    >
                      {({ id }) => (
                        <Input
                          id={id}
                          size="lg"
                          type="text"
                          value={editName}
                          onChange={(e) => setEditName(e.target.value)}
                          className="font-bold"
                          required
                        />
                      )}
                    </FormField>

                    {/* Group Description */}
                    <FormField
                      required
                      label={
                        <span className="flex items-center gap-1.5">
                          <FileText className="w-4 h-4 text-slate-400 shrink-0" />
                          الوصف التفصيلي
                        </span>
                      }
                    >
                      {({ id }) => (
                        <Textarea
                          id={id}
                          value={editDescription}
                          onChange={(e) => setEditDescription(e.target.value)}
                          rows={3}
                          className="resize-none"
                          required
                        />
                      )}
                    </FormField>

                    {/* Schedule grids inside container */}
                    <Card flat className="grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-50 dark:bg-slate-950/40">
                      {/* START */}
                      <div className="space-y-3">
                        <h4 className="flex items-center gap-1.5 text-sm sm:text-base font-black text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-3 py-1.5 rounded-xl w-fit">
                          <Calendar className="w-4 h-4" />
                          تاريخ/وقت الإتاحة (بداية الظهور) (اختياري)
                        </h4>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <FormField label="تاريخ البدء">
                            {({ id }) => (
                              <Input id={id} size="lg" type="date" value={editStartDate} onChange={(e) => setEditStartDate(e.target.value)} />
                            )}
                          </FormField>

                          <FormField label="ساعة البدء">
                            {({ id }) => (
                              <Input id={id} size="lg" type="time" value={editStartTime} onChange={(e) => setEditStartTime(e.target.value)} />
                            )}
                          </FormField>
                        </div>
                      </div>

                      {/* END */}
                      <div className="space-y-3">
                        <h4 className="flex items-center gap-1.5 text-sm sm:text-base font-black text-rose-600 dark:text-rose-400 bg-rose-500/10 px-3 py-1.5 rounded-xl w-fit">
                          <Clock className="w-4 h-4" />
                          تاريخ/وقت الإخفاء والانتهاء (اختياري)
                        </h4>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <FormField label="تاريخ الإخفاء">
                            {({ id }) => (
                              <Input id={id} size="lg" type="date" value={editEndDate} onChange={(e) => setEditEndDate(e.target.value)} />
                            )}
                          </FormField>

                          <FormField label="ساعة الإخفاء">
                            {({ id }) => (
                              <Input id={id} size="lg" type="time" value={editEndTime} onChange={(e) => setEditEndTime(e.target.value)} />
                            )}
                          </FormField>
                        </div>
                      </div>
                    </Card>

                    {/* DYNAMIC SHIELD/VISIBILITY RULE CALLOUT — same guidance
                        the "add" panel shows, missing here before this fix. */}
                    <div className="flex gap-2.5 p-3.5 bg-blue-50/60 dark:bg-blue-500/10 rounded-xl border border-blue-200/50 dark:border-blue-500/20">
                       <Info className="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                       <div className="space-y-1">
                          <p className="text-xs sm:text-sm font-bold text-blue-800 dark:text-blue-300">قواعد ظهور وجدولة المجموعة للدارسين</p>
                          <p className="text-[11px] sm:text-xs font-normal leading-relaxed text-slate-600 dark:text-slate-400">
                            في حال تعيين تاريخ البدء ووقت الظهور، سيتولى النظام تمكين المجموعة تلقائياً عند حلول الساعة المقررة. أما إذا تركت هذا الحقل فارغاً، فسيتم نشر المجموعة وإتاحتها للطلاب فوراً بمجرد الضغط على زر الإرسال. وإذا لم تقم بتدوين وقت للإخفاء؛ فستظل المجموعة متاحة على الدوام للطلاب دون تعطيل مفاجئ.
                          </p>
                       </div>
                    </div>

                    {/* SAVE BUTTON ACCORDING TO DESIGN SYSTEM */}
                    <div className="pt-4 flex justify-end border-t border-slate-100 dark:border-slate-800">
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
                        حفظ جميع التعديلات الحالية
                      </Button>
                    </div>
                  </motion.form>
                ) : activeTab === 'delete' ? (
                  selectedGroupId ? (
                    /* CONFIRM DELETION STEP INLINE (BETTER THAN WINDOW.CONFIRM) */
                    /*
                      Both steps stay as INLINE panels rather than becoming a
                      `ConfirmDialog`: `showConfirmDeleteAlert` is not a
                      modal-open flag — an effect resets it whenever the
                      course/group selection changes, switching tabs clears it,
                      and step one is the delete tab's whole body. Turning
                      either into a dialog would change when and where it
                      appears. Only the chrome is restyled.
                    */
                    <div className="pt-2">
                      <AnimatePresence mode="wait">
                        {!showConfirmDeleteAlert ? (
                          <motion.div
                            key="delete_warning_banner"
                            initial={{ opacity: 0, scale: 0.98 }}
                            animate={{ opacity: 1, scale: 1 }}
                            exit={{ opacity: 0 }}
                            className="p-6 sm:p-8 rounded-panel bg-slate-50 dark:bg-slate-950/40 border border-dashed border-rose-200 dark:border-rose-500/30 text-center space-y-6"
                          >
                            <div className="w-16 h-16 bg-rose-100 dark:bg-rose-500/15 rounded-full flex items-center justify-center mx-auto mb-2">
                              <AlertTriangle className="w-8 h-8 text-rose-600 dark:text-rose-400" />
                            </div>

                            <div className="space-y-2">
                              <h3 className="text-lg font-black text-rose-700 dark:text-rose-400">حذف مجموعة الدارسين المحددة</h3>
                              <p className="text-sm sm:text-base font-normal text-slate-600 dark:text-slate-400 max-w-xl mx-auto leading-relaxed">
                                أنت توشك الآن على المباشرة في إلغاء شطب مجموعة <span className="font-black text-slate-900 dark:text-white">&quot;{groups.find(g => g.id === selectedGroupId)?.name}&quot;</span> من قاعدة تنظيم كورس &quot;{courses.find((c: any) => c.id === selectedCourseId)?.title}&quot;.
                              </p>
                            </div>

                            <Card flat padding="sm" className="max-w-md mx-auto text-right">
                               <div className="flex gap-2 text-sm text-rose-600 dark:text-rose-400 font-black">
                                  <span>⚠️</span>
                                  <h6>ملاحظة أمنية بالغة:</h6>
                               </div>
                               <p className="text-xs leading-relaxed font-normal text-slate-500 dark:text-slate-400 mt-1">
                                 سيؤدي هذا الشطب لإسقاط الجداول وإخفاء التواريخ المرتبطة مباشرة بحسابات وواجهات الطلبة المتابعين لهذه الحصص التنشيطية بشكل فوري.
                               </p>
                            </Card>

                            <div className="pt-4 flex flex-col sm:flex-row justify-center gap-3.5">
                              <Button
                                type="button"
                                size="lg"
                                variant="danger"
                                icon={Trash2}
                                onClick={() => setShowConfirmDeleteAlert(true)}
                                className="flex-1 shadow-lg shadow-red-500/20 hover:shadow-xl hover:shadow-red-500/30 transition-shadow"
                              >
                                طلب حذف وتصفية المجموعة
                              </Button>

                              <Button
                                type="button"
                                size="lg"
                                variant="secondary"
                                onClick={() => setActiveTab('edit')}
                                className="flex-1"
                              >
                                إلغاء والعودة للتعديل
                              </Button>
                            </div>
                          </motion.div>
                        ) : (
                          <motion.div
                            key="final_confirm_action"
                            initial={{ opacity: 0, y: 15 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0 }}
                            className="p-6 sm:p-8 rounded-panel bg-rose-50 dark:bg-rose-500/10 border-2 border-rose-200 dark:border-rose-500/30 text-center space-y-6"
                          >
                            <div className="w-16 h-16 bg-rose-100 dark:bg-rose-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                              <AlertTriangle className="w-8 h-8 text-rose-600 dark:text-rose-400" />
                            </div>

                            <div className="space-y-2">
                              <h3 className="text-xl font-black text-rose-700 dark:text-rose-400">تأكيد نهائي وحاسم للعملية</h3>
                              <p className="text-sm sm:text-base text-slate-600 dark:text-slate-400 font-medium leading-relaxed">
                                هل بالتأكيد ترغب في المتابعة؟ هذا هو الإجراء الأخير لتأكيد الإزالة وسوف تُنفذ التغييرات فوراً.
                              </p>
                            </div>

                            <div className="pt-4 flex flex-col sm:flex-row justify-center gap-3.5">
                              <Button
                                type="button"
                                size="lg"
                                variant="danger"
                                disabled={isDeleting}
                                isLoading={isDeleting}
                                loadingText="جاري الحذف..."
                                onClick={executeGroupDeletion}
                                className="flex-1 shadow-lg shadow-red-500/20 hover:shadow-xl hover:shadow-red-500/30 transition-shadow"
                              >
                                نعم، احذف نهائياً بلا رجوع
                              </Button>

                              <Button
                                type="button"
                                size="lg"
                                variant="secondary"
                                onClick={() => setShowConfirmDeleteAlert(false)}
                                className="flex-1"
                              >
                                إلغاء الإجراء، البقاء عليها
                              </Button>
                            </div>
                          </motion.div>
                        )}
                      </AnimatePresence>
                    </div>
                  ) : (
                    <EmptyState size="sm" icon={Users} title="يرجى تحديد مجموعة صالحة للمتابعة." />
                  )
                ) : null}

              </motion.div>
            )}

          </AnimatePresence>
        </div>
        )}
      </AnimatePresence>

    </div>
  );
}
