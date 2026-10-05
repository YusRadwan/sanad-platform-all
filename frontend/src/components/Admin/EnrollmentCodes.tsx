import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { KeyRound, User, BookOpen, Sparkles, Hash, Download } from 'lucide-react';
import { adminService } from '../../services/adminService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { downloadXlsx, XlsxColumn } from '../../utils/xlsxExport';
import {
  PageHeader,
  Card,
  CardHeader,
  Button,
  Select,
  FormField,
} from '../UI';

/**
 * صفحة إنشاء أكواد الاشتراك (admin only).
 *
 * Bulk-generation rebuild (open decision 10.15): the "أحدث الأكواد
 * المولّدة" section is gone entirely — a screen-list of possibly hundreds of
 * freshly generated codes is not how an admin consumes this data. Instead,
 * generation immediately triggers a browser-side .xlsx download of exactly
 * the batch just created, via the zero-dependency `downloadXlsx` writer
 * already used by ManageSubscriptions.tsx.
 *
 * Flow: pick a teacher -> the teacher's courses load -> pick a course -> pick
 * a count (1-200) -> Generate calls POST /admin/enrollment-codes once with
 * `count`, which returns the whole batch as `{codes: [...]}`; the codes are
 * never displayed on screen, only exported.
 */

const MAX_COUNT = 500;

interface TeacherOption {
  id: string;
  full_name?: string;
  name?: string;
  username?: string;
}

interface CourseOption {
  id: string;
  title: string;
  price?: number;
  is_published?: boolean;
}

interface GeneratedCode {
  id: string;
  code: string;
  course_id: string;
  course_title: string | null;
  teacher_name: string | null;
  is_used: boolean;
  created_at: string | null;
}

const EXPORT_COLUMNS: XlsxColumn[] = [
  { header: 'الكود', width: 16 },
  { header: 'الكورس', width: 28 },
  { header: 'المدرس', width: 22 },
  { header: 'تاريخ الإنشاء', width: 18 },
];

