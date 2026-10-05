import { Sun, Moon } from 'lucide-react';

/**
 * The theme pill-switch, shared by Header.tsx, AdminSystem.tsx and
 * InstructorSystem.tsx.
 *
 * All three previously carried a near-identical copy. Header's was the refined
 * one (inset shadow, inset ring, an active-state widening of the knob, a
 * focus-visible ring); the two dashboards had a flatter border-only variant.
 * Header's version is the one kept here.
 *
 * State stays with the caller — each surface owns its own `isDark` and its own
 * settingsService write, and this component only renders and reports clicks.
 */
interface DarkModeToggleProps {
  isDark: boolean;
  onToggle: () => void;
}

export default function DarkModeToggle({ isDark, onToggle }: DarkModeToggleProps) {
  return (
    <button
      onClick={onToggle}
      className="group relative h-10 w-[72px] rounded-full p-1 bg-slate-100/90 hover:bg-slate-200/80 dark:bg-slate-900/90 dark:hover:bg-slate-800/80 ring-1 ring-inset ring-slate-900/[0.06] dark:ring-white/10 shadow-[inset_0_1px_2px_0_rgb(15_23_42/0.06)] dark:shadow-[inset_0_1px_2px_0_rgb(0_0_0/0.4)] transition-colors duration-200 cursor-pointer select-none focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
      aria-label="Toggle Theme"
    >
      <div className="absolute inset-x-1 top-1 bottom-1 flex justify-between items-center text-slate-400 dark:text-slate-600 z-0">
        <Sun className="w-4 h-4 mx-1.5" />
        <Moon className="w-4 h-4 mx-1.5" />
      </div>

      <div
        className={`absolute top-1 bottom-1 w-[32px] rounded-full bg-white dark:bg-slate-700 shadow-md ring-1 ring-slate-900/[0.06] dark:ring-white/10 flex items-center justify-center transition-all duration-300 ease-[cubic-bezier(0.4,0,0.2,1)] z-10 group-active:w-[36px] ${
          isDark ? 'right-1 translate-x-0' : 'right-[35px] translate-x-0'
        }`}
      >
        {isDark ? (
          <Moon className="w-4 h-4 text-blue-400" />
        ) : (
          <Sun className="w-4 h-4 text-amber-500" />
        )}
      </div>
    </button>
  );
}
