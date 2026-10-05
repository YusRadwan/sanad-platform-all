import { authService } from '../../services/authService';
import React, { useState, useEffect, useMemo } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import type { LucideIcon } from 'lucide-react';
// `ChevronDown` is gone: the shared `Select` draws its own chevron, so the
// hand-positioned absolute ones are no longer needed. `Loader2` is gone too —
// the spinners it drove are now `Button`'s built-in `isLoading` state.
// `Tabs` (pill bar) is gone: replaced by the ActionCard picker + PanelBackBar
// pattern below, so `ArrowRight` is added for the back button.
import { Plus, Edit, Trash2, Calendar, CheckCircle2, GraduationCap, Video, PlayCircle, Upload, Image, ArrowRight } from 'lucide-react';
import { Group, VideoItem, VideoManagementProps } from '../../types/index';
import { groupApiService } from '../../services/groupApiService';
import { videoApiService } from '../../services/videoApiService';
import { uploadApiService } from '../../services/uploadApiService';
import { logger } from '../../services/logger';
import { validateImageFile } from '../../utils/fileValidation';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { safeUrl, isSafeUrl } from '../../utils/urlValidation';
import { resolveVideoEmbed } from '../../utils/videoEmbed';
import {
  PageHeader,
  Card,
  Button,
  Input,
  Select,
  Textarea,
  FormField,
  EmptyState,
  cn,
} from '../UI';

type VideoAction = 'add' | 'edit' | 'delete';

interface ActionDef {
  id: VideoAction;
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
  { id: 'add', icon: Plus, title: 'إضافة', description: 'إضافة فيديو جديد', tone: 'rose' },
  { id: 'edit', icon: Edit, title: 'تعديل', description: 'تعديل فيديو موجود', tone: 'amber' },
  { id: 'delete', icon: Trash2, title: 'حذف', description: 'حذف فيديو نهائياً', tone: 'red' },
];

/**
 * The landing picker: one clearly-labelled tile per operation instead of a
 * tab bar, so the page opens on a single, uncluttered decision rather than
 * three simultaneous forms/lists. Centred icon-over-label layout (no footer
 * meta/arrow row) — a plain, instantly-scannable menu of the 3 operations.
 */
