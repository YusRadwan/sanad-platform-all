import { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { BookOpen, Sparkles, BookMarked } from 'lucide-react';
import { useNavigate } from 'react-router';
import CourseCard from '../CourseCard';
import { MyCoursesProps } from '../../types/index';
import { studentService } from '../../services/studentService';






export default function MyCourses({ username }: MyCoursesProps) {
  const navigate = useNavigate();
  const [subscribedCourses, setSubscribedCourses] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchCourses = async () => {
      try {
        setLoading(true);
        const data = await studentService.getDashboard();
        setSubscribedCourses(data.courses);
      } catch (err) {
        console.error('Failed to fetch student courses:', err);
      } finally {
        setLoading(false);
      }
    };
    fetchCourses();
  }, [username]);

  if (loading) {
    return (
      <div className="py-24 flex flex-col items-center justify-center gap-4">
        <div className="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
        <p className="text-sm font-black text-slate-400">جاري تحميل كورساتك...</p>
      </div>
    );
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      className="space-y-6"
    >
      {/* Visual Context Header */}
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
              <BookMarked className="w-7 h-7 text-blue-600 dark:text-blue-500" />
              كورساتي المفتوحة
            </h1>

          </div>
          
          <div className="flex animate-in fade-in slide-in-from-bottom-4 duration-500 zoom-in-95">
            <div className="bg-slate-50 dark:bg-slate-950 p-4 rounded-2xl border border-slate-100 dark:border-slate-800 flex items-center gap-4 min-w-[140px] justify-center text-center">
              <div>
                <div className="text-3xl font-black text-blue-600 dark:text-blue-400 mb-0.5 shadow-blue-500/20 drop-shadow-sm">{subscribedCourses.length}</div>
                <div className="text-[10px] font-black tracking-wider text-slate-400 uppercase">كورسات مشتركة</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {subscribedCourses.length > 0 ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8">
          <AnimatePresence mode="popLayout">
            {subscribedCourses.map((course, index) => (
              <CourseCard
                key={course.id}
                course={course}
                index={index}
                isLoggedIn={true}
                onViewCourse={(id) => navigate(`/course/${id}`)}
              />
            ))}
          </AnimatePresence>
        </div>
      ) : (
        <motion.div 
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          className="bg-white dark:bg-slate-900 rounded-3xl p-12 lg:p-20 border border-slate-200/50 dark:border-slate-800 text-center flex flex-col items-center justify-center min-h-[400px] shadow-sm relative overflow-hidden"
        >
          {/* Background decoration */}
          <div className="absolute inset-0 opacity-[0.015] dark:opacity-[0.03]" style={{ backgroundImage: 'radial-gradient(circle at 2px 2px, currentColor 1px, transparent 0)', backgroundSize: '24px 24px' }}></div>
          
          <div className="relative">
            <div className="w-24 h-24 mx-auto bg-slate-50 dark:bg-slate-950 rounded-[2rem] border-2 border-slate-100 dark:border-slate-800 flex items-center justify-center mb-6 shadow-sm rotate-3 hover:rotate-0 transition-transform duration-300">
              <BookOpen className="w-10 h-10 text-slate-300 dark:text-slate-600 stroke-[1.5]" />
            </div>
            <h3 className="text-xl md:text-2xl font-black text-slate-800 dark:text-slate-100 mb-3">لا توجد كورسات مشتركة حالياً</h3>
            <p className="text-sm font-bold text-slate-500 dark:text-slate-400 max-w-sm mx-auto leading-relaxed mb-8">
              أنت غير مشترك في أي كورس حتى الآن. يمكنك تصفح كورسات المنصة والاشتراك فيها لتبدأ رحلتك التعليمية.
            </p>
            
            <button
              onClick={() => navigate('/')}
              className="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm rounded-xl transition-all cursor-pointer shadow-lg shadow-blue-500/20 hover:shadow-blue-500/30 active:scale-95"
            >
              <BookOpen className="w-4 h-4" />
              <span>تصفح الكورسات المتاحة</span>
            </button>
          </div>
        </motion.div>
      )}

    </motion.div>
  );
}
