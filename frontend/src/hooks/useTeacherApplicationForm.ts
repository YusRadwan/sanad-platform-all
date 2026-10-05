import { useState, useEffect, useMemo, useCallback } from 'react';
import { teacherApplicationService } from '../services/teacherApplicationService';
import { catalogService, CatalogItem } from '../services/catalogService';
import { logger } from '../services/logger';
import { useCenteredNotification } from '../context/CenteredNotificationContext';
import { EGYPT_GOVERNORATES, EGYPT_PHONE_DIGITS } from '../constants/egyptLocation';

/**
 * The three wizard steps of TeacherApplicationPage.tsx — same "one shared
 * state, never split per step" architecture as useSignupForm.ts, so going
 * back and forward never loses anything.
 *
 *   1 personal   — the four name parts, phone, whatsapp, governorate, age.
 *   2 teaching   — years of experience, subject, section (single choice),
 *                  grades (multi-select, scoped to the chosen section).
 *   3 links      — social/educational links (add/remove list) + submit.
 */
export const APPLICATION_STEPS = [
  { id: 1, title: 'بياناتك الشخصية', subtitle: 'اسمك وبيانات التواصل' },
  { id: 2, title: 'بيانات التدريس', subtitle: 'مادتك ومرحلتك وصفوفك' },
  { id: 3, title: 'روابط التواصل', subtitle: 'أرسل طلبك' },
] as const;

export type ApplicationStep = 1 | 2 | 3;

/** The four given-name inputs — identical convention to NAME_PARTS in useSignupForm.ts. */
export const TEACHER_NAME_PARTS = [
  { key: 'firstName', label: 'الاسم الأول', placeholder: 'مثال: أحمد' },
  { key: 'secondName', label: 'الاسم الثاني', placeholder: 'اسم الأب' },
  { key: 'thirdName', label: 'الاسم الثالث', placeholder: 'اسم الجد' },
  { key: 'fourthName', label: 'الاسم الرابع', placeholder: 'اسم العائلة' },
] as const;

export type TeacherNamePartKey = (typeof TEACHER_NAME_PARTS)[number]['key'];

const STEP_FIELDS: Record<ApplicationStep, string[]> = {
  1: ['firstName', 'secondName', 'thirdName', 'fourthName', 'phone', 'whatsapp', 'governorate', 'age'],
  2: ['yearsOfExperience', 'subject', 'sectionId', 'grades'],
  3: ['socialLinks'],
};

