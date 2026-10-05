import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { KeyRound, User, Sparkles, Hash, Download } from 'lucide-react';
import { adminService } from '../../services/adminService';
import { centerService } from '../../services/centerService';
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
 * صفحة توليد أكواد السنتر (admin only).
 *
 * Mirrors EnrollmentCodes.tsx's bulk-generate-then-export flow: pick a
 * teacher, pick a count, Generate calls POST /admin/center-codes once with
 * `count`, which returns the whole batch as `{codes: [...]}`. The codes are
 * never displayed on screen — they are only exported, immediately, as an
 * .xlsx download via the shared zero-dependency writer.
 */

const MAX_COUNT = 500;

interface TeacherOption {
  id: string;
  full_name?: string;
  name?: string;
  username?: string;
}

interface GeneratedCenterCode {
  id: string;
  code: string;
  teacher_id: string;
  teacher_name: string | null;
  is_active: boolean;
  created_at: string | null;
}

const EXPORT_COLUMNS: XlsxColumn[] = [
  { header: 'الكود', width: 16 },
  { header: 'المدرس', width: 22 },
  { header: 'تاريخ الإنشاء', width: 18 },
];

export default function GenerateCenterCodes() {
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  const [selectedTeacher, setSelectedTeacher] = useState('');
  const [count, setCount] = useState('1');

  const [loadingTeachers, setLoadingTeachers] = useState(true);
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

  const parsedCount = Math.max(1, Math.min(MAX_COUNT, parseInt(count, 10) || 1));

  const handleGenerate = async () => {
    if (!selectedTeacher) return;
    try {
      setGenerating(true);
      const codes: GeneratedCenterCode[] = await centerService.createCenterCodes(selectedTeacher, parsedCount);
      const rows = codes.map(c => [
        c.code,
        c.teacher_name ?? '—',
        c.created_at ?? '—',
      ]);
      const stamp = new Date().toISOString().slice(0, 10);
      downloadXlsx(`اكواد-سنتر-${stamp}.xlsx`, EXPORT_COLUMNS, rows, 'الأكواد');
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
        title="توليد أكواد السنتر"
        breadcrumbs={[{ label: 'منظومة السنتر' }, { label: 'أكواد السنتر' }]}
        description="توليد أكواد سنتر جديدة"
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
          <div className="md:col-span-8">
            <FormField
              label={<span className="inline-flex items-center gap-1.5"><User className="w-3 h-3" /> اختر المدرس:</span>}
            >
              {({ id }) => (
                <Select
                  id={id}
                  value={selectedTeacher}
                  onChange={(e) => setSelectedTeacher(e.target.value)}
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
              disabled={!selectedTeacher || generating}
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
