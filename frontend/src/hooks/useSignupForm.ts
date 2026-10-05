import { useState, useEffect, useMemo, useCallback } from 'react';
import { authService } from '../services/authService';
import { catalogService, CatalogItem } from '../services/catalogService';
import { logger } from '../services/logger';
import { useCenteredNotification } from '../context/CenteredNotificationContext';
import { EGYPT_GOVERNORATES, EGYPT_PHONE_DIGITS } from '../constants/egyptLocation';

/**
 * The three wizard steps of SignupPage.tsx.
 *
 *   1 personal   — fullName, gender, governorate.
 *   2 academic   — eduType, section, grade.
 *   3 contact    — phones + password. Phones are always plain local digits
 *                  (`EGYPT_PHONE_DIGITS`), the platform serves Egypt only.
 *
 * The grade list is the SAME admin-managed `grades` catalog teacher
 * classification uses, but grades are now grouped under a real, also
 * admin-managed `grade_sections` catalog (open decision 10.12) — a section
 * picker comes before the grade select, and the grade list shown is
 * filtered to the chosen section's own grades. `educationalStage` (a real,
 * separate column on `students`) is still sent on submit, derived
 * automatically from the chosen SECTION's name (see `deriveEducationalStage`
 * below) instead of asked for directly.
 */
export const SIGNUP_STEPS = [
  { id: 1, title: 'بياناتك الشخصية', subtitle: 'اسمك ومحافظتك' },
  { id: 2, title: 'البيانات الأكاديمية', subtitle: 'قسمك وصفّك' },
  { id: 3, title: 'التواصل وكلمة المرور', subtitle: 'تأمين الحساب' },
] as const;

export type SignupStep = 1 | 2 | 3;

/** The four given-name inputs, in the order they are written and concatenated. */
export const NAME_PARTS = [
  { key: 'firstName', label: 'الاسم الأول', placeholder: 'مثال: محمد' },
  { key: 'secondName', label: 'الاسم الثاني', placeholder: 'اسم الأب' },
  { key: 'thirdName', label: 'الاسم الثالث', placeholder: 'اسم الجد' },
  { key: 'fourthName', label: 'الاسم الرابع', placeholder: 'اسم العائلة' },
] as const;

export type NamePartKey = (typeof NAME_PARTS)[number]['key'];

/** Which fields each step owns — used to decide what "this step is valid" means. */
const STEP_FIELDS: Record<SignupStep, string[]> = {
  1: ['firstName', 'secondName', 'thirdName', 'fourthName', 'gender', 'governorate'],
  2: ['eduType', 'sectionId', 'grade'],
  3: ['studentPhone', 'parentPhone', 'password', 'confirmPassword'],
};

/**
 * `students.educational_stage` is a real column separate from `grade_level`,
 * previously populated from a now-removed stage question. Derived from the
 * chosen SECTION's name (not the grade's — see open decision 10.12): every
 * seeded section name contains "إعدادي" or "ثانوي" (see backend
 * CatalogSeeder::SECTIONS) — so an admin who later adds a section whose name
 * contains neither simply gets an empty stage, never a wrong one.
 */
const deriveEducationalStage = (sectionName: string): string => {
  if (sectionName.includes('إعدادي')) return 'المرحلة الإعدادية';
  if (sectionName.includes('ثانوي')) return 'المرحلة الثانوية';
  return '';
};

