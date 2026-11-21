import { Link } from 'react-router';
import { motion, AnimatePresence } from 'motion/react';
import { GraduationCap, ArrowLeft } from 'lucide-react';
import TeacherCard from './TeacherCard';
import TeachersCarousel from './TeachersCarousel';
import SectionDivider from './SectionDivider';
import SectionHeading from './SectionHeading';
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
    <section id="teachers" aria-labelledby="teachers-title" className="pt-24 transition-colors duration-300">
      <div className="container mx-auto px-6 md:px-12 lg:px-16">

        {/* Section Header — plain page background; the fill colour only
            starts below this, in the full-bleed panel wrapping the cards. */}
        <SectionHeading
          id="teachers-title"
          before="اختر"
          highlight="سندك"
          lead="خُبراء يقودون رحلتك التعليمية"
        />
      </div>

      {/* Full-bleed panel — the background colour starts HERE, below the
          title block above, not from the top of the section. The carousel
          itself is rendered OUTSIDE the padded `.container` (edge-to-edge
          against the panel's own full-viewport width) so cards can reach
          the true left/right edges instead of stopping at the container's
          side padding, which used to read as a dead margin framing them. */}
      <div className="bg-sanad-steel relative left-1/2 -translate-x-1/2 w-screen py-16 md:py-24">
        <SectionDivider variant="wave" position="top" />
        <SectionDivider variant="wave" position="bottom" />

        {isPreview ? (
          displayedTeachers.length === 0 ? (
            <div className="container mx-auto px-6 md:px-12 lg:px-16">
              <div className="text-center py-20 space-y-4" dir="rtl">
                <div className="flex justify-center text-white/80">
                  <GraduationCap className="w-10 h-10" aria-hidden="true" />
                </div>
                <div className="space-y-1.5">
                  <h3 className="text-lg font-black text-white">لا يوجد معلمون متاحون حالياً</h3>
                  <p className="text-sm font-medium text-white max-w-sm mx-auto leading-relaxed">
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
                  <div className="flex justify-center text-white/80">
                    <GraduationCap className="w-10 h-10" aria-hidden="true" />
                  </div>
                  <div className="space-y-1.5">
                    <h3 className="text-lg font-black text-white">لا يوجد معلمون متاحون حالياً</h3>
                    <p className="text-sm font-medium text-white max-w-sm mx-auto leading-relaxed">
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
                className="btn-sanad-light inline-flex items-center gap-2 min-h-12 px-10 py-4 rounded-full font-black text-base"
              >
                كل المدرسين
                <ArrowLeft className="w-5 h-5" aria-hidden="true" />
              </Link>
            </div>
          </div>
        )}
      </div>
    </section>
  );
}
