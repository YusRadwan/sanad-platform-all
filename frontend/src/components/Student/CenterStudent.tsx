import { useCallback, useEffect, useState } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import {
  KeyRound,
  Users2,
  BookOpen,
  Sparkles,
  ChevronDown,
} from 'lucide-react';

import { centerService } from '../../services/centerService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import Avatar from '../Layout/Avatar';
import CourseCard from '../CourseCard';
import { Course } from '../../types/index';
import { Card, CardHeader, Button, Input, FormField, EmptyState, Skeleton } from '../UI';

/**
 * صفحة "طالب سنتر" — student-facing Center System page.
 *
 * Two independent sections stacked vertically, matching the sibling
 * Student/*.tsx pages' header-card + content-card visual rhythm:
 *   1. Join-by-code form (POST /center/join via centerService.joinCenter).
 *   2. One independent card per joined teacher (GET /center/my-centers),
 *      each with only that teacher's own center-visible courses, RENDERED
 *      WITH THE SAME `CourseCard` COMPONENT the home page catalogue uses —
 *      not a smaller bespoke tile — so a center course reads as the exact
 *      same kind of object everywhere in the app. The backend now returns
 *      the full `CourseResource` shape (teacher/grade/subject relations
 *      included) for exactly this reason: CourseCard reads fields
 *      (`grade`, `subject`, `teacher.image`, `created_at`, ...) the old
 *      hand-picked `{id,title,thumbnail_url,price,discount_price}` subset
 *      never carried. Teachers' course lists are NEVER merged — see
 *      centerService.getMyCenters()'s one-array-entry-per-teacher shape.
 *
 * The student's own QR/code card was removed on request — this page is now
 * join + browse only.
 */

interface CenterEntry {
  teacher: { id: string | number; name: string; avatar_url?: string | null };
  courses: Course[];
}

const CODE_PATTERN = /^[A-Z]\d{5}$/;

