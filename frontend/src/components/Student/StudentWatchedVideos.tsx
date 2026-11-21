import StudentShell from './StudentShell';
import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { Video, CheckCircle2, Clock, Loader2, BookOpen } from 'lucide-react';

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
    <StudentShell title="الفيديوهات" lead="كل فيديو فتحته، وبقالك فيه قد إيه">
      <div className="space-y-8">
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
      </div>
    </StudentShell>
  );
}
