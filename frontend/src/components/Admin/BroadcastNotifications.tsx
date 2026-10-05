import React, { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { Bell, Plus, Trash2, Edit3, AlertTriangle, Calendar, Clock, Link2, Users, Power, X, FileText, Check, Send, ShieldAlert, Radio, CalendarClock, UserCog, LayoutList, PenSquare, ArrowRight, Archive, ListFilter } from 'lucide-react';
import { PlatformNotification, Teacher } from '../../types/index';
import { adminService } from '../../services/adminService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  broadcastApiService,
  BroadcastAudience,
  BroadcastPayload
} from '../../services/broadcastApiService';
import { safeUrl } from '../../utils/urlValidation';
import { formatDateTime } from '../../utils/dateUtils';
import { AVATAR_PLACEHOLDER } from '../../utils/imagePlaceholders';
import StatCard from '../Layout/StatCard';
import {
  PageHeader,
  Card,
  CardHeader,
  Button,
  Badge,
  Input,
  Textarea,
  FormField,
  SearchBox,
  EmptyState,
  ConfirmDialog,
  TablePagination,
} from '../UI';
import type { BadgeTone } from '../UI';

/** Rows per page for the broadcast list — keeps the column readable instead of stacking every result. */
const PAGE_SIZE = 10;


/**
 * Admin broadcast console.
 *
 * Every action on this screen is persisted through
 * services/broadcastApiService.ts -> /api/v1/admin/broadcasts (admin-only,
 * rate-limited). Creating a broadcast resolves its audience server-side and
 * writes one real `notifications` row per recipient, so the message genuinely
 * lands in the targeted users' notification lists; scheduling, expiry and the
 * enable/disable switch are enforced by the backend read gate in
 * NotificationRepository::listForUser.
 *
 * (This component previously mutated local React state only and persisted
 * nothing — it announced "تم إنشاء وبث التنبيه" for a message that reached
 * nobody and vanished on refresh. That is now real.)
 */
