import { useEffect, useRef, useState } from 'react';
import { motion, useAnimationFrame, useMotionValue } from 'motion/react';
import CourseCard from './CourseCard';
import { Course } from '../types/index';

/**
 * Infinite, hover/drag-pausable horizontal carousel for the "كورسات سند"
 * homepage preview — same mechanics as `TeachersCarousel`, applied to
 * `CourseCard`. See that component's docblock for how the seamless
 * loop/pause/drag/resume logic works; kept as a near-identical sibling
 * rather than a shared abstraction so each can size its own card wrapper
 * independently (course cards are noticeably wider than teacher cards).
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
  const trackRef = useRef<HTMLDivElement>(null);
  const x = useMotionValue(0);
  const isPaused = useRef(false);
  const isDragging = useRef(false);
  const [copyWidth, setCopyWidth] = useState(0);

  // Nudged up slightly from the original 32 per a direct request to speed
  // it up a bit — same change already applied to TeachersCarousel.tsx.
  const SPEED = 48;

  useEffect(() => {
    const el = trackRef.current;
    if (!el) return;
    const measure = () => setCopyWidth(el.scrollWidth / 2);
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [courses]);

  useEffect(() => {
    if (copyWidth > 0) x.set(-copyWidth);
  }, [copyWidth, x]);

  useAnimationFrame((_, delta) => {
    if (isPaused.current || isDragging.current || copyWidth === 0) return;
    let next = x.get() + (delta / 1000) * SPEED;
    if (next > 0) next -= copyWidth;
    x.set(next);
  });

  if (courses.length === 0) return null;

  const loopedCourses = [...courses, ...courses];

  return (
    <div
      dir="ltr"
      className="relative overflow-x-hidden overflow-y-visible pt-6 pb-4 px-4 sm:px-6"
      onMouseEnter={() => { isPaused.current = true; }}
      onMouseLeave={() => { isPaused.current = false; }}
    >
      {/* See TeachersCarousel.tsx for why the track is forced `dir="ltr"`
          against the app's ambient RTL while each card stays `dir="rtl"`. */}
      <motion.div
        ref={trackRef}
        style={{ x }}
        drag="x"
        dragConstraints={copyWidth > 0 ? { left: -copyWidth, right: 0 } : undefined}
        dragElastic={0.06}
        onDragStart={() => { isDragging.current = true; }}
        onDragEnd={() => { isDragging.current = false; }}
        className="flex w-max gap-6 cursor-grab active:cursor-grabbing select-none"
      >
        {loopedCourses.map((course, idx) => (
          <div key={`${course.id}-${idx}`} dir="rtl" className="w-[85vw] max-w-[320px] sm:w-[380px] sm:max-w-none md:w-[420px] shrink-0 pointer-events-auto">
            <CourseCard
              course={course}
              isLoggedIn={isLoggedIn}
              onViewCourse={onViewCourse}
              index={0}
            />
          </div>
        ))}
      </motion.div>
    </div>
  );
}
