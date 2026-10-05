import { authService } from '../../services/authService';
import React, { useState, useEffect, useMemo } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import type { LucideIcon } from 'lucide-react';
import { Plus, Edit, Trash2, AlertTriangle, GraduationCap, HelpCircle, FileQuestion, ArrowRight, Layers } from 'lucide-react';
import { QuestionGroup } from '../../types/index';
import { questionBankApiService } from '../../services/questionBankApiService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  Card,
  CardHeader,
  PageHeader,
  Button,
  Input,
  Select,
  FormField,
  Skeleton,
  cn,
} from '../UI';

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
  { id: 'add', icon: Plus, title: 'إضافة', description: 'إضافة مجموعة أسئلة جديدة', tone: 'rose' },
  { id: 'edit', icon: Edit, title: 'تعديل', description: 'تعديل مجموعة أسئلة موجودة', tone: 'amber' },
  { id: 'delete', icon: Trash2, title: 'حذف', description: 'حذف مجموعة أسئلة نهائياً', tone: 'red' },
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
          <h2 className="text-lg font-black text-slate-900 dark:text-white leading-tight">{title}</h2>
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
 * is assigned exactly one grade. Not a disabled Select on purpose: the original
 * markup rendered a plain div here (no form control, nothing submitted), and
 * turning it into a control would add a field to the form.
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



