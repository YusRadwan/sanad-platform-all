import type { ReactNode } from 'react';

/**
 * Shared title block for the public homepage sections.
 *
 * The five homepage sections each carried their own hand-copied version of
 * the same block: a small "kicker" label flanked by two gradient bars ABOVE
 * the heading, the heading itself with one oversized brand-blue word, and a
 * decorative underline bar BELOW it. Three decorations framing every
 * heading made each section open identically and pushed the content of the
 * section further down the page.
 *
 * Now: the heading speaks for itself, the brand word keeps its signature
 * oversized blue treatment, and any kicker copy that carried real meaning
 * (e.g. "إزاي سند بتشتغل؟") moves BELOW the heading as a lead sentence.
 *
 * `before` / `after` are the plain words around the highlighted one, so the
 * reading order stays exactly as written in Arabic.
 */
export default function SectionHeading({
  before,
  highlight,
  after,
  lead,
  id,
}: {
  before?: ReactNode;
  highlight: ReactNode;
  after?: ReactNode;
  lead?: ReactNode;
  /** id for the <h2>, so the section can point aria-labelledby at it. */
  id?: string;
}) {
  return (
    <div className="text-center max-w-3xl mx-auto mb-14 md:mb-16">
      <h2
        id={id}
        className="text-4xl md:text-5xl font-black text-slate-900 dark:text-white tracking-tight flex items-baseline justify-center flex-wrap gap-x-4 gap-y-1"
      >
        {before && <span>{before}</span>}
        <span className="text-6xl md:text-8xl leading-[1.1] text-sanad">{highlight}</span>
        {after && <span>{after}</span>}
      </h2>
      {lead && (
        <p className="mt-5 text-lg md:text-xl font-medium leading-relaxed text-slate-600 dark:text-slate-300 max-w-2xl mx-auto">
          {lead}
        </p>
      )}
    </div>
  );
}
