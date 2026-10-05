import React, { useState, useEffect, useMemo } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import {
  User, Phone, MapPin, Lock, Eye, EyeOff, Check,
  GraduationCap, School, Layers, ArrowLeft, ShieldCheck, Sparkles, CheckCircle2, UserPlus
} from 'lucide-react';
import { useNavigate } from 'react-router';
import { authService } from '../../services/authService';
import { catalogService, CatalogItem } from '../../services/catalogService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { EGYPT_GOVERNORATES, EGYPT_PHONE_DIGITS } from '../../constants/egyptLocation';
import {
  PageHeader,
  CardHeader,
  Button,
  Badge,
  Input,
  Select,
  FormField,
  cn,
} from '../UI';

/**
 * Selectable chip used by the النوع / المرحلة / نوع التعليم pickers.
 *
 * These stay `<button type="button">` rather than becoming the design system's
 * `RadioGroup`: each click also flips a `touched` flag, and the surrounding
 * validation reads that flag, so swapping the control type would change
 * behaviour. This is styling only — `selected` and `onClick` are passed
 * straight through.
 */
function ChoiceChip({
  selected,
  onClick,
  tone = 'brand',
  size = 'md',
  children,
}: {
  selected: boolean;
  onClick: () => void;
  /** 'indigo' exists only for the أنثى chip, which was indigo before. */
  tone?: 'brand' | 'indigo';
  size?: 'sm' | 'md' | 'lg';
  children: React.ReactNode;
}) {
  const SIZES = {
    sm: 'py-2 px-3 rounded-xl text-xs sm:text-sm',
    md: 'py-3 px-6 rounded-2xl text-sm sm:text-base',
    lg: 'py-3.5 px-6 rounded-2xl text-base md:text-lg flex-col gap-1',
  } as const;

  const SELECTED = {
    brand:
      'bg-blue-50 dark:bg-blue-500/10 border-blue-600 text-blue-700 dark:text-blue-400 ring-2 ring-blue-500/15',
    indigo:
      'bg-indigo-50 dark:bg-indigo-500/10 border-indigo-600 text-indigo-700 dark:text-indigo-400 ring-2 ring-indigo-500/15',
  } as const;

  return (
    <button
      type="button"
      aria-pressed={selected}
      onClick={onClick}
      className={cn(
        'flex items-center justify-center gap-2 border font-black transition-all cursor-pointer text-center',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
        SIZES[size],
        selected
          ? SELECTED[tone]
          : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800 hover:border-slate-300 dark:hover:border-slate-700',
      )}
    >
      {children}
    </button>
  );
}

/**
 * Purely decorative — the four form sections in display order, each with the
 * one accent color that identifies it (a numbered badge repeated above its
 * own block). Mirrors the exact pattern introduced in AddTeacher.tsx; carries
 * no behaviour and does not reorder any field.
 */
const FORM_STEPS = [
  { label: 'الاسم الرباعي', icon: User, color: 'blue' as const },
  { label: 'أرقام التواصل', icon: Phone, color: 'violet' as const },
  { label: 'المحافظة والمستوى الدراسي', icon: School, color: 'emerald' as const },
  { label: 'كلمة المرور', icon: Lock, color: 'amber' as const },
];

const STEP_TONES = {
  blue: { bg: 'bg-blue-500', text: 'text-blue-600 dark:text-blue-400', ring: 'shadow-blue-500/30', line: 'from-blue-500/50' },
  violet: { bg: 'bg-violet-500', text: 'text-violet-600 dark:text-violet-400', ring: 'shadow-violet-500/30', line: 'from-violet-500/50' },
  emerald: { bg: 'bg-emerald-500', text: 'text-emerald-600 dark:text-emerald-400', ring: 'shadow-emerald-500/30', line: 'from-emerald-500/50' },
  amber: { bg: 'bg-amber-500', text: 'text-amber-600 dark:text-amber-400', ring: 'shadow-amber-500/30', line: 'from-amber-500/50' },
} as const;

/** One numbered, color-coded badge — placed above each form section. */
function StepBadge({ index }: { index: number }) {
  const step = FORM_STEPS[index];
  const tone = STEP_TONES[step.color];
  const Icon = step.icon;
  return (
    <div className={`shrink-0 w-9 h-9 rounded-2xl ${tone.bg} text-white flex items-center justify-center shadow-lg ${tone.ring}`}>
      <Icon className="w-4 h-4" aria-hidden="true" />
    </div>
  );
}

