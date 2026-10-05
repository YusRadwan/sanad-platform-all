import { authService } from '../../services/authService';
import React, { useState, useEffect, useMemo, useRef } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import type { LucideIcon } from 'lucide-react';
import { Plus, Edit, Trash2, AlertTriangle, CheckCircle2, Sparkles, HelpCircle, BookOpenCheck, ListTodo, Upload, FileQuestion, Type, Image as ImageIcon, X, ArrowRight } from 'lucide-react';
import { Question, QuestionBankProps, QuestionGroup, QuestionChoiceFormat } from '../../types/index';
import { validateImageFile } from '../../utils/fileValidation';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { questionBankApiService } from '../../services/questionBankApiService';
import { uploadApiService } from '../../services/uploadApiService';
import { logger } from '../../services/logger';
import {
  Card,
  CardHeader,
  PageHeader,
  SectionHeader,
  Button,
  Badge,
  Input,
  Select,
  Textarea,
  FormField,
  Tabs,
  EmptyState,
  ConfirmDialog,
  Skeleton,
  cn,
} from '../UI';

type BankAction = 'add' | 'manage';

/** Choice-round-button/placeholder labels per format — presentation only. */
const CHOICE_FORMAT_LABELS: Record<QuestionChoiceFormat, string[]> = {
  '1234': ['1', '2', '3', '4'],
  abcd: ['A', 'B', 'C', 'D'],
  arabic: ['أ', 'ب', 'ج', 'د'],
};

interface ActionDef {
  id: BankAction;
  icon: LucideIcon;
  title: string;
  description: string;
  tone: 'rose' | 'amber';
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
};

const ACTIONS: ActionDef[] = [
  { id: 'add', icon: Plus, title: 'إضافة سؤال', description: 'إضافة سؤال جديد إلى المجموعة', tone: 'rose' },
  { id: 'manage', icon: ListTodo, title: 'إدارة الأسئلة', description: 'استعراض وحذف الأسئلة الحالية', tone: 'amber' },
];

/**
 * The landing picker: one clearly-labelled tile per operation instead of a
 * tab bar, so the page opens on a single, uncluttered decision rather than
 * both operations at once. Centred icon-over-label layout (no footer
 * meta/arrow row) — a plain, instantly-scannable menu of the 2 operations.
 */