export const useTeacherApplicationForm = () => {
  const { notify } = useCenteredNotification();

  const [step, setStep] = useState<ApplicationStep>(1);

  const [nameParts, setNameParts] = useState<Record<TeacherNamePartKey, string>>({
    firstName: '',
    secondName: '',
    thirdName: '',
    fourthName: '',
  });
  const [phone, setPhone] = useState('');
  const [whatsapp, setWhatsapp] = useState('');
  const [governorate, setGovernorate] = useState('');
  const [age, setAge] = useState('');
  const [yearsOfExperience, setYearsOfExperience] = useState('');
  const [subject, setSubject] = useState('');
  const [sectionId, setSectionId] = useState('');
  const [selectedGrades, setSelectedGrades] = useState<string[]>([]);
  const [socialLinks, setSocialLinks] = useState<string[]>(['']);

  const governorates = EGYPT_GOVERNORATES;
  const phoneDigits = EGYPT_PHONE_DIGITS;

  const [sections, setSections] = useState<CatalogItem[]>([]);
  const [sectionsLoading, setSectionsLoading] = useState(true);
  const [gradeItems, setGradeItems] = useState<CatalogItem[]>([]);
  const [gradesLoading, setGradesLoading] = useState(true);

  /** The chosen section's grades only — same derivation useSignupForm.ts uses. */
  const availableGrades = useMemo(
    () => gradeItems.filter((g) => g.sectionId === sectionId).map((g) => g.name),
    [gradeItems, sectionId]
  );

  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const setNamePart = (key: TeacherNamePartKey, value: string) => {
    setNameParts((prev) => ({ ...prev, [key]: value }));
  };

  const fullName = useMemo(
    () =>
      TEACHER_NAME_PARTS.map(({ key }) => nameParts[key].trim())
        .filter((part) => part !== '')
        .join(' '),
    [nameParts]
  );

  // Both catalogs are public — see catalogService.ts — safe before any account exists.
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
        logger.error('Failed to load sections/grades for teacher application', error as Error);
        if (!cancelled) notify('تعذر تحميل قائمة المراحل والصفوف الدراسية، يرجى إعادة تحميل الصفحة', 'error');
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

  // A previously-picked grade that no longer belongs to the newly-chosen
  // section must not stay selected but invisible.
  useEffect(() => {
    setSelectedGrades((current) => current.filter((g) => availableGrades.includes(g)));
  }, [availableGrades]);

  const toggleGrade = (grade: string) => {
    setSelectedGrades((prev) =>
      prev.includes(grade) ? prev.filter((g) => g !== grade) : [...prev, grade]
    );
  };

  const addSocialLink = () => setSocialLinks((prev) => [...prev, '']);
  const removeSocialLink = (index: number) =>
    setSocialLinks((prev) => (prev.length > 1 ? prev.filter((_, i) => i !== index) : prev));
  const setSocialLink = (index: number, value: string) =>
    setSocialLinks((prev) => prev.map((link, i) => (i === index ? value : link)));

  // Validation
  const validateNamePart = (value: string, label: string) => {
    const trimmed = value.trim();
    if (!trimmed) return `${label} مطلوب`;
    if (trimmed.length < 2) return `${label} يجب أن يتكون من حرفين على الأقل`;
    if (/\s/.test(trimmed)) return `${label} يجب أن يكون اسماً واحداً بدون مسافات`;
    return '';
  };

  const validatePhoneShape = (value: string, label: string) => {
    if (!value) return `${label} مطلوب`;
    if (!/^\d+$/.test(value)) return `${label} يجب أن يحتوي على أرقام فقط`;
    if (!value.startsWith('0')) return `${label} يجب أن يبدأ بصفر كما يُكتب محلياً`;
    if (value.length !== phoneDigits) return `${label} يجب أن يتكون من ${phoneDigits} رقم`;
    return '';
  };

  const validateAge = (value: string) => {
    if (!value) return 'السن مطلوب';
    const n = Number(value);
    if (!Number.isInteger(n)) return 'السن يجب أن يكون رقماً صحيحاً';
    if (n < 18) return 'السن يجب ألا يقل عن 18 سنة';
    if (n > 80) return 'السن يجب ألا يزيد عن 80 سنة';
    return '';
  };

  const validateExperience = (value: string) => {
    if (!value) return 'سنوات الخبرة مطلوبة';
    const n = Number(value);
    if (!Number.isInteger(n) || n < 0) return 'سنوات الخبرة يجب أن تكون رقماً صحيحاً موجباً';
    if (n > 60) return 'سنوات الخبرة غير منطقية';
    return '';
  };

  const handleBlur = (field: string) => {
    setTouched((prev) => ({ ...prev, [field]: true }));
  };

  const rawErrors: Record<string, string> = {
    ...Object.fromEntries(
      TEACHER_NAME_PARTS.map(({ key, label }) => [key, validateNamePart(nameParts[key], label)])
    ),
    phone: validatePhoneShape(phone, 'رقم هاتف المدرس'),
    whatsapp: validatePhoneShape(whatsapp, 'رقم واتساب المدرس'),
    governorate: !governorate ? 'الرجاء اختيار المحافظة' : '',
    age: validateAge(age),
    yearsOfExperience: validateExperience(yearsOfExperience),
    subject: !subject.trim() ? 'الرجاء إدخال المادة التي تدرّسها' : '',
    sectionId: !sectionId ? 'الرجاء اختيار المرحلة الدراسية' : '',
    grades: selectedGrades.length === 0 ? 'الرجاء اختيار صف دراسي واحد على الأقل' : '',
    socialLinks: socialLinks.every((l) => !l.trim()) ? 'الرجاء إضافة رابط واحد على الأقل' : '',
  };

  const errors: Record<string, string> = Object.fromEntries(
    Object.entries(rawErrors).map(([field, message]) => [field, touched[field] ? message : ''])
  );

  const isStepValid = useCallback(
    (target: ApplicationStep) => STEP_FIELDS[target].every((field) => !rawErrors[field]),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [nameParts, phone, whatsapp, governorate, age, yearsOfExperience, subject, sectionId, selectedGrades, socialLinks]
  );

  const markStepTouched = (target: ApplicationStep) => {
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
    setStep((prev) => (prev < 3 ? ((prev + 1) as ApplicationStep) : prev));
  };

  const goToPreviousStep = () => {
    setStep((prev) => (prev > 1 ? ((prev - 1) as ApplicationStep) : prev));
  };

  const isFormValid = () => isStepValid(1) && isStepValid(2) && isStepValid(3);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

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

        await teacherApplicationService.submit({
          full_name: fullName,
          phone,
          whatsapp,
          governorate,
          age: Number(age),
          years_of_experience: Number(yearsOfExperience),
          subject: subject.trim(),
          section_name: chosenSection?.name ?? '',
          grades: selectedGrades,
          social_links: socialLinks.map((l) => l.trim()).filter((l) => l !== ''),
        });

        setIsSubmitted(true);
      } catch (error) {
        logger.error('Teacher application submit failed', error as Error);
        notify('حدث خطأ أثناء إرسال طلبك، يرجى المحاولة مرة أخرى', 'error');
      } finally {
        setIsSubmitting(false);
      }
    } else {
      const firstBadStep = ([1, 2, 3] as ApplicationStep[]).find((s) => !isStepValid(s));
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
    phone, setPhone,
    whatsapp, setWhatsapp,
    governorate, setGovernorate,
    age, setAge,
    yearsOfExperience, setYearsOfExperience,
    subject, setSubject,
    sectionId, setSectionId,
    selectedGrades, toggleGrade,
    socialLinks, addSocialLink, removeSocialLink, setSocialLink,
    phoneDigits,
    sections,
    sectionsLoading,
    availableGrades,
    gradesLoading,
    governorates,
    errors,
    handleBlur,
    isSubmitted, setIsSubmitted,
    isSubmitting,
    setTouched,
    handleSubmit,
  };
};