/** One label/value pair inside the success screen's student card. */
function SummaryItem({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <span className="block text-xs text-slate-500 dark:text-slate-400 font-bold mb-1">{label}</span>
      <span className="text-sm font-black text-slate-800 dark:text-slate-100">{children}</span>
    </div>
  );
}

/**
 * The server owns every rule this form mirrors (phone shape, password policy,
 * duplicate phone), so its Arabic message is always more accurate than
 * anything invented here. Typed against
 * `unknown` rather than `any` so a shape change is a compile error, not a
 * runtime `undefined`.
 */
const apiMessage = (error: unknown, fallback: string): string => {
  if (typeof error === 'object' && error !== null && 'response' in error) {
    const { response } = error as { response?: { data?: { message?: unknown } } };
    const message = response?.data?.message;
    if (typeof message === 'string' && message.trim() !== '') return message;
  }
  return fallback;
};

/**
 * `students.educational_stage` is a real column separate from `grade_level`.
 * There used to be a dedicated "المرحلة" chip picker that set it directly;
 * removed along with the stage-scoped grade list. Grades are now grouped
 * under a real, admin-managed `grade_sections` catalog (open decision
 * 10.12), and the stage is derived from the chosen SECTION's own name, same
 * as the public signup wizard (useSignupForm.ts) — every seeded section name
 * contains "إعدادي" or "ثانوي".
 */
const deriveEducationalStage = (sectionName: string): string => {
  if (sectionName.includes('إعدادي')) return 'المرحلة الإعدادية';
  if (sectionName.includes('ثانوي')) return 'المرحلة الثانوية';
  return '';
};

/** The four given-name inputs, in the order they are written and concatenated. */
const NAME_PARTS = [
  { key: 'firstName', label: 'الاسم الأول', placeholder: 'مثال: محمد' },
  { key: 'secondName', label: 'الاسم الثاني', placeholder: 'اسم الأب' },
  { key: 'thirdName', label: 'الاسم الثالث', placeholder: 'اسم الجد' },
  { key: 'fourthName', label: 'الاسم الرابع', placeholder: 'اسم العائلة' },
] as const;

type NamePartKey = (typeof NAME_PARTS)[number]['key'];

