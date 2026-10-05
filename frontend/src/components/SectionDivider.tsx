/**
 * Decorative shape divider carved into the top OR bottom edge of a
 * full-bleed coloured section panel (HowItWorksSection's teal,
 * TeachersSection/CoursesSection's grey, FeaturesSection's yellow,
 * JoinUsSection's blue — see each file's own
 * `style={{ backgroundColor: ... }}` panel).
 *
 * Purely decorative and purely additive: it changes nothing about a
 * section's content, data or logic, only how the flat rectangular edge
 * between one panel and the plain page background reads visually. Each
 * `variant` is a different SVG path so adjacent sections don't all wear the
 * identical wave shape (two sections happen to share the same greyish
 * panel colour — `bumps` vs `blob` keeps them visually distinct anyway).
 *
 * TECHNIQUE — filled with the PAGE background, not the panel's own colour,
 * and positioned INSIDE the panel at its edge (`top-0`/`bottom-0`), not
 * outside it. Every one of these panels (and its parent `<section>`) is
 * `overflow-hidden`, so anything positioned outside the panel's own box
 * (e.g. via `bottom-full`) would simply be clipped away and never render.
 * Painting a page-background-coloured shape INSIDE the panel's own edge
 * instead avoids that entirely: the path fills only the wave-shaped sliver,
 * the panel's real background colour shows through everywhere else in that
 * strip (SVG has no opaque backdrop of its own), so the net effect —a wavy
 * boundary instead of a hard rectangle edge — looks identical without
 * needing to escape the clipping box.
 */

export type DividerVariant = 'wave' | 'bumps' | 'tilt' | 'blob' | 'arc';

const PATHS: Record<DividerVariant, string> = {
  // A single gentle S-curve — HowItWorksSection.
  wave: 'M0,0 L1440,0 L1440,32 C1080,96 360,-32 0,32 Z',
  // A soft double arc — TeachersSection.
  bumps: 'M0,0 L1440,0 L1440,60 C1200,10 960,10 720,60 C480,110 240,110 0,60 Z',
  // A leaner diagonal-ish tilt — FeaturesSection (a bit more energetic to
  // match the brand-yellow panel's playful tone).
  tilt: 'M0,0 L1440,0 L1440,90 L0,20 Z',
  // An asymmetric organic curve — CoursesSection (same grey as
  // TeachersSection, deliberately a different profile from `bumps`).
  blob: 'M0,0 L1440,0 L1440,50 C1200,10 980,80 700,40 C420,0 180,110 0,70 Z',
  // A single deep, rounded arc — JoinUsSection, the most pronounced curve of
  // the five, matching its role as the homepage's closing CTA panel.
  arc: 'M0,0 L1440,0 L1440,110 C960,-30 480,-30 0,110 Z',
};

export default function SectionDivider({
  variant,
  position,
  className = '',
}: {
  variant: DividerVariant;
  /** `top`: carved into the panel's top edge. `bottom`: its bottom edge. */
  position: 'top' | 'bottom';
  className?: string;
}) {
  return (
    <div
      aria-hidden="true"
      className={`absolute left-0 w-full overflow-hidden pointer-events-none select-none leading-0 z-10 ${
        position === 'top' ? 'top-0' : 'bottom-0 rotate-180'
      } ${className}`}
    >
      <svg viewBox="0 0 1440 120" preserveAspectRatio="none" className="w-full h-7 sm:h-10 md:h-13">
        <path d={PATHS[variant]} className="fill-slate-50 dark:fill-slate-950" />
      </svg>
    </div>
  );
}
