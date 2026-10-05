import type { LucideIcon } from 'lucide-react';

/**
 * One row in the dashboard sidebar — the single rendering used by BOTH
 * AdminSystem.tsx and InstructorSystem.tsx. The two ITEM LISTS stay entirely
 * separate (instructor permissions and admin permissions are NOT merged) —
 * only this rendering is shared, so the two sidebars can never visually drift
 * apart again.
 *
 * FIFTH PASS — the surface (Sidebar.tsx) moved from white-with-a-blue-tint to
 * a full solid blue panel, per direct feedback that the white version still
 * read as "mostly white." This file is the same row STRUCTURE as every prior
 * pass (no boxed icon chip, one accent bar, one calm colour/opacity
 * transition, no shared-layout slide animation) with colours flipped again:
 * WHITE is now the foreground colour (text, active icon, active bar) and the
 * blue panel itself is the background, rather than blue-on-white.
 *
 * SIXTH PASS — the sidebar now follows the site-wide dark toggle (see
 * Sidebar.tsx), so every colour here gained a `dark:` partner. The light
 * values above are untouched: white-on-blue is still exactly right on the
 * blue-600 panel. Dark mode sits on slate-950, where plain white on white-alpha
 * would blow out, so it uses the app's existing dark accent pair instead —
 * `blue-500/10` fills and `blue-400`/`blue-300` foregrounds (the same shades
 * AdminSystem/InstructorSystem already use in dark mode), with slate-400 text
 * for the resting state and a slate-800 hover. Structure, spacing and every
 * duration are unchanged; this is a colour-only pass.
 *
 * `collapsed` is the desktop icon-rail reading. Every collapse rule is an `lg:`
 * variant, so below lg the row always renders in full regardless of the flag —
 * that is what lets one piece of state drive both the mobile drawer and the
 * desktop rail with no resize listener (see Sidebar.tsx's railMode note).
 */
export type SidebarNavEntry = {
  path: string;
  label: string;
  icon: LucideIcon;
  /** Only for a section root that would otherwise prefix-match its children. */
  exact?: boolean;
};

export type SidebarNavGroup = { heading: string; items: SidebarNavEntry[] };

/**
 * The section rule above each group of nav items — a small white dot bullet
 * plus a hairline that fades out, so it reads as a section rule rather than a
 * stray dash. Drops to a bare divider in the collapsed rail (see
 * `SidebarNavRailDivider`).
 */
export function SidebarNavHeading({ heading, collapsed }: { heading: string; collapsed: boolean }) {
  return (
    <div className={`px-3 mb-2.5 flex items-center gap-2.5 ${collapsed ? 'lg:hidden' : ''}`}>
      <span className="w-1 h-1 rounded-full bg-white/60 dark:bg-blue-400/70" />
      <span className="text-[10px] font-black tracking-[0.1em] text-blue-200/70 dark:text-slate-500 whitespace-nowrap uppercase">
        {heading}
      </span>
      <span className="flex-1 h-px bg-gradient-to-l from-white/15 dark:from-slate-700 to-transparent" />
    </div>
  );
}

/** Collapsed-rail stand-in for a group heading: groups stay legible as groups
 *  even once their labels are gone. */
export function SidebarNavRailDivider() {
  return <div className="hidden lg:block h-px bg-white/15 dark:bg-slate-800 mx-2.5 mb-3" />;
}

interface SidebarNavItemProps {
  label: string;
  icon: LucideIcon;
  isActive: boolean;
  collapsed: boolean;
  onClick: () => void;
  /** Unused by this pass (no stagger/shared-layout animation) — kept optional
   *  so neither caller needs editing if a future pass wants it back. */
  index?: number;
}

export default function SidebarNavItem({
  label,
  icon: Icon,
  isActive,
  collapsed,
  onClick,
}: SidebarNavItemProps) {
  return (
    <button
      type="button"
      aria-current={isActive ? 'page' : undefined}
      // `title` is the always-available accessible fallback for the rail; the
      // styled bubble below is purely visual.
      title={label}
      onClick={onClick}
      className={`group relative w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-bold cursor-pointer transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40 dark:focus-visible:ring-blue-500/40 ${
        collapsed ? 'lg:justify-center lg:px-0' : ''
      } ${
        isActive
          ? 'bg-white/15 text-white dark:bg-blue-500/10 dark:text-blue-300'
          : 'text-blue-100/70 dark:text-slate-400 hover:bg-white/8 dark:hover:bg-slate-800/60 hover:text-white dark:hover:text-white'
      }`}
    >
      {/* Accent bar on the row's inner edge (right, RTL) — solid presence, no
          travelling animation, just a quick fade in/out with the row itself. */}
      <span
        className={`absolute right-0 top-1.5 bottom-1.5 w-[3px] rounded-full bg-white dark:bg-blue-400 shadow-[0_0_8px_rgba(255,255,255,0.5)] dark:shadow-[0_0_8px_rgba(96,165,250,0.5)] transition-opacity duration-150 ${
          isActive ? 'opacity-100' : 'opacity-0'
        }`}
      />

      <div className={`relative flex items-center gap-3 ${collapsed ? 'lg:gap-0' : ''}`}>
        {/* The icon is never hidden — it IS the rail. No boxed chip: a bare
            icon that turns solid white when active reads calmer at this
            density than a filled tile would. */}
        <Icon
          className={`w-[18px] h-[18px] shrink-0 transition-colors duration-150 ${
            isActive
              ? 'text-white dark:text-blue-300'
              : 'text-blue-200/60 dark:text-slate-500 group-hover:text-white dark:group-hover:text-white'
          }`}
        />
        <span className={`text-right whitespace-nowrap ${collapsed ? 'lg:hidden' : ''}`}>
          {label}
        </span>
      </div>

      {/* Collapsed rail: the accent bar above already marks the active item,
          so no separate dot is needed here — one signal, not two. */}

      {/* Hover tooltip, collapsed rail only. Opens to the LEFT because the rail
          is on the right in RTL. */}
      {collapsed && (
        <span className="hidden lg:group-hover:flex absolute right-full top-1/2 -translate-y-1/2 mr-3 z-50 items-center px-2.5 py-1.5 rounded-lg bg-slate-900 dark:bg-slate-800 text-white text-[11px] font-bold whitespace-nowrap shadow-lg ring-1 ring-white/10 dark:ring-slate-700 pointer-events-none opacity-0 group-hover:opacity-100 translate-x-1 group-hover:translate-x-0 transition-all duration-150">
          {label}
        </span>
      )}
    </button>
  );
}
