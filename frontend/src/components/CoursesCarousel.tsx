import { motion } from 'motion/react';
import CourseCard from './CourseCard';
import CarouselPauseButton from './CarouselPauseButton';
import { Course } from '../types/index';
import { useAutoScrollTrack } from '../hooks/useAutoScrollTrack';

/**
 * Infinite auto-scrolling carousel for the "كورسات سند" homepage preview —
 * same engine as TeachersCarousel (see `useAutoScrollTrack`), wider cards.
 * The duplicated second copy is `aria-hidden` + `inert`.
 */
export default function CoursesCarousel({
  courses,
  isLoggedIn,
  onViewCourse,
}: {
  courses: Course[];
  isLoggedIn?: boolean;
  onViewCourse?: (id: string | number) => void;
}) {
  const { trackRef, viewportRef, x, viewportHandlers, trackDragProps, canAutoplay, isUserPaused, toggleUserPause } =
    useAutoScrollTrack(courses.length);

  if (courses.length === 0) return null;

  const loopedCourses = [...courses, ...courses];

  return (
    <div role="region" aria-roledescription="carousel" aria-label="الكورسات">
      <div
        ref={viewportRef}
        dir="ltr"
        className="relative overflow-x-hidden overflow-y-visible pt-6 pb-4 px-4 sm:px-6"
        {...viewportHandlers}
      >
        <motion.div
          ref={trackRef}
          style={{ x }}
          {...trackDragProps}
          className="flex w-max gap-6 cursor-grab active:cursor-grabbing select-none"
        >
          {loopedCourses.map((course, idx) => {
            const isClone = idx >= courses.length;
            return (
              <div
                key={`${course.id}-${idx}`}
                dir="rtl"
                data-carousel-item
                aria-hidden={isClone || undefined}
                inert={isClone || undefined}
                className="w-[85vw] max-w-[320px] sm:w-[380px] sm:max-w-none md:w-[420px] shrink-0"
              >
                <CourseCard course={course} isLoggedIn={isLoggedIn} onViewCourse={onViewCourse} index={0} />
              </div>
            );
          })}
        </motion.div>
      </div>
      {canAutoplay && (
        <div className="flex justify-center mt-6">
          <CarouselPauseButton paused={isUserPaused} onToggle={toggleUserPause} label="الكورسات" />
        </div>
      )}
    </div>
  );
}
