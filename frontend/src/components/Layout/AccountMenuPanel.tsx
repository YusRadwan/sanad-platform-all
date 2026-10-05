import type { ReactNode } from 'react';

/**
 * The account dropdown shell shared by Header.tsx, AdminSystem.tsx and
 * InstructorSystem.tsx: click-away scrim + the animated panel container.
 *
 * TWO things this fixes, both of which had diverged across the three bars:
 *
 * 1. Geometry. Header used `w-[17rem] mt-3 p-2 shadow-e3 ring-1`; the two
 *    dashboards used `w-64 mt-2.5 p-1.5 shadow-xl border ring-1`. One width
 *    (17rem) and one elevation ramp now, everywhere.
 *
 * 2. The animation actually running. The dashboards animated with
 *    `animate-in fade-in slide-in-from-top-2` — classes from the
 *    `tailwindcss-animate` plugin, which this project does NOT install (see
 *    frontend/package.json). They emitted nothing: the panels simply appeared.
 *    Header had already been moved to real CSS transitions; that approach is
 *    the one here.
 *
 * The panel stays MOUNTED and is hidden with opacity/translate/`invisible`
 * rather than being conditionally rendered, because a conditionally rendered
 * element can never animate its own *closing* half. `invisible` +
 * `pointer-events-none` keep it out of the hit-test and the accessibility tree
 * while closed.
 */
/**
 * Shared shape for every row inside the panel. Exported rather than baked in
 * because the rows themselves differ per surface (an admin gets "الرجوع
 * للبوابة الرئيسية", a teacher does not) — only their styling is common.
 */
export const accountMenuItemClass =
  'group/item relative w-full flex items-center gap-3 px-2.5 py-2.5 text-right text-xs md:text-sm font-bold rounded-xl transition-all duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40';

/** The leading icon tile inside an account-menu row. */
export const accountMenuIconClass =
  'w-9 h-9 shrink-0 rounded-xl flex items-center justify-center ring-1 ring-inset transition-all duration-200 group-hover/item:scale-105';

interface AccountMenuPanelProps {
  isOpen: boolean;
  onClose: () => void;
  children: ReactNode;
}

export default function AccountMenuPanel({ isOpen, onClose, children }: AccountMenuPanelProps) {
  return (
    <>
      {isOpen && <div className="fixed inset-0 z-30" onClick={onClose} />}

      <div
        className={`absolute left-0 mt-3 w-[17rem] origin-top-left rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-slate-900/[0.08] dark:ring-white/10 p-2 shadow-e3 z-40 transition-all duration-200 ease-out ${
          isOpen
            ? 'opacity-100 translate-y-0 scale-100'
            : 'opacity-0 -translate-y-2 scale-[0.97] pointer-events-none invisible'
        }`}
        aria-hidden={!isOpen}
      >
        {children}
      </div>
    </>
  );
}
