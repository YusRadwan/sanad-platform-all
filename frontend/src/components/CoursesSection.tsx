import { Link } from 'react-router';
import { motion } from 'motion/react';
import { BookOpen } from 'lucide-react';
import CourseCard from './CourseCard';
import CoursesCarousel from './CoursesCarousel';
import SectionDivider from './SectionDivider';



export default function CoursesSection({
  courses = [],
  isLoggedIn = false,
  onNavigate,
  onViewCourse,
  hasMore = false,
  isLoading = false,
  onLoadMore,
  isPreview = false
}: any) {
  // The homepage preview now shows the FULL live list as an auto-scrolling
  // carousel (see CoursesCarousel) instead of a capped grid, so there is no
  // slice here any more — every course is reachable by letting it scroll.
  const visibleCourses = courses;

  return (
    <section id="courses" className="pt-24 transition-colors duration-300">
      <div className="container mx-auto px-6 md:px-12 lg:px-16">

        {/* Section Title Header Block — plain page background; the fill
            colour only starts below this, in the full-bleed panel wrapping
            the cards. */}
        <div className="text-center max-w-2xl mx-auto mb-16">
          <motion.div
            initial={{ opacity: 0, y: -10 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            className="flex items-center gap-3 justify-center mb-3"
          >
            <span className="h-[3px] w-9 rounded-full bg-gradient-to-l from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 shrink-0" />
            <span className="text-base font-black tracking-wide text-blue-700 dark:text-blue-400">الكورسات المقترحة</span>
            <span className="h-[3px] w-9 rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 shrink-0" />
          </motion.div>
          <motion.h2
            initial={{ opacity: 0, y: 15 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ delay: 0.1 }}
            className="text-4xl md:text-5xl font-black text-slate-900 dark:text-white mb-5 tracking-tight flex items-center justify-center gap-4"
          >
            <span>كورسات</span>
            <span className="text-6xl md:text-8xl pb-2 md:pb-4 leading-none inline-block" style={{ color: '#3544e1' }}>
              سند
            </span>
          </motion.h2>
          <div className="w-16 h-1 bg-blue-600 mx-auto mt-6 rounded-full" />
        </div>
      </div>

      {/* Full-bleed panel — the background colour starts HERE, below the
          title block above, not from the top of the section. The carousel
          (preview mode) is rendered OUTSIDE the padded `.container` so cards
          reach the true left/right edges instead of stopping at the
          container's side padding. */}
      <div className="relative left-1/2 -translate-x-1/2 w-screen py-16 md:py-20" style={{ backgroundColor: '#2b70b3' }}>
        <SectionDivider variant="blob" position="top" />
        <SectionDivider variant="blob" position="bottom" />

        {isPreview ? (
          visibleCourses.length === 0 ? (
            <div className="container mx-auto px-6 md:px-12 lg:px-16">
              <div className="text-center py-20 space-y-4" dir="rtl">
                <div className="w-16 h-16 rounded-2xl bg-white dark:bg-slate-950 border border-slate-200/60 dark:border-slate-800 flex items-center justify-center mx-auto text-slate-400 dark:text-slate-600 shadow-sm">
                  <BookOpen className="w-8 h-8" />
                </div>
                <div className="space-y-1.5">
                  <h3 className="text-lg font-black text-slate-800 dark:text-slate-200">لا توجد كورسات متاحة حالياً</h3>
                  <p className="text-xs font-bold text-slate-400 dark:text-slate-400 max-w-sm mx-auto leading-relaxed">
                    لم يتم نشر أي كورس على المنصة حتى الآن. تابعنا قريباً — سيظهر هنا كل كورس جديد فور إتاحته.
                  </p>
                </div>
              </div>
            </div>
          ) : (
            <CoursesCarousel courses={visibleCourses} isLoggedIn={isLoggedIn} onViewCourse={onViewCourse} />
          )
        ) : (
          <div className="container mx-auto px-6 md:px-12 lg:px-16">
            {/* Course Cards Grid — dedicated /courses page only. An empty
                grid used to render as a blank gap, which reads as a broken
                page rather than as "no courses published yet". */}
            {visibleCourses.length === 0 ? (
              <div className="text-center py-20 space-y-4" dir="rtl">
                <div className="w-16 h-16 rounded-2xl bg-white dark:bg-slate-950 border border-slate-200/60 dark:border-slate-800 flex items-center justify-center mx-auto text-slate-400 dark:text-slate-600 shadow-sm">
                  <BookOpen className="w-8 h-8" />
                </div>
                <div className="space-y-1.5">
                  <h3 className="text-lg font-black text-slate-800 dark:text-slate-200">لا توجد كورسات متاحة حالياً</h3>
                  <p className="text-xs font-bold text-slate-400 dark:text-slate-400 max-w-sm mx-auto leading-relaxed">
                    لم يتم نشر أي كورس على المنصة حتى الآن. تابعنا قريباً — سيظهر هنا كل كورس جديد فور إتاحته.
                  </p>
                </div>
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                {visibleCourses.map((course: any, index: number) => (
                  <CourseCard
                    key={course.id || index}
                    course={course}
                    isLoggedIn={isLoggedIn}
                    onNavigate={onNavigate}
                    onViewCourse={onViewCourse}
                    index={index}
                  />
                ))}
              </div>
            )}

            {/* Dedicated /courses page: real server-pagination load more button */}
            {hasMore && (
              <div className="flex justify-center mt-16">
                <button
                  onClick={onLoadMore}
                  disabled={isLoading}
                  className="inline-flex items-center px-10 py-4 rounded-full bg-white dark:bg-slate-950 border-2 border-slate-200/80 dark:border-slate-800/80 font-black text-slate-800 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors duration-300 cursor-pointer text-base disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {isLoading ? 'جاري التحميل...' : 'عرض المزيد من الكورسات'}
                </button>
              </div>
            )}
          </div>
        )}

        {isPreview && visibleCourses.length > 0 && (
          <div className="container mx-auto px-6 md:px-12 lg:px-16">
            <div className="flex justify-center mt-16">
              <Link
                to="/courses"
                className="inline-flex items-center px-10 py-4 rounded-full text-white font-black text-base"
                style={{ backgroundColor: '#3544e1' }}
              >
                عرض المزيد
              </Link>
            </div>
          </div>
        )}
      </div>
    </section>
  );
}