export const useSignupForm = () => {
  const { notify } = useCenteredNotification();

  // --- Wizard position -------------------------------------------------
  // Field state below is deliberately NOT split per step: one source of truth
  // means going back and forward never loses anything, because nothing is
  // unmounted-and-remounted with its own copy.
  const [step, setStep] = useState<SignupStep>(1);

  // Form Field States
  //
  // The single "الاسم الرباعي" input is gone: a visitor now fills the four
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

  // Egypt is the only market served, so the governorate list is static; the
  // sections/grades lists are the real, admin-managed catalogs fetched once
  // below.
  const governorates = EGYPT_GOVERNORATES;
  const phoneDigits = EGYPT_PHONE_DIGITS;

  const [sections, setSections] = useState<CatalogItem[]>([]);
  const [sectionsLoading, setSectionsLoading] = useState(true);
  const [gradeItems, setGradeItems] = useState<CatalogItem[]>([]);
  const [gradesLoading, setGradesLoading] = useState(true);

  /** The chosen section's grades only — what the <select> actually renders. */
  const grades = useMemo(
    () => gradeItems.filter((g) => g.sectionId === sectionId).map((g) => g.name),
    [gradeItems, sectionId]
  );

  // Password visibility states
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  // Field validation and touched states (real-time tracking)
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [generatedStudentCode, setGeneratedStudentCode] = useState<string>('');

  const setNamePart = (key: NamePartKey, value: string) => {
    setNameParts((prev) => ({ ...prev, [key]: value }));
  };

  /** The single `fullName` the API still receives, rebuilt from the four inputs. */
  const fullName = useMemo(
    () =>
      NAME_PARTS.map(({ key }) => nameParts[key].trim())
        .filter((part) => part !== '')
        .join(' '),
    [nameParts]
  );

  // Both catalogs are public (no session needed) — see catalogService.ts —
  // so this is safe to call before the visitor has any account at all.
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
        logger.error('Failed to load sections/grades for signup', error as Error);
        if (!cancelled) notify('تعذر تحميل قائمة الأقسام والصفوف الدراسية، يرجى إعادة تحميل الصفحة', 'error');
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

  // Validation Rules
  const validateNamePart = (value: string, label: string) => {
    const trimmed = value.trim();
    if (!trimmed) return `${label} مطلوب`;
    if (trimmed.length < 2) return `${label} يجب أن يتكون من حرفين على الأقل`;
    if (/\s/.test(trimmed)) return `${label} يجب أن يكون اسماً واحداً بدون مسافات`;
    return '';
  };

  /**
   * The visitor types the number exactly as they would dial it at home —
   * `EGYPT_PHONE_DIGITS` digits including the leading trunk zero.
   */
  const validatePhoneShape = (phone: string, label: string) => {
    if (!phone) return `${label} مطلوب`;
    if (!/^\d+$/.test(phone)) return 'يجب أن يحتوي رقم الهاتف على أرقام فقط';
    if (!phone.startsWith('0')) return 'يجب أن يبدأ الرقم بصفر كما يُكتب محلياً';
    if (phone.length !== phoneDigits) return `رقم الهاتف يجب أن يتكون من ${phoneDigits} رقم`;
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
    // acceptable as long as it is at least 6 characters. This copy is UX
    // only — the server rejects a too-short password regardless.
    if (pass.length < 6) {
      return 'يجب ألا تقل كلمة المرور عن 6 أحرف';
    }
    return '';
  };

  const validateConfirmPassword = (confirm: string, pass: string) => {
    if (!confirm) return 'تأكيد كلمة المرور مطلوب';
    if (confirm !== pass) return 'كلمتا المرور غير متطابقتين';
    return '';
  };

  const handleBlur = (field: string) => {
    setTouched((prev) => ({ ...prev, [field]: true }));
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

  const isStepValid = useCallback(
    (target: SignupStep) => STEP_FIELDS[target].every((field) => !rawErrors[field]),
    // rawErrors is rebuilt each render from the state below; listing it would
    // change identity every render, so the real inputs are listed instead.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [nameParts, gender, governorate, eduType, sectionId, grade, studentPhone, parentPhone, password, confirmPassword]
  );

  const markStepTouched = (target: SignupStep) => {
    setTouched((prev) => {
      const next = { ...prev };
      STEP_FIELDS[target].forEach((field) => {
        next[field] = true;
      });
      return next;
    });
  };

  const goToNextStep = () => {
    markStepTouched(step);
    if (!isStepValid(step)) return;
    setStep((prev) => (prev < 3 ? ((prev + 1) as SignupStep) : prev));
  };

  const goToPreviousStep = () => {
    setStep((prev) => (prev > 1 ? ((prev - 1) as SignupStep) : prev));
  };

  const isFormValid = () => isStepValid(1) && isStepValid(2) && isStepValid(3);

  const handleSignupSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    // Only the last step can submit; the earlier ones advance instead. This
    // is what guarantees no partial POST ever leaves the page.
    if (step !== 3) {
      goToNextStep();
      return;
    }

    markStepTouched(1);
    markStepTouched(2);
    markStepTouched(3);

    if (isFormValid()) {
      if (isSubmitting) return;
      try {
        setIsSubmitting(true);
        const chosenSection = sections.find((s) => s.id === sectionId);
        const data = await authService.register({
          fullName,
          gender,
          governorate,
          educationalStage: deriveEducationalStage(chosenSection?.name ?? ''),
          gradeLevel: grade,
          eduType,
          // Sent exactly as typed — plain local digits, leading zero included.
          studentPhone,
          parentPhone,
          password,
        });

        if (data?.studentCode) setGeneratedStudentCode(data.studentCode);
        setIsSubmitted(true);
      } catch (error) {
        logger.error('Signup Failed', error as Error);
        notify('حدث خطأ أثناء إنشاء الحساب، يرجى المحاولة مرة أخرى', 'error');
      } finally {
        setIsSubmitting(false);
      }
    } else {
      // Land the visitor on the first step that still has a problem instead of
      // failing silently on a step they cannot see.
      const firstBadStep = ([1, 2, 3] as SignupStep[]).find((s) => !isStepValid(s));
      if (firstBadStep && firstBadStep !== step) {
        setStep(firstBadStep);
        return;
      }
      const firstErrorElement = document.querySelector('.error-text');
      if (firstErrorElement) {
        firstErrorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }
  };

  return {
    step, setStep,
    goToNextStep, goToPreviousStep,
    isStepValid,
    nameParts, setNamePart,
    fullName,
    studentPhone, setStudentPhone,
    parentPhone, setParentPhone,
    governorate, setGovernorate,
    gender, setGender,
    eduType, setEduType,
    sectionId, setSectionId,
    grade, setGrade,
    password, setPassword,
    confirmPassword, setConfirmPassword,
    showPassword, setShowPassword,
    showConfirmPassword, setShowConfirmPassword,
    phoneDigits,
    sections,
    sectionsLoading,
    grades,
    gradesLoading,
    governorates,
    errors,
    handleBlur,
    isSubmitted, setIsSubmitted,
    isSubmitting,
    setTouched,
    generatedStudentCode,
    handleSignupSubmit
  };
};
