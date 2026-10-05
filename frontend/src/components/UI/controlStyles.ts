import { cn } from './cn';

export type ControlSize = 'sm' | 'md' | 'lg';

/** Heights/type sizes shared by Input, Select and SearchBox so they line up in a Toolbar. */
export const CONTROL_SIZES: Record<ControlSize, string> = {
  sm: 'h-8 px-3 text-[11px] rounded-lg',
  md: 'h-10 px-3.5 text-xs rounded-xl',
  lg: 'h-12 px-4 text-sm rounded-xl',
};

/**
 * The single source of truth for how an interactive form control looks:
 * surface, border, placeholder, focus ring, invalid tint, disabled state.
 * Every text-entry primitive in this directory composes this.
 */
export function controlBase(hasError?: boolean, extra?: string): string {
  return cn(
    'w-full bg-white dark:bg-slate-900 text-slate-900 dark:text-white',
    'border font-medium transition-colors duration-200',
    'placeholder:text-slate-400 dark:placeholder:text-slate-500 placeholder:font-medium',
    'focus:outline-none focus:ring-2 focus:ring-offset-0',
    'disabled:opacity-60 disabled:cursor-not-allowed disabled:bg-slate-50 dark:disabled:bg-slate-900/50',
    hasError
      ? 'border-rose-400 dark:border-rose-500/60 focus:border-rose-500 focus:ring-rose-500/25'
      : 'border-slate-300 dark:border-slate-700 hover:border-slate-400 dark:hover:border-slate-600 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-blue-500/25',
    extra,
  );
}
