import { ChevronRight, ChevronLeft } from 'lucide-react';
import { cn } from './cn';

export interface PaginationProps {
  /** 1-based. */
  page: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  /** Optional "عرض 1–20 من 340" summary line. */
  totalItems?: number;
  pageSize?: number;
  className?: string;
}

/** Builds a compact page list with ellipses, e.g. [1,'…',4,5,6,'…',20]. */
function buildPages(page: number, totalPages: number): Array<number | '…'> {
  if (totalPages <= 7) return Array.from({ length: totalPages }, (_, i) => i + 1);

  const pages: Array<number | '…'> = [1];
  const start = Math.max(2, page - 1);
  const end = Math.min(totalPages - 1, page + 1);

  if (start > 2) pages.push('…');
  for (let i = start; i <= end; i += 1) pages.push(i);
  if (end < totalPages - 1) pages.push('…');
  pages.push(totalPages);

  return pages;
}

/**
 * Page navigator.
 *
 * RTL note: the app root is `dir="rtl"`, so "previous" points RIGHT and "next"
 * points LEFT. The icons below are chosen to match that reading direction —
 * they are intentionally the opposite of an LTR layout.
 */
export function Pagination({
  page,
  totalPages,
  onPageChange,
  totalItems,
  pageSize,
  className,
}: PaginationProps) {
  if (totalPages <= 1) return null;

  const pages = buildPages(page, totalPages);
  const canPrev = page > 1;
  const canNext = page < totalPages;

  const navBtn =
    'inline-flex items-center justify-center w-9 h-9 rounded-xl border text-xs font-bold transition-colors cursor-pointer ' +
    'border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 ' +
    'hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white ' +
    'disabled:opacity-40 disabled:pointer-events-none';

  return (
    <div
      className={cn(
        'flex items-center justify-between gap-4 flex-wrap px-1 pt-4',
        className,
      )}
    >
      {typeof totalItems === 'number' && typeof pageSize === 'number' ? (
        <p className="text-[11px] font-medium text-slate-500 dark:text-slate-400">
          عرض {Math.min((page - 1) * pageSize + 1, totalItems)}–{Math.min(page * pageSize, totalItems)} من{' '}
          <span className="font-black text-slate-700 dark:text-slate-200">{totalItems}</span>
        </p>
      ) : (
        <span />
      )}

      <nav className="flex items-center gap-1.5" aria-label="ترقيم الصفحات">
        <button
          type="button"
          className={navBtn}
          onClick={() => onPageChange(page - 1)}
          disabled={!canPrev}
          aria-label="الصفحة السابقة"
        >
          <ChevronRight className="w-4 h-4" />
        </button>

        {pages.map((p, i) =>
          p === '…' ? (
            <span key={`gap-${i}`} className="px-1.5 text-xs font-bold text-slate-400 select-none">
              …
            </span>
          ) : (
            <button
              key={p}
              type="button"
              aria-current={p === page ? 'page' : undefined}
              onClick={() => onPageChange(p)}
              className={cn(
                'inline-flex items-center justify-center w-9 h-9 rounded-xl border text-xs font-black transition-colors cursor-pointer',
                p === page
                  ? 'bg-blue-600 border-blue-600 text-white shadow-sm shadow-blue-600/20'
                  : 'border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white',
              )}
            >
              {p}
            </button>
          ),
        )}

        <button
          type="button"
          className={navBtn}
          onClick={() => onPageChange(page + 1)}
          disabled={!canNext}
          aria-label="الصفحة التالية"
        >
          <ChevronLeft className="w-4 h-4" />
        </button>
      </nav>
    </div>
  );
}

export default Pagination;
