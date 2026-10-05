import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { useNavigate } from 'react-router';
import {
  Video,
  ArrowRight,
  Sparkles,
  CheckCircle2,
  Clock,
  Loader2,
  BookOpen,
} from 'lucide-react';

import { studentService } from '../../services/studentService';
import { formatDateTime } from '../../utils/dateUtils';

interface WatchedVideo {
  content_item_id: number | string;
  title: string;
  course_id: number | string;
  course_title: string;
  progress_seconds: number;
  completed: boolean;
  last_watched_at: string;
}

function formatWatchDuration(seconds: number): string {
  const total = Math.max(0, Math.floor(seconds || 0));
  const minutes = Math.floor(total / 60);
  const secs = total % 60;
  if (minutes <= 0) return `${secs} ثانية`;
  return `${minutes} د ${secs > 0 ? `${secs} ث` : ''}`.trim();
}

export default function StudentWatchedVideos() {
  const navigate = useNavigate();
  const [videos, setVideos] = useState<WatchedVideo[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    const fetchVideos = async () => {
      try {
        setLoading(true);
        const data = await studentService.getWatchedVideos();
        if (!cancelled) setVideos(Array.isArray(data) ? data : []);
      } catch (err) {
        console.error('Failed to fetch watched videos:', err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    };
    fetchVideos();
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <motion.div
      initial={{ opacity: 0, y: 15 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.4 }}
      className="space-y-8 pb-16"
      dir="rtl"
    >
      {/* Navigation Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <button 
          onClick={() => navigate('/student/statistics')}
          className="self-start flex items-center gap-2 px-4.5 py-2.5 text-xs md:text-sm font-black text-slate-700 dark:text-slate-200 hover:text-blue-600 dark:hover:text-blue-400 bg-white dark:bg-slate-900 border border-slate-200/60 dark:border-slate-800 rounded-2xl shadow-3xs hover:shadow-2xs transition-all cursor-pointer"
        >
          <ArrowRight className="w-4 h-4 text-slate-500" />
          <span>الرجوع للوحة الإحصائيات</span>
        </button>

        <div className="flex items-center gap-2 bg-blue-500/10 dark:bg-blue-500/5 px-4 py-2 rounded-2xl border border-blue-500/15">
          <Sparkles className="w-4 h-4 text-blue-500" />
          <span className="text-xs font-bold text-blue-600 dark:text-blue-400">سجل المشاهدة التفاعلي</span>
        </div>
      </div>

      {/* Main Title Block */}
      <div className="bg-white dark:bg-slate-900 rounded-3xl p-6 md:p-8 border border-slate-200/50 dark:border-slate-800 shadow-3xs relative overflow-hidden group">
        <div className="absolute top-0 left-0 w-full h-1.5 bg-gradient-to-r from-purple-500 to-indigo-500" />
        <div className="relative flex items-center gap-4.5">
          <div className="w-14 h-14 rounded-2xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 flex items-center justify-center shadow-3xs">
            <Video className="w-7 h-7" />
          </div>
          <div>
            <h1 className="text-xl md:text-2xl font-black text-slate-900 dark:text-white">سجل الدروس والمحاضرات المرئية</h1>
            <p className="text-xs md:text-sm font-bold text-slate-400 dark:text-slate-500 mt-1">متابعة تفصيلية للفيديوهات التي قمت بفتحها ومستوى تقدمك الفعلي في كل كورس.</p>
          </div>
        </div>
      </div>

      {/* Videos List */}
      <div className="space-y-5">
        {loading ? (
          <div className="py-20 flex flex-col items-center justify-center gap-4">
            <Loader2 className="w-9 h-9 text-purple-600 animate-spin" />
            <p className="text-sm font-black text-slate-400">جاري تحميل سجل المشاهدات...</p>
          </div>
        ) : videos.length === 0 ? (
          <div className="bg-white dark:bg-slate-900 rounded-3xl p-16 text-center border border-slate-100 dark:border-slate-800 shadow-3xs">
            <Video className="w-14 h-14 text-slate-300 dark:text-slate-700 mx-auto mb-4" />
            <p className="text-base font-black text-slate-900 dark:text-white mb-2">لا توجد محاضرات في السجل حالياً</p>
            <p className="text-xs font-bold text-slate-400 dark:text-slate-500 max-w-md mx-auto leading-relaxed">
              تأكد من اختيار الكورسات والبدء بمشاهدة الدروس المرئية ليتم تسجيل ومتابعة تقدمك التفصيلي هنا.
            </p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {videos.map((video) => (
              <motion.div
                key={video.content_item_id}
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
                className="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-100 dark:border-slate-800 shadow-3xs hover:shadow-md transition-all duration-300 flex flex-col gap-3"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h3 className="text-sm font-black text-slate-900 dark:text-white truncate">{video.title}</h3>
                    <p className="text-[11px] font-bold text-slate-400 dark:text-slate-500 mt-1 flex items-center gap-1.5">
                      <BookOpen className="w-3.5 h-3.5 shrink-0" />
                      <span className="truncate">{video.course_title}</span>
                    </p>
                  </div>
                  <span
                    className={`shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black border ${
                      video.completed
                        ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 border-emerald-100/50 dark:border-emerald-900/30'
                        : 'bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 border-blue-100/50 dark:border-blue-900/30'
                    }`}
                  >
                    <CheckCircle2 className="w-3 h-3" />
                    <span>{video.completed ? 'مكتمل' : 'قيد المشاهدة'}</span>
                  </span>
                </div>

                <div className="flex items-center justify-between pt-3 border-t border-slate-100 dark:border-slate-800/70 text-[11px] font-bold text-slate-400 dark:text-slate-500">
                  <span className="flex items-center gap-1.5">
                    <Clock className="w-3.5 h-3.5" />
                    <span>{formatWatchDuration(video.progress_seconds)}</span>
                  </span>
                  <span>آخر مشاهدة: {formatDateTime(video.last_watched_at)}</span>
                </div>
              </motion.div>
            ))}
          </div>
        )}
      </div>
    </motion.div>
  );
}
