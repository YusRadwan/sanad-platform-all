import { X } from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';
import { SidebarProps } from '../types/index';

/**
 * Shared drawer used by AdminSystem and InstructorSystem.
 *
 * `railMode` is OPT-IN and changes desktop behaviour only:
 *
 *   default (InstructorSystem, unchanged)
 *     A pure drawer at every width. Closed = translate-x-full, i.e. entirely
 *     off-screen.
 *
 *   railMode (AdminSystem)
 *     Below lg it is still exactly that drawer, because a permanent rail would
 *     eat a third of a phone screen. From xl up the sidebar leaves the overlay
 *     layer entirely (sticky, in normal flow, so `main` reflows beside it
 *     instead of being covered) and "closed" means COLLAPSED, not hidden:
 *     the width animates 20rem -> 5rem and the icons stay visible and
 *     clickable. Hiding the labels is left to the caller's own children via
 *     lg: variants, since only the caller knows which parts are text.
 *
 * The width/position switch is done purely with lg: variants rather than a JS
 * breakpoint listener, so there is one state (`isOpen`) driving both the
 * mobile drawer and the desktop rail with no resize handling to get wrong.
 *
 * SEVENTH PASS — one flat, fully opaque blue, matching the platform logo's
 * own blue, on direct request after the previous gradient (blue -> indigo)
 * read as blended/uncertain rather than "the logo's colour." No gradient, no
 * alpha on the fill itself — `bg-blue-600` is a solid paint, not a tint over
 * whatever sits behind it.
 *
 * EIGHTH PASS — the earlier "fixed palette, no `dark:` pair" decision is
 * REVERSED: the sidebar now follows the site-wide light/dark toggle like the
 * rest of the app. Light mode is untouched (still exactly `bg-blue-600`);
 * dark mode adopts the surface language already established by this app's own
 * dashboard topbars in AdminSystem/InstructorSystem — `dark:bg-slate-950` with
 * a `dark:border-slate-800` edge — so the panel and the header it hangs under
 * read as one shell instead of a blue slab floating on a slate page.
 * Every layout/position class below — fixed vs sticky, the lg: breakpoint,
 * the right-edge RTL anchoring, translate-x drawer motion, the w-72 -> w-16
 * rail collapse — is still preserved byte-for-byte; only colour changed.
 */
