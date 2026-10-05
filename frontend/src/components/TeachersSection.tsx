import { Link } from 'react-router';
import { motion, AnimatePresence } from 'motion/react';
import { GraduationCap } from 'lucide-react';
import TeacherCard from './TeacherCard';
import TeachersCarousel from './TeachersCarousel';
import SectionDivider from './SectionDivider';
import { TeachersSectionProps } from '../types/index';

/**
 * The public "اختر سندك" teachers showcase.
 *
 * The stage filter (جميع المراحل / الإعدادية / الثانوية) that used to sit
 * under the heading is gone, along with its `activeFilter` state and the
 * substring-matching `belongsToPrep`/`belongsToSec` helpers. It classified
 * teachers by looking for Arabic fragments inside free-text grade names, so it
 * silently hid anyone whose curriculum names its grades differently — the
 * whole list is simply shown now.
 *
 * Homepage preview (`isPreview`) renders the live teacher list as an
 * auto-scrolling `TeachersCarousel` instead of a fixed grid — see that
 * component for the drag/hover/loop mechanics. The dedicated /teachers page
 * (`isPreview=false`) keeps the original paginated-feeling grid, since that
 * page's whole purpose is browsing the full roster at once.
 */

export default function TeachersSection({ teachersList, onViewProfile, isPreview = false }: TeachersSectionProps & { isPreview?: boolean }) {
  const allTeachers = teachersList || [];
  const displayedTeachers = allTeachers;

  return (
    <section id="teachers" className="pt-24 transition-colors duration-300">
      <div className="container mx-auto px-6 md:px-12 lg:px-16">

        {/* Section Header — plain page background; the fill colour only
            starts below this, in the full-bleed panel wrapping the cards. */}
        <div className="text-center max-w-2xl mx-auto mb-16">
          <motion.div
            initial={{ opacity: 0, y: -10 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            className="flex items-center gap-3 justify-center mb-3"
          >
            <span className="h-[3px] w-9 rounded-full bg-gradient-to-l from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 shrink-0" />
            <span className="text-base font-black tracking-wide text-blue-700 dark:text-blue-400">خُبراء يقودون رحلتك التعليمية</span>
            <span className="h-[3px] w-9 rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 shrink-0" />
          </motion.div>
          <motion.h2
            initial={{ opacity: 0, y: 15 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ delay: 0.1 }}
            className="text-4xl md:text-5xl font-black text-slate-900 dark:text-white mb-5 tracking-tight flex items-center justify-center gap-4"
          >
            <span>اختر</span>
            <span className="text-6xl md:text-8xl pb-2 md:pb-4 leading-none inline-block" style={{ color: '#3544e1' }}>
              سندك
            </span>
          </motion.h2>
          <div className="w-16 h-1 bg-blue-600 mx-auto mt-6 rounded-full" />
        </div>
      </div>

      {/* Full-bleed panel — the background colour starts HERE, below the
          title block above, not from the top of the section. The carousel
          itself is rendered OUTSIDE the padded `.container` (edge-to-edge
          against the panel's own full-viewport width) so cards can reach
          the true left/right edges instead of stopping at the container's
          side padding, which used to read as a dead margin framing them. */}
      <div className="relative left-1/2 -translate-x-1/2 w-screen py-16 md:py-24" style={{ backgroundColor: '#2971b6' }}>
        <SectionDivider variant="bumps" position="top" />
        <SectionDivider variant="bumps" position="bottom" />

        {isPreview ? (
          displayedTeachers.length === 0 ? (
            <div className="container mx-auto px-6 md:px-12 lg:px-16">
              <div className="text-center py-20 space-y-4" dir="rtl">
                <div className="w-16 h-16 rounded-2xl bg-slate-50 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800 flex items-center justify-center mx-auto text-slate-400 dark:text-slate-600">
                  <GraduationCap className="w-8 h-8" />
                </div>
                <div className="space-y-1.5">
                  <h3 className="text-lg font-black text-slate-800 dark:text-slate-200">لا يوجد معلمون متاحون حالياً</h3>
                  <p className="text-xs font-bold text-slate-400 dark:text-slate-400 max-w-sm mx-auto leading-relaxed">
                    لم يتم تفعيل أي حساب معلم على المنصة حتى الآن من لوحة التحكم.
                  </p>
                </div>
              </div>
            </div>
          ) : (
            <TeachersCarousel teachers={displayedTeachers} onViewProfile={onViewProfile} />
          )
        ) : (
          /* Teachers Cards Grid — dedicated /teachers page only */
          <div className="container mx-auto px-6 md:px-12 lg:px-16">
            <motion.div
              layout
              // `gap-y-56` (not the previous `gap-y-28`) and `mt-36` (not
              // `mt-16`): TeacherCard.tsx's octagonal photo frame pops out
              // well above the card's own top edge, and this grid's rows
              // used to sit close enough together that the next row's
              // popped-out frame actually overlapped the row above it — the
              // same clearance problem TeachersCarousel.tsx's `pt-36` fixes
              // for the homepage's auto-scrolling version of this card.
              className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-56 max-w-5xl mx-auto min-h-[300px] mt-36"
            >
              <AnimatePresence mode="popLayout">
                {displayedTeachers.map((teacher) => (
                  <motion.div
                    key={teacher.id}
                    layout
                    initial={{ opacity: 0, scale: 0.95 }}
                    animate={{ opacity: 1, scale: 1 }}
                    exit={{ opacity: 0, scale: 0.95 }}
                    transition={{ duration: 0.3 }}
                  >
                    <TeacherCard
                      teacher={teacher}
                      onViewProfile={onViewProfile}
                    />
                  </motion.div>
                ))}
              </AnimatePresence>

              {/* Empty State */}
              {displayedTeachers.length === 0 && (
                <motion.div
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  className="col-span-full text-center py-20 space-y-4"
                  dir="rtl"
                >
                  <div className="w-16 h-16 rounded-2xl bg-slate-50 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800 flex items-center justify-center mx-auto text-slate-400 dark:text-slate-600">
                    <GraduationCap className="w-8 h-8" />
                  </div>
                  <div className="space-y-1.5">
                    <h3 className="text-lg font-black text-slate-800 dark:text-slate-200">لا يوجد معلمون متاحون حالياً</h3>
                    <p className="text-xs font-bold text-slate-400 dark:text-slate-400 max-w-sm mx-auto leading-relaxed">
                      لم يتم تفعيل أي حساب معلم على المنصة حتى الآن من لوحة التحكم.
                    </p>
                  </div>
                </motion.div>
              )}
            </motion.div>
          </div>
        )}

        {isPreview && displayedTeachers.length > 0 && (
          <div className="container mx-auto px-6 md:px-12 lg:px-16">
            <div className="flex justify-center mt-16">
              <Link
                to="/teachers"
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
