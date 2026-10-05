import React, { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import type { LucideIcon } from 'lucide-react';
import {
  BookPlus,
  BookOpen,
  Trash2,
  Save,
  DollarSign,
  Sparkles,
  AlertTriangle,
  Info,
  Layers,
  GraduationCap,
  Tag,
  ArrowRight,
  Edit,
  Globe,
  Building2,
} from 'lucide-react';
import CourseCard from '../CourseCard';
import { CourseManagementProps, Course } from '../../types/index';
import { authService } from '../../services/authService';
import { validateImageFile } from '../../utils/fileValidation';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { uploadApiService } from '../../services/uploadApiService';
import { AVATAR_PLACEHOLDER, COURSE_IMAGE_PLACEHOLDER } from '../../utils/imagePlaceholders';
import { formatNumber } from '../../utils/formatMoney';
import {
  PageHeader,
  Button,
  Input,
  Select,
  Textarea,
  FormField,
  EmptyState,
  ConfirmDialog,
  cn,
} from '../UI';

type CourseAction = 'add' | 'edit' | 'delete';

interface ActionDef {
  id: CourseAction;
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
  { id: 'add', icon: BookPlus, title: 'إضافة كورس', description: 'إطلاق مساق تعليمي جديد', tone: 'rose' },
  { id: 'edit', icon: BookOpen, title: 'تعديل كورس', description: 'تحديث بيانات كورس قائم', tone: 'amber' },
  { id: 'delete', icon: Trash2, title: 'حذف كورس', description: 'إزالة كورس نهائياً', tone: 'red' },
];

/**
 * The landing picker: one clearly-labelled tile per operation instead of a
 * persistent rail, so the page opens on a single, uncluttered decision
 * rather than three simultaneous forms/lists. Ported from
 * GroupManagement.tsx's ActionCard to keep both instructor pages consistent.
 */
function ActionCard({ action, onSelect, index }: { action: ActionDef; onSelect: (id: CourseAction) => void; index: number }) {
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

/** Back-to-picker bar shown at the top of every action panel, ported from GroupManagement.tsx's PanelBackBar. */
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
 * Read-only stand-in used where the original markup rendered a plain div
 * instead of a `<select>` (when the instructor has exactly one grade / one
 * subject). Deliberately NOT a disabled Select: the original was not a form
 * control at all, and turning it into one would add a field to the form.
 */
function StaticFieldValue({
  children,
  tone = 'muted',
  className,
}: {
  children: React.ReactNode;
  /** `danger` reproduces the rose "no grades/subjects enabled" notice. */
  tone?: 'muted' | 'danger';
  className?: string;
}) {
  return (
    <div
      className={cn(
        'w-full flex items-center rounded-xl border font-medium select-none',
        tone === 'danger'
          ? 'bg-rose-50 dark:bg-rose-500/10 border-rose-200 dark:border-rose-500/30 text-rose-600 dark:text-rose-400'
          : 'bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400',
        className,
      )}
    >
      {children}
    </div>
  );
}

type CourseVisibility = 'platform' | 'center' | 'both';

const VISIBILITY_OPTIONS: Array<{ id: CourseVisibility; icon: LucideIcon; title: string; description: string }> = [
  { id: 'platform', icon: Globe, title: 'المنصة', description: 'يظهر في كتالوج المنصة العام فقط' },
  { id: 'center', icon: Building2, title: 'السنتر', description: 'حصري لطلاب سنترك، لا يظهر بالكتالوج العام' },
  { id: 'both', icon: Layers, title: 'المنصة والسنتر', description: 'يظهر بالكتالوج العام ومتاح لطلاب سنترك أيضاً' },
];

/**
 * "نطاق الكورس" — three tappable tiles instead of a plain `<select>`, same
 * selected/unselected visual language as the gender/eduType chips elsewhere
 * in the admin/instructor forms, so a 3-way choice this consequential (it
 * decides who can even see the course) reads as a real decision rather than
 * one more dropdown lost among a dozen others.
 */
function VisibilityPicker({ value, onChange }: { value: CourseVisibility; onChange: (v: CourseVisibility) => void }) {
  return (
    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
      {VISIBILITY_OPTIONS.map((opt) => {
        const Icon = opt.icon;
        const selected = value === opt.id;
        return (
          <button
            key={opt.id}
            type="button"
            aria-pressed={selected}
            onClick={() => onChange(opt.id)}
            className={cn(
              'flex flex-col items-center text-center gap-2 p-4 rounded-2xl border-2 transition-all cursor-pointer',
              'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
              selected
                ? 'border-blue-500 bg-blue-50/70 dark:bg-blue-500/10 shadow-sm'
                : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:border-slate-300 dark:hover:border-slate-700',
            )}
          >
            <div className={cn(
              'w-11 h-11 rounded-xl flex items-center justify-center transition-colors',
              selected ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/25' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
            )}>
              <Icon className="w-5 h-5" aria-hidden="true" />
            </div>
            <span className={cn('text-sm font-black', selected ? 'text-blue-700 dark:text-blue-400' : 'text-slate-700 dark:text-slate-200')}>
              {opt.title}
            </span>
            <span className="text-[11px] font-bold text-slate-400 dark:text-slate-500 leading-relaxed">
              {opt.description}
            </span>
          </button>
        );
      })}
    </div>
  );
}

/**
 * The "free course" switch. Same button, same `onClick` toggle and same
 * emerald/slate colour language as before — only the track/knob geometry is
 * normalised onto real Tailwind steps (the old markup used `h-6.5`, `h-5.5`
 * and `left-5.5`) and `role="switch"`/`aria-checked` are added so the control
 * is announced correctly.
 */
function FreeToggle({
  checked,
  onToggle,
  size = 'md',
  label,
}: {
  checked: boolean;
  onToggle: () => void;
  size?: 'sm' | 'md';
  label: string;
}) {
  const isSm = size === 'sm';
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      onClick={onToggle}
      className={cn(
        'rounded-full transition-colors relative cursor-pointer shrink-0 flex items-center',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
        isSm ? 'w-10 h-6' : 'w-12 h-7',
        checked ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-700',
      )}
    >
      <span
        className={cn(
          'rounded-full bg-white shadow-sm transition-all duration-300 absolute',
          isSm ? 'w-4 h-4' : 'w-5 h-5',
          checked ? (isSm ? 'left-1' : 'left-1') : (isSm ? 'left-5' : 'left-6'),
        )}
      />
    </button>
  );
}

// NOTE: a six-entry `STOCK_IMAGES` table of Unsplash photographs used to live
// here. Only `STOCK_IMAGES[0].url` was ever referenced (as the initial value of
// `addImageUrl`) — the picker UI that consumed the other five entries no longer
// exists, so they were dead data. Worse, that single reference meant an
// instructor who published a course WITHOUT uploading a cover silently shipped
// a stranger's stock photograph of a physics lab to students as if it were the
// real cover of the course they were paying for.
// The default is now an empty string: no image chosen means no image is sent,
// and the card renders the explicit COURSE_IMAGE_PLACEHOLDER instead.

const GRADES_LIST = [
  { label: 'الصف الأول الإعدادي', stage: 'المرحلة الإعدادية' },
  { label: 'الصف الثاني الإعدادي', stage: 'المرحلة الإعدادية' },
  { label: 'الصف الثالث الإعدادي', stage: 'المرحلة الإعدادية' },
  { label: 'الصف الأول الثانوي', stage: 'المرحلة الثانوية' },
  { label: 'الصف الثاني الثانوي', stage: 'المرحلة الثانوية' },
  { label: 'الصف الثالث الثانوي', stage: 'المرحلة الثانوية' }
];

export default function CourseManagement({ courses, onAddCourse, onEditCourse, onDeleteCourse }: CourseManagementProps) {
  const [courseActionTab, setCourseActionTab] = useState<CourseAction | null>(null);
  const { notify } = useCenteredNotification();

  const getInstructorInfo = () => {
    try {
      const currentUser = authService.getUser();
      // users.role is ENUM('student','instructor','admin') — 'teacher' is not
      // a value the database can hold, so this test never passed for a real
      // instructor. getInstructorInfo() always returned null and the grade /
      // subject pickers below silently fell back to the "not logged in at all"
      // defaults, tagging new courses with a hard-coded الصف الثالث الثانوي.
      if (currentUser && currentUser.role === 'instructor') {
        return {
          name: currentUser.fullName || currentUser.username,
          username: currentUser.username,
          // Carried through so the live course-preview card can show the
          // instructor's REAL uploaded avatar (falling back to an explicit
          // placeholder) instead of the stock portrait it used to hard-code.
          avatar_url: currentUser.avatar_url || '',
          grades: currentUser.grades || [],
          subjects: currentUser.subjects || []
        };
      }
    } catch (e) {
      console.error('Error reading currentUser:', e);
    }

    // A teacherService.getTeachers() lookup used to run here; it was a no-op
    // stub that always returned [], so it could never produce a match and
    // always fell through to null. Removed.
    return null;
  };

  const currentTeacher = getInstructorInfo();

  // Strictly use teacher's assigned grades and subjects from profile
  const availableGrades = currentTeacher?.grades && Array.isArray(currentTeacher.grades) && currentTeacher.grades.length > 0
    ? currentTeacher.grades
    : (currentTeacher ? [] : ['الصف الثالث الثانوي']); // Only fallback if not logged in at all

  const availableSubjects = currentTeacher?.subjects && Array.isArray(currentTeacher.subjects) && currentTeacher.subjects.length > 0
    ? currentTeacher.subjects
    : (currentTeacher ? [] : ['مواد عامة']); // Only fallback if not logged in at all

  // Add course specific stage & grade & subject
  const [addGrade, setAddGrade] = useState(() => availableGrades[0]);
  const [addSubject, setAddSubject] = useState(() => availableSubjects[0]);

  useEffect(() => {
    if (availableGrades.length > 0) {
      setAddGrade((prev: string) => {
        if (!prev || !availableGrades.includes(prev)) {
          return availableGrades[0];
        }
        return prev;
      });
    }
  }, [availableGrades]);

  useEffect(() => {
    if (availableSubjects.length > 0) {
      setAddSubject((prev: string) => {
        if (!prev || !availableSubjects.includes(prev)) {
          return availableSubjects[0];
        }
        return prev;
      });
    }
  }, [availableSubjects]);

  const addStage = (GRADES_LIST.find(g => g.label === addGrade)?.stage as any) || 'المرحلة الثانوية';

  // Add course form inputs
  const [addTitle, setAddTitle] = useState('');
  const [addDescription, setAddDescription] = useState('');
  const [addPrice, setAddPrice] = useState('');
  const [addDiscount, setAddDiscount] = useState('');
  const [addImageUrl, setAddImageUrl] = useState('');
  const [customImageUrl, setCustomImageUrl] = useState('');
  const [addImageFile, setAddImageFile] = useState<File | null>(null);
  const [addLessonsCount, setAddLessonsCount] = useState('12');
  const [addIsFree, setAddIsFree] = useState(false);
  const [addVisibility, setAddVisibility] = useState<'platform' | 'center' | 'both'>('platform');

  // Edit course form inputs & course selection state
  const [editingCourseId, setEditingCourseId] = useState<string | number>('');
  const [editTitle, setEditTitle] = useState('');
  const [editDescription, setEditDescription] = useState('');
  const [editPrice, setEditPrice] = useState('');
  const [editDiscount, setEditDiscount] = useState('');
  const [editImageUrl, setEditImageUrl] = useState('');
  const [editImageFile, setEditImageFile] = useState<File | null>(null);
  const [editLessonsCount, setEditLessonsCount] = useState('12');
  const [editSubject, setEditSubject] = useState('');
  const [editGrade, setEditGrade] = useState('');
  const [editIsFree, setEditIsFree] = useState(false);
  const [editVisibility, setEditVisibility] = useState<'platform' | 'center' | 'both'>('platform');

  // Delete selection state triggering the RTL deletion confirmation modal
  const [courseToDeleteId, setCourseToDeleteId] = useState<string | number | null>(null);

  // Button loading states
  const [isAdding, setIsAdding] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  const filteredCourses = courses || [];

  // Handle Add course submit — creates the course via the real backend
  // (onAddCourse -> useCourses().addCourse -> courseService.createCourse ->
  // POST /courses, see App.tsx wiring), no local mock/fake object anymore.
  const handleAddSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!addTitle.trim() || !addDescription.trim() || (!addIsFree && !addPrice)) {
      return;
    }

    const priceNum = addIsFree ? 0 : parseFloat(addPrice || '0');
    const discountNum = !addIsFree && addDiscount.trim() ? parseFloat(addDiscount) : undefined;

    if (!addIsFree && discountNum !== undefined && discountNum >= priceNum) {
      return;
    }

    setIsAdding(true);
    try {
      let imageUrl = customImageUrl.trim() ? customImageUrl.trim() : addImageUrl;
      if (addImageFile) {
        imageUrl = await uploadApiService.uploadPublicImage(addImageFile, 'banner');
      }

      const newCourse: Course = {
        id: '',
        subject: addSubject,
        grade: addGrade,
        stage: addStage,
        title: addTitle.trim(),
        description: addDescription.trim(),
        image: imageUrl,
        price: priceNum,
        discountPrice: discountNum,
        lessons: parseInt(addLessonsCount) || 10,
        students: 0,
        rating: 5.0,
        isFree: addIsFree,
        visibility: addVisibility,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString()
      };

      await onAddCourse?.(newCourse);

      // Clear state inputs
      setAddTitle('');
      setAddDescription('');
      setAddPrice('');
      setAddDiscount('');
      setCustomImageUrl('');
      setAddImageFile(null);
      setAddLessonsCount('12');
      setAddIsFree(false);
      setAddVisibility('platform');

      notify(`تم إضافة الكورس "${newCourse.title}" بنجاح!`, 'success');
    } catch (err) {
      console.error('Failed to add course:', err);
      notify('حدث خطأ أثناء إضافة الكورس، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setIsAdding(false);
    }
  };

  // Populate form for Edit course
  const handleSelectCourseForEdit = (course: Course) => {
    setEditingCourseId(course.id);
    setEditTitle(course.title);
    setEditDescription(course.description || '');
    setEditPrice(course.price?.toString() || '0');
    const dPrice = course.discountPrice !== undefined ? course.discountPrice : course.discount_price;
    setEditDiscount(dPrice ? dPrice.toString() : '');
    setEditImageUrl(course.image || '');
    setEditLessonsCount((course.lessons || 0).toString());
    setEditSubject(course.subject || addSubject);
    setEditGrade(course.grade || addGrade);
    setEditIsFree(!!course.isFree);
    setEditVisibility((course.visibility as 'platform' | 'center' | 'both') || 'platform');

  };

  // Update course submission — persists via onEditCourse -> useCourses().editCourse
  // -> courseService.updateCourse -> PUT /courses/{id}.
  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingCourseId) {
      return;
    }

    if (!editTitle.trim() || !editDescription.trim() || (!editIsFree && !editPrice)) {
      return;
    }

    const priceNum = editIsFree ? 0 : parseFloat(editPrice || '0');
    const discountNum = !editIsFree && editDiscount.trim() ? parseFloat(editDiscount) : undefined;

    if (!editIsFree && discountNum !== undefined && discountNum >= priceNum) {
      return;
    }

    setIsEditing(true);
    try {
      const oldCourse = (courses || []).find((c) => c.id === editingCourseId);

      let imageUrl = editImageUrl;
      if (editImageFile) {
        imageUrl = await uploadApiService.uploadPublicImage(editImageFile, 'banner');
      }

      const updatedCourse: Course = {
        id: editingCourseId,
        subject: editSubject,
        grade: editGrade,
        stage: (GRADES_LIST.find(g => g.label === editGrade)?.stage as any) || oldCourse?.stage || 'المرحلة الثانوية',
        title: editTitle.trim(),
        description: editDescription.trim(),
        image: imageUrl,
        price: priceNum,
        discountPrice: discountNum,
        lessons: parseInt(editLessonsCount) || 12,
        students: oldCourse?.students || 0,
        rating: oldCourse?.rating || 5.0,
        isFree: editIsFree,
        visibility: editVisibility,
        created_at: oldCourse?.created_at || new Date().toISOString(),
        updated_at: new Date().toISOString()
      };

      await onEditCourse?.(updatedCourse);
      setEditingCourseId('');
      setEditImageFile(null);

      notify(`تم تحديث بيانات الكورس "${updatedCourse.title}" بنجاح!`, 'success');
    } catch (err) {
      console.error('Failed to update course:', err);
      notify('حدث خطأ أثناء تعديل الكورس، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setIsEditing(false);
    }
  };

  // Execute actual deletion — onDeleteCourse -> useCourses().deleteCourse -> DELETE /courses/{id}.
  const confirmDelete = async () => {
    if (courseToDeleteId) {
      const deletedTitle = (courses || []).find((c) => c.id === courseToDeleteId)?.title || '';
      setIsDeleting(true);
      try {
        await onDeleteCourse?.(courseToDeleteId);
        setCourseToDeleteId(null);
        notify(`تم حذف الكورس "${deletedTitle}" بنجاح!`, 'success');
      } catch (err) {
        console.error('Failed to delete course:', err);
        notify('حدث خطأ أثناء حذف الكورس، يرجى المحاولة مرة أخرى', 'error');
      } finally {
        setIsDeleting(false);
      }
    }
  };

