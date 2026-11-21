import { useCallback, useEffect, useRef, useState, type FocusEvent } from 'react';
import { useAnimationFrame, useMotionValue, useReducedMotion } from 'motion/react';

/**
 * Shared engine for the homepage's infinite auto-scrolling carousels
 * (TeachersCarousel, CoursesCarousel) — previously two near-identical copies.
 *
 * Mechanics unchanged: the track renders its items twice and `x` is kept in
 * the `(-copyWidth, 0]` range so the wrap is seamless.
 *
 * Accessibility fixes (WCAG 2.2.2 Pause, Stop, Hide + 2.3.3):
 *  - autoplay never starts when the OS asks for reduced motion
 *    (`useAnimationFrame` is NOT covered by `<MotionConfig reducedMotion>`,
 *    so the old carousels kept moving for those users);
 *  - a user-controlled pause toggle (`isUserPaused` / `toggleUserPause`);
 *  - pauses while hovered, while any card inside has keyboard focus, while
 *    dragged, and while the carousel is off-screen or the tab is hidden
 *    (the last two also stop burning a rAF loop nobody can see).
 */
export function useAutoScrollTrack(itemCount: number, speed = 48) {
  const trackRef = useRef<HTMLDivElement>(null);
  const viewportRef = useRef<HTMLDivElement>(null);
  const x = useMotionValue(0);
  const prefersReducedMotion = useReducedMotion();

  const isHovered = useRef(false);
  const hasFocusWithin = useRef(false);
  const isDragging = useRef(false);
  const isOffscreen = useRef(false);
  const [copyWidth, setCopyWidth] = useState(0);
  const [isUserPaused, setIsUserPaused] = useState(false);

  useEffect(() => {
    const el = trackRef.current;
    if (!el) return;
    const measure = () => setCopyWidth(el.scrollWidth / 2);
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [itemCount]);

  useEffect(() => {
    if (copyWidth > 0) x.set(-copyWidth);
  }, [copyWidth, x]);

  useEffect(() => {
    const el = viewportRef.current;
    if (!el || typeof IntersectionObserver === 'undefined') return;
    const io = new IntersectionObserver(([entry]) => {
      isOffscreen.current = !entry.isIntersecting;
    });
    io.observe(el);
    return () => io.disconnect();
  }, []);

  const autoplayEnabled = !prefersReducedMotion && !isUserPaused;

  useAnimationFrame((_, delta) => {
    if (
      !autoplayEnabled ||
      isHovered.current ||
      hasFocusWithin.current ||
      isDragging.current ||
      isOffscreen.current ||
      copyWidth === 0 ||
      (typeof document !== 'undefined' && document.hidden)
    ) {
      return;
    }
    // Clamp delta: after a background tab returns, the first frame's delta
    // can be seconds long and would visibly jump the track.
    let next = x.get() + (Math.min(delta, 64) / 1000) * speed;
    if (next > 0) next -= copyWidth;
    x.set(next);
  });

  const toggleUserPause = useCallback(() => setIsUserPaused((p) => !p), []);

  const viewportHandlers = {
    onMouseEnter: () => { isHovered.current = true; },
    onMouseLeave: () => { isHovered.current = false; },
    onFocus: (e: FocusEvent<HTMLDivElement>) => {
      hasFocusWithin.current = true;
      // Tabbing into a card that sits off-screen: slide the track so the
      // focused card is fully visible (transform-based tracks never scroll
      // into view on their own, so keyboard focus used to land out of sight).
      const item = (e.target as HTMLElement).closest<HTMLElement>('[data-carousel-item]');
      const viewport = viewportRef.current;
      if (!item || !viewport || copyWidth === 0) return;
      const left = item.offsetLeft;
      const right = left + item.offsetWidth;
      const visibleStart = -x.get();
      const visibleEnd = visibleStart + viewport.clientWidth;
      if (left >= visibleStart && right <= visibleEnd) return;
      const target = -Math.max(0, left - 24);
      x.set(Math.max(-copyWidth, Math.min(0, target)));
    },
    onBlur: (e: FocusEvent<HTMLDivElement>) => {
      if (!e.currentTarget.contains(e.relatedTarget as Node | null)) hasFocusWithin.current = false;
    },
  };

  const trackDragProps = {
    drag: 'x' as const,
    dragConstraints: copyWidth > 0 ? { left: -copyWidth, right: 0 } : undefined,
    dragElastic: 0.06,
    onDragStart: () => { isDragging.current = true; },
    onDragEnd: () => { isDragging.current = false; },
  };

  return {
    trackRef,
    viewportRef,
    x,
    viewportHandlers,
    trackDragProps,
    /** True when motion is actually allowed to run (not reduced-motion). */
    canAutoplay: !prefersReducedMotion,
    isUserPaused,
    toggleUserPause,
  };
}
