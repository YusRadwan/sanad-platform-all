import { useState, useEffect, useMemo } from 'react';
import { motion } from 'motion/react';
import { useNavigate, useParams } from 'react-router';
import {
  UserCog, User, Phone, MapPin, GraduationCap, School, Lock, Eye, EyeOff,
  ArrowRight, Save, ShieldCheck, ShieldAlert, Power, Hash, Layers, Check,
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import { catalogService, CatalogItem } from '../../services/catalogService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { EGYPT_GOVERNORATES, EGYPT_PHONE_DIGITS } from '../../constants/egyptLocation';
import {
  PageHeader,
  Card,
  CardHeader,
  Badge,
  Button,
  Input,
  Select,
  FormField,
  cn,
} from '../UI';

/**
 * "تعديل بيانات الطالب" — a full page, not a modal (explicit user request).
 *
 * GET /admin/students/{id} loads the current row (StudentResource shape);
 * PUT /admin/students/{id} (adminService.updateStudent) saves it, using the
 * exact wire names UpdateStudentRequest/UpdateStudent expect.
 *
 * ┌── SAME FIELDS, SAME CATALOG, SAME VALIDATION AS AddStudent.tsx. ─────────┐
 * │ النوع (gender) ونوع التعليم (eduType) كانا مفقودَين هنا تماماً — الأول لا │
 * │ يُقرأ ولا يُكتَب إطلاقاً من صفحة التعديل، والثاني كان صندوقاً للقراءة    │
 * │ فقط. الصف الدراسي/المرحلة كانا حقلي نص حر غير مرتبطين بكتالوج           │
 * │ `grade_sections`/`grades` الذي تستخدمه صفحة الإنشاء، فكان بإمكان الأدمن  │
 * │ كتابة قيمة لا تطابق أي عنصر في الكتالوج. الآن تستخدم الصفحتان نفس        │
 * │ الأزرار (chips) لـ gender/eduType ونفس منتقي القسم→الصف الديناميكي.      │
 * │                                                                          │
 * │ القسم/الصف هنا **اختياريان** عمداً (بخلاف صفحة الإنشاء، حيث كلاهما       │
 * │ إلزامي): إن لم يغيّرهما الأدمن، لا يُرسَلان في الحمولة إطلاقاً — تطابقاً  │
 * │ مع دلالة "absent = untouched" التي يفرضها UpdateStudent — فتعديل رقم     │
 * │ الهاتف مثلاً لا يفرض على الأدمن اختيار قسم/صف من جديد.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

interface StudentDetail {
  student_id: string;
  username: string;
  name: string;
  status: 'active' | 'inactive';
  studentCode: string;
  governorate: string | null;
  phone: string | null;
  parentPhone: string | null;
  gradeLevel: string | null;
  educationalStage: string | null;
  educationType: string | null;
  gender: string | null;
}

/** Same selectable-chip look AddStudent.tsx uses for النوع/نوع التعليم/القسم. */
function ChoiceChip({
  selected,
  onClick,
  tone = 'brand',
  children,
}: {
  selected: boolean;
  onClick: () => void;
  tone?: 'brand' | 'indigo';
  children: React.ReactNode;
}) {
  const SELECTED = {
    brand: 'bg-blue-50 dark:bg-blue-500/10 border-blue-600 text-blue-700 dark:text-blue-400 ring-2 ring-blue-500/15',
    indigo: 'bg-indigo-50 dark:bg-indigo-500/10 border-indigo-600 text-indigo-700 dark:text-indigo-400 ring-2 ring-indigo-500/15',
  } as const;

  return (
    <button
      type="button"
      aria-pressed={selected}
      onClick={onClick}
      className={cn(
        'flex items-center justify-center gap-2 border font-black transition-all cursor-pointer text-center py-2.5 px-4 rounded-xl text-sm',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
        selected
          ? SELECTED[tone]
          : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800 hover:border-slate-300 dark:hover:border-slate-700',
      )}
    >
      {children}
    </button>
  );
}

const deriveEducationalStage = (sectionName: string): string => {
  if (sectionName.includes('إعدادي')) return 'المرحلة الإعدادية';
  if (sectionName.includes('ثانوي')) return 'المرحلة الثانوية';
  return '';
};

/** Same four given-name inputs as AddStudent.tsx, in the order they are concatenated. */
const NAME_PARTS = [
  { key: 'firstName', label: 'الاسم الأول', placeholder: 'مثال: محمد' },
  { key: 'secondName', label: 'الاسم الثاني', placeholder: 'اسم الأب' },
  { key: 'thirdName', label: 'الاسم الثالث', placeholder: 'اسم الجد' },
  { key: 'fourthName', label: 'الاسم الرابع', placeholder: 'اسم العائلة' },
] as const;

type NamePartKey = (typeof NAME_PARTS)[number]['key'];

export default function EditStudent() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [student, setStudent] = useState<StudentDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [notFound, setNotFound] = useState(false);

  // The name is edited as four separate given-name inputs, same as
  // AddStudent.tsx — the API still receives one `fullName`, rebuilt below by
  // joining the four parts with single spaces.
  const [nameParts, setNameParts] = useState<Record<NamePartKey, string>>({
    firstName: '',
    secondName: '',
    thirdName: '',
    fourthName: '',
  });
  const setNamePart = (key: NamePartKey, value: string) =>
    setNameParts((prev) => ({ ...prev, [key]: value }));
  const fullName = useMemo(
    () =>
      NAME_PARTS.map(({ key }) => nameParts[key].trim())
        .filter((part) => part !== '')
        .join(' '),
    [nameParts]
  );

  const [studentPhone, setStudentPhone] = useState('');
  const [parentPhone, setParentPhone] = useState('');
  const [governorate, setGovernorate] = useState('');
  const [gender, setGender] = useState<'ذكر' | 'أنثى' | ''>('');
  const [eduType, setEduType] = useState<'عام' | 'بكالوريا' | 'أزهري' | ''>('');

  // Section/grade — deliberately blank on load (see class docblock): only
  // sent to the server if the admin actively picks a new value here.
  const [sectionId, setSectionId] = useState('');
  const [grade, setGrade] = useState('');
  const [sections, setSections] = useState<CatalogItem[]>([]);
  const [sectionsLoading, setSectionsLoading] = useState(true);
  const [gradeItems, setGradeItems] = useState<CatalogItem[]>([]);
  const [gradesLoading, setGradesLoading] = useState(true);

  const grades = useMemo(
    () => gradeItems.filter((g) => g.sectionId === sectionId).map((g) => g.name),
    [gradeItems, sectionId]
  );

  useEffect(() => {
    setGrade((current) => (grades.includes(current) ? current : ''));
  }, [grades]);

  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [passwordError, setPasswordError] = useState('');
  const [phoneError, setPhoneError] = useState('');

  const [statusPending, setStatusPending] = useState(false);

  const { notify } = useCenteredNotification();
  const showToast = (text: string, type: 'success' | 'warning' = 'success') => {
    notify(text, type === 'warning' ? 'error' : 'success');
  };

  useEffect(() => {
    (async () => {
      try {
        const [sectionList, gradeList] = await Promise.all([
          catalogService.list('grade_sections'),
          catalogService.list('grades'),
        ]);
        setSections(sectionList);
        setGradeItems(gradeList);
      } catch (error) {
        logger.error('Failed to load sections/grades for EditStudent', error as Error);
      } finally {
        setSectionsLoading(false);
        setGradesLoading(false);
      }
    })();
  }, []);

  useEffect(() => {
    if (!id) return;
    (async () => {
      try {
        setLoading(true);
        const data = await adminService.getStudent(id);
        setStudent(data);
        // Split the stored full name back into the four inputs: the first
        // three words each take their own cell, and any overflow beyond
        // that (a compound family name, etc.) stays together in the fourth
        // rather than losing words or splitting a name that shouldn't be.
        const words = (data.name ?? '').trim().split(/\s+/).filter(Boolean);
        setNameParts({
          firstName: words[0] ?? '',
          secondName: words[1] ?? '',
          thirdName: words[2] ?? '',
          fourthName: words.slice(3).join(' '),
        });
        setStudentPhone(data.phone ?? '');
        setParentPhone(data.parentPhone ?? '');
        setGovernorate(data.governorate ?? '');
        setGender(data.gender === 'ذكر' || data.gender === 'أنثى' ? data.gender : '');
        setEduType(
          data.educationType === 'عام' || data.educationType === 'بكالوريا' || data.educationType === 'أزهري'
            ? data.educationType
            : ''
        );
      } catch (err: any) {
        logger.error('Failed to load student for edit', err as Error);
        if (err?.response?.status === 404) setNotFound(true);
        else showToast('تعذر تحميل بيانات الطالب', 'warning');
      } finally {
        setLoading(false);
      }
    })();
  }, [id]);

  const phoneDigits = EGYPT_PHONE_DIGITS;

  const validatePhone = (): string => {
    if (studentPhone && studentPhone.length !== phoneDigits) {
      return `رقم هاتف الطالب يجب أن يتكون من ${phoneDigits} رقماً`;
    }
    if (parentPhone && parentPhone.length !== phoneDigits) {
      return `رقم هاتف ولي الأمر يجب أن يتكون من ${phoneDigits} رقماً`;
    }
    if (studentPhone && parentPhone && studentPhone === parentPhone) {
      return 'لا يمكن أن يتطابق رقم هاتف ولي الأمر مع هاتف الطالب';
    }
    return '';
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!id) return;

    setPasswordError('');
    setPhoneError('');

    const phoneIssue = validatePhone();
    if (phoneIssue) {
      setPhoneError(phoneIssue);
      return;
    }
    if (password && password.length < 6) {
      setPasswordError('يجب ألا تقل كلمة المرور عن 6 أحرف');
      return;
    }
    if (password && password !== confirmPassword) {
      setPasswordError('كلمتا المرور غير متطابقتين');
      return;
    }

    try {
      setSaving(true);
      const payload: Record<string, string> = {
        fullName,
        studentPhone,
        parentPhone,
        governorate,
        gender,
        eduType,
      };
      // Only touched when the admin actually picked a section+grade here —
      // see the class docblock for why this pair is optional on this page.
      if (sectionId && grade) {
        payload.gradeLevel = grade;
        payload.educationalStage = deriveEducationalStage(
          sections.find((s) => s.id === sectionId)?.name ?? ''
        );
      }
      if (password) payload.password = password;

      const updated = await adminService.updateStudent(id, payload);
      setStudent((prev) => prev ? { ...prev, ...updated } : prev);
      setPassword('');
      setConfirmPassword('');
      setSectionId('');
      setGrade('');
      showToast(password ? 'تم حفظ البيانات وإعادة تعيين كلمة السر بنجاح' : 'تم حفظ التعديلات بنجاح');
    } catch (err: any) {
      logger.error('Failed to save student edits', err as Error);
      showToast(err?.response?.data?.message || 'فشل حفظ التعديلات', 'warning');
    } finally {
      setSaving(false);
    }
  };

  const handleToggleStatus = async () => {
    if (!id || !student) return;
    const next = student.status !== 'active';
    try {
      setStatusPending(true);
      await adminService.updateStudentStatus(id, next);
      setStudent({ ...student, status: next ? 'active' : 'inactive' });
      showToast(next ? 'تم تفعيل حساب الطالب بنجاح' : 'تم إيقاف حساب الطالب مؤقتاً', next ? 'success' : 'warning');
    } catch {
      showToast('فشل تحديث حالة الطالب', 'warning');
    } finally {
      setStatusPending(false);
    }
  };

  const dependentPlaceholder = () => {
    if (!sectionId) return '-- اختر القسم أولاً --';
    if (gradesLoading) return '-- جاري تحميل الصفوف... --';
    if (grades.length === 0) return '-- لا توجد صفوف متاحة --';
    return '-- اختر الصف الدراسي الجديد --';
  };

  if (notFound) {
    return (
      <div dir="rtl" className="text-right py-20 text-center space-y-4">
        <ShieldAlert className="w-12 h-12 text-rose-400 mx-auto" />
        <p className="text-slate-500 dark:text-slate-400 font-bold">لم يتم العثور على هذا الطالب.</p>
        <Button variant="outline" icon={ArrowRight} onClick={() => navigate('/admin/users')}>
          العودة لجدول الطلاب
        </Button>
      </div>
    );
  }

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <PageHeader
        icon={UserCog}
        title="تعديل بيانات الطالب"
        breadcrumbs={[
          { label: 'لوحة التحكم الإدارية', onClick: () => navigate('/admin') },
          { label: 'إدارة الطلاب', onClick: () => navigate('/admin/users') },
          { label: 'تعديل البيانات' },
        ]}
        description={student ? `${student.name} — ${student.studentCode}` : 'جاري التحميل...'}
        className="mb-0"
        actions={
          <Button variant="outline" icon={ArrowRight} onClick={() => navigate('/admin/users')}>
            العودة لجدول الطلاب
          </Button>
        }
      />


      {loading ? (
        <Card className="h-64 animate-pulse" />
      ) : student && (
        <div className="max-w-4xl mx-auto space-y-6">

          {/* STATUS STRIP */}
          <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.35 }}>
            <Card className="flex flex-col sm:flex-row items-center justify-between gap-4">
              <div className="flex items-center gap-4">
                <div className="w-12 h-12 shrink-0 rounded-2xl bg-blue-50 dark:bg-blue-500/10 flex items-center justify-center text-blue-600 dark:text-blue-400 font-black text-lg">
                  {student.name?.charAt(0)}
                </div>
                <div>
                  <div className="flex items-center gap-2">
                    <span className="font-black text-slate-900 dark:text-white">{student.name}</span>
                    <Badge dot tone={student.status === 'active' ? 'success' : 'danger'}>
                      {student.status === 'active' ? 'نشط' : 'موقوف'}
                    </Badge>
                  </div>
                  <span className="text-xs font-mono font-bold text-slate-400 dark:text-slate-500 flex items-center gap-1 mt-0.5">
                    <Hash className="w-3 h-3" />{student.studentCode}
                  </span>
                </div>
              </div>
              <Button
                variant={student.status === 'active' ? 'warning' : 'success'}
                icon={Power}
                isLoading={statusPending}
                onClick={() => void handleToggleStatus()}
              >
                {student.status === 'active' ? 'حظر الطالب' : 'تفعيل الطالب'}
              </Button>
            </Card>
          </motion.div>

          <form onSubmit={handleSave} className="space-y-6">
            {/* PROFILE FIELDS */}
            <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.06, duration: 0.35 }}>
              <Card className="space-y-5">
                <CardHeader icon={User} title="البيانات الشخصية" />

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                  <div className="sm:col-span-2 space-y-3">
                    <span className="text-sm font-black text-slate-700 dark:text-slate-300">اسم الطالب الرباعي:</span>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                      {NAME_PARTS.map(({ key, label, placeholder }) => (
                        <FormField key={key} label={label}>
                          {({ id: fid }) => (
                            <Input
                              id={fid}
                              icon={User}
                              size="lg"
                              placeholder={placeholder}
                              value={nameParts[key]}
                              onChange={(e) => setNamePart(key, e.target.value)}
                              className="font-bold"
                            />
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
                  </div>

                  <FormField label="رقم هاتف الطالب:" error={phoneError && phoneError.includes('الطالب') ? phoneError : ''}>
                    {({ id: fid, hasError }) => (
                      <Input
                        id={fid} icon={Phone} size="lg" hasError={hasError}
                        maxLength={phoneDigits}
                        value={studentPhone}
                        onChange={(e) => setStudentPhone(e.target.value.replace(/\D/g, ''))}
                        dir="ltr" className="font-mono"
                      />
                    )}
                  </FormField>

                  <FormField label="رقم هاتف ولي الأمر:" error={phoneError && !phoneError.includes('الطالب') ? phoneError : ''}>
                    {({ id: fid, hasError }) => (
                      <Input
                        id={fid} icon={Phone} size="lg" hasError={hasError}
                        maxLength={phoneDigits}
                        value={parentPhone}
                        onChange={(e) => setParentPhone(e.target.value.replace(/\D/g, ''))}
                        dir="ltr" className="font-mono"
                      />
                    )}
                  </FormField>

                  <FormField label="المحافظة:">
                    {({ id: fid }) => (
                      <div className="relative">
                        <MapPin className="pointer-events-none absolute top-1/2 -translate-y-1/2 start-3 w-4 h-4 text-slate-400 dark:text-slate-500 z-10" />
                        <Select id={fid} size="lg" value={governorate} onChange={(e) => setGovernorate(e.target.value)} className="ps-9">
                          <option value="">-- اختر المحافظة --</option>
                          {EGYPT_GOVERNORATES.map((g) => <option key={g} value={g}>{g}</option>)}
                        </Select>
                      </div>
                    )}
                  </FormField>

                  <FormField label="النوع:">
                    <div className="grid grid-cols-2 gap-3">
                      <ChoiceChip selected={gender === 'ذكر'} onClick={() => setGender('ذكر')}>
                        <span>ذكر</span>
                        {gender === 'ذكر' && <Check className="w-4 h-4 shrink-0" />}
                      </ChoiceChip>
                      <ChoiceChip tone="indigo" selected={gender === 'أنثى'} onClick={() => setGender('أنثى')}>
                        <span>أنثى</span>
                        {gender === 'أنثى' && <Check className="w-4 h-4 shrink-0" />}
                      </ChoiceChip>
                    </div>
                  </FormField>
                </div>
              </Card>
            </motion.div>

            {/* ACADEMIC LEVEL — same catalog-driven flow as AddStudent.tsx */}
            <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1, duration: 0.35 }}>
              <Card className="space-y-5">
                <CardHeader
                  icon={School}
                  title="المستوى الدراسي"
                  description="القيم الحالية معروضة أدناه؛ لا تُعدَّل إلا إذا اخترت قسماً وصفاً جديدَين."
                />

                <FormField label="نوع التعليم:">
                  <div className="grid grid-cols-3 gap-3">
                    {(['عام', 'بكالوريا', 'أزهري'] as const).map((type) => (
                      <ChoiceChip key={type} selected={eduType === type} onClick={() => setEduType(type)}>
                        {type}
                      </ChoiceChip>
                    ))}
                  </div>
                </FormField>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                  <div className="p-3.5 rounded-xl border-2 border-dashed border-slate-200 dark:border-slate-800">
                    <span className="block text-[11px] font-black text-slate-400 dark:text-slate-500 mb-1">الصف الدراسي الحالي</span>
                    <span className="text-sm font-bold text-slate-700 dark:text-slate-200">{student.gradeLevel || 'غير محدد'}</span>
                  </div>
                  <div className="p-3.5 rounded-xl border-2 border-dashed border-slate-200 dark:border-slate-800">
                    <span className="block text-[11px] font-black text-slate-400 dark:text-slate-500 mb-1">المرحلة التعليمية الحالية</span>
                    <span className="text-sm font-bold text-slate-700 dark:text-slate-200">{student.educationalStage || 'غير محدد'}</span>
                  </div>
                </div>

                <div>
                  <FormField label="القسم (لتغيير الصف الدراسي):">
                    {sectionsLoading ? (
                      <div className="grid grid-cols-2 gap-3" aria-hidden="true">
                        {[0, 1].map((i) => (
                          <div key={i} className="h-11 rounded-xl bg-slate-100 dark:bg-slate-900 animate-pulse" />
                        ))}
                      </div>
                    ) : (
                      <div className="grid grid-cols-2 gap-3">
                        {sections.map((section) => (
                          <ChoiceChip key={section.id} selected={sectionId === section.id} onClick={() => setSectionId(section.id)}>
                            <Layers className="w-4 h-4 shrink-0" />
                            {section.name}
                          </ChoiceChip>
                        ))}
                      </div>
                    )}
                  </FormField>
                </div>

                <div>
                  <FormField label="الصف الدراسي الجديد:">
                    {({ id: fid }) => (
                      <div className="relative">
                        <GraduationCap className="pointer-events-none absolute top-1/2 -translate-y-1/2 start-3 w-4 h-4 text-slate-400 dark:text-slate-500 z-10" />
                        <Select
                          id={fid} size="lg" disabled={!sectionId || gradesLoading}
                          value={grade} onChange={(e) => setGrade(e.target.value)}
                          className="ps-9"
                        >
                          <option value="">{dependentPlaceholder()}</option>
                          {grades.map((g) => <option key={g} value={g}>{g}</option>)}
                        </Select>
                      </div>
                    )}
                  </FormField>
                </div>
              </Card>
            </motion.div>

            {/* PASSWORD RESET */}
            <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.16, duration: 0.35 }}>
              <Card className="space-y-5">
                <CardHeader
                  icon={ShieldCheck}
                  title="إعادة تعيين كلمة السر"
                  description="اتركهما فارغتين إن كنت لا تريد تغيير كلمة سر الطالب."
                />
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                  <FormField label="كلمة السر الجديدة:" error={passwordError}>
                    {({ id: fid, hasError }) => (
                      <div className="relative">
                        <Input
                          id={fid} icon={Lock} size="lg" hasError={hasError}
                          type={showPassword ? 'text' : 'password'}
                          value={password}
                          onChange={(e) => { setPassword(e.target.value); setPasswordError(''); }}
                          placeholder="لا تقل عن 6 أحرف"
                          className="pe-11"
                        />
                        <button
                          type="button"
                          onClick={() => setShowPassword((v) => !v)}
                          className="absolute end-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-blue-500 transition-colors p-1 rounded cursor-pointer"
                          aria-label={showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
                        >
                          {showPassword ? <EyeOff className="w-4.5 h-4.5" /> : <Eye className="w-4.5 h-4.5" />}
                        </button>
                      </div>
                    )}
                  </FormField>

                  <FormField label="تأكيد كلمة السر:">
                    {({ id: fid }) => (
                      <Input
                        id={fid} icon={Lock} size="lg"
                        type={showPassword ? 'text' : 'password'}
                        value={confirmPassword}
                        onChange={(e) => { setConfirmPassword(e.target.value); setPasswordError(''); }}
                        placeholder="أعد كتابة كلمة السر"
                      />
                    )}
                  </FormField>
                </div>
              </Card>
            </motion.div>

            <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.22, duration: 0.35 }}>
              <Button
                type="submit"
                size="lg"
                fullWidth
                icon={Save}
                isLoading={saving}
                className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
              >
                حفظ التعديلات
              </Button>
            </motion.div>
          </form>
        </div>
      )}
    </div>
  );
}
