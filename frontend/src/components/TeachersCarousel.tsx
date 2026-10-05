import { useEffect, useRef, useState } from 'react';
import { motion, useAnimationFrame, useMotionValue } from 'motion/react';
import TeacherCard from './TeacherCard';
import { Teacher } from '../types/index';

/**
 * Infinite, hover/drag-pausable horizontal carousel for the "اختر سندك"
 * homepage preview.
 *
 * The track renders the teacher list TWICE back to back. Because both
 * halves are identical, translating the track by exactly one half-width
 * (`copyWidth`) is visually seamless — so the auto-scroll loop simply keeps
 * `x` inside the `(-copyWidth, 0]` range and wraps by `copyWidth` instead of
 * jumping back to 0, which is what would otherwise produce a visible "reset"
 * flicker or gap.
 *
 * Autoplay is a manual `useAnimationFrame` increment (not a CSS/keyframe
 * animation) specifically so the SAME `x` motion value can be driven by
 * Framer Motion's built-in `drag="x"` during a manual swipe — pausing is
 * just "skip the increment while hovered or mid-drag", and resuming picks
 * up from wherever the drag left off, with no jump.
 */
export default function TeachersCarousel({
  teachers,
  onViewProfile,
}: {
  teachers: Teacher[];
  onViewProfile?: (id: string) => void;
}) {
  const trackRef = useRef<HTMLDivElement>(null);
  const x = useMotionValue(0);
  const isPaused = useRef(false);
  const isDragging = useRef(false);
  const [copyWidth, setCopyWidth] = useState(0);

  // Pixels per second — kept slow so it never reads as a distracting ticker,
  // nudged up slightly from the original 32 per a direct request to speed it
  // up a bit.
  const SPEED = 48;

  useEffect(() => {
    const el = trackRef.current;
    if (!el) return;
    const measure = () => setCopyWidth(el.scrollWidth / 2);
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [teachers]);

  useEffect(() => {
    if (copyWidth > 0) x.set(-copyWidth);
    // Re-anchor into the safe wrap range whenever the measured width changes
    // (e.g. a teacher is added/removed), so the loop never runs against a
    // stale width.
  }, [copyWidth, x]);

  useAnimationFrame((_, delta) => {
    if (isPaused.current || isDragging.current || copyWidth === 0) return;
    let next = x.get() + (delta / 1000) * SPEED;
    if (next > 0) next -= copyWidth;
    x.set(next);
  });

  if (teachers.length === 0) return null;

  const loopedTeachers = [...teachers, ...teachers];

  return (
    <div
      dir="ltr"
      // `pt-36`: TeacherCard.tsx's photo frame pops out well above the
      // card's own top edge (`-mt-[58%]` on its image container). The
      // section's own `SectionDivider` (its wavy top edge) sits at a fixed
      // z-10 right at the panel's top, so with too little clearance here
      // the divider visually painted over the popped-out top of the frame
      // instead of the card clearing it — this padding is what keeps the
      // frame's peak below that strip.
      className="relative overflow-x-hidden overflow-y-visible pt-36 pb-2 px-4 sm:px-6"
      onMouseEnter={() => { isPaused.current = true; }}
      onMouseLeave={() => { isPaused.current = false; }}
    >
      {/*
        `dir="ltr"` here (against the app's ambient `dir="rtl"`) is
        deliberate: in RTL, a flex row wider than its container overflows to
        the LEFT by default (the box's untransformed position is right-
        aligned), which threw off every `translateX` computation below —
        the track would sit thousands of pixels off-screen before the loop
        math ever ran. Pinning the track itself to LTR makes "the track's
        own x=0" match the physical left edge like the wrap/drag math
        assumes; each card re-declares `dir="rtl"` so its Arabic content
        still reads correctly.
      */}
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
        {loopedTeachers.map((teacher, idx) => (
          <div key={`${teacher.id}-${idx}`} dir="rtl" className="w-[260px] sm:w-[280px] shrink-0 pointer-events-auto">
            <TeacherCard teacher={teacher} onViewProfile={onViewProfile} />
          </div>
        ))}
      </motion.div>
    </div>
  );
}
