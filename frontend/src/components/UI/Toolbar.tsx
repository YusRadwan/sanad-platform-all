import type { ReactNode } from 'react';
import { cn } from './cn';

export interface ToolbarProps {
  /** Search field / primary filter. Grows to fill available space. */
  search?: ReactNode;
  /** Secondary filters (selects, segmented controls). */
  filters?: ReactNode;
  /** Action buttons, pushed to the opposite edge. */
  actions?: ReactNode;
  /** Draw a divider beneath — use when the Toolbar sits directly above a Table. */
  divider?: boolean;
  className?: string;
  /** Free-form content; when provided, the slots above are ignored. */
  children?: ReactNode;
}

/**
 * The search + filters + actions row that sits above a table.
 *
 * Every Admin listing page builds this by hand today (ManageSubscriptions,
 * Revenue, ManageUsers, ManageTeachers…), each with slightly different gaps and
 * wrap behaviour. This fixes one layout: the search grows, filters keep their
 * intrinsic width, actions hug the opposite edge, and everything stacks
 * vertically below `sm`.
 */
export function Toolbar({ search, filters, actions, divider = false, className, children }: ToolbarProps) {
  return (
    <div
      className={cn(
        'flex flex-col sm:flex-row sm:items-center gap-3',
        divider && 'pb-4 mb-4 border-b border-slate-100 dark:border-slate-800',
        className,
      )}
    >
      {children ?? (
        <>
          {search && <div className="w-full sm:max-w-xs shrink-0">{search}</div>}
          {filters && <div className="flex items-center gap-2 flex-wrap">{filters}</div>}
          {actions && (
            <div className="flex items-center gap-2 flex-wrap sm:ms-auto shrink-0">{actions}</div>
          )}
        </>
      )}
    </div>
  );
}

export default Toolbar;
