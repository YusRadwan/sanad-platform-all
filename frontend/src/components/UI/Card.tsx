import type { HTMLAttributes, ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from './cn';

export interface CardProps extends HTMLAttributes<HTMLDivElement> {
  /** Surface padding preset. Use 'none' when the card contains a full-bleed table. @default 'md' */
  padding?: 'none' | 'sm' | 'md' | 'lg';
  /** Corner rounding. 'card' = 1rem, 'panel' = 1.5rem for large hero surfaces. @default 'card' */
  radius?: 'card' | 'panel';
  /** Lift + border tint on hover. Only enable on cards that are clickable. */
  interactive?: boolean;
  /** Removes the drop shadow, leaving a flat bordered surface. */
  flat?: boolean;
  children?: ReactNode;
}

const PADDING = {
  none: '',
  sm: 'p-4',
  md: 'p-5 sm:p-6',
  lg: 'p-6 sm:p-8',
} as const;

/**
 * The standard content surface: white / slate-900, hairline border, shallow
 * shadow. Every panel, stat tile and table wrapper should sit in one of these
 * so radius and elevation stay consistent platform-wide.
 */
export function Card({
  padding = 'md',
  radius = 'card',
  interactive = false,
  flat = false,
  className,
  children,
  ...rest
}: CardProps) {
  return (
    <div
      className={cn(
        'bg-white dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800',
        radius === 'panel' ? 'rounded-3xl' : 'rounded-2xl',
        !flat && 'shadow-sm',
        interactive &&
          'transition-all duration-200 hover:shadow-md hover:border-slate-300 dark:hover:border-slate-700 cursor-pointer',
        PADDING[padding],
        className,
      )}
      {...rest}
    >
      {children}
    </div>
  );
}

export interface CardHeaderProps extends Omit<HTMLAttributes<HTMLDivElement>, 'title'> {
  title: ReactNode;
  description?: ReactNode;
  /** Icon shown in a tinted chip beside the title. */
  icon?: LucideIcon;
  /** Right-hand slot (visually LEFT in RTL) for buttons, filters, menus. */
  action?: ReactNode;
  /** Draw a divider under the header. @default true */
  divider?: boolean;
}

/** Title + optional description/icon on one side, an action slot on the other. */
export function CardHeader({
  title,
  description,
  icon: Icon,
  action,
  divider = true,
  className,
  ...rest
}: CardHeaderProps) {
  return (
    <div
      className={cn(
        'flex items-start justify-between gap-4 flex-wrap',
        divider && 'pb-4 mb-5 border-b border-slate-100 dark:border-slate-800',
        className,
      )}
      {...rest}
    >
      <div className="flex items-center gap-3 min-w-0">
        {Icon && (
          <div className="shrink-0 p-2 rounded-xl bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400">
            <Icon className="w-4.5 h-4.5" aria-hidden="true" />
          </div>
        )}
        <div className="min-w-0">
          <h3 className="text-sm sm:text-base font-black text-slate-900 dark:text-white leading-tight truncate">
            {title}
          </h3>
          {description && (
            <p className="text-[11px] sm:text-xs font-medium text-slate-500 dark:text-slate-400 mt-1">
              {description}
            </p>
          )}
        </div>
      </div>
      {action && <div className="shrink-0 flex items-center gap-2">{action}</div>}
    </div>
  );
}

export type CardFooterProps = HTMLAttributes<HTMLDivElement>;

/** Bottom action bar of a card, separated by a divider. */
export function CardFooter({ className, children, ...rest }: CardFooterProps) {
  return (
    <div
      className={cn(
        'flex items-center justify-end gap-2 flex-wrap pt-4 mt-5 border-t border-slate-100 dark:border-slate-800',
        className,
      )}
      {...rest}
    >
      {children}
    </div>
  );
}

export default Card;
