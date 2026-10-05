import { useEffect, useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import type { LucideIcon } from 'lucide-react';
import {
  Building2, Plus, Edit, Trash2, Save, ArrowRight, Users, Calendar,
  AlertTriangle, Info, Sparkles, Layers, ListPlus,
} from 'lucide-react';
import { centerService } from '../../services/centerService';
import { catalogService } from '../../services/catalogService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  PageHeader,
  Button,
  Input,
  Select,
  FormField,
  EmptyState,
  ConfirmDialog,
  Skeleton,
  cn,
} from '../UI';

/** Saturday-first week — matches CenterGroup::DAYS on the backend exactly. */
const DAYS = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];

const BILLING_TYPES: Array<'شهر' | 'حصة'> = ['شهر', 'حصة'];

/**
 * "إنشاء مجموعة" — same page shell as CourseManagement.tsx's "إضافة وتعديل
 * الكورسات" (landing action-picker + PanelBackBar + tinted per-action panel),
 * content swapped for a center group's four fields instead of a course's.
 */

interface ScheduleSlot {
  day_of_week: string;
  time: string;
}

interface CenterGroup {
  id: string;
  center_name: string;
  grade_label: string;
  group_name: string;
  schedule_text: string;
  billing_type: 'شهر' | 'حصة';
  schedules: ScheduleSlot[];
  created_at?: string;
}

interface GroupFormState {
  center_name: string;
  grade_label: string;
  group_name: string;
  billing_type: 'شهر' | 'حصة';
  schedules: ScheduleSlot[];
}

const EMPTY_FORM: GroupFormState = {
  center_name: '',
  grade_label: '',
  group_name: '',
  billing_type: 'حصة',
  schedules: [{ day_of_week: '', time: '' }],
};

type GroupAction = 'add' | 'edit' | 'delete';

interface ActionDef {
  id: GroupAction;
  icon: LucideIcon;
  title: string;
  description: string;
  tone: 'rose' | 'amber' | 'red';
}

const TONE_STYLES: Record<ActionDef['tone'], { chip: string; hoverBorder: string; panel: string; border: string }> = {
  rose: {
    chip: 'bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400',
    hoverBorder: 'hover:border-rose-300 dark:hover:border-rose-500/40',
    panel: 'bg-rose-50/40 dark:bg-rose-500/5',
    border: 'border-rose-200/60 dark:border-rose-500/20',
  },
  amber: {
    chip: 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
    hoverBorder: 'hover:border-amber-300 dark:hover:border-amber-500/40',
    panel: 'bg-amber-50/40 dark:bg-amber-500/5',
    border: 'border-amber-200/60 dark:border-amber-500/20',
  },
  red: {
    chip: 'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400',
    hoverBorder: 'hover:border-red-300 dark:hover:border-red-500/40',
    panel: 'bg-red-50/40 dark:bg-red-500/5',
    border: 'border-red-200/60 dark:border-red-500/20',
  },
};

const ACTIONS: ActionDef[] = [
  { id: 'add', icon: Plus, title: 'إضافة مجموعة', description: 'إنشاء مجموعة سنتر جديدة', tone: 'rose' },
  { id: 'edit', icon: Edit, title: 'تعديل مجموعة', description: 'تحديث بيانات مجموعة قائمة', tone: 'amber' },
  { id: 'delete', icon: Trash2, title: 'حذف مجموعة', description: 'إزالة مجموعة نهائياً', tone: 'red' },
];

/** Landing picker tile — one clear decision at a time, same as CourseManagement.tsx's ActionCard. */
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

