import type { ReactNode } from 'react';
import { ArrowLeft } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from './cn';

export interface BreadcrumbItem {
  label: string;
  /** Omit on the final (current) crumb. */
  onClick?: () => void;
}

export interface PageHeaderProps {
  title: ReactNode;
  /** One-line explanation of what the page is for. */
  description?: ReactNode;
  /** Icon shown in a tinted chip beside the title. */
  icon?: LucideIcon;
  /**
   * Kept for backward compatibility with existing call sites — deliberately
   * NOT rendered any more. The header now carries only the icon, title and
   * description, no breadcrumb trail (explicit user request: "لا اريد اي
   * كلام اخر").
   */
  breadcrumbs?: BreadcrumbItem[];
  /** Buttons/filters aligned to the opposite edge of the title. */
  actions?: ReactNode;
  className?: string;
}

/**
 * Standard page title block for Admin/Instructor screens — a single flat,
 * bordered card: title + one-line description on the right, the icon chip
 * beside them, and a plain left-pointing arrow at the far edge. No breadcrumb
 * trail (see BreadcrumbItem's docblock).
 */
export function PageHeader({
  title,
  description,
  icon: Icon,
  actions,
  className,
}: PageHeaderProps) {
  return (
    <header
      className={cn(
        'flex items-center justify-between gap-4 flex-wrap p-5 rounded-2xl',
        'bg-white dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800',
        className,
      )}
    >
      <div className="flex items-center gap-4 min-w-0">
        <div className="min-w-0">
          <h1 className="text-xl sm:text-2xl font-black text-slate-900 dark:text-white leading-tight tracking-tight">
            {title}
          </h1>
          {description && (
            <p className="mt-1.5 text-xs sm:text-sm font-bold text-slate-500 dark:text-slate-400 leading-relaxed">
              {description}
            </p>
          )}
        </div>
        {Icon && (
          <div className="shrink-0 p-3 rounded-2xl bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400">
            <Icon className="w-5 h-5" aria-hidden="true" />
          </div>
        )}
      </div>

      <div className="flex items-center gap-3 shrink-0">
        {actions}
        <ArrowLeft className="w-4 h-4 text-slate-300 dark:text-slate-600" aria-hidden="true" />
      </div>
    </header>
  );
}

export interface SectionHeaderProps {
  title: ReactNode;
  description?: ReactNode;
  icon?: LucideIcon;
  actions?: ReactNode;
  className?: string;
}

/** Smaller heading for a section WITHIN a page (a step, a group of cards). */
export function SectionHeader({ title, description, icon: Icon, actions, className }: SectionHeaderProps) {
  return (
    <div className={cn('flex items-center justify-between gap-3 flex-wrap mb-4', className)}>
      <div className="flex items-center gap-2.5 min-w-0">
        {Icon && <Icon className="w-4 h-4 shrink-0 text-slate-400 dark:text-slate-500" aria-hidden="true" />}
        <div className="min-w-0">
          <h2 className="text-xs sm:text-sm font-black text-slate-800 dark:text-slate-100 leading-tight">
            {title}
          </h2>
          {description && (
            <p className="mt-0.5 text-[11px] font-medium text-slate-500 dark:text-slate-400">{description}</p>
          )}
        </div>
      </div>
      {actions && <div className="flex items-center gap-2 shrink-0">{actions}</div>}
    </div>
  );
}

export default PageHeader;
