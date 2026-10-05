import type { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes, ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from './cn';
import { EmptyState } from './EmptyState';
import { Skeleton } from './Skeleton';
import { Pagination } from './Pagination';

export interface TableProps extends HTMLAttributes<HTMLTableElement> {
  /** Alternate row background for easier horizontal scanning. */
  zebra?: boolean;
  /** Wrapper className, applied to the horizontal-scroll container. */
  containerClassName?: string;
  children?: ReactNode;
}

/**
 * Data table shell.
 *
 * Always wraps the table in an `overflow-x-auto` container so a wide table
 * scrolls WITHIN its card instead of forcing the whole RTL page to scroll
 * sideways — the failure mode this replaces on several Admin screens.
 */
export function Table({ zebra = false, className, containerClassName, children, ...rest }: TableProps) {
  return (
    <div className={cn('w-full overflow-x-auto', containerClassName)}>
      <table
        className={cn(
          'w-full text-right border-collapse',
          zebra && '[&_tbody_tr:nth-child(even)]:bg-slate-50/60 dark:[&_tbody_tr:nth-child(even)]:bg-slate-800/20',
          className,
        )}
        {...rest}
      >
        {children}
      </table>
    </div>
  );
}

export interface TableHeaderProps extends HTMLAttributes<HTMLTableSectionElement> {
  /** Keep the header row visible while the table body scrolls. @default true */
  sticky?: boolean;
  children?: ReactNode;
}

/** `<thead>` with the platform's muted, uppercase-weight header treatment. */
export function TableHeader({ sticky = true, className, children, ...rest }: TableHeaderProps) {
  return (
    <thead
      className={cn(
        'bg-slate-50 dark:bg-slate-800/40',
        sticky && 'sticky top-0 z-10',
        className,
      )}
      {...rest}
    >
      {children}
    </thead>
  );
}

export type TableBodyProps = HTMLAttributes<HTMLTableSectionElement>;

/** `<tbody>` with hairline row dividers. */
export function TableBody({ className, children, ...rest }: TableBodyProps) {
  return (
    <tbody className={cn('divide-y divide-slate-100 dark:divide-slate-800', className)} {...rest}>
      {children}
    </tbody>
  );
}

export interface TableRowProps extends HTMLAttributes<HTMLTableRowElement> {
  /** Row highlight on hover. @default true */
  hoverable?: boolean;
  /** Persistent selected/active tint. */
  selected?: boolean;
}

export function TableRow({ hoverable = true, selected = false, className, children, ...rest }: TableRowProps) {
  return (
    <tr
      className={cn(
        'transition-colors',
        hoverable && 'hover:bg-slate-50 dark:hover:bg-slate-800/40',
        selected && 'bg-blue-50/70 dark:bg-blue-500/10',
        className,
      )}
      {...rest}
    >
      {children}
    </tr>
  );
}

export interface TableHeadProps extends Omit<ThHTMLAttributes<HTMLTableCellElement>, 'align'> {
  /** Right-align numeric columns' headers with their cells. */
  align?: 'start' | 'center' | 'end';
}

const ALIGN = {
  start: 'text-start',
  center: 'text-center',
  end: 'text-end',
} as const;

/** `<th>` — column label. */
export function TableHead({ align = 'start', className, children, ...rest }: TableHeadProps) {
  return (
    <th
      scope="col"
      className={cn(
        'px-4 py-3 text-[11px] font-black text-slate-500 dark:text-slate-400 whitespace-nowrap',
        'border-b border-slate-200 dark:border-slate-800',
        ALIGN[align],
        className,
      )}
      {...rest}
    >
      {children}
    </th>
  );
}

export interface TableCellProps extends Omit<TdHTMLAttributes<HTMLTableCellElement>, 'align'> {
  align?: 'start' | 'center' | 'end';
  /** Renders the cell content in the emphasis weight used for identifiers/amounts. */
  emphasis?: boolean;
}

/** `<td>` — body cell. */
export function TableCell({ align = 'start', emphasis = false, className, children, ...rest }: TableCellProps) {
  return (
    <td
      className={cn(
        'px-4 py-3.5 text-xs align-middle',
        emphasis
          ? 'font-black text-slate-900 dark:text-white'
          : 'font-medium text-slate-600 dark:text-slate-300',
        ALIGN[align],
        className,
      )}
      {...rest}
    >
      {children}
    </td>
  );
}

export interface TableEmptyStateProps {
  /** Must match the table's column count so the message spans the full width. */
  colSpan: number;
  icon?: LucideIcon;
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
}

/** Full-width "no rows" message rendered as a single spanning cell. */
export function TableEmptyState({ colSpan, icon, title, description, action }: TableEmptyStateProps) {
  return (
    <tr>
      <td colSpan={colSpan} className="p-0">
        <EmptyState icon={icon} title={title} description={description} action={action} />
      </td>
    </tr>
  );
}

export interface TableSkeletonProps {
  /** Column count — must match the header. */
  columns: number;
  /** @default 5 */
  rows?: number;
}

/** Placeholder rows shown while the table's data request is in flight. */
export function TableSkeleton({ columns, rows = 5 }: TableSkeletonProps) {
  return (
    <>
      {Array.from({ length: rows }).map((_, r) => (
        <tr key={r}>
          {Array.from({ length: columns }).map((__, c) => (
            <td key={c} className="px-4 py-3.5">
              <Skeleton variant="text" width={c === 0 ? '70%' : '50%'} />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}

/** Pagination bar sized for placement directly under a Table. */
export const TablePagination = Pagination;

export default Table;