export default function Sidebar({ isOpen, onClose, headerContent, children, overlayClassName, useAnimation, railMode }: SidebarProps) {
  const overlayClass = overlayClassName || 'fixed inset-0 bg-slate-950/25 backdrop-blur-sm z-40';

  const TRANSITION = 'transition-all duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]';

  // One flat, fully opaque fill in each theme — the platform's actual brand
  // blue (#3544e1, the same hex used site-wide for primary buttons/accents,
  // not the Tailwind `blue-900` swatch this used to be pinned to) in light
  // mode, and in dark mode the same slate-950/slate-800 pair the topbars in
  // AdminSystem.tsx and InstructorSystem.tsx already use for their surface.
  const SURFACE = 'bg-[#3544e1] dark:bg-slate-950 border-[#2635b0] dark:border-slate-800';

  const asideClass = railMode
    ? [
        SURFACE,
        TRANSITION,
        'shrink-0',
        // --- below lg: the original overlay drawer, unchanged ---
        'fixed top-0 bottom-0 right-0 z-50 w-72 border-l shadow-2xl',
        isOpen ? 'translate-x-0' : 'translate-x-full',
        // --- lg and up: pinned to the REAL viewport edge, not the centered
        // content column. `fixed` here anchors it to the actual browser window
        // edge, below the header (top-20 = the header's own h-20) and flush to
        // the bottom. The caller reserves space for it via a matching margin
        // on `main` (see AdminSystem.tsx).
        'lg:fixed lg:top-20 lg:bottom-0 lg:right-0 lg:z-30',
        'lg:translate-x-0 lg:h-[calc(100vh-5rem)]',
        'lg:border-l lg:rounded-none lg:shadow-none',
        // The one line that makes "collapsed" a rail instead of a disappearance.
        // `lg:w-64` (not `w-72`, which the mobile drawer above keeps): the
        // desktop rail sits in normal flow and reserves real content width
        // via `main`'s matching margin — narrower here directly widens every
        // page's usable area, unlike the mobile drawer that overlays on top.
        isOpen ? 'lg:w-64' : 'lg:w-16',
      ].join(' ')
    : `fixed top-0 bottom-0 right-0 z-50 w-72 ${SURFACE} border-l shadow-2xl lg:shadow-none ${TRANSITION} shrink-0 ${isOpen ? 'translate-x-0' : 'translate-x-full'}`;

  return (
    <>
      {useAnimation ? (
        <AnimatePresence>
          {isOpen && (
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 0.35 }}
              exit={{ opacity: 0 }}
              onClick={onClose}
              className={overlayClass}
            />
          )}
        </AnimatePresence>
      ) : (
        isOpen && (
          <div
            // In rail mode the backdrop belongs to the mobile drawer only —
            // at xl the sidebar sits in normal flow and covers nothing, so
            // dimming the page there would be wrong.
            className={`${overlayClass}${railMode ? ' lg:hidden' : ''}`}
            onClick={onClose}
          />
        )
      )}

      <aside
        className={`${asideClass} shadow-[4px_0_32px_-12px_rgba(53,68,225,0.35)]`}
        // A single, faint inner seam of light along the panel's LEFT edge (the
        // boundary against the content area, RTL) — the one place a lighter
        // tone touches the shell itself rather than a nav row. Left as ONE
        // fixed value in both themes on purpose: an 8%-white 1px hairline is
        // the correct reading against blue-600 AND against slate-950 (dark
        // surfaces are exactly where a white seam belongs), so branching it on
        // `document.documentElement.classList.contains('dark')` would add a
        // state subscription for an effect no one could tell apart.
        style={{ boxShadow: 'inset 1px 0 0 0 rgba(255,255,255,0.08)' }}
      >
        <div className="sidebar-scroll flex flex-col h-full overflow-y-auto overflow-x-hidden">
          {/* Header Area. With an identity block (headerContent truthy) this
              gets full padding plus two soft ambient glows behind it; with no
              identity content it collapses to a slim bar that exists only to
              host the close button, so the panel doesn't open with a tall
              empty gap. */}
          <div className={`border-b border-white/10 dark:border-slate-800 relative overflow-hidden ${
            headerContent
              ? `p-5 pb-5 ${railMode && !isOpen ? 'lg:p-3 lg:pb-3' : ''}`
              : 'h-14'
          }`}>
            {headerContent && (
              <>
                {/* Ambient glows. On blue-600 a plain white haze reads as
                    light; on slate-950 it would read as grey dust, so dark mode
                    tints the same two blobs with the brand blue/indigo instead
                    — same size, same blur, same positions, colour only. */}
                <div className="pointer-events-none absolute -top-10 -right-8 w-32 h-32 rounded-full bg-white/10 dark:bg-blue-500/10 blur-3xl" />
                <div className="pointer-events-none absolute -top-6 -left-10 w-28 h-28 rounded-full bg-indigo-300/10 dark:bg-indigo-500/10 blur-3xl" />
              </>
            )}
            <button
              onClick={onClose}
              aria-label="إغلاق القائمة"
              // Already collapsed at xl -> the close affordance has nothing
              // left to do and would only crowd the 5rem rail.
              className={`absolute top-4 left-4 p-2 rounded-xl text-blue-200 dark:text-slate-400 hover:text-white dark:hover:text-white hover:bg-white/10 dark:hover:bg-slate-800/60 transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40 dark:focus-visible:ring-blue-500/40 ${
                railMode && !isOpen ? 'lg:hidden' : ''
              }`}
            >
              <X className="w-5 h-5" />
            </button>
            {headerContent && (
              <div className={`relative flex items-center gap-4 text-right pt-2 ${
                railMode && !isOpen ? 'lg:justify-center lg:pt-0' : ''
              }`}>
                {headerContent}
              </div>
            )}
          </div>

          {/* Nav Links */}
          <div className={`flex-1 py-5 px-3 space-y-5 ${railMode && !isOpen ? 'lg:px-2' : ''}`}>
            {children}
          </div>
        </div>
      </aside>
    </>
  );
}