return (
  <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <PageHeader
        icon={Layers}
        title="إضافة وتعديل الكورسات"
        description="إدارة وتعديل كورساتك التعليمية"
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إضافة وتعديل الكورسات' }]}
      />

      <AnimatePresence mode="wait">
        {courseActionTab === null ? (
          /* LANDING PICKER — one decision at a time, nothing crowded */
          <motion.div
            key="course_action_picker"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="grid grid-cols-1 sm:grid-cols-3 gap-4"
          >
            {ACTIONS.map((action, i) => (
              <ActionCard key={action.id} action={action} index={i} onSelect={setCourseActionTab} />
            ))}
          </motion.div>
        ) : (
        <div key="course_action_panel">

          {courseActionTab === 'add' && (
            <PanelBackBar
              onBack={() => setCourseActionTab(null)}
              icon={BookPlus}
              title="إضافة كورس جديد"
              description={`بمرحلة ${addStage} - ${addGrade}`}
              tone="rose"
            />
          )}
          {courseActionTab === 'edit' && (
            <PanelBackBar
              onBack={() => setCourseActionTab(null)}
              icon={BookOpen}
              title="تعديل وتحرير الكورسات القائمة"
              description={editingCourseId ? 'عدّل بيانات الكورس المحدد ثم احفظ التغييرات' : 'اختر الكورس المطلوب تعديله من القائمة أدناه'}
              tone="amber"
            />
          )}
          {courseActionTab === 'delete' && (
            <PanelBackBar
              onBack={() => setCourseActionTab(null)}
              icon={Trash2}
              title={<span className="text-red-600 dark:text-red-400">حذف الكورسات القائمة</span>}
              description="اضغط على زر الحذف لإزالة الكورس نهائياً من على المنصة — هذا الإجراء لا يمكن التراجع عنه"
              tone="red"
            />
          )}

                    <AnimatePresence mode="wait">

                      {/* SUBTAB 1: ADD COURSE */}
                      {courseActionTab === 'add' && (
                        <motion.div
                          key="action_tab_add"
                          initial={{ opacity: 0 }}
                          animate={{ opacity: 1 }}
                          exit={{ opacity: 0 }}
                        >
                          <div className="grid grid-cols-1 lg:grid-cols-5 gap-8 lg:gap-12">
                          <form onSubmit={handleAddSubmit} className="space-y-6 lg:col-span-3">

                          <div className="space-y-6">

                            {/* Auto-controlled Subject and Grade */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                              {/* Subject */}
                              <FormField label="المادة العلمية المعتمدة">
                                {({ id }) =>
                                  availableSubjects.length > 1 ? (
                                    <Select id={id} size="lg" value={addSubject} onChange={(e) => setAddSubject(e.target.value)}>
                                      {availableSubjects.map((sub: string) => (
                                        <option key={sub} value={sub}>{sub}</option>
                                      ))}
                                    </Select>
                                  ) : availableSubjects.length === 1 ? (
                                    <StaticFieldValue className="h-12 px-4 text-base">
                                      {availableSubjects[0]}
                                    </StaticFieldValue>
                                  ) : (
                                    <StaticFieldValue tone="danger" className="h-12 px-4 text-sm font-bold">
                                      لا يوجد مواد مفعلة لحسابك حالياً.
                                    </StaticFieldValue>
                                  )
                                }
                              </FormField>

                              {/* Grade */}
                              <FormField label="الصف الدراسي المدعوم">
                                {({ id }) =>
                                  availableGrades.length > 1 ? (
                                    <Select id={id} size="lg" value={addGrade} onChange={(e) => setAddGrade(e.target.value)}>
                                      {availableGrades.map((grade: string) => (
                                        <option key={grade} value={grade}>{grade}</option>
                                      ))}
                                    </Select>
                                  ) : availableGrades.length === 1 ? (
                                    <StaticFieldValue className="h-12 px-4 text-base">
                                      {availableGrades[0]}
                                    </StaticFieldValue>
                                  ) : (
                                    <StaticFieldValue tone="danger" className="h-12 px-4 text-sm font-bold">
                                      لا يوجد صفوف مفعلة لحسابك حالياً، يرجى مراجعة الإدارة.
                                    </StaticFieldValue>
                                  )
                                }
                              </FormField>
                            </div>

                            {/* Course Title input */}
                            <FormField label="عنوان الكورس الشامل" required>
                              {({ id }) => (
                                <Input
                                  id={id}
                                  size="lg"
                                  type="text"
                                  value={addTitle}
                                  onChange={(e) => setAddTitle(e.target.value)}
                                  placeholder="مثال: الشرح التفصيلي للميكانيكا التحليلية"
                                  className="font-bold"
                                  required
                                />
                              )}
                            </FormField>

                          </div>

                          {/* Course Description */}
                          <FormField label="وصف وفهرس تفاصيل الكورس" required>
                            {({ id }) => (
                              <Textarea
                                id={id}
                                rows={4}
                                value={addDescription}
                                onChange={(e) => setAddDescription(e.target.value)}
                                placeholder="أهم الفصول، مخرجات التعلم، تفاصيل بنك الأسئلة المرفق مع الدورة"
                                required
                              />
                            )}
                          </FormField>

                          {/* Center System — course scope */}
                          <FormField label="نطاق الكورس" required>
                            {() => <VisibilityPicker value={addVisibility} onChange={setAddVisibility} />}
                          </FormField>

                          {/* Free Course Toggle */}
                          <div className="p-4 rounded-card bg-amber-50/60 dark:bg-amber-500/10 border border-amber-200/60 dark:border-amber-500/20 flex items-center justify-between gap-4">
                            <div className="space-y-1 text-right">
                              <span className="block text-sm font-black text-slate-800 dark:text-slate-200 font-sans">تفعيل خاصية مجاني 🎁</span>
                              <span className="block text-xs font-normal text-slate-500 dark:text-slate-400 leading-relaxed font-sans">
                                إذا تم تفعيل هذا الخيار، يمكن لأي طالب قام بتسجيل الدخول مشاهدة الكورس ومحاضراته بحرية دون الحاجة لاشتراك مسبق.
                              </span>
                            </div>
                            <FreeToggle
                              checked={addIsFree}
                              onToggle={() => setAddIsFree(!addIsFree)}
                              label="تفعيل خاصية مجاني"
                            />
                          </div>

                          {/* Price setup */}
                          {!addIsFree && (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">

                              {/* Original Price */}
                              <FormField label="سعر الاشتراك الأصلي للكورس (بالجنيه المصري)" required>
                                {({ id }) => (
                                  <Input
                                    id={id}
                                    size="lg"
                                    icon={DollarSign}
                                    type="number"
                                    value={addPrice}
                                    onChange={(e) => setAddPrice(e.target.value)}
                                    placeholder="250"
                                    min="0"
                                    className="font-bold"
                                    required
                                  />
                                )}
                              </FormField>

                              {/* Discounted Price */}
                              <FormField label="سعر الخصم التمكيني (اختياري)">
                                {({ id }) => (
                                  <Input
                                    id={id}
                                    size="lg"
                                    icon={DollarSign}
                                    type="number"
                                    value={addDiscount}
                                    onChange={(e) => setAddDiscount(e.target.value)}
                                    placeholder="180"
                                    min="0"
                                    className="font-bold"
                                  />
                                )}
                              </FormField>

                            </div>
                          )}

                          {/* Image choices */}
                          {/*
                            Styling only. The <input type="file"> keeps its exact
                            `accept` and inline `onChange`, so `validateImageFile()`
                            still rejects a bad file (and clears the input) before
                            any FileReader/state write happens.
                          */}
                          <FormField label="تحديد الصورة الغلاف للكورس">
                            <input
                              type="file"
                              accept="image/*"
                              onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (file) {
                                  const validation = validateImageFile(file);
                                  if (!validation.valid) {
                                    notify(validation.error!, 'error');
                                    e.target.value = '';
                                    return;
                                  }
                                  setAddImageFile(file);
                                  const reader = new FileReader();
                                  reader.onloadend = () => {
                                    setAddImageUrl(reader.result as string);
                                    setCustomImageUrl('');
                                  };
                                  reader.readAsDataURL(file);
                                }
                              }}
                              className={cn(
                                'w-full px-4 py-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700',
                                'text-slate-800 dark:text-slate-200 text-sm font-medium cursor-pointer transition-colors',
                                'hover:border-slate-400 dark:hover:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500',
                                'file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 dark:file:bg-blue-500/15 dark:file:text-blue-400 file:font-bold file:cursor-pointer hover:file:bg-blue-100 dark:hover:file:bg-blue-500/25',
                              )}
                            />
                          </FormField>

                          <Button
                            type="submit"
                            size="lg"
                            icon={BookPlus}
                            fullWidth
                            disabled={isAdding}
                            isLoading={isAdding}
                            loadingText="جاري إضافة ونشر الكورس..."
                            className="shadow-lg shadow-rose-500/20 hover:shadow-xl hover:shadow-rose-500/30 transition-shadow"
                          >
                            تأكيد ونشر الكورس على المنصة
                          </Button>

                          </form>

                          {/* Course Preview Card (Live Rendering) */}
                          <div className="lg:col-span-2 flex flex-col space-y-4">
                            <span className="text-xs font-bold text-slate-500 dark:text-slate-400">معاينة شكل المساق للطلاب قبل الإضافة</span>

                            <div className="w-full">
                              <CourseCard
                                course={{
                                  id: 'preview',
                                  subject: addSubject,
                                  grade: addGrade,
                                  stage: addStage,
                                  title: addTitle || 'عنوان الكورس الشامل',
                                  description: addDescription || 'وصف تفاصيل ومحتوى المساق سيظهر هنا للطلاب...',
                                  image: customImageUrl.trim() || addImageUrl || COURSE_IMAGE_PLACEHOLDER,
                                  price: parseFloat(addPrice) || 0,
                                  discountPrice: addDiscount.trim() ? parseFloat(addDiscount) : undefined,
                                  lessons: parseInt(addLessonsCount) || 12,
                                  students: 0,
                                  rating: 5.0,
                                  // Preview of the REAL signed-in instructor. It used to render a
                                  // stock Unsplash portrait for both branches, and an invented
                                  // person ("أحمد محمود") when no instructor was resolved — i.e.
                                  // fabricated identity data shown inside a real product screen.
                                  teacher: {
                                    name: currentTeacher?.name || 'اسم المدرس',
                                    image: currentTeacher?.avatar_url || AVATAR_PLACEHOLDER
                                  }
                                }}
                                isLoggedIn={true}
                                index={0}
                              />
                            </div>
                          </div>
                          </div>
                        </motion.div>
                      )}

                      {/* SUBTAB 2: EDIT COURSE */}
                      {courseActionTab === 'edit' && (
                        <motion.div
                          key="action_tab_edit"
                          initial={{ opacity: 0 }}
                          animate={{ opacity: 1 }}
                          exit={{ opacity: 0 }}
                          className="space-y-6"
                        >
                          <AnimatePresence mode="wait">
                          {!editingCourseId ? (
                          /* Course picker list — the form only appears once one is chosen */
                          <motion.div
                            key="edit_list"
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                          >
                            {filteredCourses.length === 0 ? (
                              <EmptyState
                                icon={Info}
                                title="لا يوجد مساقات تدريبية متاحة حالياً"
                                description={'استخدم بطاقة "إضافة" لإطلاق مساق تعليمي'}
                              />
                            ) : (
                              /* Same visual language as the public homepage's
                                 CourseCard (floating thumbnail, gradient ring,
                                 grade/subject pills, price tag) — simplified
                                 into a single "select to edit" card instead of
                                 the public card's view/subscribe buttons,
                                 which make no sense for an instructor picking
                                 their own course to edit. */
                              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-5 gap-y-24 pt-20">
                                {filteredCourses.map(course => {
                                  const priceNum = course.price !== undefined && course.price !== null ? Number(course.price) : 0;
                                  const discountVal = course.discountPrice !== undefined ? course.discountPrice : course.discount_price;
                                  const discountNum = discountVal !== undefined && discountVal !== null ? Number(discountVal) : undefined;
                                  const hasDiscount = discountNum !== undefined && discountNum >= 0 && priceNum > discountNum;

                                  return (
                                    <button
                                      type="button"
                                      key={course.id}
                                      onClick={() => handleSelectCourseForEdit(course)}
                                      className="group relative text-right cursor-pointer w-full"
                                    >
                                      <div className="absolute -inset-3 rounded-[32px] bg-gradient-to-br from-amber-400/30 via-orange-400/20 to-amber-500/30 blur-xl opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none -z-10" />

                                      {/* Floating thumbnail, straddling the card's top edge */}
                                      <div className="relative w-[85%] mx-auto h-32 -mt-20 -mb-20 z-20 rounded-2xl overflow-hidden border-[3px] border-white dark:border-slate-900 shadow-xl transition-all duration-500 group-hover:shadow-2xl group-hover:shadow-amber-500/25 group-hover:-translate-y-1">
                                        <img
                                          src={course.image}
                                          alt={course.title}
                                          className="w-full h-full object-cover transform group-hover:scale-110 transition-all duration-700"
                                          onError={(e) => {
                                            e.currentTarget.onerror = null;
                                            e.currentTarget.src = COURSE_IMAGE_PLACEHOLDER;
                                          }}
                                        />
                                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/10 to-transparent pointer-events-none" />
                                      </div>

                                      <div className="relative rounded-[22px] p-[1.5px] bg-gradient-to-br from-amber-300 via-orange-300 to-amber-400 dark:from-amber-500 dark:via-orange-500 dark:to-amber-600">
                                        <div className="relative flex flex-col rounded-[20.5px] bg-white dark:bg-slate-900 p-3 pt-16 overflow-hidden shadow-lg group-hover:shadow-2xl group-hover:shadow-amber-500/15 transition-shadow duration-500">
                                          <div className="flex flex-wrap gap-1.5 justify-center mb-2.5">
                                            {course.grade && (
                                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 text-[11px] font-bold border border-indigo-200/70 dark:border-indigo-500/25">
                                                <GraduationCap className="w-3 h-3" />
                                                {course.grade}
                                              </span>
                                            )}
                                            {course.subject && (
                                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 text-[11px] font-bold border border-emerald-200/70 dark:border-emerald-500/25">
                                                <BookOpen className="w-3 h-3" />
                                                {course.subject}
                                              </span>
                                            )}
                                          </div>

                                          <h4 className="text-center text-sm font-black text-slate-900 dark:text-white truncate mb-1">{course.title}</h4>
                                          <p className="text-center text-[11px] text-slate-500 dark:text-slate-400 font-medium line-clamp-2 leading-relaxed mb-3 px-1">{course.description}</p>

                                          <div className="flex items-center justify-center mb-3">
                                            {hasDiscount ? (
                                              <div className="flex items-center gap-1.5 bg-gradient-to-r from-rose-500 to-pink-600 px-3 py-1 rounded-lg shadow-sm">
                                                <span className="text-sm font-black text-white tabular-nums">{formatNumber(discountNum, { maximumFractionDigits: 2 })} ج.م</span>
                                                <span className="line-through text-white/60 text-[11px] font-bold tabular-nums">{formatNumber(priceNum, { maximumFractionDigits: 2 })}</span>
                                              </div>
                                            ) : priceNum > 0 ? (
                                              <div className="flex items-center gap-1 bg-gradient-to-r from-indigo-500 to-blue-600 px-3 py-1 rounded-lg shadow-sm">
                                                <Tag className="w-3 h-3 text-white/80" />
                                                <span className="text-sm font-black text-white tabular-nums">{formatNumber(priceNum, { maximumFractionDigits: 2 })} ج.م</span>
                                              </div>
                                            ) : (
                                              <div className="flex items-center gap-1 bg-gradient-to-r from-emerald-500 to-teal-600 px-3 py-1 rounded-lg shadow-sm">
                                                <Sparkles className="w-3 h-3 text-white" />
                                                <span className="text-xs font-black text-white">مجاني بالكامل</span>
                                              </div>
                                            )}
                                          </div>

                                          <div className="w-full pt-2.5 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-center gap-1.5 text-amber-600 dark:text-amber-400 font-black text-xs group-hover:text-amber-700 dark:group-hover:text-amber-300 transition-colors">
                                            <Edit className="w-3.5 h-3.5 group-hover:scale-110 transition-transform" />
                                            <span>تعديل هذا الكورس</span>
                                          </div>
                                        </div>
                                      </div>
                                    </button>
                                  );
                                })}
                              </div>
                            )}
                          </motion.div>
                          ) : (
                          /* Edit form for the selected course only */
                          <motion.form
                            key="edit_form"
                            initial={{ opacity: 0, y: 8 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0 }}
                            transition={{ duration: 0.25 }}
                            onSubmit={handleEditSubmit}
                            className="space-y-5 p-5 rounded-panel bg-amber-50/40 dark:bg-amber-500/5 border border-amber-200/60 dark:border-amber-500/20 text-right"
                          >
                                <div className="flex justify-between items-center gap-3 pb-3 border-b border-amber-200/40 dark:border-amber-500/20">
                                  <div className="flex items-center gap-1.5">
                                    <Sparkles className="w-4 h-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                    <span className="text-sm font-black text-amber-700 dark:text-amber-400">تحرير الكورس المحدد حالياً</span>
                                  </div>
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    icon={ArrowRight}
                                    onClick={() => setEditingCourseId('')}
                                    className="hover:text-rose-600 dark:hover:text-rose-400"
                                  >
                                    الرجوع لقائمة الكورسات
                                  </Button>
                                </div>

                                <div className="space-y-4">

                                  {/* Auto-controlled Subject and Grade */}
                                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    {/* Subject */}
                                    <FormField label="المادة العلمية">
                                      {({ id }) =>
                                        availableSubjects.length > 1 ? (
                                          <Select id={id} value={editSubject} onChange={(e) => setEditSubject(e.target.value)}>
                                            {availableSubjects.map((sub: string) => (
                                              <option key={sub} value={sub}>{sub}</option>
                                            ))}
                                          </Select>
                                        ) : (
                                          <StaticFieldValue className="h-10 px-3.5 text-sm">
                                            {availableSubjects[0]}
                                          </StaticFieldValue>
                                        )
                                      }
                                    </FormField>

                                    {/* Grade */}
                                    <FormField label="الصف الدراسي">
                                      {({ id }) =>
                                        availableGrades.length > 1 ? (
                                          <Select id={id} value={editGrade} onChange={(e) => setEditGrade(e.target.value)}>
                                            {availableGrades.map((grade: string) => (
                                              <option key={grade} value={grade}>{grade}</option>
                                            ))}
                                          </Select>
                                        ) : (
                                          <StaticFieldValue className="h-10 px-3.5 text-sm">
                                            {availableGrades[0]}
                                          </StaticFieldValue>
                                        )
                                      }
                                    </FormField>
                                  </div>

                                  {/* Title edit */}
                                  <FormField label="عنوان الكورس الشامل" required>
                                    {({ id }) => (
                                      <Input
                                        id={id}
                                        type="text"
                                        value={editTitle}
                                        onChange={(e) => setEditTitle(e.target.value)}
                                        className="font-bold"
                                        required
                                      />
                                    )}
                                  </FormField>

                                  {/* Description edit */}
                                  <FormField label="وصف المساق" required>
                                    {({ id }) => (
                                      <Textarea
                                        id={id}
                                        rows={3}
                                        value={editDescription}
                                        onChange={(e) => setEditDescription(e.target.value)}
                                        required
                                      />
                                    )}
                                  </FormField>

                                  {/* Image Edit */}
                                  {/*
                                    Styling only. The <input type="file"> keeps its
                                    exact `accept` and inline `onChange`, so
                                    `validateImageFile()` still gates the file
                                    before any FileReader/state write.
                                  */}
                                  <FormField label="الصورة الغلاف للمساق">
                                    <>
                                      <input
                                        type="file"
                                        accept="image/*"
                                        onChange={(e) => {
                                          const file = e.target.files?.[0];
                                          if (file) {
                                            const validation = validateImageFile(file);
                                            if (!validation.valid) {
                                              notify(validation.error!, 'error');
                                              e.target.value = '';
                                              return;
                                            }
                                            setEditImageFile(file);
                                            const reader = new FileReader();
                                            reader.onloadend = () => {
                                              setEditImageUrl(reader.result as string);
                                            };
                                            reader.readAsDataURL(file);
                                          }
                                        }}
                                        className={cn(
                                          'w-full px-3.5 h-10 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700',
                                          'text-slate-800 dark:text-slate-200 text-sm font-medium cursor-pointer transition-colors',
                                          'hover:border-slate-400 dark:hover:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500',
                                          'file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 dark:file:bg-blue-500/15 dark:file:text-blue-400 file:text-xs file:font-bold file:cursor-pointer hover:file:bg-blue-100 dark:hover:file:bg-blue-500/25',
                                        )}
                                      />
                                      {editImageUrl && (
                                        <div className="mt-2 text-left">
                                          <img
                                            src={editImageUrl}
                                            alt="preview"
                                            className="w-16 h-12 object-cover rounded-lg border border-slate-200 dark:border-slate-700 shadow-e1"
                                            onError={(e) => {
                                              e.currentTarget.onerror = null;
                                              e.currentTarget.src = COURSE_IMAGE_PLACEHOLDER;
                                            }}
                                          />
                                        </div>
                                      )}
                                    </>
                                  </FormField>

                                  {/* Center System — course scope */}
                                  <FormField label="نطاق الكورس" required>
                                    {() => <VisibilityPicker value={editVisibility} onChange={setEditVisibility} />}
                                  </FormField>

                                  {/* Edit Free Course Toggle */}
                                  <div className="p-3.5 rounded-xl bg-amber-50/60 dark:bg-amber-500/10 border border-amber-200/60 dark:border-amber-500/20 flex items-center justify-between gap-3 text-right">
                                    <div className="space-y-0.5 font-sans">
                                      <span className="block text-sm font-black text-slate-800 dark:text-slate-200">تفعيل خاصية مجاني 🎁</span>
                                      <span className="block text-[11px] font-normal text-slate-500 dark:text-slate-400">
                                        يسمح لأي طالب مسجل بمطالعة المادة مجاناً دون اشتراك.
                                      </span>
                                    </div>
                                    <FreeToggle
                                      size="sm"
                                      checked={editIsFree}
                                      onToggle={() => setEditIsFree(!editIsFree)}
                                      label="تفعيل خاصية مجاني"
                                    />
                                  </div>

                                  {!editIsFree && (
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                      {/* Price edit */}
                                      <FormField label="السعر الأصلي" required>
                                        {({ id }) => (
                                          <Input
                                            id={id}
                                            type="number"
                                            value={editPrice}
                                            onChange={(e) => setEditPrice(e.target.value)}
                                            className="font-bold"
                                            required
                                          />
                                        )}
                                      </FormField>

                                      {/* Discount price list */}
                                      <FormField label="سعر الخصم">
                                        {({ id }) => (
                                          <Input
                                            id={id}
                                            type="number"
                                            value={editDiscount}
                                            onChange={(e) => setEditDiscount(e.target.value)}
                                            className="font-bold"
                                          />
                                        )}
                                      </FormField>
                                    </div>
                                  )}

                                </div>

                                {/*
                                  `variant="warning"` replaces the bespoke
                                  amber→orange gradient, keeping "edit = amber".
                                */}
                                <Button
                                  type="submit"
                                  size="lg"
                                  variant="warning"
                                  icon={Save}
                                  fullWidth
                                  disabled={isEditing}
                                  isLoading={isEditing}
                                  loadingText="جاري حفظ التعديلات..."
                                  className="shadow-lg shadow-amber-500/20 hover:shadow-xl hover:shadow-amber-500/30 transition-shadow"
                                >
                                  تطبيق وحفظ التعديلات الحالية
                                </Button>

                          </motion.form>
                          )}
                          </AnimatePresence>
                        </motion.div>
                      )}

                      {/* SUBTAB 3: DELETE COURSE */}
                      {courseActionTab === 'delete' && (
                        <motion.div
                          key="action_tab_delete"
                          initial={{ opacity: 0 }}
                          animate={{ opacity: 1 }}
                          exit={{ opacity: 0 }}
                          className="space-y-6"
                        >
                          <div className="space-y-3">
                            {filteredCourses.length === 0 ? (
                              <EmptyState icon={Info} title="لا يوجد كورسات حالية للحذف" />
                            ) : (
                              filteredCourses.map(course => (
                                <div
                                  key={course.id}
                                  className="group relative flex items-center justify-between p-4 pe-5 bg-slate-50/60 dark:bg-slate-800/30 border border-slate-200/70 dark:border-slate-800 rounded-2xl gap-4 overflow-hidden transition-all hover:bg-white dark:hover:bg-slate-800 hover:border-red-300 dark:hover:border-red-500/40 hover:shadow-md"
                                >
                                  <span className="absolute inset-y-0 right-0 w-1 bg-red-500 opacity-0 group-hover:opacity-100 transition-opacity" />
                                  <div className="flex gap-4 items-center overflow-hidden min-w-0">
                                    <div className="w-14 h-14 rounded-2xl overflow-hidden shrink-0 border border-slate-200 dark:border-slate-700 bg-slate-200 dark:bg-slate-800">
                                      <img
                                      src={course.image}
                                      alt={course.title}
                                      className="w-full h-full object-cover"
                                      onError={(e) => {
                                        e.currentTarget.onerror = null;
                                        e.currentTarget.src = COURSE_IMAGE_PLACEHOLDER;
                                      }}
                                    />
                                    </div>
                                    <div className="min-w-0">
                                      <h4 className="text-base font-black text-slate-800 dark:text-white truncate">{course.title}</h4>
                                      <p className="text-xs text-slate-500 dark:text-slate-400 font-bold truncate mt-1 inline-flex items-center gap-1">
                                        <GraduationCap className="w-3 h-3 shrink-0" />{course.grade}
                                      </p>
                                    </div>
                                  </div>

                                  <Button
                                    type="button"
                                    variant="danger"
                                    iconOnly
                                    icon={Trash2}
                                    onClick={() => setCourseToDeleteId(course.id)}
                                    aria-label="Remove learning course from SANAD platform"
                                    className="shrink-0"
                                  />
                                </div>
                              ))
                            )}
                          </div>
                        </motion.div>
                      )}

                    </AnimatePresence>
        </div>
        )}
      </AnimatePresence>

      {/* 4. MANDATED CONFIRMATION DELETE MODAL */}
      {/*
        The hand-rolled modal (backdrop + panel + two buttons) is replaced by
        the shared `ConfirmDialog`. `courseToDeleteId` was already a pure
        modal-open flag — set only by a course row's trash button and cleared
        on cancel or after a successful delete — so the trigger, the
        `confirmDelete()` call and the `isDeleting` busy state are unchanged;
        the backdrop click that used to clear it is now `onClose`.
      */}
      <ConfirmDialog
        open={courseToDeleteId !== null}
        onClose={() => setCourseToDeleteId(null)}
        onConfirm={confirmDelete}
        title="تأكيد حذف الكورس نهائياً؟"
        message={
          <>
            هل أنت متأكد من رغبتك في حذف كورس &quot;
            <strong className="text-slate-800 dark:text-slate-200 font-black">
              {(courses || []).find(c => c.id === courseToDeleteId)?.title}
            </strong>
            &quot; للمرحلة والصف المختار؟ هذا الإجراء لا يمكن التراجع عنه.
          </>
        }
        confirmLabel="حذف الكورس نهائياً"
        cancelLabel="إلغاء الأمر"
        tone="danger"
        icon={AlertTriangle}
        isLoading={isDeleting}
      />
  </div>
);
}
