import { forwardRef } from 'react';
import type { InputHTMLAttributes } from 'react';
import { Search, X } from 'lucide-react';
import { cn } from './cn';
import { CONTROL_SIZES, controlBase } from './controlStyles';
import type { ControlSize } from './controlStyles';

export interface SearchBoxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'size' | 'type'> {
  /** @default 'md' */
  size?: ControlSize;
  /** Shows a clear (×) button when the field is non-empty. Requires `value`. */
  onClear?: () => void;
  /** @default 'بحث...' */
  placeholder?: string;
}

/**
 * Search input with a leading magnifier and an optional clear button.
 * Replaces the hand-built `relative + absolute Search icon` block repeated in
 * ManageSubscriptions, Revenue, ManageUsers and friends.
 */
const SearchBox = forwardRef<HTMLInputElement, SearchBoxProps>(function SearchBox(
  { size = 'md', onClear, placeholder = 'بحث...', className, value, ...rest },
  ref,
) {
  const showClear = Boolean(onClear && typeof value === 'string' && value.length > 0);

  return (
    <div className="relative w-full">
      <Search
        className="pointer-events-none absolute top-1/2 -translate-y-1/2 start-3 w-4 h-4 text-slate-400 dark:text-slate-500"
        aria-hidden="true"
      />
      <input
        ref={ref}
        type="search"
        role="searchbox"
        value={value}
        placeholder={placeholder}
        className={cn(
          controlBase(false),
          CONTROL_SIZES[size],
          'ps-9',
          showClear && 'pe-9',
          '[&::-webkit-search-cancel-button]:hidden',
          className,
        )}
        {...rest}
      />
      {showClear && (
        <button
          type="button"
          onClick={onClear}
          aria-label="مسح البحث"
          className="absolute top-1/2 -translate-y-1/2 end-2 p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors cursor-pointer"
        >
          <X className="w-3.5 h-3.5" />
        </button>
      )}
    </div>
  );
});

export default SearchBox;