/** Back-to-picker bar, same shape as CourseManagement.tsx's PanelBackBar. */
function PanelBackBar({ onBack, icon: Icon, title, description, tone = 'rose' }: {
  onBack: () => void;
  icon: LucideIcon;
  title: ReactNode;
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

/** One row in the edit/delete pickers — icon chip instead of a course thumbnail (a group has no image). */
function GroupRow({ group, accent, action }: { group: CenterGroup; accent: 'amber' | 'red'; action: ReactNode }) {
  const ring = accent === 'amber' ? 'hover:border-amber-300 dark:hover:border-amber-500/40' : 'hover:border-red-300 dark:hover:border-red-500/40';
  const bar = accent === 'amber' ? 'bg-amber-500' : 'bg-red-500';
  return (
    <div className={cn(
      'group relative flex items-center justify-between p-4 pe-5 bg-slate-50/60 dark:bg-slate-800/30 border border-slate-200/70 dark:border-slate-800 rounded-2xl gap-4 overflow-hidden transition-all hover:bg-white dark:hover:bg-slate-800 hover:shadow-md',
      ring,
    )}>
      <span className={cn('absolute inset-y-0 right-0 w-1 opacity-0 group-hover:opacity-100 transition-opacity', bar)} />
      <div className="flex gap-4 items-center overflow-hidden min-w-0">
        <div className="w-14 h-14 rounded-2xl shrink-0 border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-900 flex items-center justify-center text-slate-400 dark:text-slate-500">
          <Building2 className="w-6 h-6" />
        </div>
        <div className="min-w-0">
          <h4 className="text-base font-black text-slate-800 dark:text-white truncate">{group.center_name}</h4>
          <p className="text-xs text-slate-500 dark:text-slate-400 font-bold truncate mt-1 flex items-center gap-3">
            <span className="inline-flex items-center gap-1"><Layers className="w-3 h-3 shrink-0" />{group.grade_label}</span>
            <span className="inline-flex items-center gap-1"><Users className="w-3 h-3 shrink-0" />{group.group_name}</span>
            <span className="inline-flex items-center gap-1"><Calendar className="w-3 h-3 shrink-0" />{group.schedule_text}</span>
          </p>
        </div>
      </div>
      <div className="shrink-0">{action}</div>
    </div>
  );
}

/**
 * "يمكن اضافة السنتر/اسم المجموعة مرة واحدة وبعد ذالك يظهر في الخلية" — a
 * name typed once (for center_name or group_name) becomes a reusable option
 * for every group after it, instead of retyping it each time. `options` is
 * derived from the teacher's own already-saved groups (no separate catalog
 * table); picking "+ إضافة جديد" drops back to free text for a brand-new name.
 */
function NameCombo({
  id, value, onChange, options,
}: { id: string; value: string; onChange: (v: string) => void; options: string[] }) {
  const [addingNew, setAddingNew] = useState(() => value !== '' && !options.includes(value));

  if (addingNew || options.length === 0) {
    return (
      <div className="space-y-1.5">
        <Input
          id={id}
          size="lg"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="font-bold"
          required
          autoFocus={addingNew}
        />
        {options.length > 0 && (
          <button
            type="button"
            onClick={() => { setAddingNew(false); onChange(''); }}
            className="text-[11px] font-bold text-blue-600 dark:text-blue-400 hover:underline cursor-pointer"
          >
            اختر من القائمة الحالية بدلاً من ذلك
          </button>
        )}
      </div>
    );
  }

  return (
    <Select
      id={id}
      value={options.includes(value) ? value : ''}
      onChange={(e) => {
        if (e.target.value === '__new__') {
          setAddingNew(true);
          onChange('');
        } else {
          onChange(e.target.value);
        }
      }}
    >
      <option value="">-- اختر --</option>
      {options.map((o) => <option key={o} value={o}>{o}</option>)}
      <option value="__new__">+ إضافة جديد</option>
    </Select>
  );
}

/** "يتم اختيار اليوم والوقت و يمكن اضافة اكثر من معاد لهذه المجموعة" — one row per (day, time) slot, at least one, any number more. */
function ScheduleEditor({ slots, onChange }: { slots: ScheduleSlot[]; onChange: (slots: ScheduleSlot[]) => void }) {
  const updateSlot = (index: number, patch: Partial<ScheduleSlot>) => {
    onChange(slots.map((s, i) => (i === index ? { ...s, ...patch } : s)));
  };
  const addSlot = () => onChange([...slots, { day_of_week: '', time: '' }]);
  const removeSlot = (index: number) => onChange(slots.filter((_, i) => i !== index));

  return (
    <div className="space-y-2">
      {slots.map((slot, i) => (
        <div key={i} className="flex items-center gap-2">
          <Select
            value={slot.day_of_week}
            onChange={(e) => updateSlot(i, { day_of_week: e.target.value })}
            className="flex-1"
            required
          >
            <option value="">-- اليوم --</option>
            {DAYS.map((d) => <option key={d} value={d}>{d}</option>)}
          </Select>
          <Input
            type="time"
            size="md"
            value={slot.time}
            onChange={(e) => updateSlot(i, { time: e.target.value })}
            className="flex-1 font-mono"
            required
          />
          {slots.length > 1 && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              iconOnly
              icon={Trash2}
              onClick={() => removeSlot(i)}
              aria-label="حذف هذا الميعاد"
              className="text-rose-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 shrink-0"
            />
          )}
        </div>
      ))}
      <Button type="button" variant="outline" size="sm" icon={ListPlus} onClick={addSlot}>
        إضافة ميعاد آخر
      </Button>
    </div>
  );
}

export default function CreateCenterGroup() {
  const { notify } = useCenteredNotification();

  const [groupActionTab, setGroupActionTab] = useState<GroupAction | null>(null);

  const [groups, setGroups] = useState<CenterGroup[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [form, setForm] = useState<GroupFormState>(EMPTY_FORM);
  const [submitting, setSubmitting] = useState(false);

  const [editingGroupId, setEditingGroupId] = useState<string | null>(null);
  const [editForm, setEditForm] = useState<GroupFormState>(EMPTY_FORM);
  const [savingEdit, setSavingEdit] = useState(false);

  const [deleteTarget, setDeleteTarget] = useState<CenterGroup | null>(null);
  const [deleting, setDeleting] = useState(false);
  // "لا يمكن حذف مجموعة مسجل بها طلاب إلا بعد إدخال باسورد المدرس" — the
  // backend only actually enforces this when the group has a roster, but
  // the field is offered up front rather than round-tripping a 401 first.
  const [deletePassword, setDeletePassword] = useState('');
  const [deleteError, setDeleteError] = useState('');

  // "الصفوف الدراسية تظهر جميع الصفوف... في قائمة لا يمكن التعديل عليها" —
  // the same platform-wide, admin-managed grades catalog AddStudent.tsx/
  // EditStudent.tsx read, shown flat (every prep+secondary grade, no
  // section filtering) since a center group is not scoped to one section.
  const [gradeOptions, setGradeOptions] = useState<string[]>([]);
  const [loadingGrades, setLoadingGrades] = useState(true);

  useEffect(() => {
    (async () => {
      try {
        setLoadingGrades(true);
        const items = await catalogService.list('grades');
        setGradeOptions(items.map((g) => g.name));
      } catch (err) {
        logger.error('CreateCenterGroup: failed to load grades catalog', err as Error);
      } finally {
        setLoadingGrades(false);
      }
    })();
  }, []);

  const loadGroups = async () => {
    try {
      setLoading(true);
      setLoadError(null);
      const data = await centerService.getMyGroups();
      setGroups(Array.isArray(data) ? data : []);
    } catch (err) {
      logger.error('CreateCenterGroup: failed to load groups', err as Error);
      setLoadError('تعذر تحميل مجموعاتك، يرجى إعادة تحميل الصفحة أو المحاولة لاحقاً');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadGroups();
  }, []);

  // "يمكن اضافة السنتر/اسم المجموعة مرة واحدة وبعد ذالك يظهر في الخلية" —
  // reuse options derived straight from the teacher's own saved groups.
  const centerNameOptions = useMemo(
    () => Array.from(new Set(groups.map((g) => g.center_name))).filter(Boolean).sort(),
    [groups],
  );
  const groupNameOptions = useMemo(
    () => Array.from(new Set(groups.map((g) => g.group_name))).filter(Boolean).sort(),
    [groups],
  );

  const validSchedules = (slots: ScheduleSlot[]) =>
    slots.length > 0 && slots.every((s) => s.day_of_week.trim() !== '' && s.time.trim() !== '');

  // ── ADD ────────────────────────────────────────────────────────────
  const handleAddSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!form.center_name.trim() || !form.grade_label.trim() || !form.group_name.trim() || !validSchedules(form.schedules)) {
      notify('يرجى تعبئة جميع الحقول المطلوبة، بما فيها اليوم والوقت لكل ميعاد', 'error');
      return;
    }

    setSubmitting(true);
    try {
      const payload = {
        center_name: form.center_name.trim(),
        grade_label: form.grade_label.trim(),
        group_name: form.group_name.trim(),
        billing_type: form.billing_type,
        schedules: form.schedules,
      };
      const created = await centerService.createGroup(payload);
      if (created) setGroups((prev) => [created, ...prev]);
      setForm(EMPTY_FORM);
      notify('تم إنشاء المجموعة بنجاح', 'success');
    } catch (err: any) {
      logger.error('CreateCenterGroup: add failed', err as Error);
      notify(err?.response?.data?.message || 'حدث خطأ أثناء الحفظ، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  // ── EDIT ───────────────────────────────────────────────────────────
  const startEdit = (group: CenterGroup) => {
    setEditingGroupId(group.id);
    setEditForm({
      center_name: group.center_name || '',
      grade_label: group.grade_label || '',
      group_name: group.group_name || '',
      billing_type: group.billing_type || 'حصة',
      schedules: group.schedules && group.schedules.length > 0 ? group.schedules : [{ day_of_week: '', time: '' }],
    });
  };

  const handleEditSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!editingGroupId) return;
    if (!editForm.center_name.trim() || !editForm.grade_label.trim() || !editForm.group_name.trim() || !validSchedules(editForm.schedules)) {
      notify('يرجى تعبئة جميع الحقول المطلوبة، بما فيها اليوم والوقت لكل ميعاد', 'error');
      return;
    }

    setSavingEdit(true);
    try {
      const payload = {
        center_name: editForm.center_name.trim(),
        grade_label: editForm.grade_label.trim(),
        group_name: editForm.group_name.trim(),
        billing_type: editForm.billing_type,
        schedules: editForm.schedules,
      };
      const updated = await centerService.updateGroup(editingGroupId, payload);
      setGroups((prev) => prev.map((g) => (g.id === editingGroupId ? { ...g, ...updated } : g)));
      notify('تم تحديث بيانات المجموعة بنجاح', 'success');
      setEditingGroupId(null);
    } catch (err: any) {
      logger.error('CreateCenterGroup: edit failed', err as Error);
      notify(err?.response?.data?.message || 'حدث خطأ أثناء الحفظ، يرجى المحاولة مرة أخرى', 'error');
    } finally {
      setSavingEdit(false);
    }
  };

  // ── DELETE ─────────────────────────────────────────────────────────
  const confirmDelete = async () => {
    if (!deleteTarget) return;
    setDeleteError('');
    setDeleting(true);
    try {
      await centerService.deleteGroup(deleteTarget.id, deletePassword.trim() || undefined);
      setGroups((prev) => prev.filter((g) => g.id !== deleteTarget.id));
      notify(`تم حذف مجموعة "${deleteTarget.group_name}" بنجاح`, 'success');
      setDeleteTarget(null);
      setDeletePassword('');
    } catch (err: any) {
      logger.error('CreateCenterGroup: delete failed', err as Error);
      if (err?.response?.status === 401) {
        setDeleteError(err.response.data?.message || 'كلمة المرور مطلوبة لتأكيد حذف مجموعة بها طلاب');
      } else {
        notify('حدث خطأ أثناء الحذف، يرجى المحاولة مرة أخرى', 'error');
      }
    } finally {
      setDeleting(false);
    }
  };

  const groupFormFields = (state: GroupFormState, setState: (updater: (f: GroupFormState) => GroupFormState) => void) => (
    <div className="space-y-4">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <FormField required label="اسم السنتر">
          {({ id }) => (
            <NameCombo
              id={id}
              value={state.center_name}
              onChange={(v) => setState((f) => ({ ...f, center_name: v }))}
              options={centerNameOptions}
            />
          )}
        </FormField>

        <FormField required label="الصف الدراسي">
          {({ id }) => (
            <Select
              id={id}
              value={state.grade_label}
              onChange={(e) => setState((f) => ({ ...f, grade_label: e.target.value }))}
              disabled={loadingGrades}
              required
            >
              <option value="">{loadingGrades ? '-- جاري التحميل... --' : '-- اختر الصف الدراسي --'}</option>
              {gradeOptions.map((g) => <option key={g} value={g}>{g}</option>)}
            </Select>
          )}
        </FormField>

        <FormField required label="اسم المجموعة">
          {({ id }) => (
            <NameCombo
              id={id}
              value={state.group_name}
              onChange={(v) => setState((f) => ({ ...f, group_name: v }))}
              options={groupNameOptions}
            />
          )}
        </FormField>

        <FormField required label="نوع الفترة">
          {({ id }) => (
            <Select
              id={id}
              value={state.billing_type}
              onChange={(e) => setState((f) => ({ ...f, billing_type: e.target.value as 'شهر' | 'حصة' }))}
              required
            >
              {BILLING_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
            </Select>
          )}
        </FormField>
      </div>

      <FormField required label="ميعاد المجموعة">
        {() => (
          <ScheduleEditor
            slots={state.schedules}
            onChange={(schedules) => setState((f) => ({ ...f, schedules }))}
          />
        )}
      </FormField>
    </div>
  );

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <PageHeader
        icon={Building2}
        title="إنشاء مجموعة"
        description="أضف مجموعات السنتر الخاصة بك وحدّد ميعادها، ثم استخدمها لاحقاً في تسجيل الحضور"
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إنشاء مجموعة' }]}
      />

      {loadError && (
        <div className="p-4 rounded-2xl flex items-center gap-3.5 font-bold text-sm border bg-rose-50 text-rose-800 border-rose-200/60 dark:bg-rose-500/10 dark:text-rose-400 dark:border-rose-500/20">
          {loadError}
        </div>
      )}

      <AnimatePresence mode="wait">
        {groupActionTab === null ? (
          /* LANDING PICKER — one decision at a time */
          <motion.div
            key="group_action_picker"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="grid grid-cols-1 sm:grid-cols-3 gap-4"
          >
            {ACTIONS.map((action, i) => (
              <ActionCard key={action.id} action={action} index={i} onSelect={setGroupActionTab} />
            ))}
          </motion.div>
        ) : (
          <div key="group_action_panel">
            {groupActionTab === 'add' && (
              <PanelBackBar
                onBack={() => setGroupActionTab(null)}
                icon={Plus}
                title="إضافة مجموعة جديدة"
                description="أدخل بيانات مجموعة السنتر الجديدة"
                tone="rose"
              />
            )}
            {groupActionTab === 'edit' && (
              <PanelBackBar
                onBack={() => setGroupActionTab(null)}
                icon={Edit}
                title="تعديل وتحرير المجموعات القائمة"
                description={editingGroupId ? 'عدّل بيانات المجموعة المحددة ثم احفظ التغييرات' : 'اختر المجموعة المطلوب تعديلها من القائمة أدناه'}
                tone="amber"
              />
            )}
            {groupActionTab === 'delete' && (
              <PanelBackBar
                onBack={() => setGroupActionTab(null)}
                icon={Trash2}
                title={<span className="text-red-600 dark:text-red-400">حذف المجموعات القائمة</span>}
                description="اضغط على زر الحذف لإزالة المجموعة نهائياً — هذا الإجراء لا يمكن التراجع عنه"
                tone="red"
              />
            )}

            <AnimatePresence mode="wait">
              {/* SUBTAB: ADD */}
              {groupActionTab === 'add' && (
                <motion.form
                  key="tab_add"
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  exit={{ opacity: 0 }}
                  onSubmit={handleAddSubmit}
                  className={cn('space-y-6 p-5 rounded-panel border text-right', TONE_STYLES.rose.panel, TONE_STYLES.rose.border)}
                >
                  {groupFormFields(form, setForm)}
                  <div className="flex justify-end pt-2 border-t border-rose-200/40 dark:border-rose-500/20">
                    <Button type="submit" icon={Plus} isLoading={submitting} loadingText="جاري الحفظ...">
                      إضافة المجموعة
                    </Button>
                  </div>
                </motion.form>
              )}

              {/* SUBTAB: EDIT */}
              {groupActionTab === 'edit' && (
                <motion.div key="tab_edit" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="space-y-6">
                  <AnimatePresence mode="wait">
                    {!editingGroupId ? (
                      <motion.div key="edit_list" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                        {loading ? (
                          <div className="space-y-3">
                            {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} variant="block" className="h-20" />)}
                          </div>
                        ) : groups.length === 0 ? (
                          <EmptyState icon={Info} title="لا توجد مجموعات حالياً للتعديل" description='استخدم بطاقة "إضافة" لإنشاء مجموعتك الأولى' />
                        ) : (
                          <div className="space-y-3">
                            {groups.map((group) => (
                              <button key={group.id} type="button" onClick={() => startEdit(group)} className="w-full text-right cursor-pointer">
                                <GroupRow
                                  group={group}
                                  accent="amber"
                                  action={
                                    <span className="inline-flex items-center gap-1.5 text-amber-600 dark:text-amber-400 font-black text-xs">
                                      <Edit className="w-3.5 h-3.5" />
                                      تعديل
                                    </span>
                                  }
                                />
                              </button>
                            ))}
                          </div>
                        )}
                      </motion.div>
                    ) : (
                      <motion.form
                        key="edit_form"
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.25 }}
                        onSubmit={handleEditSubmit}
                        className={cn('space-y-5 p-5 rounded-panel border text-right', TONE_STYLES.amber.panel, TONE_STYLES.amber.border)}
                      >
                        <div className="flex justify-between items-center gap-3 pb-3 border-b border-amber-200/40 dark:border-amber-500/20">
                          <div className="flex items-center gap-1.5">
                            <Sparkles className="w-4 h-4 shrink-0 text-amber-600 dark:text-amber-400" />
                            <span className="text-sm font-black text-amber-700 dark:text-amber-400">تحرير المجموعة المحددة حالياً</span>
                          </div>
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            icon={ArrowRight}
                            onClick={() => setEditingGroupId(null)}
                            className="hover:text-rose-600 dark:hover:text-rose-400"
                          >
                            الرجوع لقائمة المجموعات
                          </Button>
                        </div>

                        {groupFormFields(editForm, setEditForm)}

                        <div className="flex justify-end pt-2 border-t border-amber-200/40 dark:border-amber-500/20">
                          <Button type="submit" icon={Save} isLoading={savingEdit} loadingText="جاري الحفظ...">
                            حفظ التعديلات
                          </Button>
                        </div>
                      </motion.form>
                    )}
                  </AnimatePresence>
                </motion.div>
              )}

              {/* SUBTAB: DELETE */}
              {groupActionTab === 'delete' && (
                <motion.div key="tab_delete" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="space-y-3">
                  {loading ? (
                    <div className="space-y-3">
                      {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} variant="block" className="h-20" />)}
                    </div>
                  ) : groups.length === 0 ? (
                    <EmptyState icon={Info} title="لا توجد مجموعات حالية للحذف" />
                  ) : (
                    groups.map((group) => (
                      <GroupRow
                        key={group.id}
                        group={group}
                        accent="red"
                        action={
                          <Button
                            type="button"
                            variant="danger"
                            iconOnly
                            icon={Trash2}
                            onClick={() => setDeleteTarget(group)}
                            aria-label="حذف المجموعة"
                          />
                        }
                      />
                    ))
                  )}
                </motion.div>
              )}
            </AnimatePresence>
          </div>
        )}
      </AnimatePresence>

      <ConfirmDialog
        open={!!deleteTarget}
        onClose={() => { setDeleteTarget(null); setDeletePassword(''); setDeleteError(''); }}
        onConfirm={confirmDelete}
        title="تأكيد حذف المجموعة نهائياً؟"
        message={
          deleteTarget ? (
            <>
              هل أنت متأكد من رغبتك في حذف مجموعة &quot;
              <strong className="text-slate-800 dark:text-slate-200 font-black">{deleteTarget.group_name}</strong>
              &quot;؟ هذا الإجراء لا يمكن التراجع عنه.
            </>
          ) : ''
        }
        confirmLabel="حذف المجموعة نهائياً"
        cancelLabel="إلغاء الأمر"
        tone="danger"
        icon={AlertTriangle}
        isLoading={deleting}
      >
        {/* Only actually required when the group has a registered roster —
            the backend enforces this conditionally, this field is simply
            offered up front so the teacher isn't round-tripped a 401 first. */}
        <FormField label="كلمة مرورك (مطلوبة فقط إذا كانت المجموعة بها طلاب مسجَّلون)" error={deleteError || undefined}>
          {({ id, hasError }) => (
            <Input
              id={id}
              type="password"
              hasError={hasError}
              value={deletePassword}
              onChange={(e) => { setDeletePassword(e.target.value); setDeleteError(''); }}
              placeholder="كلمة المرور"
            />
          )}
        </FormField>
      </ConfirmDialog>
    </div>
  );
}