export default function BroadcastNotifications() {
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [notifications, setNotifications] = useState<PlatformNotification[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    const fetchTeachers = async () => {
      try {
        const data = await adminService.getAllTeachers();
        setTeachers(data);
      } catch (err) {
        console.error('Failed to fetch teachers:', err);
      }
    };
    fetchTeachers();
  }, []);

  const loadBroadcasts = async () => {
    try {
      setLoadError(null);
      const data = await broadcastApiService.listBroadcasts();
      setNotifications(data);
    } catch (err) {
      console.error('Failed to fetch broadcasts:', err);
      setLoadError('تعذّر تحميل قائمة التعاميم البثية من الخادم');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadBroadcasts();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Handle live updating of date status (Optional, but great for realism)
  const [nowTime, setNowTime] = useState(new Date());
  useEffect(() => {
    const timer = setInterval(() => setNowTime(new Date()), 30000); // refresh every 30s
    return () => clearInterval(timer);
  }, []);

  // -------------------------------------------------------------
  // Filter and Search States
  // -------------------------------------------------------------
  const [filterStatus, setFilterStatus] = useState<'all' | 'active' | 'scheduled' | 'expired' | 'disabled'>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [page, setPage] = useState(1);

  // Which workspace panel is in view — a purely presentational switch (this
  // screen used to show the form and the list side by side at all times; the
  // redesign puts them in one focused workspace and a rail toggles between
  // them). Does not affect any persisted state or API call.
  const [viewMode, setViewMode] = useState<'compose' | 'manage'>('manage');

  // -------------------------------------------------------------
  // Form States (for Create & Edit modes)
  // -------------------------------------------------------------
  const [isEditing, setIsEditing] = useState<boolean>(false);
  const [editId, setEditId] = useState<string | null>(null);

  const [formTitle, setFormTitle] = useState('');
  const [formContent, setFormContent] = useState('');
  const [formUrl, setFormUrl] = useState('');
  
  // Safe defaults for dates matching local timezone format
  const getLocalDateTimeString = (date: Date) => {
    const tzOffset = date.getTimezoneOffset() * 60000; // offset in milliseconds
    const localISOTime = (new Date(date.getTime() - tzOffset)).toISOString().slice(0, 16);
    return localISOTime;
  };

  const [formStartDate, setFormStartDate] = useState(() => getLocalDateTimeString(new Date()));
  const [formEndDate, setFormEndDate] = useState('');
  
  const [formAudience, setFormAudience] = useState<PlatformNotification['targetAudience']>('all_teachers');
  const [selectedTeacherIds, setSelectedTeacherIds] = useState<string[]>([]);
  const [specificUserInput, setSpecificUserInput] = useState('');

  // Search inside Teacher Select block
  const [teacherSearch, setTeacherSearch] = useState('');

  // -------------------------------------------------------------
  // Feedback Messages
  // -------------------------------------------------------------
  const { notify } = useCenteredNotification();
  const showNotificationToast = (text: string, mode: 'success' | 'warn' | 'danger' = 'success') => {
    notify(text, mode === 'success' ? 'success' : 'error');
  };

  // -------------------------------------------------------------
  // Delete Modal States
  // -------------------------------------------------------------
  const [deleteTarget, setDeleteTarget] = useState<PlatformNotification | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  // -------------------------------------------------------------
  // Calculated Status Logic Helper
  // -------------------------------------------------------------
  const calculateStatus = (notif: PlatformNotification): 'active' | 'scheduled' | 'expired' | 'disabled' => {
    if (!notif.isActive) return 'disabled';
    
    const start = new Date(notif.startDate || '');
    if (nowTime < start) return 'scheduled';

    if (notif.endDate) {
      const end = new Date(notif.endDate || '');
      if (nowTime > end) return 'expired';
    }

    return 'active';
  };

  // -------------------------------------------------------------
  // UI Format Helpers
  // -------------------------------------------------------------
  const formatAudienceArabic = (notif: PlatformNotification) => {
    switch (notif.targetAudience) {
      case 'all_teachers':
        return 'كل المعلمين والأساتذة';
      case 'all_staff':
        return 'كافة الإداريين والمشرفين';
      case 'selected_teachers':
        const names = teachers
          .filter(t => notif.selectedTeachers?.includes(t.id))
          .map(t => t.full_name || t.name);
        return `مدرسون محددون: [${names.length > 0 ? names.join('، ') : 'لم يتم تحديد أحد'}]`;
      case 'specific_user':
        return `مستخدم محدد: @${notif.specificUser || 'بدون كنية'}`;
      default:
        return 'غير معروف';
    }
  };

  // Same options as the shared formatDateTime, so it delegates rather than
  // re-declaring the `ar-EG-u-nu-latn` tag; only the empty-value fallback
  // ("مستمر" = open-ended, for a broadcast with no start/end bound) is local.
  const formatDateStringArabic = (dateStr: string) =>
    dateStr ? formatDateTime(dateStr) : 'مستمر';

  // -------------------------------------------------------------
  // Form submission handler (Insert / Update)
  // -------------------------------------------------------------
  /**
   * Pull the first useful message out of an axios error. The backend answers
   * a failed validation with 422 {success:false, message, data:{errors:{field:[..]}}},
   * so the specific field message (bad URL, unknown target user, ...) is
   * surfaced to the admin instead of a generic failure.
   */
  const describeError = (err: unknown, fallback: string): string => {
    const response = (err as { response?: { status?: number; data?: { message?: string; data?: { errors?: Record<string, string[]> } } } }).response;
    if (response?.status === 429) {
      return 'تم تجاوز الحد المسموح من عمليات البث. يرجى المحاولة بعد قليل.';
    }
    if (response?.status === 403) {
      return 'ليست لديك صلاحية تنفيذ هذا الإجراء';
    }
    const errors = response?.data?.data?.errors;
    if (errors) {
      const first = Object.values(errors).flat()[0];
      if (first) return first;
    }
    return response?.data?.message || fallback;
  };

  /** Map the form state onto the request body the backend expects. */
  const buildPayload = (): BroadcastPayload => ({
    title: formTitle.trim(),
    body: formContent.trim(),
    link: formUrl.trim() || null,
    audience: formAudience as BroadcastAudience,
    start_at: formStartDate,
    end_at: formEndDate || null,
    ...(formAudience === 'selected_teachers' ? { target_user_ids: selectedTeacherIds } : {}),
    ...(formAudience === 'specific_user' ? { specific_user: specificUserInput.trim() } : {})
  });

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (isSubmitting) return;

    if (!formTitle.trim()) {
      showNotificationToast('يرجى تحديد عنوان إداري داخلي للتنبيه', 'warn');
      return;
    }
    if (!formContent.trim()) {
      showNotificationToast('محتوى البث لا يمكن أن يكون فارغاً', 'warn');
      return;
    }
    if (!formStartDate) {
      showNotificationToast('يرجى تحديد وقت بدء البث الفعلي', 'warn');
      return;
    }
    if (formEndDate && new Date(formEndDate) <= new Date(formStartDate)) {
      showNotificationToast('تاريخ الانتهاء يجب أن يكون بعد تاريخ البدء', 'warn');
      return;
    }

    if (formAudience === 'selected_teachers' && selectedTeacherIds.length === 0) {
      showNotificationToast('يرجى اختيار معلم واحد على الأقل للجمهور المستهدف', 'warn');
      return;
    }
    if (formAudience === 'specific_user' && !specificUserInput.trim()) {
      showNotificationToast('يرجى كتابة اسم أو كنية المستخدم المستهدف بالنظام', 'warn');
      return;
    }

    // The checks above are convenience only — the same rules (and the ones a
    // browser cannot enforce: the target users really existing and holding the
    // right role, the link passing the URL allow-list) are re-applied
    // server-side by BroadcastNotificationService::validate.
    setIsSubmitting(true);
    try {
      if (isEditing && editId) {
        const updated = await broadcastApiService.updateBroadcast(editId, buildPayload());
        // Only now, after the server confirmed the write, is the list updated
        // and the success toast shown.
        setNotifications(prev => prev.map(n => (n.id === editId ? updated : n)));
        showNotificationToast('تم تعديل التنبيه البثي وحفظ التحديثات بنجاح', 'success');
      } else {
        const created = await broadcastApiService.createBroadcast(buildPayload());
        setNotifications(prev => [created, ...prev]);
        showNotificationToast('تم إنشاء وبث التنبيه الجديد للجمهور المستهدف', 'success');
      }
      resetForm();
    } catch (err) {
      showNotificationToast(describeError(err, 'تعذّر حفظ التنبيه البثي'), 'danger');
    } finally {
      setIsSubmitting(false);
    }
  };

  // -------------------------------------------------------------
  // Cancel and Reset Form
  // -------------------------------------------------------------
  const resetForm = () => {
    setIsEditing(false);
    setEditId(null);
    setFormTitle('');
    setFormContent('');
    setFormUrl('');
    setFormStartDate(getLocalDateTimeString(new Date()));
    setFormEndDate('');
    setFormAudience('all_teachers');
    setSelectedTeacherIds([]);
    setSpecificUserInput('');
    setTeacherSearch('');
  };

  // -------------------------------------------------------------
  // Trigger Edit Mode
  // -------------------------------------------------------------
  const handleEditClick = (notif: PlatformNotification) => {
    setIsEditing(true);
    setEditId(notif.id);
    setFormTitle(notif.title);
    setFormContent(notif.content || '');
    setFormUrl(notif.url || '');
    setFormStartDate(notif.startDate || '');
    setFormEndDate(notif.endDate || '');
    setFormAudience(notif.targetAudience || 'all_teachers');
    setSelectedTeacherIds(notif.selectedTeachers || []);
    setSpecificUserInput(notif.specificUser || '');

    // The compose form now lives in its own workspace panel — switch to it
    // so the admin actually sees the fields they're about to edit.
    setViewMode('compose');

    // Smooth scroll up to form on mobile devices
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // -------------------------------------------------------------
  // Toggle Active State
  // -------------------------------------------------------------
  const handleToggleActive = async (id: string) => {
    const current = notifications.find(n => n.id === id);
    if (!current) return;
    const nextState = !current.isActive;

    try {
      // Disabling really removes the broadcast from its targets'
      // GET /notifications (the read gate gates on is_active), so this is not
      // a display-only switch.
      const updated = await broadcastApiService.setBroadcastActive(id, nextState);
      setNotifications(prev => prev.map(n => (n.id === id ? updated : n)));
      showNotificationToast(
        nextState ? 'تم إعادة تفعيل بث التنبيه' : 'تم إيقاف وتعطيل التنبيه بنجاح',
        nextState ? 'success' : 'warn'
      );
    } catch (err) {
      showNotificationToast(describeError(err, 'تعذّر تغيير حالة التنبيه'), 'danger');
    }
  };

  // -------------------------------------------------------------
  // Delete Execution
  // -------------------------------------------------------------
  const handleDeleteConfirm = async () => {
    if (!deleteTarget || isDeleting) return;
    const target = deleteTarget;

    setIsDeleting(true);
    try {
      // Cascades to every delivered notifications row (FK ON DELETE CASCADE),
      // so the message disappears from recipients' lists too.
      await broadcastApiService.deleteBroadcast(target.id);
      setNotifications(prev => prev.filter(n => n.id !== target.id));
      showNotificationToast(`تم إزالة التنبيه "${target.title}" بشكل نهائي`, 'danger');
      setDeleteTarget(null);
      if (editId === target.id) {
        resetForm();
      }
    } catch (err) {
      showNotificationToast(describeError(err, 'تعذّر حذف التنبيه'), 'danger');
    } finally {
      setIsDeleting(false);
    }
  };

  // -------------------------------------------------------------
  // Helper to toggle multiselect teacher lists
  // -------------------------------------------------------------
  const handleTeacherCheckboxToggle = (teacherId: string) => {
    setSelectedTeacherIds(prev => {
      if (prev.includes(teacherId)) {
        return prev.filter(id => id !== teacherId);
      } else {
        return [...prev, teacherId];
      }
    });
  };

  // Filtered teachers list to search inside dropdown
  const filteredTeachers = teachers.filter(t => {
    const teacherName = t.full_name || t.name || '';
    const teacherSpec = t.specialization || '';
    return teacherName.toLowerCase().includes(teacherSearch.toLowerCase()) || 
           teacherSpec.toLowerCase().includes(teacherSearch.toLowerCase());
  });

  // Filtered notifications to display
  const filteredNotifications = notifications.filter(notif => {
    // Status filter
    const status = calculateStatus(notif);
    if (filterStatus !== 'all' && status !== filterStatus) return false;

    // Search query
    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      const matchTitle = notif.title.toLowerCase().includes(q);
      const matchContent = (notif.content || '').toLowerCase().includes(q);
      const matchAudience = formatAudienceArabic(notif).toLowerCase().includes(q);
      const matchUser = notif.specificUser && notif.specificUser.toLowerCase().includes(q);
      return matchTitle || matchContent || matchAudience || matchUser;
    }

    return true;
  });

  // Reset back to page 1 whenever the filtered set changes shape, so
  // switching a filter/search never leaves the view stranded on an
  // out-of-range page.
  useEffect(() => {
    setPage(1);
  }, [filterStatus, searchQuery]);

  const totalPages = Math.max(1, Math.ceil(filteredNotifications.length / PAGE_SIZE));
  const pagedNotifications = filteredNotifications.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  // -------------------------------------------------------------
  // Derived summary counts (purely client-side, from the already-loaded
  // notifications list — no extra API calls).
  // -------------------------------------------------------------
  const totalBroadcastsCount = notifications.length;
  const activeBroadcastsCount = notifications.filter(n => calculateStatus(n) === 'active').length;
  const scheduledBroadcastsCount = notifications.filter(n => calculateStatus(n) === 'scheduled').length;
  const disabledBroadcastsCount = notifications.filter(n => calculateStatus(n) === 'disabled').length;
  // Also client-side derived (not part of the original stat row, but used by
  // the new vertical status rail to show a live count per filter).
  const expiredBroadcastsCount = notifications.filter(n => calculateStatus(n) === 'expired').length;

  const statusRailItems: { key: typeof filterStatus; label: string; icon: typeof Bell; count: number }[] = [
    { key: 'all', label: 'كل التعاميم', icon: ListFilter, count: totalBroadcastsCount },
    { key: 'active', label: 'نشطة الآن', icon: Radio, count: activeBroadcastsCount },
    { key: 'scheduled', label: 'مجدولة', icon: CalendarClock, count: scheduledBroadcastsCount },
    { key: 'expired', label: 'منتهية', icon: Archive, count: expiredBroadcastsCount },
    { key: 'disabled', label: 'موقوفة', icon: UserCog, count: disabledBroadcastsCount },
  ];

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-sky-500/8 dark:bg-sky-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-violet-500/6 dark:bg-violet-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* 1. TOP HEADER & BAR */}
      <PageHeader
        className="mb-0"
        icon={Bell}
        breadcrumbs={[{ label: 'لوحة البث الإشرافية العليا' }, { label: 'الاتصالات والتعاميم المباشرة' }]}
        title="بث التنبيهات العامة"
        description="نشر تنبيهات لمستخدمي المنصة"
      />

      {/* 1.5 SUMMARY STAT ROW — purely derived from the already-loaded notifications list */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <StatCard
          index={0}
          icon={Bell}
          label="إجمالي التعاميم البثية"
          value={totalBroadcastsCount}
          color="blue"
        />
        <StatCard
          index={1}
          icon={Radio}
          label="نشطة ومباشرة الآن"
          value={activeBroadcastsCount}
          color="emerald"
        />
        <StatCard
          index={2}
          icon={CalendarClock}
          label="مجدولة للبث لاحقاً"
          value={scheduledBroadcastsCount}
          color="purple"
        />
        <StatCard
          index={3}
          icon={UserCog}
          label="موقوفة إشرافياً"
          value={disabledBroadcastsCount}
          color="amber"
        />
      </div>


      {/* 2. RAIL + WORKSPACE LAYOUT — a persistent vertical mode/filter rail
          on one side, and a single focused workspace panel on the other that
          swaps between "compose" and "manage" instead of always showing both
          side by side. */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        {/* RAIL (GRID COLS 3) */}
        <motion.div
          className="lg:col-span-3 space-y-4 lg:sticky lg:top-6"
          initial={{ opacity: 0, x: 16 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ delay: 0, duration: 0.35 }}
        >
          {/* Mode switch: compose vs manage */}
          <Card padding="sm" className="space-y-1.5">
            <button
              type="button"
              onClick={() => setViewMode('compose')}
              className={`w-full flex items-center gap-3 rounded-2xl p-3 text-right transition-all ${
                viewMode === 'compose'
                  ? 'bg-gradient-to-l from-blue-600 to-indigo-500 text-white shadow-lg shadow-blue-500/25'
                  : 'hover:bg-slate-50 dark:hover:bg-slate-800/60 text-slate-700 dark:text-slate-300'
              }`}
            >
              <span className={`shrink-0 w-9 h-9 rounded-xl flex items-center justify-center ${
                viewMode === 'compose' ? 'bg-white/15' : 'bg-blue-50 dark:bg-blue-950/30 text-blue-600 dark:text-blue-400'
              }`}>
                <PenSquare className="w-4.5 h-4.5" />
              </span>
              <span className="flex-1">
                <span className="block text-sm font-black">{isEditing ? 'تعديل تنبيه' : 'إنشاء تنبيه'}</span>
                <span className={`block text-[11px] font-medium ${viewMode === 'compose' ? 'text-white/80' : 'text-slate-400 dark:text-slate-500'}`}>
                  {isEditing ? 'استكمال تعديل تعميم قائم' : 'صياغة وبث تعميم جديد'}
                </span>
              </span>
            </button>

            <button
              type="button"
              onClick={() => setViewMode('manage')}
              className={`w-full flex items-center gap-3 rounded-2xl p-3 text-right transition-all ${
                viewMode === 'manage'
                  ? 'bg-gradient-to-l from-blue-600 to-indigo-500 text-white shadow-lg shadow-blue-500/25'
                  : 'hover:bg-slate-50 dark:hover:bg-slate-800/60 text-slate-700 dark:text-slate-300'
              }`}
            >
              <span className={`shrink-0 w-9 h-9 rounded-xl flex items-center justify-center ${
                viewMode === 'manage' ? 'bg-white/15' : 'bg-blue-50 dark:bg-blue-950/30 text-blue-600 dark:text-blue-400'
              }`}>
                <LayoutList className="w-4.5 h-4.5" />
              </span>
              <span className="flex-1">
                <span className="block text-sm font-black">إدارة التعاميم</span>
                <span className={`block text-[11px] font-medium ${viewMode === 'manage' ? 'text-white/80' : 'text-slate-400 dark:text-slate-500'}`}>
                  استعراض وتصفية كل التنبيهات
                </span>
              </span>
            </button>
          </Card>

          {/* Vertical status filter rail — only meaningful while managing */}
          {viewMode === 'manage' && (
            <Card padding="sm" className="space-y-1">
              <div className="flex items-center gap-1.5 px-1.5 pb-1.5 pt-0.5">
                <ListFilter className="w-3.5 h-3.5 text-slate-400" />
                <span className="text-[11px] font-black text-slate-500 dark:text-slate-400">تصفية حسب الحالة</span>
              </div>
              {statusRailItems.map((item) => {
                const Icon = item.icon;
                const active = filterStatus === item.key;
                return (
                  <button
                    key={item.key}
                    type="button"
                    onClick={() => setFilterStatus(item.key)}
                    className={`w-full flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-right transition-colors ${
                      active
                        ? 'bg-blue-50 dark:bg-blue-950/30 text-blue-700 dark:text-blue-300'
                        : 'hover:bg-slate-50 dark:hover:bg-slate-800/50 text-slate-600 dark:text-slate-400'
                    }`}
                  >
                    <span className="flex items-center gap-2">
                      <Icon className={`w-3.5 h-3.5 ${active ? 'text-blue-600 dark:text-blue-400' : 'text-slate-400'}`} />
                      <span className="text-xs font-bold">{item.label}</span>
                    </span>
                    <span className={`text-[10px] font-mono font-black rounded-md px-1.5 py-0.5 ${
                      active ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'
                    }`}>
                      {item.count}
                    </span>
                  </button>
                );
              })}
            </Card>
          )}

          {/* Quick launcher — jump to a fresh compose form from anywhere */}
          {viewMode === 'manage' && (
            <Button
              variant="secondary"
              size="sm"
              icon={Plus}
              fullWidth
              onClick={() => { resetForm(); setViewMode('compose'); }}
            >
              تعميم جديد
            </Button>
          )}
        </motion.div>

        {/* WORKSPACE (GRID COLS 9) */}
        <div className="lg:col-span-9">
          <AnimatePresence mode="wait">
            {viewMode === 'compose' ? (
              <motion.div
                key="compose"
                initial={{ opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -8 }}
                transition={{ duration: 0.25 }}
              >
                <Card className="relative overflow-hidden">

                  {/* Visual Header Decoration */}
                  <div className="absolute top-0 right-0 left-0 h-1.5 bg-gradient-to-l from-blue-600 to-indigo-500" />

                  <CardHeader
                    className="mt-1"
                    icon={isEditing ? Edit3 : Plus}
                    title={isEditing ? 'تعديل التنبيه الرقمي' : 'إنشاء بث تنبيه جديد'}
                    description={isEditing ? 'تحديث البيانات المحددة للتعميم الإداري' : 'أرسل إعلاناً يظهر للمستهدفين في حساباتهم'}
                    action={
                      <div className="flex items-center gap-2">
                        {isEditing && (
                          <Button variant="ghost" size="sm" icon={X} onClick={resetForm} className="text-rose-500 hover:text-rose-600">
                            إلغاء التعديل
                          </Button>
                        )}
                        <Button
                          variant="ghost"
                          size="sm"
                          icon={ArrowRight}
                          onClick={() => setViewMode('manage')}
                        >
                          عودة للإدارة
                        </Button>
                      </div>
                    }
                  />

                  <form onSubmit={handleSubmit} className="space-y-5">
              
              {/* Internal Title (Admin only) */}
              <FormField
                required
                label={
                  <span className="inline-flex items-center gap-1.5">
                    <ShieldAlert className="w-3.5 h-3.5 text-slate-400" />
                    العنوان الرئيسي باللوحة (عرض للمشرفين فقط)
                  </span>
                }
              >
                <Input
                  type="text"
                  required
                  value={formTitle}
                  onChange={(e) => setFormTitle(e.target.value)}
                  placeholder="مثال: تعميم تذكيري بمنتصف الفصل الدراسي"
                />
              </FormField>

              {/* Broadcast Content */}
              <FormField
                required
                label={
                  <span className="inline-flex items-center gap-1.5">
                    <FileText className="w-3.5 h-3.5 text-slate-400" />
                    نص الرسالة والمحتوى المعروض للمستخدم
                  </span>
                }
              >
                <Textarea
                  required
                  rows={4}
                  value={formContent}
                  onChange={(e) => setFormContent(e.target.value)}
                  placeholder="اكتب هنا محتوى الرسالة التوضيحية بكل دقة كما ستظهر للطلاب أو المدرسين في شريط الإعلانات..."
                  className="resize-none leading-relaxed"
                />
              </FormField>

              {/* Optional Navigation URL */}
              <FormField
                label={
                  <span className="inline-flex items-center gap-1.5">
                    <Link2 className="w-3.5 h-3.5 text-slate-400" />
                    رابط توجيهي إضافي (اختياري)
                  </span>
                }
                helperText="سيتمكن المستهدف عندما يضغط على الإعلان من الانتقال المباشر لهذا الرابط للمطالعة."
              >
                <Input
                  type="url"
                  value={formUrl}
                  onChange={(e) => setFormUrl(e.target.value)}
                  placeholder="https://sanad-academy.edu/link"
                  className="text-left"
                  dir="ltr"
                />
              </FormField>

              {/* Dates grid */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {/* Start Date */}
                <FormField
                  required
                  label={
                    <span className="inline-flex items-center gap-1.5">
                      <Calendar className="w-3.5 h-3.5 text-blue-500" />
                      تاريخ ووقت البدء
                    </span>
                  }
                >
                  <Input
                    type="datetime-local"
                    required
                    value={formStartDate}
                    onChange={(e) => setFormStartDate(e.target.value)}
                  />
                </FormField>

                {/* End Date */}
                <FormField
                  label={
                    <span className="inline-flex items-center gap-1.5">
                      <Clock className="w-3.5 h-3.5 text-rose-500" />
                      تاريخ الانتهاء (اختياري)
                    </span>
                  }
                >
                  <Input
                    type="datetime-local"
                    value={formEndDate}
                    onChange={(e) => setFormEndDate(e.target.value)}
                  />
                </FormField>
              </div>

              {/* Target Audience selection (SECTION 2 DESIGN) */}
              <div className="bg-slate-50 dark:bg-slate-950/50 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 space-y-4">
                <div className="space-y-1">
                  <h4 className="text-sm font-black text-slate-800 dark:text-slate-200">توجيه وحصر المستهدفين بالبث</h4>
                  <p className="text-[11px] font-medium text-slate-500 dark:text-slate-400">حدد الفئات التي يظهر التنبيه داخل نظامهم فقط</p>
                </div>

                {/* Audience selective radios */}
                <div className="space-y-2">
                  {[
                    { key: 'all_teachers', label: 'كافة معلمي المنصة والأساتذة', desc: 'يُبث في كافة لوحات المعلمين الحالية' },
                    { key: 'all_staff', label: 'كافة أعضاء الكادر الإداري والإشرافي', desc: 'خاص بلوحة التحكم والمشرفين الإداريين' },
                    { key: 'selected_teachers', label: 'تحديد مدرسين محددين وموثقين بالاسم', desc: 'تحديد فرعي ذكي من القائمة الرئيسية' },
                    { key: 'specific_user', label: 'مستخدم محدد بالنظام (ID / معرف فردي)', desc: 'استهداف طالب أو عضو مميز بنظام التعلم' }
                  ].map((aud) => (
                    <label 
                      key={aud.key} 
                      onClick={() => setFormAudience(aud.key as any)}
                      className={`flex items-start gap-2.5 p-2.5 rounded-xl border transition-all cursor-pointer ${
                        formAudience === aud.key 
                          ? 'bg-blue-50/50 border-blue-200 dark:bg-blue-950/20 dark:border-blue-800' 
                          : 'bg-white border-slate-100 hover:border-slate-200 dark:bg-slate-900 dark:border-slate-800/40'
                      }`}
                    >
                      <input
                        type="radio"
                        name="audience_choice"
                        checked={formAudience === aud.key}
                        onChange={() => {}} // handled by click of label safely
                        className="mt-1 h-3.5 w-3.5 shrink-0 accent-blue-600 border-slate-300 focus:ring-blue-500"
                      />
                      <div className="text-right">
                        <span className="text-xs font-black text-slate-900 dark:text-slate-100 block">{aud.label}</span>
                        <span className="text-[11px] font-medium text-slate-500 dark:text-slate-400">{aud.desc}</span>
                      </div>
                    </label>
                  ))}
                </div>

                {/* Sub-UI for targetAudience === 'selected_teachers' */}
                {formAudience === 'selected_teachers' && (
                  <div className="space-y-2.5 pt-2 border-t border-slate-200/50 dark:border-slate-800/50 animate-in slide-in-from-top-2 duration-300">
                    <label className="text-[11px] font-black text-slate-500 dark:text-slate-400 block">اختر المعلمين المراد استهدافهم بالمنشور:</label>
                    
                    {/* Inline Teacher Search */}
                    <SearchBox
                      size="sm"
                      value={teacherSearch}
                      onChange={(e) => setTeacherSearch(e.target.value)}
                      onClear={() => setTeacherSearch('')}
                      placeholder="ابحث عن اسم مدرس أو تخصص..."
                    />

                    {/* Selected Teachers Count Badge */}
                    <div className="flex flex-wrap gap-1.5">
                      {selectedTeacherIds.map(id => {
                        const teach = teachers.find(t => t.id === id);
                        return (
                          <Badge key={id} tone="brand" size="sm">
                            <span>{teach ? (teach.full_name || teach.name) : id}</span>
                            <button
                              type="button"
                              onClick={() => handleTeacherCheckboxToggle(id)}
                              aria-label="إزالة من التحديد"
                              className="text-blue-500 hover:text-blue-800 dark:hover:text-blue-200 cursor-pointer bg-transparent border-0 p-0"
                            >
                              <X className="w-2.5 h-2.5" />
                            </button>
                          </Badge>
                        );
                      })}
                      {selectedTeacherIds.length === 0 && (
                        <span className="text-[9px] font-black text-rose-500">* يرجى اختيار مدرس واحد على الأقل من القائمة أدناه</span>
                      )}
                    </div>

                    {/* Teachers selection scroll container */}
                    <div className="max-h-36 overflow-y-auto border border-slate-200 dark:border-slate-800 rounded-xl bg-white dark:bg-slate-900 divide-y divide-slate-100 dark:divide-slate-800/60 p-1">
                      {filteredTeachers.map(teacher => {
                        const checked = selectedTeacherIds.includes(teacher.id);
                        return (
                          <div 
                            key={teacher.id} 
                            onClick={() => handleTeacherCheckboxToggle(teacher.id)}
                            className="flex items-center justify-between p-2 hover:bg-slate-50 dark:hover:bg-slate-800/60 rounded-lg cursor-pointer transition-colors"
                          >
                             <div className="flex items-center gap-2">
                               <img
                                 src={teacher.avatar_url || AVATAR_PLACEHOLDER}
                                 alt=""
                                 className="w-6 h-6 rounded-full object-cover"
                                 referrerPolicy="no-referrer"
                                 onError={(e) => {
                                   e.currentTarget.onerror = null;
                                   e.currentTarget.src = AVATAR_PLACEHOLDER;
                                 }}
                               />
                               <div>
                                 <span className="text-[11px] font-black text-slate-800 dark:text-slate-200 block">{teacher.full_name || teacher.name}</span>
                                 <span className="text-[9px] font-medium text-slate-500 dark:text-slate-400 block">{teacher.specialization || teacher.role}</span>
                               </div>
                             </div>
                            <div className={`w-4 h-4 rounded-md border flex items-center justify-center transition-all shrink-0 ${
                              checked
                                ? 'bg-blue-600 border-transparent text-white'
                                : 'border-slate-300 dark:border-slate-700'
                            }`}>
                              {checked && <Check className="w-2.5 h-2.5 stroke-3" />}
                            </div>
                          </div>
                        );
                      })}
                      {filteredTeachers.length === 0 && (
                        <div className="p-3 text-center text-[11px] font-bold text-slate-500 dark:text-slate-400">لا يوجد معلّم يطابق البحث</div>
                      )}
                    </div>
                  </div>
                )}

                {/* Sub-UI for targetAudience === 'specific_user' */}
                {formAudience === 'specific_user' && (
                  <div className="space-y-2 pt-2 border-t border-slate-200/50 dark:border-slate-800/50 animate-in slide-in-from-top-2 duration-300">
                    <FormField
                      label="اسم المعرف أو كنية المستخدم المستهدف:"
                      required
                      helperText="سيظهر هذا التنبيه حصرياً في الصفحة الرئيسية وحساب هذا المعرف وحده ومطالعتها بشكل آمن للخصوصية."
                    >
                      <Input
                        type="text"
                        size="sm"
                        required
                        value={specificUserInput}
                        onChange={(e) => setSpecificUserInput(e.target.value)}
                        placeholder="مثل: user_admin_demo أو طالب_المستقبل"
                      />
                    </FormField>
                  </div>
                )}

              </div>

              {/* Action submission buttons block */}
              <div className="flex gap-3 pt-3">
                <Button
                  type="submit"
                  variant="primary"
                  icon={Send}
                  fullWidth
                  disabled={isSubmitting}
                  className="flex-1 shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
                >
                  {isSubmitting
                    ? 'جارٍ الإرسال إلى الخادم...'
                    : isEditing ? 'حفظ تعديلات البث الرقمي' : 'بث التعميم ونشره فوراً'}
                </Button>

                {isEditing && (
                  <Button type="button" variant="secondary" onClick={resetForm} className="shrink-0">
                    إلغاء التعديل
                  </Button>
                )}
              </div>

            </form>
                </Card>
              </motion.div>
            ) : (
              <motion.div
                key="manage"
                initial={{ opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -8 }}
                transition={{ duration: 0.25 }}
                className="space-y-4"
              >

                {/* Search bar for the manage workspace */}
                <Card padding="sm">
                  <SearchBox
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    onClear={() => setSearchQuery('')}
                    placeholder="ابحث بالنص أو الفئة المستهدفة..."
                  />
                </Card>

                {isLoading && (
                  <Card radius="panel" className="border-dashed">
                    <EmptyState
                      icon={Bell}
                      title="جارٍ تحميل التعاميم البثية من الخادم..."
                    />
                  </Card>
                )}

                {!isLoading && loadError && (
                  <div className="py-10 bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900 rounded-3xl text-center flex flex-col items-center justify-center gap-3">
                    <AlertTriangle className="w-8 h-8 text-rose-500" />
                    <p className="text-sm font-black text-rose-700 dark:text-rose-300">{loadError}</p>
                    <Button
                      variant="danger"
                      size="sm"
                      onClick={() => { setIsLoading(true); loadBroadcasts(); }}
                    >
                      إعادة المحاولة
                    </Button>
                  </div>
                )}

                {/* Compact list-row view — each broadcast is a single row
                    instead of a full card, so far more of the queue is
                    visible in the workspace at once. */}
                {!isLoading && !loadError && filteredNotifications.length > 0 && (
                  <Card padding="sm" className="divide-y divide-slate-100 dark:divide-slate-800/60 overflow-hidden">
                    <AnimatePresence mode="popLayout">
                      {pagedNotifications.map((notif) => {
                        const status = calculateStatus(notif);

                        // Tone mapping is written out explicitly instead of using
                        // the design system's `statusTone()` helper: that helper
                        // maps `expired` to `danger` (red), but this screen keeps
                        // an expired broadcast in NEUTRAL slate — an archived
                        // announcement is not an error, and red is reserved for
                        // "موقوف إدارياً". `scheduled` likewise keeps its brand blue.
                        const statusBadgeTone: BadgeTone =
                          status === 'active'
                            ? 'success'
                            : status === 'scheduled'
                            ? 'brand'
                            : status === 'expired'
                            ? 'neutral'
                            : 'danger';

                        const statusBadgeLabel =
                          status === 'active' ? 'نشط ومباشر'
                          : status === 'scheduled' ? 'مجدول للبث'
                          : status === 'expired' ? 'منتهى ومؤرشف'
                          : 'موقف إدارياً';

                        const statusDot =
                          status === 'active' ? 'bg-emerald-500'
                          : status === 'scheduled' ? 'bg-blue-500'
                          : status === 'expired' ? 'bg-slate-300 dark:bg-slate-700'
                          : 'bg-rose-500';

                        return (
                          <motion.div
                            layout
                            id={`notif_card_${notif.id}`}
                            key={notif.id}
                            initial={{ opacity: 0, y: 8 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -8 }}
                            transition={{ duration: 0.2 }}
                            className={`group flex flex-col gap-3 py-4 px-1.5 first:pt-1.5 last:pb-1.5 ${
                              notif.isActive ? '' : 'opacity-70'
                            }`}
                          >
                            <div className="flex items-start gap-3">
                              <span className={`mt-1.5 w-2 h-2 rounded-full shrink-0 ${statusDot}`} aria-hidden="true" />

                              <div className="flex-1 min-w-0 space-y-1.5">
                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                  <h4 className="font-extrabold text-sm text-slate-800 dark:text-slate-200 truncate">
                                    {notif.title}
                                  </h4>
                                  <Badge dot size="sm" tone={statusBadgeTone}>
                                    {statusBadgeLabel}
                                  </Badge>
                                  <span className="text-[9px] font-bold text-slate-400 dark:text-slate-500 font-mono">#{notif.id}</span>
                                </div>

                                <p className="text-xs text-slate-600 dark:text-slate-400 leading-relaxed line-clamp-2">
                                  {notif.content}
                                </p>

                                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] font-bold text-slate-400 pt-0.5">
                                  <span className="flex items-center gap-1">
                                    <Users className="w-3 h-3 text-blue-500" />
                                    <span className="text-blue-600 dark:text-blue-400">{formatAudienceArabic(notif)}</span>
                                  </span>
                                  <span className="flex items-center gap-1">
                                    <Calendar className="w-3 h-3" />
                                    <span>البدء: {formatDateStringArabic(notif.startDate || '')}</span>
                                  </span>
                                  {notif.endDate && (
                                    <span className="flex items-center gap-1">
                                      <Clock className="w-3 h-3" />
                                      <span>الانتهاء: {formatDateStringArabic(notif.endDate || '')}</span>
                                    </span>
                                  )}
                                  {notif.url && (
                                    <a
                                      href={safeUrl(notif.url)}
                                      target="_blank"
                                      rel="noopener noreferrer"
                                      className="flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:underline"
                                    >
                                      <Link2 className="w-3 h-3" />
                                      <span>الرابط المرفق</span>
                                    </a>
                                  )}
                                </div>
                              </div>

                              {/* Row actions */}
                              <div className="flex items-center gap-1 shrink-0">
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  iconOnly
                                  icon={Edit3}
                                  onClick={() => handleEditClick(notif)}
                                  className="text-indigo-600 hover:text-indigo-700 dark:text-indigo-400"
                                  title="تعديل هذا التعميم البثي"
                                  aria-label="تعديل هذا التعميم البثي"
                                />
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  iconOnly
                                  icon={Power}
                                  onClick={() => handleToggleActive(notif.id)}
                                  className={
                                    notif.isActive
                                      ? 'text-amber-600 hover:text-amber-700 dark:text-amber-400'
                                      : 'text-emerald-600 hover:text-emerald-700 dark:text-emerald-400'
                                  }
                                  title={notif.isActive ? "إيقاف البث مؤقتاً" : "إعادة تشغيل البث ونشره"}
                                  aria-label={notif.isActive ? "إيقاف البث مؤقتاً" : "إعادة تشغيل البث ونشره"}
                                />
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  iconOnly
                                  icon={Trash2}
                                  onClick={() => setDeleteTarget(notif)}
                                  className="text-rose-500 hover:text-rose-600 dark:text-rose-400"
                                  title="إزالة التعميم نهائياً من اللوحة"
                                  aria-label="إزالة التعميم نهائياً من اللوحة"
                                />
                              </div>
                            </div>
                          </motion.div>
                        );
                      })}
                    </AnimatePresence>
                  </Card>
                )}

                {!isLoading && !loadError && filteredNotifications.length === 0 && (
                  <Card radius="panel" className="border-dashed">
                    <EmptyState
                      icon={Bell}
                      title="لا يوجد تعميمات بثيّة مطابقة"
                      description={
                        searchQuery || filterStatus !== 'all'
                          ? 'جرب تعديل خيارات البحث أو مرشح فئات الحالات للحصول على نتائج.'
                          : 'ابدأ بصياغة تعاميم بثية تذكر المعلمين والطلاب بقوانين المنصة عبر زر "تعميم جديد".'}
                    />
                  </Card>
                )}

                {!isLoading && !loadError && filteredNotifications.length > 0 && (
                  <Card padding="sm">
                    <TablePagination
                      page={page}
                      totalPages={totalPages}
                      onPageChange={setPage}
                      totalItems={filteredNotifications.length}
                      pageSize={PAGE_SIZE}
                    />
                  </Card>
                )}

              </motion.div>
            )}
          </AnimatePresence>
        </div>

      </div>

      {/* 3. CONFIRMATION DELETION DIALOG */}
      <ConfirmDialog
        open={deleteTarget !== null}
        onClose={() => setDeleteTarget(null)}
        onConfirm={handleDeleteConfirm}
        isLoading={isDeleting}
        tone="danger"
        icon={AlertTriangle}
        title="تأكيد إزالة التنبيه العام"
        message="انتبه! هذا الإجراء دائم وسيختفي البث فوراً من لوحات الأعضاء"
        confirmLabel={isDeleting ? 'جارٍ الحذف...' : 'تأكيد الحذف النهائي'}
        cancelLabel="التراجع والإبقاء"
      >
        {deleteTarget && (
          <div className="space-y-4 text-right">
            <div className="bg-slate-50 dark:bg-slate-950/80 p-4 rounded-xl border border-slate-100 dark:border-slate-800">
              <h4 className="text-sm font-black text-slate-800 dark:text-slate-200">التنبيه المختار للحذف:</h4>
              <p className="text-xs font-medium text-slate-600 dark:text-slate-400 mt-1.5 leading-relaxed">
                "{deleteTarget.title}"
              </p>
              {deleteTarget.url && (
                <span className="text-[11px] font-bold text-blue-500 block mt-1">({deleteTarget.url})</span>
              )}
            </div>

            <p className="text-xs font-bold text-slate-500 dark:text-slate-400 leading-relaxed">
              هل أنت متأكد تماماً من رغبتك في حذف هذا البث التنبيهي نهائياً من القاعدة؟ لن يتمكن المعلمون أو الطلاب من قراءة محتواه مجدداً.
            </p>
          </div>
        )}
      </ConfirmDialog>

    </div>
  );
}
