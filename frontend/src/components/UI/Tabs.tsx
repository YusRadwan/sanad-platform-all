import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from './cn';

export interface TabItem<T extends string = string> {
  /** Stable key compared against `value`. */
  id: T;
  label: ReactNode;
  icon?: LucideIcon;
  /** Optional trailing count pill. */
  count?: number;
  disabled?: boolean;
}

export interface TabsProps<T extends string = string> {
  items: Array<TabItem<T>>;
  /** Currently active tab id. */
  value: T;
  onChange: (id: T) => void;
  /**
   * 'underline' — classic bar, good for page-level sections.
   * 'pill'      — filled segmented control, good inside a Card.
   * @default 'underline'
   */
  variant?: 'underline' | 'pill';
  className?: string;
}

/**
 * Controlled tab bar.
 *
 * Controlled on purpose: the nine pages that already use tabs (PlatformSettings,
 * QuestionBank, VideoManagement, NotesManagement, …) each hold their own
 * `activeTab` state and often derive data from it, so this component must not
 * own that state — it renders whatever the page passes and calls back.
 */
export function Tabs<T extends string = string>({
  items,
  value,
  onChange,
  variant = 'underline',
  className,
}: TabsProps<T>) {
  const isPill = variant === 'pill';

  return (
    <div
      role="tablist"
      className={cn(
        'flex items-center gap-1 overflow-x-auto',
        isPill
          ? 'p-1 rounded-xl bg-slate-100 dark:bg-slate-800/60 w-fit max-w-full'
          : 'border-b border-slate-200 dark:border-slate-800',
        className,
      )}
    >
      {items.map((item) => {
        const isActive = item.id === value;
        const Icon = item.icon;

        return (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={isActive}
            disabled={item.disabled}
            onClick={() => onChange(item.id)}
            className={cn(
              'relative inline-flex items-center gap-2 whitespace-nowrap font-bold transition-colors cursor-pointer',
              'disabled:opacity-50 disabled:pointer-events-none',
              'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
              isPill
                ? cn(
                    'px-3.5 h-8 rounded-lg text-[11px]',
                    isActive
                      ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-sm'
                      : 'text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200',
                  )
                : cn(
                    'px-4 py-3 text-xs -mb-px border-b-2',
                    isActive
                      ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                      : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-700',
                  ),
            )}
          >
            {Icon && <Icon className="w-3.5 h-3.5 shrink-0" aria-hidden="true" />}
            <span>{item.label}</span>
            {typeof item.count === 'number' && (
              <span
                className={cn(
                  'inline-flex items-center justify-center min-w-5 px-1.5 h-5 rounded-md text-[10px] font-black',
                  isActive
                    ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400'
                    : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                )}
              >
                {item.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}

export default Tabs;