function ActionCard({ action, onSelect, index }: { action: ActionDef; onSelect: (id: VideoAction) => void; index: number }) {
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
          <h2 className="text-base sm:text-lg font-black text-slate-900 dark:text-white leading-tight">{title}</h2>
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
 * One of the four on/off setting cards (thumbnail + free, in both the Add and
 * the Edit tab). Kept as a bespoke switch rather than the shared `Checkbox`:
 * it is a settings toggle with an explanatory body and an optional expanded
 * panel, not a form checkbox. This only removes the block being written out
 * four times verbatim — each caller still passes its own state setter, and the
 * two accents ("thumbnail = blue" / "free = emerald") are preserved exactly.
 */
function ToggleSettingCard({
  accent,
  title,
  description,
  checked,
  onToggle,
  children,
}: {
  accent: 'blue' | 'emerald';
  title: string;
  description: string;
  checked: boolean;
  onToggle: () => void;
  children?: React.ReactNode;
}) {
  const ACCENTS = {
    blue: {
      shell: 'bg-blue-500/10 border-blue-500/30',
      title: 'text-blue-700 dark:text-blue-400',
      track: 'bg-blue-500',
      mark: 'text-blue-500',
      ring: 'focus-visible:ring-blue-500/40',
    },
    emerald: {
      shell: 'bg-emerald-500/10 border-emerald-500/30',
      title: 'text-emerald-700 dark:text-emerald-400',
      track: 'bg-emerald-500',
      mark: 'text-emerald-500',
      ring: 'focus-visible:ring-emerald-500/40',
    },
  }[accent];

  return (
    <div
      className={cn(
        'p-5 rounded-card border transition-colors duration-300',
        checked ? ACCENTS.shell : 'bg-slate-50 dark:bg-slate-950/40 border-slate-200 dark:border-slate-800',
      )}
    >
      <div className="flex items-center justify-between gap-4">
        <div className="min-w-0">
          <span
            className={cn(
              'text-base font-black block transition-colors',
              checked ? ACCENTS.title : 'text-slate-800 dark:text-white',
            )}
          >
            {title}
          </span>
          <span className="text-[11px] text-slate-500 dark:text-slate-400 font-bold block mt-0.5">{description}</span>
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={checked}
          aria-label={title}
          onClick={onToggle}
          className={cn(
            'relative inline-flex h-8 w-14 items-center rounded-full transition-colors duration-300 shadow-sm shrink-0 cursor-pointer',
            'focus:outline-none focus-visible:ring-2',
            ACCENTS.ring,
            checked ? ACCENTS.track : 'bg-slate-300 dark:bg-slate-700 hover:bg-slate-400 dark:hover:bg-slate-600',
          )}
        >
          <span
            className={cn(
              'absolute right-1 inline-flex items-center justify-center h-6 w-6 transform rounded-full bg-white transition-transform duration-300 shadow-md',
              checked ? '-translate-x-6' : 'translate-x-0',
            )}
          >
            {checked
              ? <CheckCircle2 className={cn('w-3.5 h-3.5', ACCENTS.mark)} />
              : <div className="w-1.5 h-1.5 rounded-full bg-slate-300" />}
          </span>
        </button>
      </div>
      {children}
    </div>
  );
}

export default function VideoManagement({ courses = [] }: VideoManagementProps) {
  const [groups, setGroups] = useState<Group[]>([]);
  const [videos, setVideos] = useState<VideoItem[]>([]);

  // Null = the landing picker is shown (no operation chosen yet). This
  // replaces the old always-on 'add' tab so the page opens on the picker
  // instead of implicitly landing inside the Add form.
  const [activeTab, setActiveTab] = useState<'add' | 'edit' | 'delete' | null>(null);
  const [isAdding, setIsAdding] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  useEffect(() => {
    let isMounted = true;
    (async () => {
      try {
        const apiGroups = await groupApiService.getInstructorGroups();
        const fetchedGroups: Group[] = (apiGroups || []).map((g: any) => ({
          id: String(g.id),
          courseId: String(g.courseId || g.course_id || ''),
          name: g.name || g.title || '',
        }));
        const itemsByGroup = await Promise.all(
          fetchedGroups.map(g => videoApiService.listByGroup(g.id).catch(() => []))
        );
        if (isMounted) {
          setGroups(fetchedGroups);
          setVideos(itemsByGroup.flat());
        }
      } catch (err) {
        logger.error('Failed to load groups/videos', err as Error);
        if (isMounted) triggerFeedback('حدث خطأ أثناء تحميل الفيديوهات', 'error');
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
  const [addDuration, setAddDuration] = useState('');
  const [addProvider, setAddProvider] = useState<'Bunny' | 'YouTube' | 'Vimeo'>('YouTube');
  const [addUrl, setAddUrl] = useState('');
  const [addHasThumbnail, setAddHasThumbnail] = useState(false);
  const [addThumbnailUrl, setAddThumbnailUrl] = useState('');
  const [addStartDate, setAddStartDate] = useState('');
  const [addStartTime, setAddStartTime] = useState('');
  const [addEndDate, setAddEndDate] = useState('');
  const [addEndTime, setAddEndTime] = useState('');
  const [addIsFree, setAddIsFree] = useState(false);
  const [addShowPreview, setAddShowPreview] = useState(false);
  const [editShowPreview, setEditShowPreview] = useState(false);

  // Synchronize grade
  useEffect(() => {
    if (availableGrades.length > 0 && !availableGrades.includes(selectedGrade)) {
      setSelectedGrade(availableGrades[0]);
    }
  }, [availableGrades, selectedGrade]);

  const coursesForGrade = useMemo(() => {
    return (courses || []).filter(c => c.grade === selectedGrade);
  }, [courses, selectedGrade]);
  
  // No auto-pick of the first course: the rest of the "add video" form only
  // reveals itself once the instructor has explicitly chosen a course and a
  // group, so silently pre-selecting either here would skip that step.
  useEffect(() => {
    if (coursesForGrade.length > 0 && !coursesForGrade.find(c => c.id === addCourseId)) {
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

  // EDIT state
  const [editCourseId, setEditCourseId] = useState('');
  const [editGroupId, setEditGroupId] = useState('');
  const [editVideoId, setEditVideoId] = useState('');
  const [editTitle, setEditTitle] = useState('');
  const [editDescription, setEditDescription] = useState('');
  const [editDuration, setEditDuration] = useState('');
  const [editProvider, setEditProvider] = useState<'Bunny' | 'YouTube' | 'Vimeo'>('YouTube');
  const [editUrl, setEditUrl] = useState('');
  const [editHasThumbnail, setEditHasThumbnail] = useState(false);
  const [editThumbnailUrl, setEditThumbnailUrl] = useState('');
  const [editStartDate, setEditStartDate] = useState('');
  const [editStartTime, setEditStartTime] = useState('');
  const [editEndDate, setEditEndDate] = useState('');
  const [editEndTime, setEditEndTime] = useState('');
  const [editIsFree, setEditIsFree] = useState(false);

  useEffect(() => {
    if (!coursesForGrade.find(c => c.id === editCourseId)) {
      setEditCourseId('');
    }
  }, [coursesForGrade, editCourseId]);

  const groupsForEditCourse = useMemo(() => {
    return groups.filter(g => g.courseId === editCourseId);
  }, [groups, editCourseId]);

  useEffect(() => {
    if (!groupsForEditCourse.find(g => g.id === editGroupId)) {
      setEditGroupId('');
    }
  }, [groupsForEditCourse, editGroupId]);

  const videosForEditGroup = useMemo(() => {
    return videos.filter(v => v.groupId === editGroupId);
  }, [videos, editGroupId]);

  useEffect(() => {
    if (!videosForEditGroup.find(v => v.id === editVideoId)) {
      setEditVideoId('');
    }
  }, [videosForEditGroup, editVideoId]);

  // Populate edit fields
  useEffect(() => {
    const selectedVideo = videos.find(v => v.id === editVideoId);
    if (selectedVideo) {
      setEditTitle(selectedVideo.title);
      setEditDescription(selectedVideo.description || '');
      setEditDuration(selectedVideo.duration !== undefined ? String(selectedVideo.duration) : '');
      setEditProvider((selectedVideo.provider as "YouTube" | "Vimeo" | "Bunny") || 'YouTube');
      setEditUrl(selectedVideo.url || '');
      setEditThumbnailUrl(selectedVideo.thumbnailUrl || '');
      setEditHasThumbnail(!!selectedVideo.thumbnailUrl);
      setEditStartDate(selectedVideo.startDate || '');
      setEditStartTime(selectedVideo.startTime || '');
      setEditEndDate(selectedVideo.endDate || '');
      setEditEndTime(selectedVideo.endTime || '');
      setEditIsFree(!!selectedVideo.isFree);
    } else {
      setEditTitle('');
      setEditDescription('');
      setEditDuration('');
      setEditProvider('YouTube');
      setEditUrl('');
      setEditThumbnailUrl('');
      setEditHasThumbnail(false);
      setEditStartDate('');
      setEditStartTime('');
      setEditEndDate('');
      setEditEndTime('');
      setEditIsFree(false);
    }
  }, [editVideoId, videos]);

  // DELETE state
  const [deleteCourseId, setDeleteCourseId] = useState('');
  const [deleteGroupId, setDeleteGroupId] = useState('');
  const [deleteVideoId, setDeleteVideoId] = useState('');
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

  useEffect(() => {
    if (!coursesForGrade.find(c => c.id === deleteCourseId)) {
      setDeleteCourseId('');
    }
  }, [coursesForGrade, deleteCourseId]);

  const groupsForDeleteCourse = useMemo(() => {
    return groups.filter(g => g.courseId === deleteCourseId);
  }, [groups, deleteCourseId]);

  useEffect(() => {
    if (!groupsForDeleteCourse.find(g => g.id === deleteGroupId)) {
      setDeleteGroupId('');
    }
  }, [groupsForDeleteCourse, deleteGroupId]);

  const videosForDeleteGroup = useMemo(() => {
    return videos.filter(v => v.groupId === deleteGroupId);
  }, [videos, deleteGroupId]);

  useEffect(() => {
    if (!videosForDeleteGroup.find(v => v.id === deleteVideoId)) {
      setDeleteVideoId('');
    }
  }, [videosForDeleteGroup, deleteVideoId]);

  const selectedVideoToDelete = useMemo(() => {
    return videos.find(v => v.id === deleteVideoId);
  }, [videos, deleteVideoId]);

  const [addThumbnailFile, setAddThumbnailFile] = useState<File | null>(null);
  const [editThumbnailFile, setEditThumbnailFile] = useState<File | null>(null);

  const handleAddThumbnailUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const validation = validateImageFile(file);
      if (!validation.valid) {
        triggerFeedback(validation.error!, 'error');
        e.target.value = '';
        return;
      }
      setAddThumbnailFile(file);
      const reader = new FileReader();
      reader.onloadend = () => {
        setAddThumbnailUrl(reader.result as string);
      };
      reader.readAsDataURL(file);
    }
  };

  const handleEditThumbnailUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const validation = validateImageFile(file);
      if (!validation.valid) {
        triggerFeedback(validation.error!, 'error');
        e.target.value = '';
        return;
      }
      setEditThumbnailFile(file);
      const reader = new FileReader();
      reader.onloadend = () => {
        setEditThumbnailUrl(reader.result as string);
      };
      reader.readAsDataURL(file);
    }
  };

  /** Uploads the pending thumbnail file (if any) via POST /uploads (kind='document') and returns its real URL. */
  const resolveThumbnailUrl = async (file: File | null, hasThumbnail: boolean, existing?: string): Promise<string | null> => {
    if (!hasThumbnail) return null;
    if (!file) return existing || null;
    return await uploadApiService.uploadPublicImage(file, 'image');
  };

  // Handlers
  const handleAddSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!addCourseId || !addGroupId || !addTitle.trim() || !addUrl.trim() || !addDuration.trim()) {
      triggerFeedback('يرجى ملء جميع الحقول الإلزامية', 'error');
      return;
    }
    // Mirrors the backend allow-list (UrlValidator) so a javascript:/data:
    // URL is reported here instead of coming back as an opaque 422. The
    // backend remains the enforcement point.
    if (!isSafeUrl(addUrl.trim())) {
      triggerFeedback('رابط الفيديو غير صالح: يُسمح فقط بروابط http:// أو https://', 'error');
      return;
    }

    setIsAdding(true);
    try {
      const thumbnailUrl = await resolveThumbnailUrl(addThumbnailFile, addHasThumbnail);
      const newVideo = await videoApiService.create(addGroupId, {
        title: addTitle.trim(),
        description: addDescription.trim(),
        url: addUrl.trim(),
        provider: addProvider,
        duration: Number(addDuration.trim()),
        thumbnailUrl,
        isFree: addIsFree,
        startDate: addStartDate || undefined,
        startTime: addStartTime || undefined,
        endDate: addEndDate || undefined,
        endTime: addEndTime || undefined,
      });

      setVideos(prev => [...prev, newVideo]);

      triggerFeedback('تمت إضافة الفيديو بنجاح', 'success');

      // Reset Add Fields
      setAddTitle('');
      setAddDescription('');
      setAddDuration('');
      setAddUrl('');
      setAddHasThumbnail(false);
      setAddThumbnailUrl('');
      setAddThumbnailFile(null);
      setAddStartDate('');
      setAddStartTime('');
      setAddEndDate('');
      setAddEndTime('');
      setAddIsFree(false);
      setAddProvider('YouTube');
    } catch (err) {
      logger.error('Failed to create video', err as Error);
      triggerFeedback('حدث خطأ أثناء إضافة الفيديو', 'error');
    } finally {
      setIsAdding(false);
    }
  };

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editVideoId || !editTitle.trim() || !editUrl.trim() || !editDuration.trim()) {
      triggerFeedback('يرجى ملء جميع الحقول الإلزامية', 'error');
      return;
    }
    if (!isSafeUrl(editUrl.trim())) {
      triggerFeedback('رابط الفيديو غير صالح: يُسمح فقط بروابط http:// أو https://', 'error');
      return;
    }

    setIsEditing(true);
    try {
      const existingVideo = videos.find(v => v.id === editVideoId);
      const thumbnailUrl = await resolveThumbnailUrl(editThumbnailFile, editHasThumbnail, existingVideo?.thumbnailUrl);
      const updated = await videoApiService.update(editVideoId, {
        title: editTitle.trim(),
        description: editDescription.trim(),
        url: editUrl.trim(),
        provider: editProvider,
        duration: Number(editDuration.trim()),
        thumbnailUrl,
        isFree: editIsFree,
        startDate: editStartDate || undefined,
        startTime: editStartTime || undefined,
        endDate: editEndDate || undefined,
        endTime: editEndTime || undefined,
      });

      setVideos(prev => prev.map(v => (v.id === editVideoId ? updated : v)));
      triggerFeedback('تم تعديل الفيديو بنجاح', 'success');
    } catch (err) {
      logger.error('Failed to update video', err as Error);
      triggerFeedback('حدث خطأ أثناء تعديل الفيديو', 'error');
    } finally {
      setIsEditing(false);
    }
  };

  const handleDeleteConfirm = async () => {
    if (!deleteVideoId) return;
    setIsDeleting(true);
    try {
      await videoApiService.delete(deleteVideoId);
      setVideos(prev => prev.filter(v => v.id !== deleteVideoId));
      setShowDeleteConfirm(false);
      triggerFeedback('تم حذف الفيديو بنجاح', 'success');
    } catch (err) {
      logger.error('Failed to delete video', err as Error);
      triggerFeedback('حدث خطأ أثناء حذف الفيديو', 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  /*
    Shared Grade Selector. Kept as the same locally-declared component (same
    identity, same three call sites) so remount behaviour is unchanged; only
    the control skin moved onto `FormField` + the shared `Select`, which draws
    its own chevron. The `disabled` condition (`availableGrades.length <= 1`)
    and the `onChange` are byte-identical.
  */
  const GradeSelector = () => (
    <FormField
      className="mb-6"
      label={
        <span className="flex items-center gap-1.5">
          <GraduationCap className="w-4 h-4 text-slate-400 shrink-0" />
          الصف الدراسي
        </span>
      }
    >
      {({ id }) => (
        <Select
          id={id}
          size="lg"
          value={selectedGrade}
          onChange={(e) => setSelectedGrade(e.target.value)}
          disabled={availableGrades.length <= 1}
        >
          {availableGrades.map((g: any) => (
            <option key={g} value={g}>{g}</option>
          ))}
        </Select>
      )}
    </FormField>
  );

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Page Title — the bespoke badge + h1 become the shared `PageHeader`. */}
      <PageHeader
        icon={Video}
        title="إضافة وتعديل الفيديوهات"
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إضافة وتعديل الفيديوهات' }]}
        className="mb-0"
      />

      {/*
        LANDING PICKER — one clearly-labelled tile per operation instead of the
        old pill `Tabs` bar, so the page opens on a single, uncluttered
        decision. Choosing a tile swaps the grid for a panel containing a
        `PanelBackBar` (which resets `activeTab` back to null) followed by
        that operation's exact original form content.
      */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.08, duration: 0.35 }}
      >
      <AnimatePresence mode="wait">
        {activeTab === null ? (
          <motion.div
            key="action_picker"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="grid grid-cols-1 sm:grid-cols-3 gap-4"
          >
            {ACTIONS.map((action, i) => (
              <ActionCard key={action.id} action={action} index={i} onSelect={setActiveTab} />
            ))}
          </motion.div>
        ) : (
        <div key="action_panel">
          <AnimatePresence mode="wait">
            {/* ADD TAB */}
            {activeTab === 'add' && (
              <motion.div
                key="add_view"
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -10 }}
                transition={{ duration: 0.15 }}
                className="space-y-6"
              >
                <PanelBackBar
                  onBack={() => setActiveTab(null)}
                  icon={Plus}
                  title="إضافة فيديو جديد"
                  description="حدد الكورس والمجموعة ثم أضف رابط الفيديو وتفاصيله."
                  tone="rose"
                />

                <GradeSelector />

                <form onSubmit={handleAddSubmit} className="space-y-6">
                  {/* Selectors */}
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <FormField required label="اختر الكورس">
                      {({ id }) => (
                        <Select
                          id={id}
                          size="lg"
                          value={addCourseId}
                          onChange={(e) => setAddCourseId(e.target.value)}
                          required
                        >
                          {coursesForGrade.length === 0 ? (
                            <option value="">لا يوجد كورسات</option>
                          ) : (
                            <>
                              <option value="">-- اختر الكورس --</option>
                              {coursesForGrade.map(c => (
                                <option key={c.id} value={c.id}>{c.title}</option>
                              ))}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>

                    <FormField required label="اختر المجموعة">
                      {({ id }) => (
                        <Select
                          id={id}
                          size="lg"
                          value={addGroupId}
                          onChange={(e) => setAddGroupId(e.target.value)}
                          disabled={!addCourseId}
                          required
                        >
                          {groupsForAddCourse.length === 0 ? (
                            <option value="">لا يوجد مجموعات</option>
                          ) : (
                            <>
                              <option value="">-- اختر المجموعة --</option>
                              {groupsForAddCourse.map(g => (
                                <option key={g.id} value={g.id}>{g.name}</option>
                              ))}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>
                  </div>

                  {/* The rest of the form only appears once a course AND a
                      group are both chosen — picking the video's parent
                      before describing the video itself. */}
                  {!addGroupId ? (
                    <EmptyState
                      icon={Video}
                      title="اختر الكورس والمجموعة أولاً"
                      description="حدد الكورس والمجموعة أعلاه لإظهار بيانات الفيديو الجديد."
                    />
                  ) : (
                  <>
                  <div className="space-y-4 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <FormField required label="عنوان الفيديو">
                      {({ id }) => (
                        <Input
                          id={id}
                          size="lg"
                          type="text"
                          value={addTitle}
                          onChange={(e) => setAddTitle(e.target.value)}
                          placeholder="مثال: الباب الأول - المحاضرة الأولى"
                          className="font-bold"
                          required
                        />
                      )}
                    </FormField>

                    <FormField label="وصف الفيديو">
                      {({ id }) => (
                        <Textarea
                          id={id}
                          value={addDescription}
                          onChange={(e) => setAddDescription(e.target.value)}
                          placeholder="نبذة عن محتوى الفيديو..."
                          rows={3}
                          className="resize-none"
                        />
                      )}
                    </FormField>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <FormField required label="مدة الفيديو (بالدقائق)">
                        {({ id }) => (
                          <Input
                            id={id}
                            size="lg"
                            type="number"
                            min="1"
                            value={addDuration}
                            onChange={(e) => setAddDuration(e.target.value)}
                            placeholder="مثال: 45"
                            className="font-bold text-left"
                            dir="ltr"
                            required
                          />
                        )}
                      </FormField>

                      {/*
                        Provider picker. The three option VALUES are the exact
                        strings the provider-detection branches switch on
                        ('YouTube' | 'Vimeo' | 'Bunny') and the `as any` cast on
                        the setter is preserved — nothing about how a provider is
                        chosen or matched has changed.
                      */}
                      <FormField required label="مزود الفيديو">
                        {({ id }) => (
                          <Select
                            id={id}
                            size="lg"
                            value={addProvider}
                            onChange={(e) => setAddProvider(e.target.value as any)}
                            className="text-left"
                            dir="ltr"
                            required
                          >
                            <option value="YouTube">YouTube</option>
                            <option value="Vimeo">Vimeo</option>
                            <option value="Bunny">Bunny</option>
                          </Select>
                        )}
                      </FormField>
                    </div>

                    <div className="space-y-3">
                      <FormField required label="رابط الفيديو (URL)">
                        {({ id }) => (
                          <Input
                            id={id}
                            size="lg"
                            type="url"
                            value={addUrl}
                            onChange={(e) => {
                              setAddUrl(e.target.value);
                              if (addShowPreview) setAddShowPreview(false);
                            }}
                            placeholder="https://..."
                            className="font-bold text-left"
                            dir="ltr"
                            required
                          />
                        )}
                      </FormField>
                      {addUrl.trim() && (
                        <div className="pt-2">
                          <Button
                            type="button"
                            variant="secondary"
                            icon={PlayCircle}
                            onClick={() => setAddShowPreview(!addShowPreview)}
                          >
                            {addShowPreview ? 'إخفاء المعاينة' : 'معاينة الفيديو'}
                          </Button>
                        </div>
                      )}
                      
                      {addShowPreview && addUrl.trim() && (() => {
                        const embed = resolveVideoEmbed(addUrl, addProvider);
                        return (
                          <div className="mt-4 rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-black aspect-video relative shadow-inner ring-1 ring-white/10">
                            {embed.kind === 'youtube' ? (
                              <iframe
                                src={safeUrl(embed.src)}
                                title="Preview"
                                className="w-full h-full border-0 absolute inset-0"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowFullScreen
                              />
                            ) : embed.kind === 'vimeo' ? (
                              <iframe
                                src={safeUrl(embed.src)}
                                title="Preview"
                                className="w-full h-full border-0 absolute inset-0"
                                allow="autoplay; fullscreen; picture-in-picture"
                                allowFullScreen
                              />
                            ) : embed.kind === 'bunny-iframe' ? (
                              <iframe
                                src={safeUrl(embed.src)}
                                title="Preview"
                                className="w-full h-full border-0 absolute inset-0"
                                allow="accelerometer; autoplay; encrypted-media; picture-in-picture"
                                allowFullScreen
                              />
                            ) : (
                              <video src={safeUrl(embed.src)} controls className="w-full h-full object-cover absolute inset-0" />
                            )}
                          </div>
                        );
                      })()}
                    </div>

                    {/* Thumbnail Option Toggle & Upload */}
                    <ToggleSettingCard
                      accent="blue"
                      title="إضافة صورة غلاف الفيديو"
                      description="تفعيل هذا الخيار لرفع صورة مخصصة لغلاف الفيديو من جهازك."
                      checked={addHasThumbnail}
                      onToggle={() => setAddHasThumbnail(!addHasThumbnail)}
                    >
                      {addHasThumbnail && (
                        <div className="pt-4 mt-4 border-t border-slate-200 dark:border-slate-800/80 space-y-3">
                          <span className="text-xs font-black text-slate-600 dark:text-slate-400 block">اختر ملف صورة الغلاف من جهازك:</span>
                          <div className="flex flex-col sm:flex-row items-center gap-4">
                            {/*
                              Still a real <label>-wrapped file input (not a
                              Button) so clicking it opens the OS picker.
                              `handleAddThumbnailUpload` and the `accept` filter
                              are untouched — validation stays in that handler.
                            */}
                            <label className="relative flex flex-col items-center justify-center gap-1 px-4 py-4 rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 hover:bg-slate-50 dark:hover:bg-slate-800 hover:border-blue-400 cursor-pointer text-center w-full sm:w-auto min-w-[150px] transition-colors">
                              <Upload className="w-5 h-5" />
                              <span className="text-sm font-black">رفع صورة الغلاف</span>
                              <input
                                type="file"
                                accept="image/*"
                                onChange={handleAddThumbnailUpload}
                                className="hidden"
                              />
                            </label>
                            {addThumbnailUrl ? (
                              <div className="relative w-36 aspect-video rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-950 shadow-e1 shrink-0">
                                {/* `safeUrl()` on this src is the security boundary — untouched. */}
                                <img src={safeUrl(addThumbnailUrl)} alt="Preview" className="w-full h-full object-cover" />
                                <Button
                                  type="button"
                                  size="sm"
                                  iconOnly
                                  icon={Trash2}
                                  variant="danger"
                                  onClick={() => setAddThumbnailUrl('')}
                                  title="حذف"
                                  aria-label="حذف صورة الغلاف"
                                  className="absolute top-1.5 right-1.5 h-7 w-7 rounded-full"
                                />
                              </div>
                            ) : (
                              <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400 text-xs font-bold">
                                <Image className="w-4 h-4 text-slate-400" />
                                <span>لم يتم اختيار صورة بعد (سيتم استخدام الغلاف الافتراضي)</span>
                              </div>
                            )}
                          </div>
                        </div>
                      )}
                    </ToggleSettingCard>
                  </div>

                  {/* Free Video Toggle */}
                  <ToggleSettingCard
                    accent="emerald"
                    title="فيديو مجاني"
                    description="يمكن لأي طالب مشاهدته دون اشتراك في الكورس."
                    checked={addIsFree}
                    onToggle={() => setAddIsFree(!addIsFree)}
                  />

                  {/* Scheduling */}
                  <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-4">
                    <div className="flex items-center gap-2 pb-1 border-b border-slate-200 dark:border-slate-800">
                      <Calendar className="w-4 h-4 text-blue-500 shrink-0" />
                      <h4 className="text-sm sm:text-base font-black text-slate-800 dark:text-slate-100">جدولة وإتاحة الفيديو (اختياري)</h4>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div className="space-y-1.5">
                        <span className="text-xs font-bold text-slate-700 dark:text-slate-300 block">تاريخ وبدء إتاحة الفيديو:</span>
                        <div className="grid grid-cols-2 gap-2">
                          <Input type="date" value={addStartDate} onChange={(e) => setAddStartDate(e.target.value)} aria-label="تاريخ بدء الإتاحة" />
                          <Input type="time" value={addStartTime} onChange={(e) => setAddStartTime(e.target.value)} aria-label="وقت بدء الإتاحة" />
                        </div>
                      </div>

                      <div className="space-y-1.5">
                        <span className="text-xs font-bold text-slate-700 dark:text-slate-300 block">تاريخ ووقت إخفاء الفيديو آلياً:</span>
                        <div className="grid grid-cols-2 gap-2">
                          <Input type="date" value={addEndDate} onChange={(e) => setAddEndDate(e.target.value)} aria-label="تاريخ الإخفاء" />
                          <Input type="time" value={addEndTime} onChange={(e) => setAddEndTime(e.target.value)} aria-label="وقت الإخفاء" />
                        </div>
                      </div>
                    </div>
                  </Card>

                  <div className="pt-4 flex justify-end">
                    <Button
                      type="submit"
                      size="lg"
                      icon={Plus}
                      disabled={!addGroupId || isAdding}
                      isLoading={isAdding}
                      loadingText="جاري إضافة الفيديو..."
                      className="w-full md:w-auto shadow-lg shadow-rose-500/20 hover:shadow-xl hover:shadow-rose-500/30 transition-shadow"
                    >
                      حفظ ونشر الفيديو
                    </Button>
                  </div>
                  </>
                  )}
                </form>
              </motion.div>
            )}

            {/* EDIT TAB */}
            {activeTab === 'edit' && (
              <motion.div
                key="edit_view"
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -10 }}
                transition={{ duration: 0.15 }}
                className="space-y-6"
              >
                <PanelBackBar
                  onBack={() => setActiveTab(null)}
                  icon={Edit}
                  title="تعديل فيديو موجود"
                  description="حدد الفيديو الذي ترغب بتعديل بياناته أو إعدادات ظهوره."
                  tone="amber"
                />

                <GradeSelector />

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <FormField label="اختر الكورس">
                    {({ id }) => (
                      <Select id={id} size="lg" value={editCourseId} onChange={(e) => setEditCourseId(e.target.value)}>
                        {coursesForGrade.length === 0 ? (
                          <option value="">لا يوجد كورسات</option>
                        ) : (
                          <>
                            <option value="">-- اختر الكورس --</option>
                            {coursesForGrade.map(c => (
                              <option key={c.id} value={c.id}>{c.title}</option>
                            ))}
                          </>
                        )}
                      </Select>
                    )}
                  </FormField>

                  <FormField label="اختر المجموعة">
                    {({ id }) => (
                      <Select id={id} size="lg" value={editGroupId} onChange={(e) => setEditGroupId(e.target.value)}>
                        {groupsForEditCourse.length === 0 ? (
                          <option value="">لا يوجد مجموعات</option>
                        ) : (
                          <>
                            <option value="">-- اختر المجموعة --</option>
                            {groupsForEditCourse.map(g => (
                              <option key={g.id} value={g.id}>{g.name}</option>
                            ))}
                          </>
                        )}
                      </Select>
                    )}
                  </FormField>

                  <FormField required label="اختر الفيديو">
                    {({ id }) => (
                      <Select id={id} size="lg" value={editVideoId} onChange={(e) => setEditVideoId(e.target.value)}>
                        {videosForEditGroup.length === 0 ? (
                          <option value="">لا يوجد فيديوهات</option>
                        ) : (
                          <>
                            <option value="">-- اختر الفيديو --</option>
                            {videosForEditGroup.map(v => (
                              <option key={v.id} value={v.id}>{v.title}</option>
                            ))}
                          </>
                        )}
                      </Select>
                    )}
                  </FormField>
                </div>

                {editVideoId ? (
                  <form onSubmit={handleEditSubmit} className="space-y-6 pt-4 border-t border-slate-100 dark:border-slate-800 mt-4">
                    <div className="space-y-4">
                      <FormField required label="عنوان الفيديو">
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

                      <FormField label="وصف الفيديو">
                        {({ id }) => (
                          <Textarea
                            id={id}
                            value={editDescription}
                            onChange={(e) => setEditDescription(e.target.value)}
                            rows={3}
                            className="resize-none"
                          />
                        )}
                      </FormField>

                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <FormField required label="مدة الفيديو (بالدقائق)">
                          {({ id }) => (
                            <Input
                              id={id}
                              size="lg"
                              type="number"
                              min="1"
                              value={editDuration}
                              onChange={(e) => setEditDuration(e.target.value)}
                              className="font-bold text-left"
                              dir="ltr"
                              required
                            />
                          )}
                        </FormField>

                        {/* Same three provider values as the Add tab — see note there. */}
                        <FormField required label="مزود الفيديو">
                          {({ id }) => (
                            <Select
                              id={id}
                              size="lg"
                              value={editProvider}
                              onChange={(e) => setEditProvider(e.target.value as any)}
                              className="text-left"
                              dir="ltr"
                              required
                            >
                              <option value="YouTube">YouTube</option>
                              <option value="Vimeo">Vimeo</option>
                              <option value="Bunny">Bunny</option>
                            </Select>
                          )}
                        </FormField>
                      </div>

                      <div className="space-y-3">
                        <FormField required label="رابط الفيديو (URL)">
                          {({ id }) => (
                            <Input
                              id={id}
                              size="lg"
                              type="url"
                              value={editUrl}
                              onChange={(e) => {
                                setEditUrl(e.target.value);
                                if (editShowPreview) setEditShowPreview(false);
                              }}
                              className="font-bold text-left"
                              dir="ltr"
                              required
                            />
                          )}
                        </FormField>
                        {editUrl.trim() && (
                          <div className="pt-2">
                            <Button
                              type="button"
                              variant="secondary"
                              icon={PlayCircle}
                              onClick={() => setEditShowPreview(!editShowPreview)}
                            >
                              {editShowPreview ? 'إخفاء المعاينة' : 'معاينة الفيديو'}
                            </Button>
                          </div>
                        )}
                        
                        {editShowPreview && editUrl.trim() && (() => {
                          const embed = resolveVideoEmbed(editUrl, editProvider);
                          return (
                            <div className="mt-4 rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-black aspect-video relative shadow-inner ring-1 ring-white/10">
                              {embed.kind === 'youtube' ? (
                                <iframe
                                  src={safeUrl(embed.src)}
                                  title="Preview"
                                  className="w-full h-full border-0 absolute inset-0"
                                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                  allowFullScreen
                                />
                              ) : embed.kind === 'vimeo' ? (
                                <iframe
                                  src={safeUrl(embed.src)}
                                  title="Preview"
                                  className="w-full h-full border-0 absolute inset-0"
                                  allow="autoplay; fullscreen; picture-in-picture"
                                  allowFullScreen
                                />
                              ) : embed.kind === 'bunny-iframe' ? (
                                <iframe
                                  src={safeUrl(embed.src)}
                                  title="Preview"
                                  className="w-full h-full border-0 absolute inset-0"
                                  allow="accelerometer; autoplay; encrypted-media; picture-in-picture"
                                  allowFullScreen
                                />
                              ) : (
                                <video src={safeUrl(embed.src)} controls className="w-full h-full object-cover absolute inset-0" />
                              )}
                            </div>
                          );
                        })()}
                      </div>

                      {/* Thumbnail Option Toggle & Upload */}
                      <ToggleSettingCard
                        accent="blue"
                        title="تعديل صورة غلاف الفيديو"
                        description="تفعيل هذا الخيار لرفع صورة مخصصة لغلاف الفيديو من جهازك."
                        checked={editHasThumbnail}
                        onToggle={() => setEditHasThumbnail(!editHasThumbnail)}
                      >
                        {editHasThumbnail && (
                          <div className="pt-4 mt-4 border-t border-slate-200 dark:border-slate-800/80 space-y-3">
                            <span className="text-xs font-black text-slate-600 dark:text-slate-400 block">اختر ملف صورة الغلاف من جهازك:</span>
                            <div className="flex flex-col sm:flex-row items-center gap-4">
                              {/* `handleEditThumbnailUpload` + `accept` untouched. */}
                              <label className="relative flex flex-col items-center justify-center gap-1 px-4 py-4 rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 hover:bg-slate-50 dark:hover:bg-slate-800 hover:border-blue-400 cursor-pointer text-center w-full sm:w-auto min-w-[150px] transition-colors">
                                <Upload className="w-5 h-5" />
                                <span className="text-sm font-black">رفع صورة الغلاف</span>
                                <input
                                  type="file"
                                  accept="image/*"
                                  onChange={handleEditThumbnailUpload}
                                  className="hidden"
                                />
                              </label>
                              {editThumbnailUrl ? (
                                <div className="relative w-36 aspect-video rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-950 shadow-e1 shrink-0">
                                  {/* `safeUrl()` on this src is the security boundary — untouched. */}
                                  <img src={safeUrl(editThumbnailUrl)} alt="Preview" className="w-full h-full object-cover" />
                                  <Button
                                    type="button"
                                    size="sm"
                                    iconOnly
                                    icon={Trash2}
                                    variant="danger"
                                    onClick={() => setEditThumbnailUrl('')}
                                    title="حذف"
                                    aria-label="حذف صورة الغلاف"
                                    className="absolute top-1.5 right-1.5 h-7 w-7 rounded-full"
                                  />
                                </div>
                              ) : (
                                <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400 text-xs font-bold">
                                  <Image className="w-4 h-4 text-slate-400" />
                                  <span>لم يتم اختيار صورة بعد (سيتم استخدام الغلاف الافتراضي)</span>
                                </div>
                              )}
                            </div>
                          </div>
                        )}
                      </ToggleSettingCard>
                    </div>

                    <ToggleSettingCard
                      accent="emerald"
                      title="فيديو مجاني"
                      description="يمكن لأي طالب مشاهدته دون اشتراك في الكورس."
                      checked={editIsFree}
                      onToggle={() => setEditIsFree(!editIsFree)}
                    />

                    {/* Scheduling */}
                    <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-4">
                      <div className="flex items-center gap-2 pb-1 border-b border-slate-200 dark:border-slate-800">
                        <Calendar className="w-4 h-4 text-blue-500 shrink-0" />
                        <h4 className="text-sm sm:text-base font-black text-slate-800 dark:text-slate-100">جدولة وإتاحة الفيديو</h4>
                      </div>

                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                          <span className="text-xs font-bold text-slate-700 dark:text-slate-300 block">تاريخ وبدء إتاحة الفيديو:</span>
                          <div className="grid grid-cols-2 gap-2">
                            <Input type="date" value={editStartDate} onChange={(e) => setEditStartDate(e.target.value)} aria-label="تاريخ بدء الإتاحة" />
                            <Input type="time" value={editStartTime} onChange={(e) => setEditStartTime(e.target.value)} aria-label="وقت بدء الإتاحة" />
                          </div>
                        </div>

                        <div className="space-y-1.5">
                          <span className="text-xs font-bold text-slate-700 dark:text-slate-300 block">تاريخ ووقت إخفاء الفيديو آلياً:</span>
                          <div className="grid grid-cols-2 gap-2">
                            <Input type="date" value={editEndDate} onChange={(e) => setEditEndDate(e.target.value)} aria-label="تاريخ الإخفاء" />
                            <Input type="time" value={editEndTime} onChange={(e) => setEditEndTime(e.target.value)} aria-label="وقت الإخفاء" />
                          </div>
                        </div>
                      </div>
                    </Card>

                    {/* `variant="warning"` replaces the amber->orange gradient (this project's "edit = amber" convention). */}
                    <div className="pt-4 flex justify-end">
                      <Button
                        type="submit"
                        size="lg"
                        variant="warning"
                        icon={CheckCircle2}
                        disabled={isEditing}
                        isLoading={isEditing}
                        loadingText="جاري حفظ التعديلات..."
                        className="w-full md:w-auto shadow-lg shadow-amber-500/20 hover:shadow-xl hover:shadow-amber-500/30 transition-shadow"
                      >
                        حفظ التعديلات
                      </Button>
                    </div>
                  </form>
                ) : (
                  <EmptyState
                    className="mt-4"
                    icon={Video}
                    title="يرجى تحديد فيديو لتعديله"
                  />
                )}
              </motion.div>
            )}

            {/* DELETE TAB */}
            {activeTab === 'delete' && (
              <motion.div
                key="delete_view"
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -10 }}
                transition={{ duration: 0.15 }}
                className="space-y-6"
              >
                <PanelBackBar
                  onBack={() => setActiveTab(null)}
                  icon={Trash2}
                  title="حذف فيديو"
                  description="حدد الفيديو الذي ترغب بحذفه نهائياً من المنصة."
                  tone="red"
                />

                <GradeSelector />

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <FormField label="اختر الكورس">
                    {({ id }) => (
                      <Select id={id} size="lg" value={deleteCourseId} onChange={(e) => setDeleteCourseId(e.target.value)}>
                        {coursesForGrade.length === 0 ? (
                          <option value="">لا يوجد كورسات</option>
                        ) : (
                          <>
                            <option value="">-- اختر الكورس --</option>
                            {coursesForGrade.map(c => (
                              <option key={c.id} value={c.id}>{c.title}</option>
                            ))}
                          </>
                        )}
                      </Select>
                    )}
                  </FormField>

                  <FormField label="اختر المجموعة">
                    {({ id }) => (
                      <Select id={id} size="lg" value={deleteGroupId} onChange={(e) => setDeleteGroupId(e.target.value)}>
                        {groupsForDeleteCourse.length === 0 ? (
                          <option value="">لا يوجد مجموعات</option>
                        ) : (
                          <>
                            <option value="">-- اختر المجموعة --</option>
                            {groupsForDeleteCourse.map(g => (
                              <option key={g.id} value={g.id}>{g.name}</option>
                            ))}
                          </>
                        )}
                      </Select>
                    )}
                  </FormField>

                  <FormField required label="اختر الفيديو">
                    {({ id }) => (
                      <Select id={id} size="lg" value={deleteVideoId} onChange={(e) => setDeleteVideoId(e.target.value)}>
                        {videosForDeleteGroup.length === 0 ? (
                          <option value="">لا يوجد فيديوهات</option>
                        ) : (
                          <>
                            <option value="">-- اختر الفيديو --</option>
                            {videosForDeleteGroup.map(v => (
                              <option key={v.id} value={v.id}>{v.title}</option>
                            ))}
                          </>
                        )}
                      </Select>
                    )}
                  </FormField>
                </div>

                {/*
                  Delete stays an INLINE two-step confirm (not `ConfirmDialog`):
                  it is driven by the `delete` tab plus `showDeleteConfirm`.
                  Turning it into a modal would change that flow. Only surfaces
                  and buttons are restyled; both handlers are untouched.
                */}
                {selectedVideoToDelete && (
                  <div className="p-6 rounded-panel border border-rose-200 dark:border-rose-900/40 bg-rose-50/50 dark:bg-rose-950/20 mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div className="space-y-1 text-center sm:text-right min-w-0">
                      <h4 className="text-base font-black text-slate-900 dark:text-white">{selectedVideoToDelete.title}</h4>
                      <p className="text-sm text-slate-500 dark:text-slate-400 font-bold">المزود: {selectedVideoToDelete.provider} • المدة: {selectedVideoToDelete.duration} دقيقة</p>
                    </div>

                    {!showDeleteConfirm ? (
                      /* ARM step — soft rose, matching the original. */
                      <Button
                        variant="outline"
                        icon={Trash2}
                        onClick={() => setShowDeleteConfirm(true)}
                        className="shrink-0 bg-rose-100 text-rose-600 border-rose-200 hover:bg-rose-200 hover:border-rose-300 dark:bg-rose-900/40 dark:text-rose-400 dark:border-rose-900/50 dark:hover:bg-rose-900/60"
                      >
                        حذف الفيديو
                      </Button>
                    ) : (
                      <div className="flex items-center gap-2 shrink-0">
                        <Button
                          variant="danger"
                          onClick={handleDeleteConfirm}
                          disabled={isDeleting}
                          isLoading={isDeleting}
                          loadingText="جاري الحذف..."
                          className="shadow-lg shadow-red-500/20 hover:shadow-xl hover:shadow-red-500/30 transition-shadow"
                        >
                          تأكيد الحذف نهائياً
                        </Button>
                        <Button variant="secondary" onClick={() => setShowDeleteConfirm(false)}>
                          تراجع
                        </Button>
                      </div>
                    )}
                  </div>
                )}

                {!selectedVideoToDelete && (
                  <EmptyState
                    className="mt-4"
                    icon={Video}
                    title="يرجى تحديد فيديو لحذفه"
                  />
                )}
              </motion.div>
            )}
          </AnimatePresence>
        </div>
        )}
      </AnimatePresence>
      </motion.div>

    </div>
  );
}