function ActionCard({ action, onSelect, index }: { action: ActionDef; onSelect: (id: BankAction) => void; index: number }) {
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








export default function QuestionBank(_props: QuestionBankProps) {
  const [questionGroups, setQuestionGroups] = useState<QuestionGroup[]>([]);

  const [questions, setQuestions] = useState<Question[]>([]);
  const [loadingGroups, setLoadingGroups] = useState(true);
  const [loadingQuestions, setLoadingQuestions] = useState(false);
  const [uploadingImage, setUploadingImage] = useState(false);

  useEffect(() => {
    let isMounted = true;
    (async () => {
      try {
        setLoadingGroups(true);
        const groups = await questionBankApiService.listGroups();
        if (isMounted) setQuestionGroups(groups);
      } catch (err) {
        logger.error('Failed to load question groups', err as Error);
        if (isMounted) triggerFeedback('حدث خطأ أثناء تحميل مجموعات الأسئلة', 'error');
      } finally {
        if (isMounted) setLoadingGroups(false);
      }
    })();
    return () => { isMounted = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const [activeTab, setActiveTab] = useState<BankAction | null>(null);
  const [isAdding, setIsAdding] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

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
  const [selectedGroupId, setSelectedGroupId] = useState('');

  // Synchronize grade
  useEffect(() => {
    if (availableGrades.length > 0 && !availableGrades.includes(selectedGrade)) {
      setSelectedGrade(availableGrades[0]);
    }
  }, [availableGrades, selectedGrade]);

  const groupsForGrade = useMemo(() => {
    return questionGroups.filter(qg => qg.grade === selectedGrade);
  }, [questionGroups, selectedGrade]);

  // No auto-pick of the first question group: the rest of the page (the
  // add/manage action picker) only reveals itself once the instructor has
  // explicitly chosen one.
  useEffect(() => {
    if (groupsForGrade.length > 0 && !groupsForGrade.find(qg => qg.id === selectedGroupId)) {
      setSelectedGroupId('');
    } else if (groupsForGrade.length === 0) {
      setSelectedGroupId('');
    }
  }, [groupsForGrade, selectedGroupId]);

  useEffect(() => {
    if (!selectedGroupId) return;
    let isMounted = true;
    (async () => {
      try {
        setLoadingQuestions(true);
        const groupQuestions = await questionBankApiService.listQuestions(selectedGroupId);
        if (isMounted) setQuestions(groupQuestions);
      } catch (err) {
        logger.error('Failed to load questions', err as Error);
        if (isMounted) triggerFeedback('حدث خطأ أثناء تحميل الأسئلة', 'error');
      } finally {
        if (isMounted) setLoadingQuestions(false);
      }
    })();
    return () => { isMounted = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedGroupId]);

  const questionsForGroup = useMemo(() => {
    return questions.filter(q => q.groupId === selectedGroupId);
  }, [questions, selectedGroupId]);

  // Form State
  const [qType, setQType] = useState<'text' | 'image'>('text');
  const [qTitle, setQTitle] = useState('');
  const [qText, setQText] = useState('');
  const [qChoices, setQChoices] = useState(['', '', '', '']);
  const [qChoiceFormat, setQChoiceFormat] = useState<QuestionChoiceFormat>('1234');
  const [qCorrect, setQCorrect] = useState(0);
  const [qImage, setQImage] = useState<string | null>(null);
  const [qImageFile, setQImageFile] = useState<File | null>(null);

  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleImageChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const validation = validateImageFile(file);
      if (!validation.valid) {
        triggerFeedback(validation.error!, 'error');
        e.target.value = '';
        return;
      }
      setQImageFile(file);
      const reader = new FileReader();
      reader.onload = (event) => {
        setQImage(event.target?.result as string);
      };
      reader.readAsDataURL(file);
    }
  };

  const resetForm = () => {
    setQTitle('');
    setQText('');
    setQChoices(['', '', '', '']);
    setQChoiceFormat('1234');
    setQCorrect(0);
    setQImage(null);
    setQImageFile(null);
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  /** Uploads the pending image file (if any) via the existing POST /uploads (kind='document') and returns its URL. */
  const resolveImageUrl = async (existing?: string | null): Promise<string | null> => {
    if (!qImageFile) return existing || null;
    setUploadingImage(true);
    try {
      return await uploadApiService.uploadPublicImage(qImageFile, 'image');
    } finally {
      setUploadingImage(false);
    }
  };

  const handleAddSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedGroupId) {
      triggerFeedback('يرجى اختيار مجموعة أسئلة أولاً', 'error');
      return;
    }

    if (qType === 'text') {
      if (!qText.trim()) {
        triggerFeedback('يرجى إدخال نص السؤال', 'error');
        return;
      }
      if (qChoices.some(c => !c.trim())) {
        triggerFeedback('يرجى ملء جميع الخيارات', 'error');
        return;
      }
    } else {
      if (!qImage) {
        triggerFeedback('يرجى إرفاق صورة السؤال', 'error');
        return;
      }
    }

    setIsAdding(true);
    try {
      const imageUrl = qType === 'image' ? await resolveImageUrl() : null;
      const choices = qType === 'text' ? qChoices.map(c => c.trim()) : ['أ', 'ب', 'ج', 'د'];
      const newQuestion = await questionBankApiService.createQuestion(selectedGroupId, {
        title: qTitle.trim() || undefined,
        body: qType === 'text' ? qText.trim() : 'سؤال مصور',
        type: 'mcq',
        options: choices,
        choiceFormat: qChoiceFormat,
        correctAnswer: choices[qCorrect],
        points: 1,
        imageUrl,
      });
      setQuestions([...questions, newQuestion]);
      triggerFeedback('تم إضافة السؤال بنجاح!', 'success');
      resetForm();
    } catch (err) {
      logger.error('Failed to create question', err as Error);
      triggerFeedback('حدث خطأ أثناء إضافة السؤال', 'error');
    } finally {
      setIsAdding(false);
    }
  };

  // Manage State
  const [editingQuestion, setEditingQuestion] = useState<Question | null>(null);
  const [showConfirmDelete, setShowConfirmDelete] = useState<string | null>(null);

  const handleStartEdit = (q: Question) => {
    setEditingQuestion(q);
    setQType((q.type as 'text' | 'image') || 'text');
    setQTitle(q.title || '');
    setQText(q.text || '');
    setQChoices((q.choices && q.choices.length === 4) ? q.choices : ['', '', '', '']);
    setQChoiceFormat(q.choiceFormat || '1234');
    setQCorrect(typeof q.correctAnswer === 'number' ? q.correctAnswer : 0);
    setQImage(q.image || null);
    setQImageFile(null);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const cancelEdit = () => {
    setEditingQuestion(null);
    resetForm();
  };

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingQuestion) return;

    if (qType === 'text') {
      if (!qText.trim()) {
        triggerFeedback('يرجى إدخال نص السؤال', 'error');
        return;
      }
      if (qChoices.some(c => !c.trim())) {
        triggerFeedback('يرجى ملء جميع الخيارات', 'error');
        return;
      }
    } else {
      if (!qImage) {
        triggerFeedback('يرجى إرفاق صورة السؤال', 'error');
        return;
      }
    }

    setIsEditing(true);
    try {
      const imageUrl = qType === 'image' ? await resolveImageUrl(editingQuestion.image) : null;
      const choices = qType === 'text' ? qChoices.map(c => c.trim()) : ['أ', 'ب', 'ج', 'د'];
      const updated = await questionBankApiService.updateQuestion(editingQuestion.id, {
        title: qTitle.trim(),
        body: qType === 'text' ? qText.trim() : 'سؤال مصور',
        type: 'mcq',
        options: choices,
        choiceFormat: qChoiceFormat,
        correctAnswer: choices[qCorrect],
        points: 1,
        imageUrl,
      });
      setQuestions(questions.map(q => (q.id === editingQuestion.id ? updated : q)));
      triggerFeedback('تم تعديل السؤال بنجاح!', 'success');
      cancelEdit();
    } catch (err) {
      logger.error('Failed to update question', err as Error);
      triggerFeedback('حدث خطأ أثناء تعديل السؤال', 'error');
    } finally {
      setIsEditing(false);
    }
  };

  const handleDelete = async (id: string) => {
    setIsDeleting(true);
    try {
      await questionBankApiService.deleteQuestion(id);
      setQuestions(questions.filter(q => q.id !== id));
      setShowConfirmDelete(null);
      triggerFeedback('تم حذف السؤال بنجاح!', 'success');
    } catch (err) {
      logger.error('Failed to delete question', err as Error);
      triggerFeedback('حدث خطأ أثناء حذف السؤال', 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  if (loadingGroups) {
    return (
      <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
        <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
        <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
        <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

        <PageHeader
          icon={BookOpenCheck}
          title="إضافة وتعديل الأسئلة"
          description="إدارة بنك أسئلتك الدراسية"
          breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إضافة وتعديل الأسئلة' }]}
        />

        <Card radius="panel" padding="lg" className="space-y-6">
          <Skeleton variant="text" width="40%" />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Skeleton variant="block" height={40} />
            <Skeleton variant="block" height={40} />
          </div>
        </Card>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Skeleton variant="block" height={160} />
          <Skeleton variant="block" height={160} />
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
        icon={BookOpenCheck}
        title="إضافة وتعديل الأسئلة"
        description="من هنا يمكنك إضافة أسئلة جديدة لمجموعة أسئلة، أو استعراض وحذف الأسئلة الحالية."
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إضافة وتعديل الأسئلة' }]}
      />


      {/* TOP SELECTION AREA */}
      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0, duration: 0.35 }}>
        <Card radius="panel" padding="lg" className="space-y-6">
          <CardHeader className="mb-0 pb-1" divider icon={HelpCircle} title="تحديد المجموعة المستهدفة" />

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                      <option value="">-- اختر مجموعة الأسئلة --</option>
                      {groupsForGrade.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                    </>
                  )}
                </Select>
              )}
            </FormField>
          </div>
        </Card>
      </motion.div>

      {!selectedGroupId ? (
        <EmptyState
          icon={FileQuestion}
          title="اختر الصف ومجموعة الأسئلة أولاً"
          description="حدد الصف الدراسي ومجموعة الأسئلة أعلاه لإظهار خيارات إضافة وإدارة الأسئلة."
        />
      ) : (
        <AnimatePresence mode="wait">
          {activeTab === null ? (
            /* LANDING PICKER — one decision at a time, nothing crowded */
            <motion.div
              key="qb_action_picker"
              initial={{ opacity: 0, y: 16 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0 }}
              transition={{ delay: 0.08, duration: 0.35 }}
              className="grid grid-cols-1 sm:grid-cols-2 gap-4"
            >
              {ACTIONS.map((action, i) => (
                <ActionCard key={action.id} action={action} index={i} onSelect={setActiveTab} />
              ))}
            </motion.div>
          ) : (
          <div key="qb_action_panel">
            <AnimatePresence mode="wait">

              {activeTab === 'add' && (
                <motion.div
                  key={editingQuestion ? "edit_section" : "add_section"}
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -10 }}
                  transition={{ duration: 0.2 }}
                  className="space-y-6"
                >
                  <PanelBackBar
                    onBack={() => setActiveTab(null)}
                    icon={editingQuestion ? Edit : Plus}
                    title={editingQuestion ? 'تعديل السؤال' : 'إضافة سؤال جديد'}
                    description={editingQuestion ? 'قم بتعديل بيانات السؤال المحدد' : 'اختر نوع السؤال وقم بملء البيانات المطلوبة'}
                    tone={editingQuestion ? 'amber' : 'rose'}
                  />

                  <SectionHeader
                    className="pb-3 border-b border-slate-100 dark:border-slate-800"
                    icon={Sparkles}
                    title={editingQuestion ? 'تعديل السؤال' : 'إضافة سؤال جديد'}
                    description={editingQuestion ? 'قم بتعديل بيانات السؤال أدناه' : 'اختر نوع السؤال وقم بملء البيانات المطلوبة'}
                    actions={
                      editingQuestion ? (
                        <Button variant="secondary" iconOnly icon={X} aria-label="إلغاء التعديل" onClick={cancelEdit} />
                      ) : undefined
                    }
                  />

                  <form onSubmit={editingQuestion ? handleEditSubmit : handleAddSubmit} className="space-y-6">

                    {/* TYPE SELECTION */}
                    {/*
                      The two hand-rolled segmented buttons become the shared
                      pill `Tabs`. `qType` is already a two-member union held in
                      page state, so `Tabs` (controlled) maps onto it 1:1 and its
                      buttons are `type="button"` — safe inside this <form>.
                    */}
                    <Tabs
                      variant="pill"
                      value={qType}
                      onChange={(id) => setQType(id)}
                      items={[
                        { id: 'text' as const, icon: Type, label: 'سؤال نصي' },
                        { id: 'image' as const, icon: ImageIcon, label: 'سؤال مصور' },
                      ]}
                    />

                    {/* QUESTION TITLE — a short label distinct from the full
                        prompt below, optional on both create and edit. */}
                    <FormField
                      label={
                        <span className="flex items-center gap-1.5">
                          <Type className="w-4 h-4 text-slate-400 shrink-0" />
                          عنوان السؤال (اختياري)
                        </span>
                      }
                    >
                      {({ id }) => (
                        <Input
                          id={id}
                          type="text"
                          value={qTitle}
                          onChange={(e) => setQTitle(e.target.value)}
                          placeholder="مثال: سؤال 1 - الفصل الأول"
                        />
                      )}
                    </FormField>

                    {/* QUESTION TEXT (Only for text type) */}
                    {qType === 'text' && (
                      <FormField
                        required
                        label={
                          <span className="flex items-center gap-1.5">
                            <FileQuestion className="w-4 h-4 text-slate-400 shrink-0" />
                            نص السؤال
                          </span>
                        }
                      >
                        {({ id }) => (
                          <Textarea
                            id={id}
                            value={qText}
                            onChange={(e) => setQText(e.target.value)}
                            placeholder="اكتب سؤالك هنا..."
                            rows={3}
                            className="resize-none"
                            required
                          />
                        )}
                      </FormField>
                    )}

                    {/* IMAGE UPLOAD */}
                    {/*
                      Dropzone chrome only. The <input type="file"> keeps its
                      exact ref, `accept` and `onChange` — `handleImageChange`
                      still runs `validateImageFile()` before anything is stored.
                    */}
                    <FormField
                      label={
                        <span className="flex items-center gap-1.5">
                          <Upload className="w-4 h-4 text-slate-400 shrink-0" />
                          {qType === 'image' ? 'صورة السؤال (إجبارية) *' : 'إرفاق صورة مساعدة (اختياري)'}
                        </span>
                      }
                    >
                      <div className="border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-card p-6 text-center hover:border-blue-400 dark:hover:border-blue-500/60 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors cursor-pointer relative">
                        <input type="file" ref={fileInputRef} accept="image/*" onChange={handleImageChange} className="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10" />
                        {qImage ? (
                          <div className="flex flex-col items-center gap-3">
                            <img
                              src={qImage}
                              alt="Question preview"
                              className="max-h-48 rounded-xl border border-slate-200 dark:border-slate-700 shadow-e1 object-contain"
                              onError={(e) => { e.currentTarget.style.display = 'none'; }}
                            />
                            <span className="text-xs font-bold text-blue-600 dark:text-blue-400">انقر لتغيير الصورة</span>
                          </div>
                        ) : (
                          <div className="flex flex-col items-center gap-2">
                            <div className="p-3 bg-slate-100 dark:bg-slate-800 rounded-full">
                              <ImageIcon className="w-6 h-6 text-slate-400 dark:text-slate-500" />
                            </div>
                            <span className="text-xs font-bold text-slate-500 dark:text-slate-400">انقر هنا أو اسحب الصورة للرفع</span>
                          </div>
                        )}
                      </div>
                    </FormField>

                    {/* CHOICE FORMAT — how the four options are labelled
                        (numbers / Latin letters / Arabic letters), purely
                        presentational: it never changes which option is
                        correct, only the prefix shown on each one below and
                        wherever this question is later rendered. */}
                    <FormField
                      label={
                        <span className="flex items-center gap-1.5">
                          <ListTodo className="w-4 h-4 text-slate-400 shrink-0" />
                          شكل الاختيارات
                        </span>
                      }
                    >
                      <div className="flex flex-wrap gap-2">
                        {(
                          [
                            { id: 'arabic' as const, label: 'أ ب ج د' },
                            { id: 'abcd' as const, label: 'A B C D' },
                            { id: '1234' as const, label: '1 2 3 4' },
                          ]
                        ).map((opt) => (
                          <button
                            key={opt.id}
                            type="button"
                            onClick={() => setQChoiceFormat(opt.id)}
                            aria-pressed={qChoiceFormat === opt.id}
                            className={cn(
                              'px-4 py-2 rounded-xl text-sm font-black border transition-colors cursor-pointer',
                              'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
                              qChoiceFormat === opt.id
                                ? 'bg-blue-600 text-white border-blue-600 shadow-e1'
                                : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-700 hover:border-blue-300 dark:hover:border-blue-500/50',
                            )}
                          >
                            {opt.label}
                          </button>
                        ))}
                      </div>
                    </FormField>

                    {/* CHOICES & CORRECT ANSWER */}
                    <FormField
                      label={
                        <span className="flex items-center gap-1.5">
                          <CheckCircle2 className="w-4 h-4 text-slate-400 shrink-0" />
                          تحديد الإجابة الصحيحة {qType === 'text' && '& كتابة الخيارات'}
                        </span>
                      }
                    >
                      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {[0, 1, 2, 3].map(index => {
                          const labels = CHOICE_FORMAT_LABELS[qChoiceFormat];
                          const isCorrect = qCorrect === index;
                          return (
                            <div
                              key={index}
                              className={cn(
                                'flex items-center gap-3 p-3 rounded-card border transition-all',
                                isCorrect
                                  ? 'bg-emerald-50 dark:bg-emerald-500/10 border-emerald-300 dark:border-emerald-500/40'
                                  : 'bg-slate-50 dark:bg-slate-800/40 border-slate-200 dark:border-slate-700',
                              )}
                            >
                              <button
                                type="button"
                                onClick={() => setQCorrect(index)}
                                aria-pressed={isCorrect}
                                className={cn(
                                  'w-8 h-8 rounded-full flex items-center justify-center text-sm font-black shrink-0 transition-colors cursor-pointer',
                                  'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
                                  isCorrect
                                    ? 'bg-emerald-500 text-white shadow-e1'
                                    : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700 hover:border-slate-400 dark:hover:border-slate-600',
                                )}
                              >
                                {labels[index]}
                              </button>
                              {qType === 'text' ? (
                                <Input
                                  type="text"
                                  size="sm"
                                  value={qChoices[index]}
                                  onChange={(e) => {
                                    const newC = [...qChoices];
                                    newC[index] = e.target.value;
                                    setQChoices(newC);
                                  }}
                                  placeholder={`الخيار ${labels[index]}`}
                                  className="bg-transparent dark:bg-transparent border-transparent dark:border-transparent hover:border-slate-300 dark:hover:border-slate-600 font-bold"
                                  required
                                />
                              ) : (
                                <span className="text-xs sm:text-sm font-medium text-slate-600 dark:text-slate-400">
                                  هذا الخيار يمثل الحرف ({labels[index]}) في الصورة
                                </span>
                              )}
                            </div>
                          );
                        })}
                      </div>
                    </FormField>

                    <div className="pt-4 flex justify-end border-t border-slate-100 dark:border-slate-800">
                      {/*
                        `variant="warning"` reproduces the old amber→orange
                        gradient for edit, `primary` the blue→indigo one for add;
                        the disabled condition (submitting OR image uploading)
                        and both loading captions are carried over verbatim.
                      */}
                      <Button
                        type="submit"
                        size="lg"
                        variant={editingQuestion ? 'warning' : 'primary'}
                        icon={editingQuestion ? Edit : Plus}
                        disabled={(editingQuestion ? isEditing : isAdding) || uploadingImage}
                        isLoading={editingQuestion ? isEditing : isAdding}
                        loadingText={editingQuestion ? 'جاري حفظ التعديلات...' : 'جاري إضافة السؤال...'}
                        className={cn(
                          'w-full md:w-auto',
                          editingQuestion
                            ? 'shadow-lg shadow-amber-500/20 hover:shadow-xl hover:shadow-amber-500/30 transition-shadow'
                            : 'shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow',
                        )}
                      >
                        {editingQuestion ? 'حفظ التعديلات' : 'إضافة السؤال'}
                      </Button>
                    </div>
                  </form>
                </motion.div>
              )}

              {activeTab === 'manage' && (
                <motion.div
                  key="manage_section"
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -10 }}
                  transition={{ duration: 0.2 }}
                  className="space-y-4"
                >
                  <PanelBackBar
                    onBack={() => setActiveTab(null)}
                    icon={ListTodo}
                    title="إدارة الأسئلة الحالية"
                    description={`استعراض وحذف أسئلة المجموعة — العدد الحالي: ${questionsForGroup.length}`}
                    tone="amber"
                  />

                  {loadingQuestions ? (
                    <div className="space-y-4">
                      <Skeleton variant="block" height={140} />
                      <Skeleton variant="block" height={140} />
                    </div>
                  ) : questionsForGroup.length === 0 ? (
                    <EmptyState
                      icon={FileQuestion}
                      title="لا توجد أسئلة مضافة في هذه المجموعة بعد"
                      description="ابدأ بإضافة سؤال جديد من قائمة العمليات."
                    />
                  ) : (
                    <div className="space-y-4">
                      {questionsForGroup.map((q, idx) => (
                        <Card key={q.id} flat padding="sm" className="bg-slate-50 dark:bg-slate-800/40 group">
                          <div className="flex justify-between items-start gap-4 mb-4">
                             <div className="flex items-center gap-3 min-w-0">
                                <span className="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-500/15 text-blue-700 dark:text-blue-400 flex items-center justify-center text-sm font-black shrink-0">
                                  {idx + 1}
                                </span>
                                <div className="min-w-0 space-y-1">
                                  {/*
                                    The question-type caption becomes a neutral
                                    Badge: it labels a KIND of question, not a
                                    good/bad state, so `statusTone()` is
                                    deliberately not used here — both types are
                                    equally valid and must read identically.
                                  */}
                                  <Badge size="sm" tone="neutral" icon={q.type === 'text' ? Type : ImageIcon}>
                                    {q.type === 'text' ? 'سؤال نصي' : 'سؤال مصور'}
                                  </Badge>
                                  {q.title && <span className="block text-xs font-black text-blue-600 dark:text-blue-400">{q.title}</span>}
                                  {q.type === 'text' && <h4 className="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-200 leading-relaxed">{q.text}</h4>}
                                </div>
                             </div>
                             {/*
                               Was `opacity-0 group-hover:opacity-100`, which hid
                               these controls entirely on touch devices. Now they
                               are always reachable and merely dim until hover on
                               pointer-capable screens.
                             */}
                             <div className="flex gap-1.5 shrink-0 sm:opacity-60 sm:group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
                               <Button
                                 variant="secondary"
                                 size="sm"
                                 iconOnly
                                 icon={Edit}
                                 aria-label="تعديل السؤال"
                                 onClick={() => { setActiveTab('add'); handleStartEdit(q); }}
                               />
                               <Button
                                 variant="secondary"
                                 size="sm"
                                 iconOnly
                                 icon={Trash2}
                                 aria-label="حذف السؤال"
                                 className="hover:text-rose-600 dark:hover:text-rose-400"
                                 onClick={() => setShowConfirmDelete(q.id)}
                               />
                             </div>
                          </div>

                          {q.image && (
                            <img
                              src={q.image}
                              alt="Question"
                              className="max-h-40 rounded-xl border border-slate-200 dark:border-slate-700 mb-4 object-contain bg-white dark:bg-slate-900"
                              onError={(e) => { e.currentTarget.style.display = 'none'; }}
                            />
                          )}

                          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2">
                             {CHOICE_FORMAT_LABELS[q.choiceFormat || '1234'].map((lbl, idx) => (
                               <div
                                 key={idx}
                                 className={cn(
                                   'p-2 rounded-xl text-xs sm:text-sm font-medium border flex items-center gap-2',
                                   q.correctAnswer === idx
                                     ? 'bg-emerald-50 dark:bg-emerald-500/10 border-emerald-200 dark:border-emerald-500/30 text-emerald-800 dark:text-emerald-400'
                                     : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400',
                                 )}
                               >
                                  <span
                                    className={cn(
                                      'w-5 h-5 rounded-full flex items-center justify-center text-[11px] font-black shrink-0',
                                      q.correctAnswer === idx
                                        ? 'bg-emerald-500 text-white'
                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
                                    )}
                                  >
                                    {lbl}
                                  </span>
                                  {q.type === 'text' ? (q.choices?.[idx] || '') : <span className="opacity-50">خيار صوري</span>}
                               </div>
                             ))}
                          </div>
                        </Card>
                      ))}
                    </div>
                  )}
                </motion.div>
              )}
            </AnimatePresence>
          </div>
          )}
        </AnimatePresence>
      )}

      {/*
        Replaces the hand-rolled absolutely-positioned overlay that used to sit
        inside each question card. `showConfirmDelete` was already a
        modal-open flag (it holds the id of the single question awaiting
        confirmation, set only by the trash button and cleared on cancel or on
        successful delete), so lifting it to one shared `ConfirmDialog` keeps
        the exact same trigger, the exact same `handleDelete(id)` call and the
        exact same `isDeleting` busy state.
      */}
      <ConfirmDialog
        open={showConfirmDelete !== null}
        onClose={() => setShowConfirmDelete(null)}
        onConfirm={() => { if (showConfirmDelete) handleDelete(showConfirmDelete); }}
        title="هل أنت متأكد من حذف هذا السؤال؟"
        message="سيتم حذف السؤال نهائياً ولا يمكن التراجع عن هذا الإجراء."
        confirmLabel="نعم، احذف"
        cancelLabel="إلغاء"
        tone="danger"
        icon={AlertTriangle}
        isLoading={isDeleting}
      />
    </div>
  );
}
