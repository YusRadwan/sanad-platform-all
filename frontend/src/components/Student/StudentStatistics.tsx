import { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { useNavigate } from 'react-router';
import {
  BookOpen,
  Video,
  Clock,
  Award,
  TrendingUp,
  Sparkles,
  CheckCircle,
  ChevronDown,
  Loader2,
} from 'lucide-react';

import { studentService } from '../../services/studentService';
import { formatNumber } from '../../utils/formatMoney';

interface StudentStatisticsProps {
  courses: any[];
  username: string;
}

interface DashboardCourse {
  id: number | string;
  title: string;
  subject?: string;
  total_lessons?: number;
  completed_lessons?: number;
  latest_grade?: number | null;
}

interface DashboardStats {
  exams_taken?: number;
  average_score?: number | null;
  total_study_time_minutes?: number;
  enrolled_courses?: number;
}

export default function StudentStatistics({ username }: StudentStatisticsProps) {
  const navigate = useNavigate();
  const [courses, setCourses] = useState<DashboardCourse[]>([]);
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [watchedVideosCount, setWatchedVideosCount] = useState<number>(0);
  const [loading, setLoading] = useState(true);
  const [showBreakdown, setShowBreakdown] = useState(false);

  useEffect(() => {
    let cancelled = false;

    const fetchAll = async () => {
      setLoading(true);
      try {
        const dashboard = await studentService.getDashboard();
        if (cancelled) return;
        setCourses(Array.isArray(dashboard?.courses) ? dashboard.courses : []);
        setStats(dashboard?.stats ?? null);
      } catch (err) {
        console.error('Failed to fetch student dashboard:', err);
      }

      try {
        const videos = await studentService.getWatchedVideos();
        if (cancelled) return;
        setWatchedVideosCount(Array.isArray(videos) ? videos.length : 0);
      } catch (err) {
        console.error('Failed to fetch watched videos count:', err);
      }

      if (!cancelled) setLoading(false);
    };

    fetchAll();
    return () => {
      cancelled = true;
    };
  }, [username]);

  if (loading) {
    return (
      <div className="py-24 flex flex-col items-center justify-center gap-4">
        <Loader2 className="w-10 h-10 text-blue-600 animate-spin" />
        <p className="text-sm font-black text-slate-400">جاري تحميل إحصائياتك...</p>
      </div>
    );
  }

  const enrolledCount = stats?.enrolled_courses ?? courses.length;
  const examsTaken = stats?.exams_taken ?? 0;
  const studyMinutes = stats?.total_study_time_minutes ?? 0;
  const studyHours = (studyMinutes / 60).toFixed(1);

  // Per-course real completion data, used for the breakdown panel and the
  // overall rating derivation.
  const coursesWithCompletion = courses.map((c) => {
    const total = c.total_lessons ?? 0;
    const completed = c.completed_lessons ?? 0;
    const completionPercent = total > 0 ? Math.round((completed / total) * 100) : 0;
    return {
      ...c,
      total,
      completed,
      completionPercent,
      grade: typeof c.latest_grade === 'number' ? c.latest_grade : null,
    };
  });

  // Overall rating = average completion percentage across subscribed
  // courses. This is a real, honest derivation from total_lessons /
  // completed_lessons — not a fabricated "level" system.
  const overallRating =
    coursesWithCompletion.length > 0
      ? Math.round(
          coursesWithCompletion.reduce((sum, c) => sum + c.completionPercent, 0) /
            coursesWithCompletion.length
        )
      : 0;

  const cards = [
    {
      key: 'courses',
      label: 'الكورسات',
      value: formatNumber(enrolledCount),
      icon: BookOpen,
      color: 'blue',
      hint: 'عرض كورساتي ←',
      onClick: () => navigate('/student'),
    },
    {
      key: 'videos',
      label: 'فيديوهاتي',
      value: formatNumber(watchedVideosCount),
      icon: Video,
      color: 'purple',
      hint: 'عرض سجل المشاهدات ←',
      onClick: () => navigate('/student/watched-videos'),
    },
    {
      key: 'exams',
      label: 'امتحاناتي',
      value: formatNumber(examsTaken),
      icon: Award,
      color: 'indigo',
      hint: 'عرض سجل الامتحانات ←',
      onClick: () => navigate('/student/completed-exams'),
    },
  ] as const;

  const colorClasses: Record<string, { chip: string; border: string; hint: string }> = {
    blue: {
      chip: 'bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400',
      border: 'hover:border-blue-500/30 dark:hover:border-blue-500/30',
      hint: 'text-blue-500',
    },
    purple: {
      chip: 'bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400',
      border: 'hover:border-purple-500/30 dark:hover:border-purple-500/30',
      hint: 'text-purple-500',
    },
    indigo: {
      chip: 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400',
      border: 'hover:border-indigo-500/30 dark:hover:border-indigo-500/30',
      hint: 'text-indigo-500',
    },
  };

  return (
    <motion.div
      initial={{ opacity: 0, y: 15 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.4 }}
      className="space-y-8 pb-16"
      dir="rtl"
    >
      {/* Title & Introduction block */}
      <div className="bg-white dark:bg-slate-900 rounded-3xl p-6 md:p-8 border border-slate-200/50 dark:border-slate-800 shadow-sm relative overflow-hidden group">
        <div className="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-blue-600 via-indigo-500 to-blue-400" />
        <div className="absolute -left-20 -top-20 w-64 h-64 bg-blue-100/40 dark:bg-blue-900/10 rounded-full blur-3xl group-hover:scale-110 transition-transform duration-700" />

        <div className="relative flex flex-col md:flex-row md:items-center justify-between gap-6 z-10">
          <div>
            <div className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 font-bold text-xs mb-3 border border-blue-100/50 dark:border-blue-800/30">
              <Sparkles className="w-3.5 h-3.5" />
              <span>مرحباً، {username}</span>
            </div>
            <h1 className="text-2xl md:text-3xl font-black text-slate-900 dark:text-white mb-2 flex items-center gap-3">
              <Award className="w-7 h-7 text-blue-600 dark:text-blue-500" />
              إحصائياتي الدراسية
            </h1>
            <p className="text-sm font-bold text-slate-500 dark:text-slate-400 leading-relaxed max-w-2xl">
              لوحة تحكم لمتابعة تقدمك التعليمي، مشاهدة الفيديوهات، نتائج الامتحانات، ومستوى إنجازك في كل كورس مشترك به.
            </p>
          </div>
        </div>
      </div>

      {/* 5 named summary cards */}
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-4 md:gap-5">
        {cards.map((card) => {
          const Icon = card.icon;
          const c = colorClasses[card.color];
          return (
            <div
              key={card.key}
              onClick={card.onClick}
              role="button"
              tabIndex={0}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault();
                  card.onClick();
                }
              }}
              className={`bg-white dark:bg-slate-900 rounded-2xl p-4.5 border border-slate-100 dark:border-slate-800 shadow-xs hover:shadow-md hover:scale-[1.02] ${c.border} transition-all duration-300 flex flex-col justify-between group cursor-pointer relative`}
            >
              <div className={`w-10 h-10 rounded-xl ${c.chip} flex items-center justify-center mb-3`}>
                <Icon className="w-5 h-5 group-hover:scale-110 transition-transform" />
              </div>
              <div>
                <div className="text-2xl md:text-3xl font-black text-slate-900 dark:text-white tracking-tight">
                  {card.value}
                </div>
                <div className="text-xs font-black text-slate-400 dark:text-slate-500 mt-1">{card.label}</div>
                <div
                  className={`text-[10px] font-black ${c.hint} mt-1.5 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-0.5`}
                >
                  <span>{card.hint}</span>
                </div>
              </div>
            </div>
          );
        })}

        {/* Card 4: Study Hours (proxy metric, no dedicated detail page) */}
        <div className="bg-white dark:bg-slate-900 rounded-2xl p-4.5 border border-slate-100 dark:border-slate-800 shadow-xs hover:shadow-md hover:scale-[1.02] transition-all duration-300 flex flex-col justify-between group">
          <div className="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mb-3">
            <Clock className="w-5 h-5 group-hover:animate-pulse transition-transform" />
          </div>
          <div>
            <div className="text-2xl md:text-3xl font-black text-slate-900 dark:text-white tracking-tight">
              {formatNumber(Number(studyHours))} س
            </div>
            <div className="text-xs font-black text-slate-400 dark:text-slate-500 mt-1">ساعات المذاكرة</div>
          </div>
        </div>

        {/* Card 5: Overall Rating — expands per-course breakdown */}
        <div
          onClick={() => setShowBreakdown((v) => !v)}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              setShowBreakdown((v) => !v);
            }
          }}
          className="bg-white dark:bg-slate-900 rounded-2xl p-4.5 border border-slate-100 dark:border-slate-800 shadow-xs hover:shadow-md hover:scale-[1.02] hover:border-rose-500/30 dark:hover:border-rose-500/30 transition-all duration-300 flex flex-col justify-between group cursor-pointer relative"
        >
          <div className="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center mb-3">
            <TrendingUp className="w-5 h-5 group-hover:translate-x-0.5" />
          </div>
          <div>
            <div className="text-2xl md:text-3xl font-black text-slate-900 dark:text-white tracking-tight">
              {overallRating}%
            </div>
            <div className="text-xs font-black text-slate-400 dark:text-slate-500 mt-1">التقييم العام</div>
            <div className="text-[10px] font-black text-rose-500 mt-1.5 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1">
              <ChevronDown className={`w-3 h-3 transition-transform ${showBreakdown ? 'rotate-180' : ''}`} />
              <span>{showBreakdown ? 'إخفاء تفاصيل الإنجاز' : 'عرض تفاصيل الإنجاز'}</span>
            </div>
          </div>
        </div>
      </div>

      {/* Overall rating explanation + expandable per-course breakdown */}
      <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-100 dark:border-slate-800 shadow-sm overflow-hidden">
        <div className="p-6 md:p-8 flex items-center justify-between gap-4">
          <div>
            <h2 className="text-lg md:text-xl font-black text-slate-900 dark:text-white mb-1.5">
              التقييم العام: متوسط نسبة إنجاز الكورسات
            </h2>
            <p className="text-xs font-bold text-slate-400 dark:text-slate-500">
              يمثل متوسط نسبة إتمام الدروس عبر جميع الكورسات المشترك بها ({coursesWithCompletion.length}{' '}
              كورس).
            </p>
          </div>
          <button
            onClick={() => setShowBreakdown((v) => !v)}
            className="shrink-0 flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-black text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-950 border border-slate-200/60 dark:border-slate-800 hover:text-blue-600 dark:hover:text-blue-400 transition-colors cursor-pointer"
          >
            <ChevronDown className={`w-4 h-4 transition-transform ${showBreakdown ? 'rotate-180' : ''}`} />
            <span>{showBreakdown ? 'إخفاء' : 'عرض التفاصيل'}</span>
          </button>
        </div>

        <AnimatePresence>
          {showBreakdown && (
            <motion.div
              initial={{ height: 0, opacity: 0 }}
              animate={{ height: 'auto', opacity: 1 }}
              exit={{ height: 0, opacity: 0 }}
              transition={{ duration: 0.3 }}
              className="overflow-hidden"
            >
              <div className="px-6 md:px-8 pb-6 md:pb-8 space-y-3 border-t border-slate-100 dark:border-slate-800 pt-5">
                {coursesWithCompletion.length > 0 ? (
                  coursesWithCompletion.map((c) => (
                    <div
                      key={c.id}
                      className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50 dark:bg-slate-950/50 rounded-xl p-4 border border-slate-100 dark:border-slate-800/70"
                    >
                      <div className="min-w-0">
                        <p className="text-sm font-black text-slate-900 dark:text-white truncate">{c.title}</p>
                        <p className="text-[11px] font-bold text-slate-400 dark:text-slate-500 mt-0.5">
                          {c.completed} / {c.total} درس مكتمل
                        </p>
                      </div>
                      <div className="flex items-center gap-4 shrink-0">
                        <div className="w-28 h-2 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden">
                          <div
                            className="h-full bg-blue-600 rounded-full"
                            style={{ width: `${c.completionPercent}%` }}
                          />
                        </div>
                        <span className="text-xs font-black text-blue-600 dark:text-blue-400 w-10 text-left">
                          {c.completionPercent}%
                        </span>
                        <span className="text-xs font-black text-slate-500 dark:text-slate-400">
                          {c.grade !== null ? `آخر تقييم: ${c.grade}%` : 'لا يوجد تقييم بعد'}
                        </span>
                      </div>
                    </div>
                  ))
                ) : (
                  <p className="text-xs font-bold text-slate-400 dark:text-slate-500 text-center py-4">
                    لا توجد كورسات مشترك بها بعد لعرض تفاصيل الإنجاز.
                  </p>
                )}
              </div>
            </motion.div>
          )}
        </AnimatePresence>
      </div>

      {courses.length === 0 && (
        <div className="bg-white dark:bg-slate-900 rounded-3xl p-12 text-center border border-slate-100 dark:border-slate-800 shadow-xs">
          <BookOpen className="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-4" />
          <h3 className="text-base font-black text-slate-900 dark:text-white mb-1.5">لا توجد كورسات مسجلة بعد</h3>
          <p className="text-xs font-bold text-slate-400 dark:text-slate-500 max-w-sm mx-auto leading-relaxed">
            قم بالاشتراك في كورسات المنصة لبدء رحلتك ومتابعة تقاريرك الدراسية هنا.
          </p>
        </div>
      )}

      {stats?.average_score != null && (
        <div className="flex items-center gap-2 text-xs font-bold text-slate-500 dark:text-slate-400 bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-xs">
          <CheckCircle className="w-4 h-4 text-emerald-500 shrink-0" />
          <span>متوسط درجاتك في الامتحانات المقدَّمة: {Math.round(stats.average_score)}%</span>
        </div>
      )}
    </motion.div>
  );
}