export default function EnrollmentCodes() {
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  const [courses, setCourses] = useState<CourseOption[]>([]);
  const [selectedTeacher, setSelectedTeacher] = useState('');
  const [selectedCourse, setSelectedCourse] = useState('');
  const [count, setCount] = useState('1');

  const [loadingTeachers, setLoadingTeachers] = useState(true);
  const [loadingCourses, setLoadingCourses] = useState(false);
  const [generating, setGenerating] = useState(false);

  const { notify } = useCenteredNotification();
  const showToast = (text: string, type: 'success' | 'warning' = 'success') => {
    notify(text, type === 'warning' ? 'error' : 'success');
  };

  const teacherLabel = (t: TeacherOption) => t.full_name || t.name || t.username || t.id;

  useEffect(() => {
    (async () => {
      try {
        const data = await adminService.getAllTeachers();
        setTeachers(Array.isArray(data) ? data : []);
      } catch {
        showToast('تعذر تحميل قائمة المدرسين من الخادم', 'warning');
      } finally {
        setLoadingTeachers(false);
      }
    })();
  }, []);

  // --- cascading select: teacher -> that teacher's courses ---
  const handleTeacherChange = async (teacherId: string) => {
    setSelectedTeacher(teacherId);
    setSelectedCourse('');
    setCourses([]);
    if (!teacherId) return;

    try {
      setLoadingCourses(true);
      const data = await adminService.getTeacherCourses(teacherId);
      setCourses(Array.isArray(data) ? data : []);
      if (!data || data.length === 0) {
        showToast('هذا المدرس لا يملك أي كورسات حتى الآن', 'warning');
      }
    } catch {
      showToast('تعذر تحميل كورسات هذا المدرس', 'warning');
    } finally {
      setLoadingCourses(false);
    }
  };

  const parsedCount = Math.max(1, Math.min(MAX_COUNT, parseInt(count, 10) || 1));

  const handleGenerate = async () => {
    if (!selectedCourse) return;
    try {
      setGenerating(true);
      const codes: GeneratedCode[] = await adminService.createEnrollmentCode(selectedCourse, parsedCount);
      const rows = codes.map(c => [
        c.code,
        c.course_title ?? '—',
        c.teacher_name ?? '—',
        c.created_at ?? '—',
      ]);
      const stamp = new Date().toISOString().slice(0, 10);
      downloadXlsx(`اكواد-اشتراك-${stamp}.xlsx`, EXPORT_COLUMNS, rows, 'الأكواد');
      showToast(
        codes.length > 1 ? `تم توليد ${codes.length} كود وتنزيل ملف الإكسل بنجاح` : 'تم توليد الكود وتنزيل ملف الإكسل بنجاح'
      );
    } catch (err: any) {
      showToast(err?.response?.data?.message || 'فشل توليد الأكواد، برجاء المحاولة مرة أخرى', 'warning');
    } finally {
      setGenerating(false);
    }
  };

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-emerald-600/10 dark:bg-emerald-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-blue-500/8 dark:bg-blue-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-violet-500/6 dark:bg-violet-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* HEADER */}
      <PageHeader
        icon={KeyRound}
        title="إنشاء أكواد الاشتراك"
        breadcrumbs={[{ label: 'منظومة تنظّيم وضبط المنصة العُليا' }, { label: 'أكواد الاشتراك' }]}
        description="توليد أكواد اشتراك جديدة"
        className="mb-0"
      />


      {/* GENERATOR */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0, duration: 0.35 }}
      >
      <Card className="space-y-5">
        <CardHeader title="توليد أكواد جديدة" icon={Sparkles} />

        <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
          {/* Teacher */}
          <div className="md:col-span-4">
            <FormField
              label={<span className="inline-flex items-center gap-1.5"><User className="w-3 h-3" /> اختر المدرس:</span>}
            >
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedTeacher}
                  onChange={(e) => void handleTeacherChange(e.target.value)}
                  disabled={loadingTeachers}
                >
                  <option value="">{loadingTeachers ? 'جاري تحميل المدرسين...' : '— اختر المدرس —'}</option>
                  {teachers.map(t => (
                    <option key={t.id} value={t.id}>{teacherLabel(t)}</option>
                  ))}
                </Select>
              )}
            </FormField>
          </div>

          {/* Course */}
          <div className="md:col-span-4">
            <FormField
              label={<span className="inline-flex items-center gap-1.5"><BookOpen className="w-3 h-3" /> اختر الكورس:</span>}
            >
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedCourse}
                  onChange={(e) => setSelectedCourse(e.target.value)}
                  disabled={!selectedTeacher || loadingCourses}
                >
                  <option value="">
                    {!selectedTeacher ? '— اختر المدرس أولاً —' : loadingCourses ? 'جاري تحميل الكورسات...' : '— اختر الكورس —'}
                  </option>
                  {courses.map(c => (
                    <option key={c.id} value={c.id}>
                      {c.title}{c.is_published === false ? ' (غير منشور)' : ''}
                    </option>
                  ))}
                </Select>
              )}
            </FormField>
          </div>

          {/* Count */}
          <div className="md:col-span-2">
            <FormField
              label={<span className="inline-flex items-center gap-1.5"><Hash className="w-3 h-3" /> عدد الأكواد:</span>}
            >
              {({ id }) => (
                <input
                  id={id}
                  type="number"
                  min={1}
                  max={MAX_COUNT}
                  value={count}
                  onChange={(e) => setCount(e.target.value)}
                  onBlur={() => setCount(String(parsedCount))}
                  className="w-full h-11 px-3.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 font-black font-mono text-center focus:border-emerald-500 dark:focus:border-emerald-500 focus:outline-none transition-colors"
                />
              )}
            </FormField>
          </div>

          {/* Generate */}
          <div className="md:col-span-2">
            <Button
              variant="success"
              fullWidth
              icon={Download}
              isLoading={generating}
              onClick={() => void handleGenerate()}
              disabled={!selectedCourse || generating}
              className="shadow-lg shadow-emerald-500/20 hover:shadow-xl hover:shadow-emerald-500/30 transition-shadow"
            >
              توليد وتنزيل
            </Button>
          </div>
        </div>

        <p className="text-xs font-bold text-slate-400 dark:text-slate-500">
          الحد الأقصى {MAX_COUNT} كود في المرة الواحدة. بمجرد التوليد، سيُنزَّل ملف إكسل يحتوي على كل الأكواد تلقائياً على جهازك.
        </p>
      </Card>
      </motion.div>
    </div>
  );
}
