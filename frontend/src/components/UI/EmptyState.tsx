import type { ReactNode } from 'react';
import { Inbox } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from './cn';

export interface EmptyStateProps {
  /** @default Inbox */
  icon?: LucideIcon;
  title: ReactNode;
  description?: ReactNode;
  /** Usually a <Button>. Rendered under the description. */
  action?: ReactNode;
  /** Tighter padding, for use inside a table body or a small panel. @default 'md' */
  size?: 'sm' | 'md';
  className?: string;
}

/** Icon + message + optional action, for "no results" and "nothing here yet". */
export function EmptyState({
  icon: Icon = Inbox,
  title,
  description,
  action,
  size = 'md',
  className,
}: EmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center text-center',
        size === 'sm' ? 'py-8 px-4' : 'py-14 px-6',
        className,
      )}
    >
      <div
        className={cn(
          'flex items-center justify-center rounded-2xl bg-slate-100 dark:bg-slate-800/70 text-slate-400 dark:text-slate-500',
          size === 'sm' ? 'w-11 h-11' : 'w-16 h-16',
        )}
      >
        <Icon className={size === 'sm' ? 'w-5 h-5' : 'w-7 h-7'} aria-hidden="true" />
      </div>
      <h4
        className={cn(
          'font-black text-slate-800 dark:text-slate-100',
          size === 'sm' ? 'mt-3 text-xs' : 'mt-4 text-sm sm:text-base',
        )}
      >
        {title}
      </h4>
      {description && (
        <p className="mt-1.5 max-w-sm text-[11px] sm:text-xs font-medium text-slate-500 dark:text-slate-400 leading-relaxed">
          {description}
        </p>
      )}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}

export default EmptyState;