export default function QuestionGroupsManagement(_props: any) {
  const [questionGroups, setQuestionGroups] = useState<QuestionGroup[]>([]);
  const [loading, setLoading] = useState(true);

  const [activeTab, setActiveTab] = useState<GroupAction | null>(null);
  const [isAdding, setIsAdding] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  useEffect(() => {
    let isMounted = true;
    (async () => {
      try {
        setLoading(true);
        const groups = await questionBankApiService.listGroups();
        if (isMounted) setQuestionGroups(groups);
      } catch (err) {
        logger.error('Failed to load question groups', err as Error);
        if (isMounted) triggerFeedback('حدث خطأ أثناء تحميل مجموعات الأسئلة', 'error');
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
  const [addName, setAddName] = useState('');

  // Synchronize grade
  useEffect(() => {
    if (availableGrades.length > 0 && !availableGrades.includes(selectedGrade)) {
      setSelectedGrade(availableGrades[0]);
    }
  }, [availableGrades, selectedGrade]);

  const handleAddSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!addName.trim()) {
      triggerFeedback('يرجى إدخال اسم مجموعة الأسئلة', 'error');
      return;
    }

    setIsAdding(true);
    try {
      const newGroup = await questionBankApiService.createGroup(addName.trim(), selectedGrade);
      setQuestionGroups([newGroup, ...questionGroups]);
      triggerFeedback(`تم إضافة مجموعة الأسئلة "${addName.trim()}" بنجاح!`, 'success');
      setAddName('');
    } catch (err) {
      logger.error('Failed to create question group', err as Error);
      triggerFeedback('حدث خطأ أثناء إضافة مجموعة الأسئلة', 'error');
    } finally {
      setIsAdding(false);
    }
  };

  // Edit / Delete Cascading
  const [selectedGroupId, setSelectedGroupId] = useState('');
  const [showConfirmDeleteAlert, setShowConfirmDeleteAlert] = useState(false);

  const [editName, setEditName] = useState('');

  const groupsForGrade = useMemo(() => {
    return questionGroups.filter(qg => qg.grade === selectedGrade);
  }, [questionGroups, selectedGrade]);

  useEffect(() => {
    if (!groupsForGrade.find(qg => qg.id === selectedGroupId)) {
      setSelectedGroupId('');
    }
  }, [groupsForGrade, selectedGroupId]);

  useEffect(() => {
    setShowConfirmDeleteAlert(false);
  }, [selectedGrade, selectedGroupId]);

  useEffect(() => {
    if (activeTab === 'edit' && selectedGroupId) {
       const group = questionGroups.find(qg => qg.id === selectedGroupId);
       if (group) {
          setEditName(group.name || group.title || '');
       }
    }
  }, [selectedGroupId, activeTab]);

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedGroupId) {
      triggerFeedback('يرجى تحديد مجموعة الأسئلة المُراد تعديلها', 'error');
      return;
    }
    if (!editName.trim()) {
      triggerFeedback('يرجى التحقق من إدخال اسم مجموعة الأسئلة', 'error');
      return;
    }

    setIsEditing(true);
    try {
      const updated = await questionBankApiService.updateGroup(selectedGroupId, editName.trim(), selectedGrade);
      setQuestionGroups(questionGroups.map(qg => (qg.id === selectedGroupId ? updated : qg)));
      triggerFeedback(`تم تحديث بيانات مجموعة الأسئلة "${editName.trim()}" بنجاح!`, 'success');
    } catch (err) {
      logger.error('Failed to update question group', err as Error);
      triggerFeedback('حدث خطأ أثناء تحديث مجموعة الأسئلة', 'error');
    } finally {
      setIsEditing(false);
    }
  };

  const executeGroupDeletion = async () => {
    if (!selectedGroupId) return;
    setIsDeleting(true);
    const targetGroup = questionGroups.find(qg => qg.id === selectedGroupId);
    try {
      await questionBankApiService.deleteGroup(selectedGroupId);
      setQuestionGroups(questionGroups.filter(qg => qg.id !== selectedGroupId));
      setSelectedGroupId('');
      setShowConfirmDeleteAlert(false);
      triggerFeedback(`تم إزالة مجموعة الأسئلة "${targetGroup?.name || ''}" بنجاح!`, 'success');
    } catch (err) {
      logger.error('Failed to delete question group', err as Error);
      triggerFeedback('حدث خطأ أثناء حذف مجموعة الأسئلة', 'error');
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

        <Skeleton variant="block" className="h-14 w-full max-w-md" />
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <Skeleton variant="block" className="h-40 w-full" />
          <Skeleton variant="block" className="h-40 w-full" />
          <Skeleton variant="block" className="h-40 w-full" />
        </div>
      </div>
    );
  }

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <PageHeader
        icon={Layers}
        title="إضافة وتعديل مجاميع الأسئلة"
        description="إدارة مجاميع أسئلتك الدراسية"
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إضافة وتعديل مجاميع الأسئلة' }]}
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
              <ActionCard key={action.id} action={action} index={i} onSelect={setActiveTab} />
            ))}
          </motion.div>
        ) : (
        <div key="action_panel">
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
                  onBack={() => { setActiveTab(null); setShowConfirmDeleteAlert(false); }}
                  icon={Plus}
                  title="إضافة مجموعة أسئلة جديدة"
                  description="أنشئ مجموعة أسئلة كوعاء لتصنيف وإضافة الأسئلة بداخلها لاحقاً"
                  tone="rose"
                />

                <form onSubmit={handleAddSubmit} className="space-y-6">
                  {/* SELECT CONTROLS */}
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
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
                          <Select
                            id={id}
                            size="lg"
                            value={selectedGrade}
                            onChange={(e) => setSelectedGrade(e.target.value)}
                          >
                            {availableGrades.map((grade: any) => <option key={grade} value={grade}>{grade}</option>)}
                          </Select>
                        ) : (
                          <StaticGradeValue className="h-12 px-4 text-base">
                            {availableGrades[0] || 'لم يتم تحديد صف'}
                          </StaticGradeValue>
                        )
                      }
                    </FormField>

                    {/* GROUP NAME */}
                    <FormField
                      required
                      label={
                        <span className="flex items-center gap-1.5">
                          <FileQuestion className="w-4 h-4 text-slate-400 shrink-0" />
                          اسم مجموعة الأسئلة
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
                          placeholder="مثال: مراجعة الفصل الأول"
                          className="font-bold"
                          required
                        />
                      )}
                    </FormField>
                  </div>

                  <div className="pt-4 flex justify-end">
                    <Button
                      type="submit"
                      size="lg"
                      icon={Plus}
                      disabled={isAdding}
                      isLoading={isAdding}
                      loadingText="جاري إضافة المجموعة..."
                      className="w-full md:w-auto shadow-lg shadow-rose-500/20 hover:shadow-xl hover:shadow-rose-500/30 transition-shadow"
                    >
                      إضافة المجموعة وحفظ التغييرات
                    </Button>
                  </div>
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
                  onBack={() => { setActiveTab(null); setShowConfirmDeleteAlert(false); }}
                  icon={activeTab === 'edit' ? Edit : Trash2}
                  title={activeTab === 'edit' ? 'تعديل مجموعة أسئلة قائمة' : <span className="text-red-600 dark:text-red-400">حذف مجموعة أسئلة قائمة</span>}
                  description={activeTab === 'edit' ? 'اختر المجموعة المطلوب تعديلها ثم عدّل اسمها' : 'اختر المجموعة ثم اضغط حذف — هذا الإجراء لا يمكن التراجع عنه'}
                  tone={activeTab === 'edit' ? 'amber' : 'red'}
                />

                <Card flat className="bg-slate-50 dark:bg-slate-950/40 space-y-4">
                  <CardHeader
                    className="mb-0 pb-1"
                    divider
                    icon={HelpCircle}
                    title="مسار تحديد المجموعة المستهدفة"
                  />

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Grade */}
                    <FormField label={<span className="text-blue-700 dark:text-blue-400">1. الصف</span>}>
                      {({ id }) =>
                        availableGrades.length > 1 ? (
                          <Select
                            id={id}
                            value={selectedGrade}
                            onChange={(e) => setSelectedGrade(e.target.value)}
                          >
                            {availableGrades.map((grade: any) => <option key={grade} value={grade}>{grade}</option>)}
                          </Select>
                        ) : (
                          <StaticGradeValue className="h-10 px-3.5 text-sm">
                            {availableGrades[0] || 'لم يتم تحديد صف'}
                          </StaticGradeValue>
                        )
                      }
                    </FormField>
                    {/* Group */}
                    <FormField label={<span className="text-blue-700 dark:text-blue-400">2. مجموعة الأسئلة</span>}>
                      {({ id }) => (
                        <Select
                          id={id}
                          value={selectedGroupId}
                          onChange={(e) => setSelectedGroupId(e.target.value)}
                          disabled={groupsForGrade.length === 0}
                        >
                          {groupsForGrade.length === 0 ? <option value="">لا توجد مجموعات</option> : (
                            <>
                              <option value="">-- اختر المجموعة --</option>
                              {groupsForGrade.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                            </>
                          )}
                        </Select>
                      )}
                    </FormField>
                  </div>
                </Card>

                {activeTab === 'edit' && selectedGroupId && (
                  <form onSubmit={handleEditSubmit} className="space-y-6 pt-4 border-t border-slate-200 dark:border-slate-800">
                    <FormField label="اسم مجموعة الأسئلة" required>
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
                    <div className="pt-4 flex justify-end">
                      {/*
                        `variant="warning"` replaces the bespoke amber→orange
                        gradient this button had, keeping "edit = amber" intact.
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
                        حفظ بيانات المجموعة
                      </Button>
                    </div>
                  </form>
                )}

                {activeTab === 'delete' && selectedGroupId && (
                  <div className="space-y-6 pt-6 border-t border-slate-200 dark:border-slate-800">
                    {!showConfirmDeleteAlert ? (
                      <Card className="flex flex-col md:flex-row gap-6 items-center justify-between">
                        <div className="space-y-1.5 flex-1 min-w-0">
                          <h4 className="text-lg font-black text-slate-800 dark:text-slate-100">{questionGroups.find(qg => qg.id === selectedGroupId)?.name}</h4>
                          <p className="text-sm text-slate-500 dark:text-slate-400 font-normal leading-relaxed">سيتم حذف مجموعة الأسئلة وكل ما بداخلها نهائياً ولن يتمكن الطلاب من الوصول إليها.</p>
                        </div>
                        <Button
                          variant="danger"
                          icon={Trash2}
                          onClick={() => setShowConfirmDeleteAlert(true)}
                          className="shrink-0 w-full md:w-auto shadow-lg shadow-red-500/20 hover:shadow-xl hover:shadow-red-500/30 transition-shadow"
                        >
                          حذف هذه المجموعة
                        </Button>
                      </Card>
                    ) : (
                      /*
                        Kept as an INLINE confirmation panel rather than swapped
                        for `ConfirmDialog`: `showConfirmDeleteAlert` is not a
                        modal-open flag — several effects reset it on grade/group
                        change, and it renders in flow under the selector. Making
                        it a dialog would change when and where it appears.
                      */
                      <div className="p-6 rounded-2xl bg-rose-50 dark:bg-rose-500/10 border-2 border-rose-200 dark:border-rose-500/30 shadow-e1">
                        <div className="flex gap-4">
                          <div className="p-3 bg-white dark:bg-rose-500/20 rounded-full shadow-sm h-fit shrink-0">
                            <AlertTriangle className="w-6 h-6 text-rose-600 dark:text-rose-400" />
                          </div>
                          <div className="space-y-4 min-w-0">
                            <div className="space-y-1">
                              <h4 className="text-lg font-black text-rose-800 dark:text-rose-300">هل أنت متأكد من حذف هذه المجموعة نهائياً؟</h4>
                              <p className="text-sm sm:text-base font-medium text-rose-600/80 dark:text-rose-400/80 leading-relaxed max-w-2xl">
                                حذف المجموعة سيؤدي إلى حذف كافة الأسئلة المرتبطة بها ولا يمكن التراجع عن هذا الإجراء.
                              </p>
                            </div>
                            <div className="flex flex-wrap gap-3 pt-2">
                              <Button
                                variant="danger"
                                onClick={executeGroupDeletion}
                                disabled={isDeleting}
                                isLoading={isDeleting}
                                loadingText="جاري الحذف..."
                              >
                                نعم، احذف المجموعة
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
