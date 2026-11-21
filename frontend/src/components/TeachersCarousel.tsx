import { motion } from 'motion/react';
import TeacherCard from './TeacherCard';
import CarouselPauseButton from './CarouselPauseButton';
import { Teacher } from '../types/index';
import { useAutoScrollTrack } from '../hooks/useAutoScrollTrack';

/**
 * Infinite, hover/focus/drag-pausable horizontal carousel for the "اختر سندك"
 * homepage preview. Loop/drag mechanics and every accessibility guard
 * (reduced motion, pause button, focus-into-view, off-screen pause) live in
 * `useAutoScrollTrack`, shared with CoursesCarousel.
 *
 * The list is rendered twice for the seamless wrap; the SECOND copy is
 * `aria-hidden` + `inert`, so screen readers and the Tab key meet each
 * teacher exactly once instead of twice.
 */
export default function TeachersCarousel({
  teachers,
  onViewProfile,
}: {
  teachers: Teacher[];
  onViewProfile?: (id: string) => void;
}) {
  const { trackRef, viewportRef, x, viewportHandlers, trackDragProps, canAutoplay, isUserPaused, toggleUserPause } =
    useAutoScrollTrack(teachers.length);

  if (teachers.length === 0) return null;

  const loopedTeachers = [...teachers, ...teachers];

  return (
    <div role="region" aria-roledescription="carousel" aria-label="المدرسين">
      <div
        ref={viewportRef}
        // `dir="ltr"` on the viewport (against the app's RTL) keeps the
        // track's x=0 at the physical left edge, which the wrap/drag math
        // assumes; each card re-declares `dir="rtl"`. `pt-36` clears the
        // TeacherCard photo frame that pops out above the card.
        dir="ltr"
        className="relative overflow-x-hidden overflow-y-visible pt-36 pb-2 px-4 sm:px-6"
        {...viewportHandlers}
      >
        <motion.div
          ref={trackRef}
          style={{ x }}
          {...trackDragProps}
          className="flex w-max gap-6 cursor-grab active:cursor-grabbing select-none"
        >
          {loopedTeachers.map((teacher, idx) => {
            const isClone = idx >= teachers.length;
            return (
              <div
                key={`${teacher.id}-${idx}`}
                dir="rtl"
                data-carousel-item
                aria-hidden={isClone || undefined}
                inert={isClone || undefined}
                className="w-[260px] sm:w-[280px] shrink-0"
              >
                <TeacherCard teacher={teacher} onViewProfile={onViewProfile} />
              </div>
            );
          })}
        </motion.div>
      </div>
      {canAutoplay && (
        <div className="flex justify-center mt-6">
          <CarouselPauseButton paused={isUserPaused} onToggle={toggleUserPause} label="المدرسين" />
        </div>
      )}
    </div>
  );
}