export default function CenterStudent() {
  const { notify } = useCenteredNotification();

  // ── Join-by-code ──────────────────────────────────────────────────────
  const [code, setCode] = useState('');
  const [joining, setJoining] = useState(false);
  const [joinError, setJoinError] = useState<string | null>(null);

  // ── My centers ───────────────────────────────────────────────────────
  const [centers, setCenters] = useState<CenterEntry[]>([]);
  const [loadingCenters, setLoadingCenters] = useState(true);
  // "يظهر المدرس أولاً وبعد الضغط عليه تظهر الكورسات الخاصة به" — an
  // accordion, one teacher's courses open at a time; clicking the same
  // teacher again collapses it.
  const [expandedTeacherId, setExpandedTeacherId] = useState<string | number | null>(null);

  const loadCenters = useCallback(async () => {
    try {
      setLoadingCenters(true);
      const data = await centerService.getMyCenters();
      setCenters(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error('Failed to fetch my centers:', err);
    } finally {
      setLoadingCenters(false);
    }
  }, []);

  useEffect(() => {
    loadCenters();
  }, [loadCenters]);

  const handleJoin = useCallback(async () => {
    const trimmed = code.trim().toUpperCase();
    if (!trimmed) {
      setJoinError('من فضلك أدخل كود السنتر');
      return;
    }
    try {
      setJoining(true);
      setJoinError(null);
      const teacher = await centerService.joinCenter(trimmed);
      notify(`تم الانضمام إلى سنتر ${teacher?.name ?? 'المعلم'} بنجاح`, 'success');
      setCode('');
      await loadCenters();
    } catch (err: any) {
      const data = err?.response?.data;
      const message =
        data?.data?.errors?.code?.[0] ||
        data?.message ||
        'الكود غير صحيح أو غير صالح';
      setJoinError(message);
      notify(message, 'error');
    } finally {
      setJoining(false);
    }
  }, [code, loadCenters, notify]);

  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      className="space-y-6"
    >
      {/* Header */}
      <div className="bg-white dark:bg-slate-900 rounded-3xl p-6 md:p-8 border border-slate-200/50 dark:border-slate-800 shadow-sm relative overflow-hidden group">
        <div className="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-blue-600 via-indigo-500 to-blue-400" />
        <div className="absolute -left-20 -top-20 w-64 h-64 bg-blue-100/40 dark:bg-blue-900/10 rounded-full blur-3xl group-hover:scale-110 transition-transform duration-700" />

        <div className="relative flex flex-col md:flex-row md:items-center justify-between gap-6 z-10">
          <div>
            <div className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 font-bold text-xs mb-3 border border-blue-100/50 dark:border-blue-800/30">
              <Sparkles className="w-3.5 h-3.5" />
              <span>منظومة السنتر</span>
            </div>
            <h1 className="text-2xl md:text-3xl font-black text-slate-900 dark:text-white mb-2 flex items-center gap-3">
              <Users2 className="w-7 h-7 text-blue-600 dark:text-blue-500" />
              طالب سنتر
            </h1>
            <p className="text-sm font-bold text-slate-500 dark:text-slate-400 leading-relaxed max-w-2xl">
              انضم إلى سنتر مدرسك بكود السنتر، واعرض كود QR الخاص بك لتسجيل الحضور، وتابع كورسات السنتر لكل مدرس انضممت إليه.
            </p>
          </div>
        </div>
      </div>

      {/* Join by code */}
      <Card>
        <CardHeader title="انضمام لسنتر جديد" description="أدخل كود السنتر المكوّن من 6 خانات (حرف إنجليزي كبير ثم 5 أرقام)" icon={KeyRound} />
        <div className="flex flex-col sm:flex-row items-start sm:items-end gap-3">
          <div className="w-full sm:max-w-xs">
            <FormField label="كود السنتر" error={joinError}>
              {({ id, hasError }) => (
                <Input
                  id={id}
                  value={code}
                  hasError={hasError}
                  maxLength={6}
                  placeholder="A58214"
                  dir="ltr"
                  className="text-center font-mono tracking-[0.3em] font-black"
                  onChange={(e) => { setCode(e.target.value.toUpperCase()); setJoinError(null); }}
                  onKeyDown={(e) => { if (e.key === 'Enter') void handleJoin(); }}
                />
              )}
            </FormField>
          </div>
          <Button
            icon={KeyRound}
            isLoading={joining}
            disabled={joining || !code.trim()}
            onClick={() => void handleJoin()}
          >
            انضمام
          </Button>
        </div>
        {code && !CODE_PATTERN.test(code.trim()) && (
          <p className="mt-2 text-[11px] font-bold text-slate-400 dark:text-slate-500">
            الشكل المتوقع للكود: حرف إنجليزي كبير متبوعًا بـ 5 أرقام (مثال: A58214).
          </p>
        )}
      </Card>

      {/* Per-teacher center cards */}
      {loadingCenters ? (
        <Card>
          <div className="flex items-center gap-3">
            <Skeleton variant="circle" width={44} height={44} />
            <Skeleton variant="text" width={160} />
          </div>
        </Card>
      ) : centers.length === 0 ? (
        <Card>
          <EmptyState
            icon={Users2}
            title="لم تنضم إلى أي سنتر بعد"
            description="استخدم كود السنتر الذي حصلت عليه من مدرسك للانضمام، وستظهر هنا كورسات السنتر الخاصة به."
          />
        </Card>
      ) : (
        <div className="space-y-6">
          {centers.map((entry) => (
            // Premium blue-gradient identity — same background TeacherCard.tsx
            // (home page) and TeacherProfilePage.tsx use for "this is a
            // teacher" surfaces, so a joined teacher reads as the same kind
            // of object everywhere in the app, not a generic list row.
            <div
              key={entry.teacher.id}
              className="relative overflow-hidden rounded-[28px] shadow-xl shadow-blue-900/20"
              style={{
                background:
                  'radial-gradient(120% 90% at 15% -10%, rgba(224,250,255,0.55) 0%, rgba(224,250,255,0) 42%), ' +
                  'linear-gradient(165deg, #082a63 0%, #0f3f8f 32%, #1f74bd 66%, #52cfe0 100%)',
              }}
            >
              <button
                type="button"
                onClick={() => setExpandedTeacherId((prev) => (prev === entry.teacher.id ? null : entry.teacher.id))}
                className="w-full p-5 sm:p-6 flex items-center gap-4 text-right cursor-pointer"
                aria-expanded={expandedTeacherId === entry.teacher.id}
              >
                <Avatar
                  initial={(entry.teacher.name || '؟').trim().charAt(0)}
                  imageUrl={entry.teacher.avatar_url || undefined}
                  alt={entry.teacher.name}
                  size="lg"
                  className="ring-2 ring-white/70 shrink-0"
                />
                <div className="min-w-0 flex-1">
                  <h3 className="text-lg sm:text-xl font-black text-white truncate">{entry.teacher.name}</h3>
                  <p className="text-xs font-bold text-white/70 mt-0.5">{entry.courses.length} كورس سنتر</p>
                </div>
                <motion.div
                  animate={{ rotate: expandedTeacherId === entry.teacher.id ? 180 : 0 }}
                  transition={{ duration: 0.2 }}
                  className="shrink-0 text-white/70"
                >
                  <ChevronDown className="w-5 h-5" />
                </motion.div>
              </button>

              {/* Glass panel — same secondary-surface pattern as
                  TeacherProfilePage.tsx's details panel, so course tiles sit
                  on the gradient without a jarring white block. Only rendered
                  once this teacher is expanded. */}
              <AnimatePresence initial={false}>
                {expandedTeacherId === entry.teacher.id && (
                  <motion.div
                    initial={{ height: 0, opacity: 0 }}
                    animate={{ height: 'auto', opacity: 1 }}
                    exit={{ height: 0, opacity: 0 }}
                    transition={{ duration: 0.25 }}
                    className="overflow-hidden"
                  >
                    <div className="mx-4 mb-4 sm:mx-5 sm:mb-5 rounded-2xl bg-white/10 backdrop-blur-sm border border-white/15 p-4">
                      {entry.courses.length === 0 ? (
                        <p className="text-xs font-bold text-white/60 text-center py-4">
                          لا توجد كورسات سنتر متاحة حالياً
                        </p>
                      ) : (
                        // Same CourseCard the home catalogue and MyCourses.tsx
                        // use — a center course is presented as the exact
                        // same kind of object as any other course.
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8">
                          {entry.courses.map((course, index) => (
                            <CourseCard key={course.id} course={course} index={index} isLoggedIn />
                          ))}
                        </div>
                      )}
                    </div>
                  </motion.div>
                )}
              </AnimatePresence>
            </div>
          ))}
        </div>
      )}

      {centers.length === 0 && !loadingCenters && (
        <div className="flex items-center gap-2 text-[11px] font-bold text-slate-400 dark:text-slate-500 px-1">
          <BookOpen className="w-3.5 h-3.5 shrink-0" />
          <span>بمجرد انضمامك إلى سنتر مدرس، ستظهر كورساته المتاحة للسنتر هنا تلقائياً.</span>
        </div>
      )}
    </motion.div>
  );
}