export default function AddStudent() {
  const navigate = useNavigate();
  const { notify } = useCenteredNotification();

  // Form Field States
  //
  // The single "الاسم الرباعي" input is gone: an admin now fills the four
  // given names separately, which is how the name is actually dictated and
  // removes the old "did you leave enough spaces" guesswork. The BACKEND
  // CONTRACT IS UNCHANGED — the four values are joined with single spaces into
  // the same `fullName` string on submit, and no column was added.
  const [nameParts, setNameParts] = useState<Record<NamePartKey, string>>({
    firstName: '',
    secondName: '',
    thirdName: '',
    fourthName: '',
  });
  const [studentPhone, setStudentPhone] = useState('');
  const [parentPhone, setParentPhone] = useState('');
  const [governorate, setGovernorate] = useState('');
  const [gender, setGender] = useState<'ذكر' | 'أنثى' | ''>('');
  const [eduType, setEduType] = useState<'عام' | 'بكالوريا' | 'أزهري' | ''>('');
  const [sectionId, setSectionId] = useState('');
  const [grade, setGrade] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');

  // Egypt is the only market served, so the governorate list is static.
  const governorates = EGYPT_GOVERNORATES;

  // The same admin-managed `grade_sections`/`grades` catalogs teacher
  // classification uses — GET /catalogs/{type}, public, already in the
  // admin's own display order. Grades are scoped to the chosen section
  // (open decision 10.12).
  const [sections, setSections] = useState<CatalogItem[]>([]);
  const [sectionsLoading, setSectionsLoading] = useState(true);
  const [gradeItems, setGradeItems] = useState<CatalogItem[]>([]);
  const [gradesLoading, setGradesLoading] = useState(true);

  const grades = useMemo(
    () => gradeItems.filter((g) => g.sectionId === sectionId).map((g) => g.name),
    [gradeItems, sectionId]
  );

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const [sectionList, gradeList] = await Promise.all([
          catalogService.list('grade_sections'),
          catalogService.list('grades'),
        ]);
        if (cancelled) return;
        setSections(sectionList);
        setGradeItems(gradeList);
      } catch (error) {
        logger.error('Failed to load sections/grades for AddStudent', error as Error);
        if (!cancelled) notify('تعذر تحميل قائمة الأقسام والصفوف الدراسية من الخادم', 'error');
      } finally {
        if (!cancelled) {
          setSectionsLoading(false);
          setGradesLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [notify]);

  // A previously-chosen grade that no longer belongs to the newly-chosen
  // section must not be left behind selected but invisible.
  useEffect(() => {
    setGrade((current) => (grades.includes(current) ? current : ''));
  }, [grades]);

  // Password visibility states
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  // Field validation and touched states
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [generatedStudentCode, setGeneratedStudentCode] = useState<string>('');
  const [submittedName, setSubmittedName] = useState('');

  const phoneDigits = EGYPT_PHONE_DIGITS;

  /** The single `fullName` the API still receives, rebuilt from the four inputs. */
  const fullName = useMemo(
    () =>
      NAME_PARTS.map(({ key }) => nameParts[key].trim())
        .filter((part) => part !== '')
        .join(' '),
    [nameParts]
  );

  // Validation Rules
  const validateNamePart = (value: string, label: string) => {
    const trimmed = value.trim();
    if (!trimmed) return `${label} مطلوب`;
    if (trimmed.length < 2) return `${label} يجب أن يتكون من حرفين على الأقل`;
    if (/\s/.test(trimmed)) return `${label} يجب أن يكون اسماً واحداً بدون مسافات`;
    return '';
  };

  /**
   * The admin keeps typing the number in plain LOCAL form (01012345678) — no
   * country code, no `+` — which is what the server expects and what the
   * username is built from.
   */
  const validatePhoneShape = (phone: string, label: string) => {
    if (!phone) return `${label} مطلوب`;
    if (!/^\d+$/.test(phone)) return 'يجب أن يحتوي رقم الهاتف على أرقام فقط';
    if (phone.length !== phoneDigits) return `${label} يجب أن يتكون من ${phoneDigits} رقماً تماماً`;
    return '';
  };

  const validateStudentPhone = (phone: string) => validatePhoneShape(phone, 'رقم هاتف الطالب');

  const validateParentPhone = (phone: string, stPhone: string) => {
    const shape = validatePhoneShape(phone, 'رقم هاتف ولي الأمر');
    if (shape) return shape;
    if (phone === stPhone && stPhone !== '') {
      return 'لا يمكن أن يتطابق رقم هاتف ولي الأمر مع هاتف الطالب';
    }
    return '';
  };

  const validatePassword = (pass: string) => {
    if (!pass) return 'كلمة المرور مطلوبة';
    // Mirrors backend/app/Rules/StudentPasswordPolicy.php — any password is
    // acceptable as long as it is at least 6 characters.
    if (pass.length < 6) return 'يجب ألا تقل كلمة المرور عن 6 أحرف';
    return '';
  };

  const validateConfirmPassword = (confirm: string, pass: string) => {
    if (!confirm) return 'تأكيد كلمة المرور مطلوب';
    if (confirm !== pass) return 'كلمتا المرور غير متطابقتين';
    return '';
  };

  const handleBlur = (field: string) => {
    setTouched(prev => ({ ...prev, [field]: true }));
  };

  const setNamePart = (key: NamePartKey, value: string) => {
    setNameParts(prev => ({ ...prev, [key]: value }));
  };

  // Every field's error, whether or not it is currently displayed.
  const rawErrors: Record<string, string> = {
    ...Object.fromEntries(
      NAME_PARTS.map(({ key, label }) => [key, validateNamePart(nameParts[key], label)])
    ),
    studentPhone: validateStudentPhone(studentPhone),
    parentPhone: validateParentPhone(parentPhone, studentPhone),
    governorate: !governorate ? 'الرجاء اختيار المحافظة' : '',
    gender: !gender ? 'الرجاء اختيار الجنس' : '',
    eduType: !eduType ? 'الرجاء اختيار نوع التعليم' : '',
    sectionId: !sectionId ? 'الرجاء اختيار القسم' : '',
    grade: !grade ? 'الرجاء اختيار الصف الدراسي' : '',
    password: validatePassword(password),
    confirmPassword: validateConfirmPassword(confirmPassword, password),
  };

  // Displayed errors: an untouched field stays silent, exactly as before.
  const errors: Record<string, string> = Object.fromEntries(
    Object.entries(rawErrors).map(([field, message]) => [field, touched[field] ? message : ''])
  );

  const isFormValid = () =>
    Object.values(rawErrors).every((message) => message === '') &&
    Boolean(eduType);

  const handleAddStudentSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const allTouched: Record<string, boolean> = Object.fromEntries(
      Object.keys(rawErrors).map((field) => [field, true])
    );
    setTouched(allTouched);

    if (isFormValid()) {
      try {
        // The student code is issued by the BACKEND
        // (AuthService::generateStudentCode -> 'STU' + 6 digits) and is what
        // actually lands in students.student_code. This page used to invent
        // its own 8-digit number client-side, show it to the admin as "the
        // student's code", and then throw it away — the value displayed on
        // the success screen did not exist anywhere in the database and
        // could never be used to find the student. The real code returned by
        // the API is used now.
        const result = await authService.register({
          // Rebuilt from the four inputs — the endpoint's shape is untouched.
          fullName,
          studentPhone,
          parentPhone,
          governorate,
          // The form validates `gender` as required but never sent it, so
          // students.gender was written NULL for every admin-created student.
          gender,
          educationalStage: deriveEducationalStage(sections.find((s) => s.id === sectionId)?.name ?? ''),
          gradeLevel: grade,
          eduType,
          password
        });

        setGeneratedStudentCode(result?.studentCode ?? '');
        // Frozen for the success screen so resetting the inputs later cannot
        // blank out the name the admin is still reading.
        setSubmittedName(fullName);
      } catch (error) {
        logger.error('Error saving new student', error as Error);
        notify(
          apiMessage(error, 'عذراً، حدث خطأ أثناء إضافة الطالب. يرجى المحاولة لاحقاً.'),
          'error'
        );
        return;
      }

      setIsSubmitted(true);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  const handleResetForm = () => {
    setNameParts({ firstName: '', secondName: '', thirdName: '', fourthName: '' });
    setStudentPhone('');
    setParentPhone('');
    setGovernorate('');
    setGender('');
    setEduType('');
    setSectionId('');
    setGrade('');
    setPassword('');
    setConfirmPassword('');
    setTouched({});
    setIsSubmitted(false);
    setGeneratedStudentCode('');
    setSubmittedName('');
  };

  /** Placeholder text for the grade select — gated on a section being chosen first. */
  const dependentPlaceholder = (ready: string, none: string) => {
    if (!sectionId) return '-- اختر القسم أولاً --';
    if (gradesLoading) return '-- جاري تحميل الصفوف... --';
    if (grades.length === 0) return none;
    return ready;
  };

  return (
    <div dir="rtl" className="relative space-y-6 text-right">
      {/* Decorative background glows — same low-opacity blurred-blob
          technique used on AddTeacher.tsx/LoginPage.tsx/SignupPage.tsx. */}
      <div className="absolute -top-6 right-1/4 w-[320px] h-[320px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-52 left-1/4 w-[360px] h-[360px] bg-emerald-500/8 dark:bg-emerald-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[280px] h-[280px] bg-violet-500/6 dark:bg-violet-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Page Header */}
      <PageHeader
        className="mb-0"
        icon={UserPlus}
        title="إضافة طالب جديد"
        description="تسجيل حساب طالب جديد"
      />

      <div className="max-w-4xl mx-auto">
        <AnimatePresence mode="wait">

          {/* SUCCESS SIGNUP STATE */}
          {isSubmitted ? (
            <motion.div
              key="signup-success"
              initial={{ opacity: 0, scale: 0.95, y: 15 }}
              animate={{ opacity: 1, scale: 1, y: 0 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.5 }}
            >
              <div className="text-center relative overflow-hidden">
                <div className="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-l from-emerald-500 to-emerald-400" />

                <div className="w-20 h-20 rounded-full bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-500/20 flex items-center justify-center mx-auto mb-6 mt-2">
                  <CheckCircle2 className="w-11 h-11" />
                </div>

                <Badge tone="success" icon={Sparkles} className="mb-3">
                  تم تسجيل الطالب بنجاح
                </Badge>

                <h2 className="text-xl sm:text-2xl font-black text-slate-900 dark:text-white mb-3 mt-3 tracking-tight">
                  الحساب مفعّل وجاهز للاستخدام! 🎉
                </h2>

                <p className="text-sm font-normal text-slate-500 dark:text-slate-400 mb-8 leading-relaxed max-w-lg mx-auto">
                  تم تسجيل الطالب <strong className="font-bold text-slate-800 dark:text-slate-100">"{submittedName}"</strong> بنجاح. الكود الخاص به مفعل وتلقائي في النظام لربط جميع المشتروات والدروس مستقبلاً.
                </p>

                {/* Student ID Card */}
                <div className="p-5 sm:p-6 rounded-2xl bg-slate-50 dark:bg-slate-950/60 border border-slate-200/70 dark:border-slate-800 max-w-lg mx-auto text-right mb-8">
                  <h3 className="text-xs font-bold text-slate-500 dark:text-slate-400 mb-4 pb-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-2">
                    <span>بطاقة الطالب المحفوظة في لوحة التحكم</span>
                    <ShieldCheck className="w-4 h-4 text-emerald-500 shrink-0" />
                  </h3>

                  <div className="grid grid-cols-2 gap-y-4 gap-x-4">
                    <div className="col-span-2 bg-blue-50/70 dark:bg-blue-500/5 p-3.5 rounded-xl border border-blue-100 dark:border-blue-500/20 flex items-center justify-between gap-3 flex-wrap text-right">
                      <div className="space-y-0.5 min-w-0">
                        <span className="block text-blue-700 dark:text-blue-400 font-bold text-xs">كود الطالب الأكاديمي المولد تلقائياً</span>
                        <p className="text-[11px] text-slate-500 dark:text-slate-400 font-medium">يرجى نسخ هذا الكود لتزويد الطالب به أو فحص بياناته.</p>
                      </div>
                      <span className="font-mono font-black text-lg md:text-xl text-blue-600 dark:text-blue-400 tracking-wider bg-white dark:bg-slate-900 px-3.5 py-1 rounded-xl shadow-sm border border-blue-100 dark:border-blue-500/20 select-all shrink-0">{generatedStudentCode}</span>
                    </div>

                    <SummaryItem label="اسم الطالب رباعي">{submittedName}</SummaryItem>
                    <SummaryItem label="رقم الهاتف (اسم المستخدم)">
                      <span className="font-mono font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-500/10 px-2 py-0.5 rounded select-all" dir="ltr">{studentPhone}</span>
                    </SummaryItem>
                    <SummaryItem label="الصف الدراسي">{grade}</SummaryItem>
                    <SummaryItem label="المحافظة الجغرافية">{governorate}</SummaryItem>
                  </div>
                </div>

                {/* Action buttons */}
                <div className="flex flex-col sm:flex-row gap-3 justify-center items-stretch sm:items-center">
                  <Button size="lg" icon={UserPlus} onClick={handleResetForm}>
                    تسجيل طالب آخر جديد
                  </Button>
                  <Button size="lg" variant="secondary" onClick={() => navigate('/admin/users')}>
                    الذهاب لجدول الطلاب والتحكم
                  </Button>
                </div>
              </div>
            </motion.div>
          ) : (
            <motion.div
              key="signup-form"
              initial={{ opacity: 0, x: -15 }}
              animate={{ opacity: 1, x: 0 }}
              exit={{ opacity: 0, x: 15 }}
              transition={{ duration: 0.4 }}
            >
              <div>

              <CardHeader
                className="mb-8"
                icon={UserPlus}
                title="بيانات حساب الطالب الجديد"
                description="تطابق تام من حيث الحقول والتحققات مع النموذج الخارجي لتسجيل الطلاب المباشر."
              />

              <form onSubmit={handleAddStudentSubmit} className="space-y-6" id="student_add_form_admin">

                {/* Quadruple name — four separate inputs, one `fullName` on submit */}
                <motion.div
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0 * 0.08, duration: 0.35 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3 px-1">
                    <StepBadge index={0} />
                    <span className={`text-sm font-black ${STEP_TONES[FORM_STEPS[0].color].text}`}>
                      1. {FORM_STEPS[0].label}
                    </span>
                    <div className={`h-px flex-1 bg-gradient-to-l ${STEP_TONES[FORM_STEPS[0].color].line} to-transparent`} />
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {NAME_PARTS.map(({ key, label, placeholder }) => (
                      <FormField key={key} label={label} required error={errors[key]}>
                        {({ id, hasError }) => (
                          <div className="relative">
                            <Input
                              id={id}
                              size="lg"
                              type="text"
                              icon={User}
                              hasError={hasError}
                              placeholder={placeholder}
                              value={nameParts[key]}
                              onChange={(e) => setNamePart(key, e.target.value)}
                              onBlur={() => handleBlur(key)}
                              className="font-bold pe-10"
                            />
                            {!rawErrors[key] && (
                              <div className="absolute end-3.5 top-1/2 -translate-y-1/2 text-emerald-500 pointer-events-none">
                                <Check className="w-4.5 h-4.5" />
                              </div>
                            )}
                          </div>
                        )}
                      </FormField>
                    ))}
                  </div>

                  {fullName !== '' && (
                    <p className="text-xs font-bold text-slate-500 dark:text-slate-400">
                      الاسم الرباعي كما سيُحفظ:{' '}
                      <span className="text-slate-800 dark:text-slate-100">{fullName}</span>
                    </p>
                  )}
                </motion.div>

                {/* Phone Numbers */}
                <motion.div
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 1 * 0.08, duration: 0.35 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3 px-1">
                    <StepBadge index={1} />
                    <span className={`text-sm font-black ${STEP_TONES[FORM_STEPS[1].color].text}`}>
                      2. {FORM_STEPS[1].label}
                    </span>
                    <div className={`h-px flex-1 bg-gradient-to-l ${STEP_TONES[FORM_STEPS[1].color].line} to-transparent`} />
                  </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">

                  {/* Student Mobile */}
                  <FormField label="رقم هاتف الطالب (اسم المستخدم)" required error={errors.studentPhone}>
                    {({ id, hasError }) => (
                      <div className="relative">
                        <Input
                          id={id}
                          size="lg"
                          type="tel"
                          icon={Phone}
                          hasError={hasError}
                          maxLength={phoneDigits}
                          placeholder={`${phoneDigits} أرقام تبدأ بصفر`}
                          value={studentPhone}
                          onChange={(e) => setStudentPhone(e.target.value.replace(/\D/g, ''))}
                          onBlur={() => handleBlur('studentPhone')}
                          className="text-right font-mono font-bold tracking-wider pe-24"
                        />
                        {studentPhone && (
                          <div className="absolute end-3.5 top-1/2 -translate-y-1/2 text-[11px] bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded font-sans text-slate-500 dark:text-slate-400 font-bold pointer-events-none">
                            {studentPhone.length}/{phoneDigits} رقماً
                          </div>
                        )}
                      </div>
                    )}
                  </FormField>

                  {/* Parent Mobile */}
                  <FormField label="رقم هاتف ولي الأمر" required error={errors.parentPhone}>
                    {({ id, hasError }) => (
                      <div className="relative">
                        <Input
                          id={id}
                          size="lg"
                          type="tel"
                          icon={Phone}
                          hasError={hasError}
                          maxLength={phoneDigits}
                          placeholder={`${phoneDigits} أرقام تبدأ بصفر`}
                          value={parentPhone}
                          onChange={(e) => setParentPhone(e.target.value.replace(/\D/g, ''))}
                          onBlur={() => handleBlur('parentPhone')}
                          className="text-right font-mono font-bold tracking-wider pe-24"
                        />
                        {parentPhone && (
                          <div className="absolute end-3.5 top-1/2 -translate-y-1/2 text-[11px] bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded font-sans text-slate-500 dark:text-slate-400 font-bold pointer-events-none">
                            {parentPhone.length}/{phoneDigits} رقماً
                          </div>
                        )}
                      </div>
                    )}
                  </FormField>

                </div>
                </motion.div>

                {/* Governorate, Gender, and academic level */}
                <motion.div
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 2 * 0.08, duration: 0.35 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3 px-1">
                    <StepBadge index={2} />
                    <span className={`text-sm font-black ${STEP_TONES[FORM_STEPS[2].color].text}`}>
                      3. {FORM_STEPS[2].label}
                    </span>
                    <div className={`h-px flex-1 bg-gradient-to-l ${STEP_TONES[FORM_STEPS[2].color].line} to-transparent`} />
                  </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">

                  {/* Governorate Select */}
                  <FormField label="المحافظة" required error={errors.governorate}>
                    {({ id, hasError }) => (
                      <div className="relative">
                        <MapPin className="pointer-events-none absolute top-1/2 -translate-y-1/2 start-3 w-4 h-4 text-slate-400 dark:text-slate-500 z-10" />
                        <Select
                          id={id}
                          size="lg"
                          hasError={hasError}
                          value={governorate}
                          onChange={(e) => setGovernorate(e.target.value)}
                          onBlur={() => handleBlur('governorate')}
                          className="font-bold ps-9"
                        >
                          <option value="">-- اختر محافظة الطالب --</option>
                          {governorates.map((gov) => (
                            <option key={gov} value={gov}>{gov}</option>
                          ))}
                        </Select>
                      </div>
                    )}
                  </FormField>

                  {/* Gender */}
                  <FormField label="النوع" required error={errors.gender}>
                    <div className="grid grid-cols-2 gap-4">
                      <ChoiceChip
                        selected={gender === 'ذكر'}
                        onClick={() => {
                          setGender('ذكر');
                          setTouched(prev => ({ ...prev, gender: true }));
                        }}
                      >
                        <span>ذكر</span>
                        {gender === 'ذكر' && <Check className="w-4 h-4 shrink-0" />}
                      </ChoiceChip>

                      <ChoiceChip
                        tone="indigo"
                        selected={gender === 'أنثى'}
                        onClick={() => {
                          setGender('أنثى');
                          setTouched(prev => ({ ...prev, gender: true }));
                        }}
                      >
                        <span>أنثى</span>
                        {gender === 'أنثى' && <Check className="w-4 h-4 shrink-0" />}
                      </ChoiceChip>
                    </div>
                  </FormField>

                </div>

                {/* Level / Stage Section */}
                <div className="p-5 sm:p-6 rounded-2xl bg-slate-50 dark:bg-slate-950/40 border border-slate-200/70 dark:border-slate-800">
                  <h3 className="text-sm font-black text-slate-500 dark:text-slate-400 tracking-wider mb-4 flex items-center gap-2">
                    <School className="w-4 h-4 text-blue-500 shrink-0" />
                    المستوى الدراسي للطالب
                  </h3>

                  <FormField label="نوع التعليم" required error={errors.eduType}>
                    <div className="grid grid-cols-3 gap-3">
                      {(['عام', 'بكالوريا', 'أزهري'] as const).map((type) => (
                        <ChoiceChip
                          key={type}
                          size="sm"
                          selected={eduType === type}
                          onClick={() => {
                            setEduType(type);
                            setTouched(prev => ({ ...prev, eduType: true }));
                          }}
                        >
                          {type}
                        </ChoiceChip>
                      ))}
                    </div>
                  </FormField>

                  {/* Section — the admin-managed catalog grouping the grade
                      list (open decision 10.12). Chosen first, as cards, so
                      the grade select below can be scoped to it. */}
                  <div className="pt-4">
                    <FormField label="القسم" required error={errors.sectionId}>
                      {sectionsLoading ? (
                        <div className="grid grid-cols-2 gap-3" aria-hidden="true">
                          {[0, 1].map((i) => (
                            <div key={i} className="h-[52px] rounded-2xl bg-slate-100 dark:bg-slate-900 animate-pulse" />
                          ))}
                        </div>
                      ) : (
                        <div className="grid grid-cols-2 gap-3">
                          {sections.map((section) => (
                            <ChoiceChip
                              key={section.id}
                              size="sm"
                              selected={sectionId === section.id}
                              onClick={() => {
                                setSectionId(section.id);
                                setTouched(prev => ({ ...prev, sectionId: true }));
                              }}
                            >
                              <Layers className="w-4 h-4 shrink-0" />
                              {section.name}
                            </ChoiceChip>
                          ))}
                        </div>
                      )}
                    </FormField>
                  </div>

                  {/* Grade — sourced from the shared admin-managed catalog,
                      filtered to the chosen section */}
                  <div className="pt-4">
                    <FormField
                      label="الصف الدراسي"
                      required
                      error={errors.grade}
                    >
                      {({ id, hasError }) => (
                        <div className="relative">
                          <GraduationCap className="pointer-events-none absolute top-1/2 -translate-y-1/2 start-3 w-4 h-4 text-slate-400 dark:text-slate-500 z-10" />
                          <Select
                            id={id}
                            size="lg"
                            hasError={hasError}
                            disabled={!sectionId || gradesLoading}
                            value={grade}
                            onChange={(e) => setGrade(e.target.value)}
                            onBlur={() => handleBlur('grade')}
                            className="font-bold ps-9"
                          >
                            <option value="">
                              {dependentPlaceholder('-- اختر الصف الدراسي --', '-- لا توجد صفوف متاحة --')}
                            </option>
                            {grades.map((g) => (
                              <option key={g} value={g}>{g}</option>
                            ))}
                          </Select>
                        </div>
                      )}
                    </FormField>
                  </div>

                </div>
                </motion.div>

                {/* Passwords */}
                <motion.div
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 3 * 0.08, duration: 0.35 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3 px-1">
                    <StepBadge index={3} />
                    <span className={`text-sm font-black ${STEP_TONES[FORM_STEPS[3].color].text}`}>
                      4. {FORM_STEPS[3].label}
                    </span>
                    <div className={`h-px flex-1 bg-gradient-to-l ${STEP_TONES[FORM_STEPS[3].color].line} to-transparent`} />
                  </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">

                  {/* Password Field */}
                  <FormField label="كلمة المرور" required error={errors.password}>
                    {({ id, hasError }) => (
                      <div className="relative">
                        <Input
                          id={id}
                          size="lg"
                          icon={Lock}
                          hasError={hasError}
                          type={showPassword ? "text" : "password"}
                          placeholder="لا تقل عن 8 أحرف أو أرقام"
                          value={password}
                          onChange={(e) => setPassword(e.target.value)}
                          onBlur={() => handleBlur('password')}
                          className="font-bold pe-11"
                        />
                        <button
                          type="button"
                          onClick={() => setShowPassword(!showPassword)}
                          aria-label={showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
                          className="absolute end-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-blue-500 transition-colors p-1 rounded cursor-pointer"
                        >
                          {showPassword ? <EyeOff className="w-4.5 h-4.5" /> : <Eye className="w-4.5 h-4.5" />}
                        </button>
                      </div>
                    )}
                  </FormField>

                  {/* Confirm Password */}
                  <FormField label="تأكيد كلمة المرور" required error={errors.confirmPassword}>
                    {({ id, hasError }) => (
                      <div className="relative">
                        <Input
                          id={id}
                          size="lg"
                          icon={Lock}
                          hasError={hasError}
                          type={showConfirmPassword ? "text" : "password"}
                          placeholder="أعد كتابة كلمة المرور للتحقق"
                          value={confirmPassword}
                          onChange={(e) => setConfirmPassword(e.target.value)}
                          onBlur={() => handleBlur('confirmPassword')}
                          className="font-bold pe-11"
                        />
                        <button
                          type="button"
                          onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                          aria-label={showConfirmPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
                          className="absolute end-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-blue-500 transition-colors p-1 rounded cursor-pointer"
                        >
                          {showConfirmPassword ? <EyeOff className="w-4.5 h-4.5" /> : <Eye className="w-4.5 h-4.5" />}
                        </button>
                      </div>
                    )}
                  </FormField>

                </div>
                </motion.div>

                {/* Submit Register */}
                <Button
                  type="submit"
                  size="lg"
                  fullWidth
                  trailingIcon={ArrowLeft}
                  className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
                >
                  تسجيل حساب الطالب للأبد
                </Button>

              </form>
              </div>

            </motion.div>
          )}

        </AnimatePresence>
      </div>
    </div>
  );
}
