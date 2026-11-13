import { useEffect, useMemo, useState } from 'react';
import { BookOpen, CalendarDays, Infinity, Search, ShieldCheck, UserRound } from 'lucide-react';
import { adminService } from '../../services/adminService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { PageHeader, Card, CardHeader, Button, FormField } from '../UI';

interface StudentOption {
  id: string;
  name?: string;
  studentCode?: string;
  phone?: string;
  parentPhone?: string;
}
interface TeacherOption { id: string; full_name?: string; name?: string; username?: string }
interface CourseOption { id: string; title: string }

const inputClass = 'w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3.5 py-3 text-sm text-slate-800 dark:text-slate-100 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 disabled:opacity-50';

function localDateString(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

export default function ManualActivation() {
  const [students, setStudents] = useState<StudentOption[]>([]);
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  const [courses, setCourses] = useState<CourseOption[]>([]);
  const [studentSearch, setStudentSearch] = useState('');
  const [studentId, setStudentId] = useState('');
  const [teacherId, setTeacherId] = useState('');
  const [courseId, setCourseId] = useState('');
  const [accessType, setAccessType] = useState<'lifetime' | 'limited'>('lifetime');
  const [expiresAt, setExpiresAt] = useState('');
  const [loading, setLoading] = useState(true);
  const [loadingCourses, setLoadingCourses] = useState(false);
  const [saving, setSaving] = useState(false);
  const { notify } = useCenteredNotification();

  useEffect(() => {
    let mounted = true;
    Promise.all([adminService.getStudents({ all: true }), adminService.getAllTeachers()])
      .then(([studentResult, teacherResult]) => {
        if (!mounted) return;
        setStudents(studentResult.items ?? []);
        setTeachers(Array.isArray(teacherResult) ? teacherResult : []);
      })
      .catch(() => notify('تعذر تحميل قائمة الطلاب أو المدرسين', 'error'))
      .finally(() => { if (mounted) setLoading(false); });
    return () => { mounted = false; };
  }, [notify]);

  useEffect(() => {
    let mounted = true;
    setCourses([]);
    setCourseId('');
    if (!teacherId) return;
    setLoadingCourses(true);
    adminService.getTeacherCourses(teacherId)
      .then((rows) => { if (mounted) setCourses(Array.isArray(rows) ? rows : []); })
      .catch(() => { if (mounted) notify('تعذر تحميل كورسات المدرس', 'error'); })
      .finally(() => { if (mounted) setLoadingCourses(false); });
    return () => { mounted = false; };
  }, [teacherId, notify]);

  const filteredStudents = useMemo(() => {
    const term = studentSearch.trim().toLocaleLowerCase();
    if (!term) return students.slice(0, 100);
    return students.filter((student) =>
      [student.name, student.studentCode, student.phone, student.parentPhone]
        .some((value) => String(value ?? '').toLocaleLowerCase().includes(term))
    ).slice(0, 100);
  }, [students, studentSearch]);

  const selectedStudent = students.find((student) => student.id === studentId);
  const selectedTeacher = teachers.find((teacher) => teacher.id === teacherId);
  const selectedCourse = courses.find((course) => course.id === courseId);

  const handleSubmit = async () => {
    if (!studentId || !teacherId || !courseId || (accessType === 'limited' && !expiresAt)) {
      notify('من فضلك أكمل اختيار الطالب والمدرس والكورس ومدة التفعيل', 'error');
      return;
    }
    setSaving(true);
    try {
      await adminService.createManualActivation({
        studentId,
        courseId,
        accessType,
        ...(accessType === 'limited' ? { expiresAt } : {}),
      });
      notify('تم فتح الكورس للطالب بنجاح دون إضافة أي إيرادات', 'success');
      setStudentId('');
      setTeacherId('');
      setCourseId('');
      setExpiresAt('');
      setAccessType('lifetime');
    } catch (error: any) {
      notify(error?.response?.data?.message || 'فشل تفعيل الكورس للطالب', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div dir="rtl" className="min-h-screen space-y-6 text-right font-sans">
      <PageHeader
        icon={ShieldCheck}
        title="التفعيل اليدوي"
        description="فتح كورس لطالب محدد مجانًا، دون تسجيل عملية بيع أو التأثير على حسابات المنصة."
        breadcrumbs={[{ label: 'المستخدمون والاشتراكات' }, { label: 'التفعيل اليدوي' }]}
      />

      <Card className="space-y-6">
        <CardHeader title="بيانات التفعيل" icon={ShieldCheck} />
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
          <FormField label="ابحث عن الطالب">
            {() => (
              <div className="relative">
                <Search className="pointer-events-none absolute right-3 top-3.5 h-4 w-4 text-slate-400" />
                <input value={studentSearch} onChange={(event) => setStudentSearch(event.target.value)} placeholder="الاسم أو كود الطالب أو رقم الهاتف" className={inputClass + ' pr-10'} />
              </div>
            )}
          </FormField>
          <FormField label="اختر الطالب">
            {() => (
              <select value={studentId} onChange={(event) => setStudentId(event.target.value)} disabled={loading} className={inputClass}>
                <option value="">{loading ? 'جاري تحميل الطلاب...' : 'اختر الطالب'}</option>
                {filteredStudents.map((student) => (
                  <option key={student.id} value={student.id}>
                    {[student.name, student.studentCode, student.phone].filter(Boolean).join(' — ')}
                  </option>
                ))}
              </select>
            )}
          </FormField>
          <FormField label="اختر المدرس">
            {() => (
              <select value={teacherId} onChange={(event) => setTeacherId(event.target.value)} disabled={loading} className={inputClass}>
                <option value="">اختر المدرس</option>
                {teachers.map((teacher) => <option key={teacher.id} value={teacher.id}>{teacher.full_name || teacher.name || teacher.username || teacher.id}</option>)}
              </select>
            )}
          </FormField>
          <FormField label="اختر الكورس">
            {() => (
              <select value={courseId} onChange={(event) => setCourseId(event.target.value)} disabled={!teacherId || loadingCourses} className={inputClass}>
                <option value="">{loadingCourses ? 'جاري تحميل الكورسات...' : 'اختر الكورس'}</option>
                {courses.map((course) => <option key={course.id} value={course.id}>{course.title}</option>)}
              </select>
            )}
          </FormField>
        </div>

        <div className="space-y-3">
          <p className="text-sm font-bold text-slate-700 dark:text-slate-200">مدة إتاحة الكورس</p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <button type="button" onClick={() => setAccessType('lifetime')} className={`flex items-center gap-3 rounded-xl border p-4 text-right transition ${accessType === 'lifetime' ? 'border-blue-500 bg-blue-50 text-blue-800 dark:bg-blue-950/30 dark:text-blue-200' : 'border-slate-200 dark:border-slate-700'}`}>
              <Infinity className="h-5 w-5 shrink-0" />
              <span><span className="block text-sm font-bold">مدى الحياة</span><span className="mt-1 block text-xs opacity-70">بدون تاريخ انتهاء</span></span>
            </button>
            <button type="button" onClick={() => setAccessType('limited')} className={`flex items-center gap-3 rounded-xl border p-4 text-right transition ${accessType === 'limited' ? 'border-blue-500 bg-blue-50 text-blue-800 dark:bg-blue-950/30 dark:text-blue-200' : 'border-slate-200 dark:border-slate-700'}`}>
              <CalendarDays className="h-5 w-5 shrink-0" />
              <span><span className="block text-sm font-bold">لفترة محددة</span><span className="mt-1 block text-xs opacity-70">حدد آخر يوم متاح فيه الكورس</span></span>
            </button>
          </div>
          {accessType === 'limited' && (
            <FormField label="تاريخ انتهاء الصلاحية">
              {() => (
                <input type="date" min={localDateString(new Date(Date.now() + 86400000))} value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} className={inputClass} />
              )}
            </FormField>
          )}
        </div>

        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">
          <p className="font-bold">تنبيه مالي</p>
          <p className="mt-1">هذا التفعيل مجاني بالكامل؛ لن يتم إنشاء Revenue Transaction ولن يظهر كمبيعات أو أرباح للمدرس.</p>
        </div>

        <div className="flex justify-start">
          <Button onClick={handleSubmit} disabled={saving || loading || !studentId || !teacherId || !courseId || (accessType === 'limited' && !expiresAt)}>
            {saving ? 'جاري التفعيل...' : 'تفعيل الكورس للطالب'}
          </Button>
        </div>
      </Card>

      {(selectedStudent || selectedTeacher || selectedCourse) && (
        <Card className="space-y-3">
          <CardHeader title="ملخص الاختيارات" icon={BookOpen} />
          <div className="grid gap-2 text-sm text-slate-600 dark:text-slate-300 sm:grid-cols-3">
            <p><UserRound className="ml-2 inline h-4 w-4" />{selectedStudent?.name || 'لم يتم اختيار طالب'}</p>
            <p>{selectedTeacher?.full_name || selectedTeacher?.name || 'لم يتم اختيار مدرس'}</p>
            <p>{selectedCourse?.title || 'لم يتم اختيار كورس'}</p>
          </div>
          {accessType === 'limited' && expiresAt && <p className="text-sm text-slate-500">ينتهي التفعيل يوم: {expiresAt}</p>}
        </Card>
      )}
    </div>
  );
}
